<?php

namespace Modules\EIS\Services\Terminal;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\EIS\Models\EisConfiguration;
use Modules\EIS\Models\EisTerminalConfiguration;
use Modules\EIS\Models\EisTaxRate;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Services\Configuration\ConfigurationSyncService;
use Modules\EIS\Services\EisSubscriptionAccess;

class EisTerminalActivationService
{
    protected string $apiBaseUrl;

    public function __construct(
        protected ConfigurationSyncService $syncService
    ) {
        $this->apiBaseUrl = config('eis.base_url', 'https://api.eis.example.com');
    }

    /**
     * Activate terminal via EIS API - No token required.
     *
     * @param int $businessId
     * @param string $activationCode
     * @param array $environment
     * @param int|null $activatedBy
     * @return array
     */
    public function activateTerminal(
        int $businessId,
        string $activationCode,
        array $environment = [],
        ?int $activatedBy = null,
        ?int $locationId = null
    ): array {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            Log::info('Terminal activation started', [
                'business_id' => $businessId,
                'activation_code' => $activationCode
            ]);

            // Step 1: Call EIS API to activate terminal
            $activationResponse = $this->callActivationAPI($businessId, $activationCode, $environment);

            // Step 2: Process activation response and save to database
            $result = $this->processActivationResponse(
                $businessId,
                $activationResponse,
                $activationCode,
                $environment,
                $activatedBy,
                $locationId
            );

            Log::info('Result: ', [$result]);

            // Step 3: After successful activation and database sync, send confirmation
            if ($result['success']) {
                $terminalId = $result['data']['terminal_id'] ?? null;
                $secretKey = $result['terminal_credentials']['secretKey'] ?? null;
                $jwtToken = $result['terminal_credentials']['jwtToken'] ?? null;
                
                if ($terminalId && $secretKey && $jwtToken) {
                    // Send activation confirmation to EIS API
                    $confirmationSuccess = $this->sendActivationConfirmation($businessId, $terminalId, $activationCode, $jwtToken, $secretKey);
                    $result['data']['is_confirmed'] = $confirmationSuccess;
                    $result['confirmed'] = $confirmationSuccess;
                    if ($confirmationSuccess) {
                        $result['data']['confirmed_at'] = now();
                    }
                    
                    if (!$confirmationSuccess) {
                        $result['message'] .= ' Warning: EIS did not acknowledge the activation confirmation; check the logs before using this terminal.';
                        Log::warning('Activation confirmation could not be sent to EIS', [
                            'terminal_id' => $terminalId,
                            'business_id' => $businessId,
                            'note' => 'Terminal configuration was saved but EIS confirmation may not have been received'
                        ]);
                    }
                } else {
                    Log::warning('Missing required credentials for confirmation', [
                        'terminal_id' => $terminalId,
                        'has_secret_key' => !empty($secretKey),
                        'has_jwt_token' => !empty($jwtToken)
                    ]);
                }
            }

            \Modules\EIS\Services\Configuration\ConfigurationSyncService::businessLocation($businessId, (int) $result['terminal_setting_id']);
            $this->dispatchSyncProductsJob($businessId, (int) $result['terminal_setting_id']);

            return $result;

        } catch (\Exception $e) {
            Log::error('Terminal activation failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to activate terminal: ' . $e->getMessage(),
                'status_code' => $e->getCode() ?: 500
            ];
        }
    }

    /**
     * Send activation confirmation to EIS API with X-Signature.
     * Signature uses terminal_activation_code and secretKey from response.
     *
     * @param string $terminalId
     * @param string $activationCode
     * @param string $secretKey
     * @return bool
     */
    private function sendActivationConfirmation(int $businessId, string $terminalId, string $activationCode, string $jwtToken, string $secretKey): bool
    {
        try {
            $url = $this->apiBaseUrl . '/onboarding/terminal-activated-confirmation';

            $payload = [
                'terminalId' => $terminalId
            ];

            // Compute signature using terminal_activation_code and secretKey from response
            $signature = $this->computeXSignature($activationCode, $secretKey);

            Log::debug('Sending activation confirmation', [
                'url' => $url,
                'terminal_id' => $terminalId,
                'signature' => $signature,
                'payload' => $payload,
                'activationCode' => $activationCode,
                'secreteKey' => $secretKey,
            ]);

            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $jwtToken,
                    'X-Signature' => $signature,
                    'accept' => 'text/plain',
                ])
                ->timeout(30)
                ->post($url, $payload);

            if (!$response->successful()) {
                Log::error('Activation confirmation failed', [
                    'terminal_id' => $terminalId,
                    'status' => $response->status(),
                    'response_present' => $response->body() !== '',
                ]);
                return false;
            }

            $responseData = $response->json();

            Log::info('Activation confirmation sent successfully', [
                'terminal_id' => $terminalId,
                'response_data_present' => is_array($responseData) && !empty($responseData),
            ]);

            // Update terminal as confirmed
            $terminal = EisTerminalConfiguration::whereHas('configuration', fn ($query) => $query->where('business_id', $businessId))
                ->where('terminal_id', $terminalId)->first();
            if ($terminal) {
                $terminal->update([
                    'confirmed_at' => now(),
                    'confirmation_response' => mb_substr((string) json_encode($responseData), 0, 255),
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send activation confirmation', [
                'terminal_id' => $terminalId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Update eis_settings table with terminal details.
     * Creates new record if not exists.
     *
     * @param object $data
     * @param int $businessId
     * @return void
     */
    private function updateEisSettings(object $data, int $businessId, int $locationId): EisSetting
    {
        try {
            // Extract values from the data object with proper null checking
            $terminalId = $data->activatedTerminal->terminalId ?? null;
            $secretKey = $data->activatedTerminal->terminalCredentials->secretKey ?? null;
            $jwtToken = $data->activatedTerminal->terminalCredentials->jwtToken ?? null;
            $tpin = $data->configuration->taxpayerConfiguration->tin ?? null;
            $siteId = $data->configuration->terminalConfiguration->terminalSite->siteId ?? null;
            if (!is_string($secretKey) || $secretKey === '' || !is_string($jwtToken) || $jwtToken === '') {
                throw new \InvalidArgumentException('EIS activation did not return complete terminal credentials.');
            }

            Log::info('Extracted EIS data for settings update', [
                'business_id' => $businessId,
                'terminal_id' => $terminalId,
                'has_secret_key' => !empty($secretKey),
                'has_jwt_token' => !empty($jwtToken),
                'tpin' => $tpin,
                'site_id' => $siteId
            ]);

            // Find or create eis_setting record
            $setting = EisSetting::where('business_id', $businessId)->where('device_id', $terminalId)->first();
            
            if ($setting) {
                // Update existing record
                $setting->update([
                    'device_id' => $terminalId,
                    'location_id' => $locationId,
                    'status' => 1,
                    'secret_key' => $secretKey,
                    'jwt_token' => $jwtToken,
                    'branch_id' => $siteId,
                    'tpin' => $tpin,
                    'last_sync_at' => now(),
                    'sync_status' => 'success',
                    'sync_error' => null,
                ]);
                
                Log::info('EIS settings updated after activation', [
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'device_id' => $terminalId,
                    'has_secret_key' => !empty($secretKey),
                    'has_jwt_token' => !empty($jwtToken),
                    'tpin' => $tpin,
                    'site_id' => $siteId
                ]);
                
                // Dispatch SyncProductsJob

            } else {
                // Create new record if not exists
                $attributes = [
                    'business_id' => $businessId,
                    'location_id' => $locationId,
                    'device_id' => $terminalId,
                    'secret_key' => $secretKey,
                    'jwt_token' => $jwtToken,
                    'branch_id' => $siteId,
                    'tpin' => $tpin,
                    'status' => 1,
                    'sync_status' => 'success',
                    'last_sync_at' => now(),
                    'successful_syncs' => 1,
                    'failed_syncs' => 0,
                ];
                // Older schemas require this column; newer deployments may omit it.
                if ((new EisSetting())->getConnection()->getSchemaBuilder()->hasColumn('eis_settings', 'base_url')) {
                    $attributes['base_url'] = $this->apiBaseUrl;
                }
                $setting = EisSetting::create($attributes);
                
                Log::info('EIS settings created after activation', [
                    'business_id' => $businessId,
                    'device_id' => $terminalId,
                    'has_secret_key' => !empty($secretKey),
                    'has_jwt_token' => !empty($jwtToken),
                    'tpin' => $tpin,
                    'site_id' => $siteId
                ]);
                
                // Dispatch SyncProductsJob
            }
            return $setting;
        } catch (\Exception $e) {
            Log::error('Failed to update/create EIS settings', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Sync configurations after activation.
     *
     * @param int $businessId
     * @param string|null $jwtToken
     * @return void
     */
    private function syncAfterActivation(int $businessId, ?string $jwtToken): void
    {
        if (!$jwtToken) {
            Log::warning('No JWT token available for sync', [
                'business_id' => $businessId
            ]);
            return;
        }

        try {
            app(ConfigurationSyncService::class)->sync($businessId, $jwtToken);
            Log::info('Configurations synced after activation', [
                'business_id' => $businessId
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to sync configurations after activation', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Dispatch SyncProductsJob after activation.
     *
     * @param int $businessId
     * @return void
     */
    private function dispatchSyncProductsJob(int $businessId, int $terminalId): void
    {
        try {
            dispatch(new \Modules\EIS\Jobs\SyncProductsJob($businessId, $terminalId))
                ->onQueue('eis-products');
            
            Log::info('SyncProductsJob dispatched successfully', [
                'business_id' => $businessId,
                'queue' => 'eis-products'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch SyncProductsJob', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Compute X-Signature using SHA-512 as per MRA specification.
     * Signature = base64_encode(hash_hmac('sha512', activationCode, secretKey, true))
     *
     * @param string $activationCode
     * @param string $secretKey
     * @return string
     */
    private function computeXSignature(string $activationCode, string $secretKey): string
    {
        // HMAC-SHA512 hash of the activation code using the secret key
        $hash = hash_hmac('sha512', $activationCode, $secretKey, true);
        return base64_encode($hash);
    }

    /**
     * Call EIS activation API with correct payload structure.
     * statusCode: 1 is considered success.
     *
     * @param int $businessId
     * @param string $activationCode
     * @param array $environment
     * @return object
     * @throws \Exception
     */
    private function callActivationAPI(int $businessId, string $activationCode, array $environment): object
    {
        $url = $this->apiBaseUrl . '/onboarding/activate-terminal';
        $mode = strtolower(trim((string) config('eis.environment', 'development')));
        if (!in_array($mode, ['development', 'dev', 'sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('EIS_ENV must be development or production.');
        }
        $productId = trim((string) config('eis.product_id'));
        if ($mode === 'production' && $productId === '') {
            throw new \InvalidArgumentException('Set the registered EIS_PRODUCT_ID before activating a production terminal.');
        }
        $productId = $productId !== '' ? $productId : trim((string) config('app.name', 'POS System'));
        $productVersion = trim((string) config('eis.product_version', '1.0.0'));
        if ($productId === '' || $productVersion === '') {
            throw new \InvalidArgumentException('EIS activation requires a product ID and EIS_PRODUCT_VERSION.');
        }

        // Build the correct payload structure
        $payload = [
            'terminalActivationCode' => $activationCode,
            'environment' => [
                'platform' => [
                    'osName' => $this->platformOsName($environment['platform']['osName'] ?? null),
                    'osVersion' => $environment['platform']['osVersion'] ?? 'Unknown',
                    'osBuild' => $environment['platform']['osBuild'] ?? '',
                    'macAddress' => $environment['platform']['macAddress'] ?? '00:00:00:00:00:00'
                ],
                'pos' => [
                    'productID' => $productId,
                    'productVersion' => $productVersion
                ]
            ]
        ];

        Log::debug('Calling EIS activation API', [
            'url' => $url,
            'business_id' => $businessId,
            'payload' => $payload
        ]);

        // No authentication token required
        $response = Http::acceptJson()
            ->timeout(60)
            ->post($url, $payload);

        if (!$response->successful()) {
            Log::error('EIS activation API error', [
                'status' => $response->status(),
                'business_id' => $businessId
            ]);

            throw new \Exception('EIS activation API failed', $response->status());
        }

        $responseData = $response->object();

        Log::debug('EIS activation API response', [
            'business_id' => $businessId,
            'status_code' => $responseData->statusCode ?? null,
            'data_present' => isset($responseData->data),
        ]);

        // Check if response has error (statusCode: 1 = success, other = error)
        if (isset($responseData->statusCode) && $responseData->statusCode !== 1) {
            throw new \Exception('EIS activation was rejected', (int) $responseData->statusCode);
        }

        // Check if data exists
        if (!isset($responseData->data) || !isset($responseData->data->activatedTerminal)) {
            Log::error('EIS activation API response missing data', [
                'business_id' => $businessId,
                'status_code' => $responseData->statusCode ?? null,
                'data_present' => isset($responseData->data),
            ]);
            throw new \Exception('EIS activation API response missing terminal data');
        }

        return $responseData;
    }

    private function platformOsName(?string $name): string
    {
        $name = trim($name ?? '');
        if ($name === '' || in_array(strtolower($name), ['unknown', 'web'], true)) {
            $name = (string) request()->header('User-Agent', '');
        }
        foreach ([
            'Android' => '/android/i',
            'iOS' => '/iphone|ipad|ipod|^ios$/i',
            'Windows' => '/windows|^win32$|^win64$/i',
            'MacOS' => '/macintosh|macintel|mac os|^macos$|^darwin$/i',
            'Linux' => '/linux/i',
        ] as $os => $pattern) {
            if (preg_match($pattern, $name)) {
                return $os;
            }
        }
        throw new \InvalidArgumentException('Cannot identify the terminal operating system. Use Windows, MacOS, Linux, Android or iOS.');
    }

    /**
     * Process activation response and save data.
     *
     * @param int $businessId
     * @param object $response
     * @param string $activationCode
     * @param array $environment
     * @param int|null $activatedBy
     * @return array
     */
    private function processActivationResponse(
        int $businessId,
        object $response,
        string $activationCode,
        array $environment,
        ?int $activatedBy,
        ?int $locationId = null
    ): array {
        try {
            $identity = $response->data->activatedTerminal->terminalId ?? null;
            if (!is_string($identity) || trim($identity) === '') {
                throw new \InvalidArgumentException('EIS activation requires a terminal identifier.');
            }
            return \Illuminate\Support\Facades\Cache::lock('eis-activate-'.hash('sha256', $identity), 60)
                ->block(10, fn () => DB::transaction(function () use ($businessId, $response, $activationCode, $environment, $activatedBy, $locationId) {
                $business = \App\Business::where('id', $businessId)->lockForUpdate()->first();
                if (! $business) {
                    throw new \InvalidArgumentException('Business not found for terminal activation.');
                }
                $isFirstTerminal = ! EisSetting::withTrashed()->where('business_id', $businessId)->exists();

                // Get activated terminal data
                $activatedTerminal = $response->data->activatedTerminal ?? null;
                $configurationData = $response->data->configuration ?? null;

                if (!$activatedTerminal) {
                    Log::error('No activated terminal data in response', [
                        'business_id' => $businessId,
                        'response_present' => $response->body() !== ''
                    ]);
                    throw new \Exception('No activated terminal data in response');
                }

                if (!$configurationData) {
                    Log::error('No configuration data in response', [
                        'business_id' => $businessId,
                        'response_present' => $response->body() !== ''
                    ]);
                    throw new \Exception('No configuration data in response');
                }

                Log::info('Processing activation response', [
                    'business_id' => $businessId,
                    'terminal_id' => $activatedTerminal->terminalId ?? null,
                    'taxpayer_id' => $activatedTerminal->taxpayerId ?? null,
                    'has_credentials' => isset($activatedTerminal->terminalCredentials)
                ]);

                $terminalId = $activatedTerminal->terminalId ?? null;
                if (!is_string($terminalId) || trim($terminalId) === '') {
                    throw new \InvalidArgumentException('EIS activation requires a terminal identifier.');
                }
                $position = filter_var($activatedTerminal->terminalPosition ?? null, FILTER_VALIDATE_INT);
                $taxpayerId = filter_var($activatedTerminal->taxpayerId ?? null, FILTER_VALIDATE_INT);
                if ($position === false || $position < 1 || $taxpayerId === false || $taxpayerId < 1) {
                    throw new \InvalidArgumentException('EIS activation requires a valid taxpayer identifier and terminal position.');
                }
                if (EisSetting::where('device_id', $terminalId)->where('business_id', '!=', $businessId)->exists()
                    || EisTerminalConfiguration::where('terminal_id', $terminalId)
                        ->whereHas('configuration', fn ($query) => $query->where('business_id', '!=', $businessId))->exists()) {
                    throw new \InvalidArgumentException('EIS terminal is already assigned to another business.');
                }
                $currentSettings = EisSetting::where('business_id', $businessId)->where('device_id', $terminalId)->lockForUpdate()->first();
                if ($currentSettings && $activatedBy !== null) {
                    $permitted = \App\User::where('business_id', $businessId)->findOrFail($activatedBy)->permitted_locations($businessId);
                    if ($permitted !== 'all' && !in_array((int) $currentSettings->location_id, array_map('intval', $permitted), true)) {
                        throw new \InvalidArgumentException('You do not have access to the terminal current location.');
                    }
                }
                $configurationTerminalId = $configurationData->terminalConfiguration->terminalId ?? null;
                if ($configurationTerminalId !== null && (string) $configurationTerminalId !== $terminalId) {
                    throw new \InvalidArgumentException('EIS activation response contains mismatched terminal identities.');
                }
                $this->assertActivationBusinessIdentity($businessId, $configurationData, $taxpayerId);
                $this->normalizeVatRegistration($configurationData);
                $location = $this->syncService->locationForActivation($businessId, $configurationData, $activatedBy);
                // A terminal can only serve an active location: reopen it and bring back its retired products.
                if ($location->is_active !== null && !$location->is_active) {
                    $location->update(['is_active' => 1]);
                    app(\App\Services\LocationProducts::class)->restore($businessId, (int) $location->id);
                }
                if ($currentSettings && (int) $currentSettings->location_id !== (int) $location->id) {
                    \App\BusinessLocation::where('business_id', $businessId)
                        ->where('default_eis_setting_id', $currentSettings->id)
                        ->update(['default_eis_setting_id' => null]);
                }

                $setting = $this->updateEisSettings($response->data, $businessId, (int) $location->id);
                TerminalContext::assertConfiguration($setting, $configurationData);
                $this->claimLocationDefault($businessId, (int) $location->id, (int) $setting->id);
                // EIS prices are tax-inclusive, so the business must show inline tax on purchases and sales.
                DB::table('business')->where('id', $businessId)->update(['enable_inline_tax' => 1]);
                if ($isFirstTerminal) {
                    $commonSettings = $business->common_settings ?? [];
                    $commonSettings['is_enabled_export'] = 1;
                    $business->common_settings = $commonSettings;
                    $business->save();
                }
                if (app()->bound('session') && (int) session('business.id') === $businessId) {
                    session(['business.enable_inline_tax' => 1]);
                    if ($isFirstTerminal) {
                        session(['business.common_settings' => $commonSettings]);
                    }
                }

                // Save or update main configuration
                $configuration = $this->saveConfiguration($businessId, $configurationData, $setting);

                // Save or update terminal configuration
                $terminal = $this->saveTerminalConfiguration(
                    $configuration,
                    $configurationData->terminalConfiguration ?? null,
                    $activatedTerminal,
                    $activationCode,
                    $environment,
                    $activatedBy
                );
                $this->syncService->syncTerminalConfiguration($configuration, $configurationData);
                $terminal->refresh();

                // Sync tax rates
                if (isset($configurationData->globalConfiguration)) {
                    $this->syncTaxRates($configuration, $configurationData);
                }

                Log::info('Terminal activated and saved successfully', [
                    'business_id' => $businessId,
                    'terminal_config_id' => $terminal->id,
                    'terminal_id' => $activatedTerminal->terminalId ?? null,
                    'activated_by' => $activatedBy
                ]);

                // Get terminal credentials for response
                $terminalCredentials = null;
                if (isset($activatedTerminal->terminalCredentials)) {
                    $terminalCredentials = [
                        'jwtToken' => $activatedTerminal->terminalCredentials->jwtToken ?? null,
                        'secretKey' => $activatedTerminal->terminalCredentials->secretKey ?? null
                    ];
                }

                return [
                    'success' => true,
                    'message' => $response->remark ?? 'Terminal activated successfully',
                    'data' => $this->getTerminalDetails($terminal, $activatedTerminal, $businessId),
                    'terminal_setting_id' => $setting->id,
                    'terminal_credentials' => $terminalCredentials,
                    'activation_code' => $activationCode,
                    'status_code' => $response->statusCode ?? 1
                ];
            }));
        } catch (\Exception $e) {
            Log::error('Failed to process activation response', [
                'business_id' => $businessId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * The first terminal activated at a location becomes its default; an existing valid default is kept.
     */
    private function claimLocationDefault(int $businessId, int $locationId, int $settingId): void
    {
        $location = \App\BusinessLocation::where('business_id', $businessId)->lockForUpdate()->find($locationId);
        if (!$location) {
            return;
        }

        $currentDefault = (int) $location->default_eis_setting_id;
        $hasValidDefault = $currentDefault > 0 && EisSetting::where('business_id', $businessId)
            ->where('location_id', $locationId)
            ->whereKey($currentDefault)
            ->exists();

        if (!$hasValidDefault) {
            \App\BusinessLocation::whereKey($locationId)->update(['default_eis_setting_id' => $settingId]);
        }
    }

    private function normalizeVatRegistration(object $data): void
    {
        $flag = $data->taxpayerConfiguration->isVATRegistered ?? null;
        if (is_string($flag)) {
            $flag = strtolower(trim($flag));
        }
        if (in_array($flag, [true, 1, '1', 'true'], true)) {
            $data->taxpayerConfiguration->isVATRegistered = true;
        } elseif (in_array($flag, [false, 0, '0', 'false'], true)) {
            $data->taxpayerConfiguration->isVATRegistered = false;
        } else {
            throw new \InvalidArgumentException('EIS activation requires a valid taxpayer VAT registration status.', 422);
        }
    }

    private function assertActivationBusinessIdentity(int $businessId, object $data, int $taxpayerId): void
    {
        $tin = $data->taxpayerConfiguration->tin ?? null;
        $tradingName = $data->terminalConfiguration->tradingName ?? null;
        if ((!is_string($tin) && !is_int($tin)) || trim((string) $tin) === ''
            || !is_string($tradingName) || trim($tradingName) === '') {
            throw new \InvalidArgumentException('EIS activation requires a taxpayer TIN and trading name to verify account ownership.', 422);
        }
        $tin = trim((string) $tin);
        $tradingName = mb_strtolower(trim($tradingName));

        // Include inactive terminals and the terminal being reactivated: neither resets account ownership.
        foreach (EisSetting::where('business_id', $businessId)->pluck('tpin') as $existingTin) {
            if ($existingTin !== null && trim((string) $existingTin) !== ''
                && trim((string) $existingTin) !== $tin) {
                throw new \InvalidArgumentException('This terminal belongs to a different taxpayer TIN. Use a separate ERP business account.', 422);
            }
        }
        $registered = DB::table('eis_configurations as configuration')
            ->leftJoin('eis_terminal_configurations as terminal', function ($join) {
                $join->on('terminal.configuration_id', '=', 'configuration.id')->whereNull('terminal.deleted_at');
            })
            ->where('configuration.business_id', $businessId)
            ->whereNull('configuration.deleted_at')
            ->get(['configuration.tpin', 'terminal.taxpayer_id', 'terminal.trading_name']);
        foreach ($registered as $terminal) {
            if (($terminal->tpin !== null && trim((string) $terminal->tpin) !== ''
                    && trim((string) $terminal->tpin) !== $tin)
                || ($terminal->taxpayer_id !== null && (int) $terminal->taxpayer_id > 0
                    && (int) $terminal->taxpayer_id !== $taxpayerId)) {
                throw new \InvalidArgumentException('This terminal belongs to a different taxpayer. Use a separate ERP business account.', 422);
            }
            if (is_string($terminal->trading_name) && trim($terminal->trading_name) !== ''
                && mb_strtolower(trim($terminal->trading_name)) !== $tradingName) {
                throw new \InvalidArgumentException('This terminal belongs to a different trading name. Use a separate ERP business account.', 422);
            }
        }

        // A taxpayer trading name belongs to exactly one ERP account, including when its terminals are inactive.
        $ownedElsewhere = DB::table('eis_configurations as configuration')
            ->join('eis_terminal_configurations as terminal', 'terminal.configuration_id', '=', 'configuration.id')
            ->where('configuration.business_id', '!=', $businessId)
            ->whereNull('configuration.deleted_at')->whereNull('terminal.deleted_at')
            ->where(fn ($query) => $query->whereRaw('TRIM(configuration.tpin) = ?', [$tin])
                ->orWhere('terminal.taxpayer_id', $taxpayerId))
            ->whereRaw('LOWER(TRIM(terminal.trading_name)) = ?', [$tradingName])
            ->exists();
        if ($ownedElsewhere) {
            throw new \InvalidArgumentException('This business is already registered in another ERP account. Activate its terminals in that account.', 422);
        }
    }

    /**
     * Generate activation code.
     *
     * @param int $businessId
     * @return string
     */
    private function generateActivationCode(int $businessId): string
    {
        return 'TAC-' . strtoupper(uniqid() . '-' . $businessId);
    }

    /**
     * Save configuration from activation response.
     *
     * @param int $businessId
     * @param object $configurationData
     * @return EisConfiguration
     */
    private function saveConfiguration(int $businessId, object $configurationData, EisSetting $setting): EisConfiguration
    {
        $globalConfig = $configurationData->globalConfiguration ?? null;
        $terminalConfig = $configurationData->terminalConfiguration ?? null;
        $taxpayerConfig = $configurationData->taxpayerConfiguration ?? null;

        $data = [
            'business_id' => $businessId,
            'eis_setting_id' => $setting->id,
            'location_id' => $setting->location_id,
            'global_version' => $globalConfig->versionNo ?? null,
            'terminal_version' => $terminalConfig->versionNo ?? null,
            'taxpayer_version' => $taxpayerConfig->versionNo ?? null,
            'tpin' => $taxpayerConfig->tin ?? null,
            'is_vat_registered' => $taxpayerConfig->isVATRegistered ?? false,
            'tax_office_code' => $taxpayerConfig->taxOfficeCode ?? null,
            'tax_office_name' => $taxpayerConfig->taxOffice->name ?? null,
            'raw_response' => json_encode(['data' => $configurationData]),
            'last_synced_at' => now()
        ];

        return EisConfiguration::updateOrCreate(
            ['eis_setting_id' => $setting->id],
            $data
        );
    }

    /**
     * Save terminal configuration from activation response.
     *
     * @param EisConfiguration $configuration
     * @param object|null $terminalData
     * @param object $activatedTerminal
     * @param string $activationCode
     * @param array $environment
     * @param int|null $activatedBy
     * @return EisTerminalConfiguration
     */
    private function saveTerminalConfiguration(
        EisConfiguration $configuration,
        ?object $terminalData,
        object $activatedTerminal,
        string $activationCode,
        array $environment,
        ?int $activatedBy
    ): EisTerminalConfiguration {
        // Prepare terminal configuration data
        $data = [
            'configuration_id' => $configuration->id,
            'version' => $terminalData->versionNo ?? null,
            'terminal_label' => $terminalData->terminalLabel ?? null,
            'is_active' => true,
            // Reactivation needs a fresh EIS confirmation.
            'confirmed_at' => null,
            'confirmation_response' => null,
            'email_address' => $terminalData->emailAddress ?? null,
            'phone_number' => $terminalData->phoneNumber ?? null,
            'trading_name' => $terminalData->tradingName ?? null,
            'max_transaction_age' => $terminalData->maxTransactionAgeInHours ?? null,
            'max_cummulative_amount' => $terminalData->maxCummulativeAmount ?? null,
            'address_lines' => isset($terminalData->addressLines) 
                ? json_encode($terminalData->addressLines) 
                : null,
            'raw_data' => json_encode($terminalData),
            'last_synced_at' => now(),
            // Activation fields
            'activated_at' => now(),
            'activated_by' => $activatedBy,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
            'toggled_at' => now(),
            'toggled_by' => $activatedBy,
            'activation_code' => $activationCode,
            'activation_environment' => json_encode($environment),
            // Terminal details from response
            'terminal_id' => $activatedTerminal->terminalId ?? null,
            'terminal_position' => $activatedTerminal->terminalPosition ?? 0,
            'taxpayer_id' => $activatedTerminal->taxpayerId ?? null,
            'activation_date' => $activatedTerminal->activationDate ?? now(),
            'jwt_token' => $activatedTerminal->terminalCredentials->jwtToken ?? null,
            'secret_key' => $activatedTerminal->terminalCredentials->secretKey ?? null,
        ];

        // Update or create terminal configuration
        return EisTerminalConfiguration::updateOrCreate(
            ['configuration_id' => $configuration->id],
            $data
        );
    }

    /**
     * Sync tax rates from configuration data.
     *
     * @param EisConfiguration $configuration
     * @param object $configurationData
     * @return void
     */
    private function syncTaxRates(EisConfiguration $configuration, object $configurationData): void
    {
        $taxRates = $configurationData->globalConfiguration->taxrates ?? [];
        $activatedTaxRateIds = $configurationData->taxpayerConfiguration->activatedTaxRateIds ?? [];
        $activatedTaxRates = $configurationData->taxpayerConfiguration->activatedTaxrates ?? [];

        if (empty($taxRates)) {
            Log::warning('No tax rates found in activation response', [
                'configuration_id' => $configuration->id
            ]);
            return;
        }

        Log::info('Syncing tax rates from activation', [
            'configuration_id' => $configuration->id,
            'total_tax_rates' => count($taxRates),
            'activated_count' => count($activatedTaxRateIds)
        ]);

        $syncedIds = [];
        foreach ($taxRates as $taxRate) {
            $isActivated = in_array($taxRate->id, $activatedTaxRateIds);
            
            $activationDetails = null;
            foreach ($activatedTaxRates as $activated) {
                if (isset($activated->taxRateId) && $activated->taxRateId === $taxRate->id) {
                    $activationDetails = $activated;
                    break;
                }
            }

            $rate = EisTaxRate::updateOrCreate(
                [
                    'configuration_id' => $configuration->id,
                    'tax_rate_id' => $taxRate->id
                ],
                [
                    'name' => $taxRate->name ?? null,
                    'charge_mode' => $taxRate->chargeMode ?? 'Item',
                    'ordinal' => $taxRate->ordinal ?? 100,
                    'rate' => $taxRate->rate ?? 0,
                    'is_activated' => $isActivated,
                    'activation_id' => $activationDetails->id ?? null,
                ]
            );
            
            $syncedIds[] = $rate->id;
        }

        // Clean up old tax rates
        EisTaxRate::where('configuration_id', $configuration->id)
            ->whereNotIn('id', $syncedIds)
            ->delete();

        Log::debug('Tax rates synced from activation', [
            'configuration_id' => $configuration->id,
            'synced_count' => count($syncedIds)
        ]);
    }

    /**
     * Get terminal details.
     *
     * @param EisTerminalConfiguration $terminal
     * @param object|null $activatedTerminal
     * @return array
     */
    private function getTerminalDetails(EisTerminalConfiguration $terminal, ?object $activatedTerminal = null, ?int $businessId = null): array
    {
        $isActive = TerminalContext::isOperational((bool) $terminal->configuration?->setting?->status, $terminal->is_active);
        $details = [
            'id' => $terminal->id,
            'configuration_id' => $terminal->configuration_id,
            'terminal_label' => $terminal->terminal_label,
            'is_active' => $isActive,
            'is_confirmed' => $terminal->is_confirmed ?? false,
            'status' => $isActive ? 'Active' : 'Inactive',
            'trading_name' => $terminal->trading_name,
            'email_address' => $terminal->email_address,
            'phone_number' => $terminal->phone_number,
            'version' => $terminal->version,
            'address' => $terminal->full_address ?? '',
            'activated_at' => $terminal->activated_at,
            'activated_by' => $terminal->activated_by,
            'deactivated_at' => $terminal->deactivated_at,
            'deactivated_by' => $terminal->deactivated_by,
            'deactivation_reason' => $terminal->deactivation_reason,
            'toggled_at' => $terminal->toggled_at,
            'toggled_by' => $terminal->toggled_by,
            'last_synced_at' => $terminal->last_synced_at,
            'activation_code' => $terminal->activation_code,
            'activation_environment' => null,
            'terminal_id' => $terminal->terminal_id,
            'terminal_position' => $terminal->terminal_position,
            'taxpayer_id' => $terminal->taxpayer_id,
            'activation_date' => $terminal->activation_date,
            'confirmed_at' => $terminal->confirmed_at,
            'confirmation_response' => $terminal->confirmation_response ? $terminal->confirmation_response : null,
        ];

        // Add business location branch if businessId is provided
        if ($businessId) {
            $businessLocation = \App\BusinessLocation::where('business_id', $businessId)
                ->whereKey($terminal->configuration->location_id)->first();
            $details['branch'] = $businessLocation?->name ?? null;
        }

        // Add site details
        if ($terminal->terminalSite) {
            $details['site'] = [
                'site_id' => $terminal->terminalSite->site_id,
                'site_name' => $terminal->terminalSite->site_name
            ];
        } else {
            $details['site'] = null;
        }

        // Add offline limit details
        if ($terminal->offlineLimit) {
            $details['offline_limit'] = [
                'max_transaction_age_hours' => $terminal->offlineLimit->max_transaction_age_hours,
                'max_cumulative_amount' => $terminal->offlineLimit->max_cumulative_amount
            ];
        } else {
            $details['offline_limit'] = null;
        }

        // Add terminal credentials if available
        if ($activatedTerminal && isset($activatedTerminal->terminalCredentials)) {
            $details['terminal_credentials'] = [
                'jwt_token' => $activatedTerminal->terminalCredentials->jwtToken ?? null,
                'secret_key' => $activatedTerminal->terminalCredentials->secretKey ?? null
            ];
        } elseif ($terminal->jwt_token || $terminal->secret_key) {
            $details['terminal_credentials'] = [
                'jwt_token' => $terminal->jwt_token,
                'secret_key' => $terminal->secret_key
            ];
        }

        return $details;
    }

    /**
     * Deactivate a terminal.
     *
     * @param int $businessId
     * @param string|null $reason
     * @param int|null $deactivatedBy
     * @return array
     */
    public function deactivate(int $businessId, ?string $reason = null, ?int $deactivatedBy = null, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            Log::info('Terminal deactivation requested', [
                'business_id' => $businessId,
                'reason' => $reason
            ]);

            $setting = TerminalContext::settings($businessId, null, $terminalId);
            $configuration = EisConfiguration::where('eis_setting_id', $setting->id)->first();
            
            if (!$configuration) {
                return [
                    'success' => false,
                    'message' => 'Configuration not found for this business'
                ];
            }

            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            if (!$terminal) {
                return [
                    'success' => false,
                    'message' => 'Terminal configuration not found'
                ];
            }

            if (!$terminal->is_active && !$setting->status) {
                app(InactiveTerminalStock::class)->clear($businessId, (int) $setting->location_id);
                app(InactiveTerminalStock::class)->unlinkProducts($businessId, (int) $setting->location_id);
                return [
                    'success' => true,
                    'message' => 'Terminal is already inactive',
                    'data' => $this->getTerminalDetails($terminal, null, $businessId)
                ];
            }

            DB::transaction(function () use ($terminal, $setting, $deactivatedBy, $reason) {
                \App\Business::whereKey($setting->business_id)->lockForUpdate()->firstOrFail();
                $setting->update(['status' => false]);
                $terminal->update([
                    'is_active' => false,
                    'deactivated_at' => now(),
                    'deactivated_by' => $deactivatedBy,
                    'deactivation_reason' => $reason,
                    'toggled_at' => now(),
                    'toggled_by' => $deactivatedBy,
                ]);
                app(InactiveTerminalStock::class)->clear((int) $setting->business_id, (int) $setting->location_id);
                app(InactiveTerminalStock::class)->unlinkProducts((int) $setting->business_id, (int) $setting->location_id);
            });

            Log::info('Terminal deactivated successfully', [
                'business_id' => $businessId,
                'terminal_config_id' => $terminal->id
            ]);

            return [
                'success' => true,
                'message' => 'Terminal deactivated successfully',
                'data' => $this->getTerminalDetails($terminal, null, $businessId)
            ];

        } catch (\Exception $e) {
            Log::error('Terminal deactivation failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to deactivate terminal: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Toggle terminal activation status.
     *
     * @param int $businessId
     * @param string $token
     * @param int|null $toggledBy
     * @return array
     */
    public function toggle(int $businessId, string $token, ?int $toggledBy = null, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            $setting = TerminalContext::settings($businessId, $token, $terminalId);
            Log::info('Terminal activation toggle requested', [
                'business_id' => $businessId
            ]);

            $configuration = EisConfiguration::where('eis_setting_id', $setting->id)->first();
            
            if (!$configuration) {
                return [
                    'success' => false,
                    'message' => 'Configuration not found'
                ];
            }

            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            if (!$terminal) {
                return [
                    'success' => false,
                    'message' => 'Terminal configuration not found'
                ];
            }

            $newStatus = !TerminalContext::isOperational((bool) $setting->status, $terminal->is_active);
            
            $updateData = [
                'is_active' => $newStatus,
                'toggled_at' => now(),
                'toggled_by' => $toggledBy
            ];

            if ($newStatus) {
                $updateData['activated_at'] = now();
                $updateData['activated_by'] = $toggledBy;
                $updateData['deactivated_at'] = null;
                $updateData['deactivated_by'] = null;
                $updateData['deactivation_reason'] = null;
            } else {
                $updateData['deactivated_at'] = now();
                $updateData['deactivated_by'] = $toggledBy;
            }

            DB::transaction(function () use ($terminal, $setting, $updateData, $newStatus) {
                \App\Business::whereKey($setting->business_id)->lockForUpdate()->firstOrFail();
                $setting->update(['status' => $newStatus]);
                $terminal->update($updateData);
                if (!$newStatus) {
                    app(InactiveTerminalStock::class)->clear((int) $setting->business_id, (int) $setting->location_id);
                    app(InactiveTerminalStock::class)->unlinkProducts((int) $setting->business_id, (int) $setting->location_id);
                }
            });

            Log::info('Terminal status toggled', [
                'business_id' => $businessId,
                'terminal_config_id' => $terminal->id,
                'new_status' => $newStatus ? 'active' : 'inactive'
            ]);

            return [
                'success' => true,
                'message' => 'Terminal ' . ($newStatus ? 'activated' : 'deactivated') . ' successfully',
                'data' => $this->getTerminalDetails($terminal, null, $businessId)
            ];

        } catch (\Exception $e) {
            Log::error('Terminal toggle failed', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to toggle terminal: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Dispatch a job to fetch the latest EIS configuration.
     *
     * @param int $businessId
     * @param string $token
     * @param int|null $toggledBy
     * @return array
     */
    public function getLatestConfig(int $businessId, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            // Get the EIS settings for this business
            $setting = TerminalContext::settings($businessId, null, $terminalId);
            
            if (!$setting) {
                return [
                    'success' => false,
                    'message' => 'Business not configured for EIS'
                ];
            }
            
            if (!$setting->jwt_token) {
                return [
                    'success' => false,
                    'message' => 'JWT token not available for this business'
                ];
            }
            
            // Sync configuration directly for this business via API
            $configuration = $this->syncService->sync($businessId, $setting->jwt_token, false, (int) $setting->id);
            
            // Get the updated terminal details after sync
            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            // Update sync status
            $setting->update([
                'last_sync_at' => now(),
                'sync_status' => 'success',
                'sync_error' => null,
                'successful_syncs' => ($setting->successful_syncs ?? 0) + 1,
                'global_version' => $configuration->global_version ?? null,
                'terminal_version' => $configuration->terminal_version ?? null,
                'taxpayer_version' => $configuration->taxpayer_version ?? null,
            ]);
            
            // Create business location if it doesn't exist
            ConfigurationSyncService::businessLocation($businessId, (int) $setting->id);
            
            Log::info('Latest configuration synced successfully', [
                'business_id' => $businessId,
                'global_version' => $configuration->global_version,
                'terminal_version' => $configuration->terminal_version,
                'taxpayer_version' => $configuration->taxpayer_version,
                'terminal_is_active' => $terminal?->is_active
            ]);
            
            return [
                'success' => true,
                'message' => 'Latest configuration fetched successfully',
                'data' => [
                    'global_version' => $configuration->global_version,
                    'terminal_version' => $configuration->terminal_version,
                    'taxpayer_version' => $configuration->taxpayer_version,
                    'terminal_is_active' => TerminalContext::isOperational((bool) $setting->status, $terminal?->is_active)
                ]
            ];

        } catch (\Throwable $e) {
            Log::error('Failed to sync latest configuration', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to fetch latest configuration: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get terminal status.
     *
     * @param int $businessId
     * @return array
     */
    public function getStatus(int $businessId, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            $setting = TerminalContext::settings($businessId, null, $terminalId);
            $configuration = EisConfiguration::where('eis_setting_id', $setting->id)->first();
            
            if (!$configuration) {
                return [
                    'success' => false,
                    'message' => 'Configuration not found',
                    'is_active' => false
                ];
            }

            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            if (!$terminal) {
                return [
                    'success' => false,
                    'message' => 'Terminal configuration not found',
                    'is_active' => false
                ];
            }

            return [
                'success' => true,
                'is_active' => TerminalContext::isOperational((bool) $setting->status, $terminal->is_active),
                'is_confirmed' => $terminal->is_confirmed ?? false,
                'data' => $this->getTerminalDetails($terminal, null, $businessId)
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get terminal status', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to get terminal status: ' . $e->getMessage(),
                'is_active' => false
            ];
        }
    }

    /**
     * Check if terminal is active.
     *
     * @param int $businessId
     * @return bool
     */
    public function isActive(int $businessId, ?int $terminalId = null): bool
    {
        $status = $this->getStatus($businessId, $terminalId);
        return $status['is_active'] ?? false;
    }

    /**
     * Get terminal activation history.
     *
     * @param int $businessId
     * @return array
     */
    public function getActivationHistory(int $businessId, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            $setting = TerminalContext::settings($businessId, null, $terminalId);
            $configuration = EisConfiguration::where('eis_setting_id', $setting->id)->first();
            
            if (!$configuration) {
                return [
                    'success' => false,
                    'message' => 'Configuration not found'
                ];
            }

            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            if (!$terminal) {
                return [
                    'success' => false,
                    'message' => 'Terminal configuration not found'
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'activated_at' => $terminal->activated_at,
                    'activated_by' => $terminal->activated_by,
                    'deactivated_at' => $terminal->deactivated_at,
                    'deactivated_by' => $terminal->deactivated_by,
                    'deactivation_reason' => $terminal->deactivation_reason,
                    'toggled_at' => $terminal->toggled_at,
                    'toggled_by' => $terminal->toggled_by,
                    'current_status' => TerminalContext::isOperational((bool) $setting->status, $terminal->is_active) ? 'Active' : 'Inactive',
                    'is_confirmed' => $terminal->is_confirmed ?? false,
                    'confirmed_at' => $terminal->confirmed_at,
                    'terminal_id' => $terminal->terminal_id,
                    'activation_date' => $terminal->activation_date
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get activation history', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to get activation history: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Regenerate terminal credentials.
     *
     * @param int $businessId
     * @param string $token
     * @return array
     */
    public function regenerateCredentials(int $businessId, string $token, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            $setting = TerminalContext::settings($businessId, $token, $terminalId);
            Log::info('Regenerating terminal credentials', [
                'business_id' => $businessId
            ]);

            $configuration = EisConfiguration::where('eis_setting_id', $setting->id)->first();
            
            if (!$configuration) {
                return [
                    'success' => false,
                    'message' => 'Configuration not found'
                ];
            }

            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)->first();
            
            if (!$terminal) {
                return [
                    'success' => false,
                    'message' => 'Terminal configuration not found'
                ];
            }

            $url = $this->apiBaseUrl . '/onboarding/regenerate-credentials';
            
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(60)
                ->post($url, [
                    'business_id' => $businessId,
                    'terminal_id' => $terminal->terminal_id
                ]);

            if (!$response->successful()) {
                Log::error('EIS regenerate credentials API error', [
                    'business_id' => $businessId,
                    'status' => $response->status(),
                    'response_present' => $response->body() !== ''
                ]);
                return ['success' => false, 'message' => 'Failed to regenerate credentials'];
            }

            $responseData = $response->json();
            
            if (!empty($responseData['data']['jwtToken']) && !empty($responseData['data']['secretKey'])) {
                DB::transaction(function () use ($terminal, $setting, $responseData) {
                    $terminal->update([
                        'jwt_token' => $responseData['data']['jwtToken'],
                        'secret_key' => $responseData['data']['secretKey'],
                    ]);
                    $setting->update([
                        'secret_key' => $responseData['data']['secretKey'],
                        'jwt_token' => $responseData['data']['jwtToken'],
                    ]);
                });
            } else {
                throw new \UnexpectedValueException('EIS did not return renewed terminal credentials.');
            }

            Log::info('Terminal credentials regenerated successfully', [
                'business_id' => $businessId,
                'terminal_config_id' => $terminal->id
            ]);

            return [
                'success' => true,
                'message' => 'Terminal credentials regenerated successfully',
                'data' => [
                    'jwt_token' => $terminal->jwt_token,
                    'secret_key' => $terminal->secret_key
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Failed to regenerate credentials', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to regenerate credentials: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Sync terminal configuration.
     *
     * @param int $businessId
     * @param string $token
     * @return array
     */
    public function syncTerminal(int $businessId, string $token, ?int $terminalId = null): array
    {
        app(EisSubscriptionAccess::class)->assertAllowed($businessId);

        try {
            Log::info('Syncing terminal configuration', [
                'business_id' => $businessId
            ]);

            $configuration = $this->syncService->sync($businessId, $token, false, $terminalId);
            
            $terminal = EisTerminalConfiguration::where('configuration_id', $configuration->id)
                ->with(['terminalSite', 'offlineLimit'])
                ->first();

            return [
                'success' => true,
                'message' => 'Terminal synced successfully',
                'data' => $terminal ? $this->getTerminalDetails($terminal, null, $businessId) : null
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync terminal', [
                'business_id' => $businessId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to sync terminal: ' . $e->getMessage()
            ];
        }
    }
}