<?php

namespace Modules\EIS\Services\Sales;

use App\Transaction;
use App\TransactionSellLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Models\EisTaxRate;
use Modules\EIS\Services\Sales\InvoiceNumberGenerator;
use Modules\EIS\Services\Sales\OfflineSignatureService;

class SaleTransformer
{
    private const DEFAULT_TAX_RATE_ID = 'A';
    private const DEFAULT_CUSTOMER_NAME = 'Walk-in Customer';
    
    /**
     * Cache for tax rate lookups
     */
    private array $taxRateCache = [];

    public function __construct(
        protected EisTaxRate $taxRateModel,
        protected OfflineSignatureService $signatureService,
        protected InvoiceNumberGenerator $invoiceNumberGenerator
    ) {}

    /**
     * Transform transaction for EIS submission.
     *
     * @param Transaction $transaction
     * @param EisSetting $settings
     * @param string $eisInvoiceNumber
     * @return array
     * @throws \InvalidArgumentException
     */
    public function transform(
        Transaction $transaction,
        EisSetting $settings,
        string $eisInvoiceNumber,
        bool $includeOfflineSignature = false,
        ?array $storedOfflineSignatureData = null
    ): array
    {
        \Modules\EIS\Services\Terminal\TerminalContext::assertBusiness($settings, (int) $transaction->business_id);
        $this->validateTransaction($transaction);
        $this->validateSettings($settings);

        // Load relationships with eager loading constraints
        $transaction->loadMissing([
            'business',
            'location',
            'contact',
            'sell_lines' => function ($query) {
                $query->with(['product', 'variations', 'modifiers']);
            },
        ]);
        if ($transaction->contact && (int) $transaction->contact->business_id !== (int) $transaction->business_id) {
            throw new \InvalidArgumentException('Sale customer belongs to another business terminal.');
        }

        // Validate sell lines exist
        if ($transaction->sell_lines->isEmpty()) {
            throw new \InvalidArgumentException('Transaction has no sell lines');
        }

        $invoiceItems = [];
        $taxBreakdown = [];
        $totalVat = 0;
        $subtotal = 0;

        foreach ($transaction->sell_lines as $index => $line) {
            if ($line->product && (int) $line->product->business_id !== (int) $transaction->business_id) {
                throw new \InvalidArgumentException('Sale product belongs to another business terminal.');
            }
            // Skip invalid lines
            if (!$this->isValidSellLine($line)) {
                Log::warning('Skipping invalid sell line', [
                    'transaction_id' => $transaction->id,
                    'line_id' => $line->id ?? 'unknown'
                ]);
                continue;
            }

            // Get tax rate ID from EIS tax rates
            $taxRateId = $this->getTaxRateId($settings->business_id, $line);

            // Calculate values with proper null handling
            $discountPerUnit = $this->getDiscountAmount($line);
            $unitPrice = $this->getUnitPrice($line);
            if ($discountPerUnit > 0) {
                $rate = $this->discountTaxRate($settings->business_id, $taxRateId);
                $unitPrice = round((float) $line->unit_price_before_discount * (1 + $rate / 100), 2);
            }
            $quantity = round($this->getQuantity($line), 2);
            $vat = round($this->getVatAmount($line) * $quantity, 2);
            $discount = $discountPerUnit > 0
                ? round($unitPrice * $quantity - round($this->getUnitPrice($line) * $quantity, 2), 2) : 0;
            if ($discount < 0) {
                throw new \InvalidArgumentException('Saved discounted prices exceed the original fiscal line value.');
            }
            $lineTotal = round($unitPrice * $quantity - $discount, 2);
            if ($discount > 0) {
                $vat = $this->getDiscountedVat($settings->business_id, $taxRateId, $lineTotal);
            }
            
            $subtotal += $lineTotal;
            $totalVat += $vat;

            // Get product details with fallbacks
            $productInfo = $this->getProductInfo($line);

            $invoiceItems[] = [
                'id' => $index + 1,
                'productCode' => (string) $productInfo['code'],
                'description' => (string) ($productInfo['description'] ?? $productInfo['name']),
                'unitPrice' => round($unitPrice, 2),
                'quantity' => round($quantity, 2),
                'discount' => round($discount, 2),
                'total' => round($lineTotal, 2),
                'totalVAT' => round($vat, 2),
                'taxRateId' => (string) $taxRateId,
                'isProduct' => true,
            ];

            // Build tax breakdown
            $this->updateTaxBreakdown($taxBreakdown, $taxRateId, $lineTotal, $vat);
        }

        // Ensure we have items after filtering
        if (empty($invoiceItems)) {
            throw new \InvalidArgumentException('No valid sell lines found after filtering');
        }
        if ((float) $transaction->discount_amount > 0) {
            $invoiceDiscount = match ($transaction->discount_type) {
                'percentage' => round($subtotal * (float) $transaction->discount_amount / 100, 2),
                'fixed' => round((float) $transaction->discount_amount, 2),
                default => throw new \InvalidArgumentException('A valid invoice discount type is required for EIS submission.'),
            };
            if ($invoiceDiscount > $subtotal || $subtotal <= 0) {
                throw new \InvalidArgumentException('Invoice discount cannot exceed the fiscal line subtotal.');
            }
            $allocated = 0;
            $cumulative = 0;
            $taxBreakdown = [];
            $totalVat = 0;
            foreach ($invoiceItems as &$item) {
                $cumulative += $item['total'];
                $target = round($invoiceDiscount * $cumulative / $subtotal, 2);
                $share = round($target - $allocated, 2);
                $allocated = $target;
                $item['discount'] = round($item['discount'] + $share, 2);
                $item['total'] = round($item['total'] - $share, 2);
                $item['totalVAT'] = $this->getDiscountedVat($settings->business_id, $item['taxRateId'], $item['total']);
                $totalVat += $item['totalVAT'];
                $this->updateTaxBreakdown($taxBreakdown, $item['taxRateId'], $item['total'], $item['totalVAT']);
            }
            unset($item);
            if (abs(round($subtotal - $invoiceDiscount, 2) - round((float) $transaction->final_total, 2)) > 0.005) {
                throw new \InvalidArgumentException('Discounted fiscal line totals do not match the saved invoice total. Review additional charges, taxes or rounding before EIS submission.');
            }
        }

        // Get site ID with fallback
        $siteId = $this->getSiteId($settings);

        // Get buyer information
        $buyerInfo = $this->getBuyerInfo($transaction);

        // Build invoice summary
        $invoiceSummary = $this->buildInvoiceSummary(
            $transaction,
            $taxBreakdown,
            $totalVat
        );

        // Keep a new online submission unsigned; retries may reuse their persisted signature.
        $offlineSignatureData = [];
        if ($includeOfflineSignature) {
            $offlineSignatureData = $storedOfflineSignatureData ?? $this->generateOfflineSignature(
                $settings,
                $transaction,
                $eisInvoiceNumber,
                $invoiceSummary,
                count($invoiceItems)
            );
        }

        if ($includeOfflineSignature) {
            $invoiceSummary['offlineSignature'] = $offlineSignatureData['offlineDataSignature'] ?? '';
            $invoiceSummary['validationURL'] = $offlineSignatureData['validationURL'] ?? '';
        }

        // Build final response
        $payload = [
            'invoiceHeader' => $this->buildInvoiceHeader(
                $transaction,
                $settings,
                $eisInvoiceNumber,
                $siteId,
                $buyerInfo
            ),
            'invoiceLineItems' => $invoiceItems,
            'invoiceSummary' => $invoiceSummary,
        ];

        if ($includeOfflineSignature) {
            $payload['offlineSignatureData'] = $offlineSignatureData;
        }

        return $payload;
    }

    /**
     * Validate transaction has required data
     */
    private function validateTransaction(Transaction $transaction): void
    {
        if (!$transaction->id) {
            throw new \InvalidArgumentException('Transaction must be saved before transformation');
        }
    }

    /**
     * Validate settings have required data
     */
    private function validateSettings(EisSetting $settings): void
    {
        if (empty($settings->tpin)) {
            Log::warning('EIS settings missing TPIN', [
                'business_id' => $settings->business_id ?? 'unknown'
            ]);
        }
    }

    /**
     * Check if sell line is valid
     */
    private function isValidSellLine(TransactionSellLine $line): bool
    {
        return $line->exists && 
               ($line->unit_price ?? -1) >= 0 &&
               ($line->quantity ?? 0) > 0;
    }

    /**
     * Get unit price with proper fallback
     */
    private function getUnitPrice(TransactionSellLine $line): float
    {
        return (float) ($line->unit_price_inc_tax ?? $line->unit_price ?? 0);
    }

    /**
     * Get quantity with proper fallback
     */
    private function getQuantity(TransactionSellLine $line): float
    {
        return (float) ($line->quantity ?? 1);
    }

    /**
     * Get VAT amount with proper fallback
     */
    private function getVatAmount(TransactionSellLine $line): float
    {
        return (float) ($line->item_tax ?? 0);
    }

    /**
     * Get discount amount with proper fallback
     */
    private function getDiscountAmount(TransactionSellLine $line): float
    {
        return (float) $line->get_discount_amount();
    }

    private function getDiscountedVat(int $businessId, string $taxRateId, float $lineTotal): float
    {
        $rate = $this->discountTaxRate($businessId, $taxRateId);
        return round($lineTotal * $rate / (100 + $rate), 2);
    }

    private function discountTaxRate(int $businessId, string $taxRateId): float
    {
        $taxRate = $this->taxRateModel
            ->where('business_id', $businessId)
            ->where('tax_rate_id', $taxRateId)
            ->first();
        if (!$taxRate || !is_numeric($taxRate->rate) || !is_finite((float) $taxRate->rate) || $taxRate->rate < 0) {
            throw new \InvalidArgumentException('A valid EIS tax rate is required to calculate discounted sale VAT.');
        }

        return (float) $taxRate->rate;
    }

    /**
     * Get product information with comprehensive fallbacks
     */
    private function getProductInfo(TransactionSellLine $line): array
    {
        try {
            if ($line->product) {
                return [
                    'code' => $line->product->sku ?? $line->product->id ?? 'N/A',
                    'name' => $line->product->name ?? 'Unknown Product',
                    'description' => $line->product->product_description ?? $line->product->name ?? 'Unknown Description'
                ];
            }

            return [
                'code' => $line->product_id ?? 'N/A',
                'name' => $line->product_name ?? 'Unknown Product',
                'description' => $line->product_description ?? $line->product_name ?? 'Unknown Product'
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to get product info', [
                'line_id' => $line->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            return [
                'code' => $line->product_id ?? 'N/A',
                'name' => $line->product_name ?? 'Unknown Product',
                'description' => 'Product description unavailable'
            ];
        }
    }

    /**
     * Update tax breakdown array
     */
    private function updateTaxBreakdown(array &$taxBreakdown, string $taxRateId, float $lineTotal, float $vat): void
    {
        if (!isset($taxBreakdown[$taxRateId])) {
            $taxBreakdown[$taxRateId] = [
                'rateId' => (string) $taxRateId,
                'taxableAmount' => 0,
                'taxAmount' => 0,
            ];
        }

        $taxBreakdown[$taxRateId]['taxableAmount'] = round($taxBreakdown[$taxRateId]['taxableAmount'] + $lineTotal - $vat, 2);
        $taxBreakdown[$taxRateId]['taxAmount'] = round($taxBreakdown[$taxRateId]['taxAmount'] + $vat, 2);
    }

    /**
     * Get site ID with proper fallbacks
     */
    private function getSiteId(EisSetting $settings): string
    {
        return \Modules\EIS\Services\Terminal\TerminalContext::site($settings);
    }

    /**
     * Build invoice summary
     */
    private function buildInvoiceSummary(
        Transaction $transaction,
        array $taxBreakdown,
        float $totalVat
    ): array {
        return [
            'taxBreakDown' => array_values($taxBreakdown),
            'levyBreakDown' => [],
            'totalVAT' => round($totalVat, 2),
            'invoiceTotal' => round($transaction->final_total ?? $transaction->total ?? 0, 2),
            'amountTendered' => round($transaction->final_total ?? $transaction->total ?? 0, 2),
        ];
    }

    /**
     * Build invoice header
     */
    private function buildInvoiceHeader(
        Transaction $transaction,
        EisSetting $settings,
        string $eisInvoiceNumber,
        string $siteId,
        array $buyerInfo
    ): array {
        return [
            'invoiceNumber' => (string) $eisInvoiceNumber,
            'invoiceDateTime' => $this->formatInvoiceDateTime($transaction->transaction_date),
            'sellerTIN' => (string) ($settings->tpin ?? ''),
            'buyerTIN' => (string) ($buyerInfo['tpin'] ?? ''),
            'buyerName' => (string) ($buyerInfo['name'] ?? ''),
            'buyerAuthorizationCode' => (string) ($buyerInfo['authorization_code'] ?? ''),
            'siteId' => $siteId,
            'globalConfigVersion' => (int) ($settings->global_version ?? 0),
            'taxpayerConfigVersion' => (int) ($settings->taxpayer_version ?? 0),
            'terminalConfigVersion' => (int) ($settings->terminal_version ?? 0),
            'isExport' => (bool) $transaction->is_export,
            'isReliefSupply' => (bool) $transaction->is_relief,
            'vat5CertificateDetails' => $transaction->is_relief ? $transaction->vat5_certificate_details : null,
            'paymentMethod' => $this->getPaymentMethod($transaction),
        ];
    }

    /**
     * Format invoice date time consistently
     */
    private function formatInvoiceDateTime($date): string
    {
        if (empty($date)) {
            return now()->toIso8601String();
        }

        try {
            return Carbon::parse($date)->toIso8601String();
        } catch (\Exception $e) {
            Log::warning('Failed to parse transaction date', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            return now()->toIso8601String();
        }
    }

    /**
     * Get payment method with standardization
     */
    private function getPaymentMethod(Transaction $transaction): string
    {
        $method = $transaction->payment_method ?? 'CASH';
        
        // Standardize common payment methods
        $methodMap = [
            'cash' => 'CASH',
            'card' => 'CARD',
            'credit_card' => 'CARD',
            'debit_card' => 'CARD',
            'bank_transfer' => 'BANK_TRANSFER',
            'transfer' => 'BANK_TRANSFER',
            'mobile_money' => 'MOBILE_MONEY',
            'momo' => 'MOBILE_MONEY',
        ];

        return strtoupper($methodMap[strtolower($method)] ?? $method);
    }

    /**
     * Get buyer information with comprehensive fallbacks
     */
    protected function getBuyerInfo(Transaction $transaction): array
    {
        $contactInfo = null;
        try {
            if ($transaction->contact && $transaction->contact->exists) {
                $taxNumber = trim((string) ($transaction->contact->tax_number ?? ''));
                $name = trim((string) ($transaction->contact->name ?? ''));
                if ($name === '') {
                    $name = trim((string) ($transaction->contact->supplier_business_name ?? ''));
                }
                if ($name === '') {
                    $name = trim((string) ($transaction->customer_name ?? ''));
                }
                $contactInfo = [
                    'name' => $name,
                    'tpin' => $taxNumber,
                    'authorization_code' => trim((string) ($transaction->customer_authorization_code
                        ?: ($transaction->contact->authorization_code ?? ''))),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get buyer info from contact', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
        }

        if ($contactInfo !== null) {
            $contactInfo['name'] = $contactInfo['name'] !== ''
                ? $contactInfo['name']
                : self::DEFAULT_CUSTOMER_NAME;
            return $contactInfo;
        }

        // Fallback to transaction fields
        $name = trim((string) ($transaction->customer_name ?? ''));
        $taxNumber = trim((string) ($transaction->customer_tpin ?? ''));

        return [
            'name' => $name !== '' ? $name : self::DEFAULT_CUSTOMER_NAME,
            'tpin' => $taxNumber,
            'authorization_code' => $transaction->customer_authorization_code ?? '',
        ];
    }

    /**
     * Get EIS tax rate ID from transaction line with caching
     */
    protected function getTaxRateId(int $businessId, TransactionSellLine $line): string
    {
        $cacheKey = $businessId . ':' . ($line->tax_id ?? '') . ':' . ($line->product_tax ?? '');
        
        // Check cache first
        if (isset($this->taxRateCache[$cacheKey])) {
            return $this->taxRateCache[$cacheKey];
        }

        try {
            // Strategy 1: Try by tax_id
            if (!empty($line->tax_id)) {
                $taxRate = $this->taxRateModel
                    ->where('business_id', $businessId)
                    ->where('id', $line->tax_id)
                    ->first();

                if ($taxRate) {
                    $this->taxRateCache[$cacheKey] = $taxRate->tax_rate_id;
                    return $taxRate->tax_rate_id;
                }
            }

            // Strategy 4: Final fallback
            $this->taxRateCache[$cacheKey] = self::DEFAULT_TAX_RATE_ID;
            return self::DEFAULT_TAX_RATE_ID;

        } catch (\Exception $e) {
            Log::error('Failed to resolve tax rate ID', [
                'business_id' => $businessId,
                'line_id' => $line->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            
            return self::DEFAULT_TAX_RATE_ID;
        }
    }

    /**
     * Generate offline signature data with better error handling
     */
    protected function generateOfflineSignature(EisSetting $settings, Transaction $transaction, string $eisInvoiceNumber, array $invoiceSummary, float $totalItems): array {
        try {
            // Parse invoice number to get components
            $parsedInvoice = $this->parseInvoiceNumber($eisInvoiceNumber);
            
            $taxpayerId = (string) $parsedInvoice['taxpayer_id'];
            $terminalPosition = $parsedInvoice['terminal_position'];
            $transactionCount = $parsedInvoice['count'];
            if (trim((string) $settings->secret_key) === '' || empty($transaction->transaction_date)) {
                throw new \InvalidArgumentException('An EIS signing key and fiscal invoice date are required for offline signing.');
            }
            foreach (['invoiceTotal', 'totalVAT'] as $field) {
                if (!is_numeric($invoiceSummary[$field] ?? null) || !is_finite((float) $invoiceSummary[$field])
                    || $invoiceSummary[$field] < 0) {
                    throw new \InvalidArgumentException('Valid fiscal invoice totals are required for offline signing.');
                }
            }
            if ($invoiceSummary['totalVAT'] > $invoiceSummary['invoiceTotal'] || !is_finite($totalItems) || $totalItems <= 0) {
                throw new \InvalidArgumentException('Valid fiscal VAT and line-item count are required for offline signing.');
            }
            
            // Prepare request data for signature
            $requestData = [
                'transactiondate' => Carbon::parse($transaction->transaction_date)->toIso8601String(),
                'transactionCount' => $transactionCount,
                'NumItems' => $totalItems,
                'InvoiceTotal' => (float) $invoiceSummary['invoiceTotal'],
                'VATAmount' => (float) $invoiceSummary['totalVAT'],
            ];
            
            // Generate offline signature
            $result = $this->signatureService->generateInvoiceResponse(
                $taxpayerId,
                $terminalPosition,
                $requestData,
                $settings->secret_key ?? ''
            );
            if ($result['combinedString'] !== $eisInvoiceNumber) {
                throw new \UnexpectedValueException('The EIS invoice identity and fiscal date do not match; offline signing was blocked.');
            }
            
            Log::debug('Offline signature generated successfully', [
                'transaction_id' => $transaction->id,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Failed to generate offline signature', [
                'transaction_id' => $transaction->id,
                'exception' => get_class($e),
            ]);
            
            throw $e;
        }
    }

    /**
     * Generate EIS offline validation data from the exact invoice sent online.
     */
    public function generateOfflineValidationData(EisSetting $settings, Transaction $transaction, array $payload): array
    {
        $invoiceNumber = $payload['invoiceHeader']['invoiceNumber'] ?? null;
        $invoiceSummary = $payload['invoiceSummary'] ?? null;
        $invoiceLines = $payload['invoiceLineItems'] ?? null;

        if (!is_string($invoiceNumber) || $invoiceNumber === ''
            || !is_array($invoiceSummary) || !is_array($invoiceLines) || $invoiceLines === []) {
            throw new \UnexpectedValueException('The submitted EIS invoice payload is incomplete; offline validation data cannot be generated.');
        }

        foreach ($invoiceLines as $line) {
            if (!is_array($line) || !is_numeric($line['quantity'] ?? null)
                || !is_finite((float) $line['quantity']) || $line['quantity'] <= 0) {
                throw new \UnexpectedValueException('The submitted EIS invoice lines are invalid; offline validation data cannot be generated.');
            }
        }

        $fiscalTransaction = clone $transaction;
        $fiscalTransaction->transaction_date = $payload['invoiceHeader']['invoiceDateTime'] ?? null;
        return $this->generateOfflineSignature(
            $settings,
            $fiscalTransaction,
            $invoiceNumber,
            $invoiceSummary,
            count($invoiceLines)
        );
    }

    /**
     * Parse invoice number with better error handling
     */
    protected function parseInvoiceNumber(string $invoiceNumber): array
    {
        if (preg_match('~^[A-Za-z0-9+/]+(?:-[A-Za-z0-9+/]+){3}$~D', $invoiceNumber)) {
            $parsed = $this->invoiceNumberGenerator->parseInvoiceNumber($invoiceNumber);
            if ($parsed !== null && $parsed['taxpayer_id'] > 0 && $parsed['terminal_position'] > 0
                && $parsed['julian_date'] > 0 && $parsed['count'] > 0) {
                return $parsed;
            }
        }
        throw new \InvalidArgumentException('A valid EIS fiscal invoice number is required for offline signing.');
    }

    /**
     * Transform items for EIS format with validation
     */
    public function transformItems(array $items): array
    {
        if (empty($items)) {
            Log::warning('Empty items array provided to transformItems');
            return [];
        }

        $transformed = [];
        
        foreach ($items as $index => $item) {
            // Skip invalid items
            if (empty($item) || !is_array($item)) {
                continue;
            }

            Log::info('Transforming item', ['item' => $item]);

            $transformed[] = [
                'id' => $index + 1,
                'productCode' => (string) ($item['code'] ?? $item['sku'] ?? 'N/A'),
                'description' => (string) ($item['name'] ?? $item['description'] ?? 'Unknown Product'),
                'unitPrice' => round((float) ($item['unit_price'] ?? 0), 2),
                'quantity' => round((float) ($item['quantity'] ?? 1), 2),
                'discount' => round((float) ($item['discount'] ?? 0), 2),
                'total' => round((float) ($item['total'] ?? 0), 2),
                'totalVAT' => round((float) ($item['tax_amount'] ?? 0), 2),
                'taxRateId' => (string) ($item['tax_rate_id'] ?? self::DEFAULT_TAX_RATE_ID),
                'isProduct' => true,
            ];
        }
        
        return $transformed;
    }

    /**
     * Clear tax rate cache
     */
    public function clearTaxRateCache(): void
    {
        $this->taxRateCache = [];
    }
}