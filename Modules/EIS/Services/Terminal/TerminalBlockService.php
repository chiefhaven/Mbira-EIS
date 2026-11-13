<?php

namespace Modules\EIS\Services\Terminal;

use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Services\Utilities\EisUtilitiesClient;

class TerminalBlockService
{
    public const DEFAULT_REASON = 'Terminal blocked by MRA. The blocking reason is unavailable; retrieve the terminal blocking message before continuing.';

    public static function shouldBlock(array $response): bool
    {
        return in_array($response['data']['shouldBlockTerminal'] ?? false, [true, 1, '1', 'true'], true);
    }

    public static function message(object $setting): string
    {
        return 'EIS terminal blocked: '.($setting->blocking_reason ?: self::DEFAULT_REASON);
    }

    public static function visibleTo($user)
    {
        $locations = $user->permitted_locations();
        return EisSetting::where('business_id', $user->business_id)
            ->when($locations !== 'all', fn ($query) => $query->whereIn('location_id', $locations))
            ->get()->filter(fn ($terminal) => $terminal->is_blocked);
    }

    public static function assertAllowed(EisSetting $setting): void
    {
        $fresh = EisSetting::where('business_id', $setting->business_id)->findOrFail($setting->id);
        if ($fresh->is_blocked) {
            throw ValidationException::withMessages(['terminal_id' => self::message($fresh)]);
        }
    }

    public function refreshStatus(EisSetting $setting): void
    {
        $setting = EisSetting::where('business_id', $setting->business_id)->findOrFail($setting->id);
        if (trim((string) $setting->device_id) === '') {
            throw new \InvalidArgumentException('Configure the selected terminal ID before checking its block status.');
        }
        $utilities = app(EisUtilitiesClient::class);
        $payload = ['terminalId' => (string) $setting->device_id];
        $result = $utilities->send($setting, 'get-terminal-blocking-message', $payload);
        $data = $result['response']['data'] ?? null;
        if ($result['outcome'] !== 'accepted' || !is_array($data) || !is_bool($data['isBlocked'] ?? null)) {
            throw new \InvalidArgumentException('MRA terminal block status could not be confirmed. The saved block state has been retained.');
        }
        if ($data['isBlocked'] || !$setting->fresh()->is_blocked) {
            return;
        }

        $result = $utilities->send($setting, 'check-terminal-unblock-status', $payload);
        $data = $result['response']['data'] ?? null;
        if ($result['outcome'] !== 'accepted' || !is_array($data) || !is_bool($data['isUnblocked'] ?? null)) {
            throw new \InvalidArgumentException('MRA terminal unblock status could not be confirmed. The terminal remains blocked.');
        }
        if (!$data['isUnblocked']) {
            Log::warning('EIS has not confirmed terminal unblock; retaining block', ['terminal_id' => $setting->id]);
        }
    }

    public function handleResponse(EisSetting $setting, array $response): void
    {
        if (!self::shouldBlock($response)) {
            return;
        }

        // Persist the block before the reason lookup; an unavailable lookup must not allow sales.
        $this->block($setting, self::DEFAULT_REASON);
        try {
            $result = app(EisUtilitiesClient::class)->send($setting, 'get-terminal-blocking-message', [
                'terminalId' => (string) $setting->device_id,
            ]);
        } catch (ConnectionException|\InvalidArgumentException $exception) {
            Log::warning('EIS terminal blocking reason unavailable', ['terminal_id' => $setting->id]);
            return;
        }
        $data = $result['response']['data'] ?? null;
        if ($result['outcome'] !== 'accepted' || !is_array($data)
            || ($data['isBlocked'] ?? null) !== true
            || !is_string($data['blockingReason'] ?? null) || trim($data['blockingReason']) === '') {
            Log::warning('EIS terminal blocking reason was not confirmed', ['terminal_id' => $setting->id]);
            return;
        }
        $this->block($setting, trim($data['blockingReason']));
    }

    public function handleUtilityResponse(EisSetting $setting, string $operation, array $response): void
    {
        if (($response['statusCode'] ?? null) !== 1 || !empty($response['errors'])) {
            return;
        }
        if ($operation === 'get-terminal-blocking-message' && ($response['data']['isBlocked'] ?? null) === true) {
            $reason = $response['data']['blockingReason'] ?? null;
            $this->block($setting, is_string($reason) && trim($reason) !== '' ? trim($reason) : self::DEFAULT_REASON);
        }
        if ($operation === 'check-terminal-unblock-status' && ($response['data']['isUnblocked'] ?? null) === true) {
            EisSetting::where('business_id', $setting->business_id)->whereKey($setting->id)
                ->update(['is_blocked' => false, 'blocking_reason' => null]);
            Log::info('EIS confirmed terminal unblock', ['terminal_id' => $setting->id]);
        }
    }

    private function block(EisSetting $setting, string $reason): void
    {
        EisSetting::where('business_id', $setting->business_id)->whereKey($setting->id)
            ->update(['is_blocked' => true, 'blocking_reason' => $reason]);
        Log::warning('EIS terminal blocked', ['terminal_id' => $setting->id, 'business_id' => $setting->business_id]);
    }

    public static function assertTransactionAllowed(Connection $db, object $transaction): void
    {
        if (!in_array($transaction->type, ['sell', 'sales_order', 'sell_return', 'purchase', 'purchase_return',
            'sell_transfer', 'purchase_transfer', 'stock_adjustment'], true)
            || !$db->getSchemaBuilder()->hasTable('eis_settings')) {
            return;
        }
        $terminals = $db->table('eis_settings')->where('business_id', $transaction->business_id)->get()
            ->filter(fn ($row) => empty($row->deleted_at)
                && (int) ($row->location_id ?? 0) === (int) $transaction->location_id);
        if (!$terminals->contains(fn ($row) => !empty($row->is_blocked))) {
            return;
        }

        $terminalId = null;
        $originalId = $transaction->return_parent_id ?? $transaction->id ?? null;
        if ($originalId && $db->getSchemaBuilder()->hasTable('eis_sales')) {
            $tracking = $db->table('eis_sales')->where('business_id', $transaction->business_id)
                ->where('transaction_id', $originalId)->first();
            $terminalId = $tracking->eis_setting_id ?? null;
        }
        if (!$terminalId && !empty($transaction->connector_till_id)
            && $db->getSchemaBuilder()->hasTable('connector_tills')) {
            $till = $db->table('connector_tills')->where('business_id', $transaction->business_id)
                ->where('location_id', $transaction->location_id)->where('id', $transaction->connector_till_id)->first();
            $terminalId = $till->eis_setting_id ?? null;
        }
        if (!$terminalId) {
            $location = $db->table('business_locations')->where('business_id', $transaction->business_id)
                ->where('id', $transaction->location_id)->first();
            $terminalId = $location->default_eis_setting_id ?? null;
        }
        $selected = $terminalId ? $terminals->firstWhere('id', $terminalId)
            : ($terminals->count() === 1 ? $terminals->first() : null);
        if (!$selected) {
            throw ValidationException::withMessages(['terminal_id' => 'Select the transaction terminal. This location has a blocked EIS terminal.']);
        }
        if (!empty($selected->is_blocked)) {
            throw ValidationException::withMessages(['terminal_id' => self::message($selected)]);
        }
    }
}
