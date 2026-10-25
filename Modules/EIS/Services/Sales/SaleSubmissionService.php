<?php

namespace Modules\EIS\Services\Sales;

use App\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\EIS\Models\EisSale;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Services\Http\EisSaleClient;
use Modules\EIS\Exceptions\EisSaleException;
use Modules\EIS\Services\Sales\InvoiceNumberGenerator;
use Modules\EIS\Services\Sales\SaleResponseService;

class SaleSubmissionService
{
    public function __construct(
        protected SaleTransformer $transformer,
        protected EisSaleClient $client,
        protected SaleResponseService $responseService,
        protected InvoiceNumberGenerator $invoiceGenerator
    ) {}

    /**
     * Submit sale transaction to EIS.
     *
     * @param Transaction $transaction
     * @param object $settings
     * @return EisSale
     * @throws EisSaleException
     */
    public function submit(Transaction $transaction, object $settings): EisSale
    {
        return Cache::lock('eis-sale-'.$transaction->business_id.'-'.$transaction->id, 300)
            ->block(10, fn () => $this->submitLocked($transaction, $settings));
    }

    private function submitLocked(Transaction $transaction, object $settings): EisSale
    {
        \Modules\EIS\Services\Terminal\TerminalContext::assertBusiness($settings, (int) $transaction->business_id);
        // Fence before invoice counters, tracking or any external call.
        \App\Services\Sales\SavedSaleWriteGuard::fiscal($transaction->getConnection(), $transaction->id);
        $transaction->refresh();
        \Modules\EIS\Services\Terminal\TerminalContext::assertBusiness($settings, (int) $transaction->business_id);
        // ----------------------------
        // IDEMPOTENCY CHECK (BEFORE DB TX)
        // ----------------------------
        $existing = EisSale::where('transaction_id', $transaction->id)->first();
        if ($existing && (int) $existing->business_id !== (int) $transaction->business_id) {
            throw new EisSaleException('EIS sale tracking belongs to a different business terminal.');
        }

        if ($existing && $existing->status === 'submitted') {
            \App\Services\Sales\SavedSaleWriteGuard::recordFiscalReceipt($transaction->getConnection(), $transaction->id);
            $transaction->refresh();
            return $existing;
        }

        // ----------------------------
        // GET EIS SETTINGS
        // ----------------------------
        $eisSetting = \Modules\EIS\Services\Terminal\TerminalContext::forSale($transaction);
        $settings = $eisSetting;
        if ($transaction->location_id) {
            $location = \App\BusinessLocation::where('business_id', $transaction->business_id)->find($transaction->location_id);
            if (!$location || (string) $location->eis_site_id !== \Modules\EIS\Services\Terminal\TerminalContext::site($settings)) {
                throw new EisSaleException('Sale location does not match the business terminal site.');
            }
        }

        // ----------------------------
        // GENERATE EIS Number
        // ----------------------------
        // Inspect history before resetting tracking; pending may have reached EIS.
        $includeOfflineSignature = $existing && ($existing->status === 'failed' || !empty($existing->request_payload));
        $includeOfflineSignature = $includeOfflineSignature || DB::table('eis_failed_transactions')
            ->where('business_id', $transaction->business_id)->where('transaction_id', $transaction->id)->exists();
        // Split fiscal guards require a top-level transaction; lock allocation without enclosing their model saves.
        [$eisSale, $eisInvoiceNumber] = Cache::lock('eis-invoice-terminal-'.$eisSetting->id, 60)->block(10, function () use ($transaction, $eisSetting) {
            $tracking = EisSale::where('transaction_id', $transaction->id)->first();
            $number = $tracking?->eis_invoice_number ?? $tracking?->fiscal_invoice_number
                ?? $this->generateEISInvoiceNumber($transaction, $eisSetting);
            $sale = EisSale::updateOrCreate(['transaction_id' => $transaction->id], [
                'business_id' => $transaction->business_id, 'invoice_number' => $transaction->invoice_no,
                'fiscal_invoice_number' => $number, 'status' => 'pending',
                'eis_invoice_number' => $number,
                'location_id' => $transaction->location_id, 'eis_setting_id' => $eisSetting->id,
            ]);
            return [$sale, $number];
        });

        // Only a sale paid in full is submitted (see SaleSubmissionRule).
        if (! SaleSubmissionRule::isPaid($transaction)) {
            return $eisSale;
        }

        $payload = [];

        try {
            // ----------------------------
            // TRANSFORM WITH EIS Number
            // ----------------------------
            $payload = $this->transformer->transform($transaction, $settings, $eisInvoiceNumber, $includeOfflineSignature);

            $validationUrl = $payload['invoiceSummary']['validationURL'] ?? null;

            $eisSale->update([
                'qr_code' => $validationUrl,
                'request_payload' => $payload,
            ]);

            // ----------------------------
            // SUBMIT (OUTSIDE DB TRANSACTION)
            // ----------------------------
            $response = $this->client->submit($payload, $settings);

            // The client returns failures instead of throwing. Persist these so
            // queue/manual retries are recognized and include the signature.
            if (! ($response['success'] ?? false) || ($response['data']['statusCode'] ?? null) !== 1) {
                $eisSale->update(['response_payload' => $response]);
                throw new EisSaleException($response['error'] ?? $response['data']['remark'] ?? 'EIS rejected the invoice');
            }

            // ----------------------------
            // SINGLE SOURCE OF TRUTHUPDAT
            // ----------------------------
            $this->responseService->handle($transaction, $response);

            return $eisSale->refresh();

        } catch (EisSaleException $e) {

            Log::error('EIS Sale Submission Failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
                'eis_invoice_number' => $eisInvoiceNumber ?? null,
            ]);

            if (empty($payload['invoiceSummary']['validationURL'])) {
                try {
                    $offlineData = $this->transformer->generateOfflineValidationData($settings, $transaction, $payload);
                    $validationUrl = $offlineData['validationURL'] ?? null;
                    if (!is_string($validationUrl) || trim($validationUrl) === '') {
                        throw new \UnexpectedValueException('EIS did not produce an offline validation URL.');
                    }
                    $eisSale->update(['qr_code' => $validationUrl]);
                } catch (\InvalidArgumentException|\UnexpectedValueException $qrException) {
                    Log::error('Failed to create EIS offline receipt QR after submission failure', [
                        'transaction_id' => $transaction->id,
                        'eis_invoice_number' => $eisInvoiceNumber ?? null,
                        'error' => $qrException->getMessage(),
                    ]);
                }
            }

            $eisSale->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // -------------------------
            // SAFE RETRY STORAGE
            // -------------------------
            $this->storeFailedTransaction($transaction, $payload, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Generate EIS compliant invoice number.
     *
     * @param Transaction $transaction
     * @param EisSetting $setting
     * @return string
     * @throws EisSaleException
     */
    protected function generateEISInvoiceNumber(Transaction $transaction, EisSetting $setting): string
    {
        try {
            $configuration = \Modules\EIS\Models\EisConfiguration::where('eis_setting_id', $setting->id)->firstOrFail();
            $terminal = \Modules\EIS\Models\EisTerminalConfiguration::where('configuration_id', $configuration->id)->firstOrFail();
            $terminalPosition = (int) $terminal->terminal_position;
            $taxpayerId = (int) $terminal->taxpayer_id;
            $transactionCount = $this->invoiceGenerator->getDailyCount((int) $transaction->business_id, (int) $setting->id) + 1;
            $invoiceNumber = $this->invoiceGenerator->generateInvoiceNumber(
                $transaction->business_id, $transactionCount, $terminalPosition, $taxpayerId, (int) $setting->id);

            Log::debug('EIS Number generated', [
                'transaction_id' => $transaction->id,
                'invoice_number' => $invoiceNumber,
            ]);

            return $invoiceNumber;

        } catch (\Exception $e) {
            Log::error('Failed to generate EIS Number', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);

            throw new EisSaleException('Unable to allocate an invoice for the selected terminal: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Get terminal position from terminal configuration.
     *
     * @param int $businessId
     * @return int
     */
    protected function getTerminalPosition(int $businessId, ?int $terminalId = null): int
    {
        return $this->invoiceGenerator->getTerminalPosition($businessId, null, $terminalId);
    }

    /**
     * Get terminal position from terminal configuration.
     *
     * @param int $businessId
     * @return int
     */
    protected function getTaxpayerId(int $businessId, ?int $terminalId = null): int
    {
        return (int) $this->invoiceGenerator->getOrCreateTerminal($businessId, null, $terminalId)->taxpayer_id;
    }

    /**
     * Get the next invoice count for today's configured terminal.
     *
     * @param int $businessId
     * @return int
     */
    protected function getTransactionCount(int $businessId, ?int $terminalId = null): int
    {
        return $this->invoiceGenerator->getDailyCount($businessId, $terminalId) + 1;
    }

    /**
     * Generate fallback invoice number.
     *
     * @param Transaction $transaction
     * @return string
     */
    protected function generateFallbackInvoiceNumber(Transaction $transaction): string
    {
        // Format: FALLBACK-TPIN-YYYYMMDD-COUNT
        $setting = \Modules\EIS\Services\Terminal\TerminalContext::forSale($transaction);
        $tpin = $setting->tpin ?? '000000';
        $date = now()->format('Ymd');
        $count = str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
        
        return 'FALLBACK-' . $tpin . '-' . $date . '-' . $count;
    }

    /**
     * Store failed transaction for retry.
     *
     * @param Transaction $transaction
     * @param array $payload
     * @param string $errorMessage
     * @return void
     */
    protected function storeFailedTransaction(Transaction $transaction, array $payload, string $errorMessage): void
    {
        $failed = DB::table('eis_failed_transactions')
            ->where('business_id', $transaction->business_id)
            ->where('transaction_id', $transaction->id)
            ->first();

        if ($failed) {
            DB::table('eis_failed_transactions')
                ->where('id', $failed->id)
                ->update([
                    'payload' => json_encode($payload),
                    'attempts' => $failed->attempts + 1,
                    'next_retry_at' => $this->calculateNextRetry($failed->attempts + 1),
                    'error_message' => $errorMessage,
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('eis_failed_transactions')->insert([
                'business_id' => $transaction->business_id,
                'location_id' => $transaction->location_id,
                'eis_setting_id' => EisSale::where('transaction_id', $transaction->id)->value('eis_setting_id'),
                'transaction_id' => $transaction->id,
                'payload' => json_encode($payload),
                'error_message' => $errorMessage,
                'attempts' => 1,
                'next_retry_at' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Calculate next retry time with exponential backoff.
     *
     * @param int $attempts
     * @return \DateTime
     */
    protected function calculateNextRetry(int $attempts): \DateTime
    {
        // Exponential backoff: 5, 10, 20, 40, 80 minutes
        $backoffMinutes = min(5 * pow(2, $attempts - 1), 1440); // Max 24 hours
        return now()->addMinutes($backoffMinutes);
    }

    /**
     * Retry a failed transaction.
     *
     * @param int $businessId
     * @param int $transactionId
     * @return array
     */
    public function retryFailedTransaction(int $businessId, int $transactionId): array
    {
        try {
            $failed = DB::table('eis_failed_transactions')
                ->where('business_id', $businessId)
                ->where('transaction_id', $transactionId)
                ->first();

            if (!$failed) {
                return [
                    'success' => false,
                    'message' => 'Failed transaction not found'
                ];
            }

            $transaction = Transaction::where('business_id', $businessId)->find($transactionId);
            if (!$transaction) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found'
                ];
            }

            $settings = \Modules\EIS\Services\Terminal\TerminalContext::forSale($transaction);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'EIS settings not found'
                ];
            }

            // Retry submission
            $result = $this->submit($transaction, $settings);
            if ($result->status !== 'submitted') {
                throw new EisSaleException($result->error_message ?: 'Sale has not been submitted; retry record retained.');
            }

            // Remove from failed table on success
            DB::table('eis_failed_transactions')
                ->where('id', $failed->id)
                ->delete();

            return [
                'success' => true,
                'message' => 'Transaction retried successfully'
            ];

        } catch (\Exception $e) {
            Log::error('Failed to retry transaction', [
                'business_id' => $businessId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to retry: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get pending failed transactions for retry.
     *
     * @param int $businessId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function getPendingRetries(int $businessId, int $limit = 100)
    {
        return DB::table('eis_failed_transactions')
            ->where('business_id', $businessId)
            ->where('next_retry_at', '<=', now())
            ->orderBy('next_retry_at')
            ->limit($limit)
            ->get();
    }
}