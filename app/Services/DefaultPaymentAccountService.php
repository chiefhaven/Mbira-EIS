<?php

namespace App\Services;

use App\Account;
use App\Business;
use App\BusinessLocation;
use RuntimeException;

class DefaultPaymentAccountService
{
    private const DEFAULT_ACCOUNTS = [
        'Cash',
        'Bank Account',
        'Mobile Money',
        'Petty Cash',
        'Card Clearing',
    ];

    public function defaultsForBusiness($business_id, array $payment_types)
    {
        $business = Business::findOrFail($business_id);
        $accounts = $this->ensureDefaultAccounts($business_id, $business->owner_id);

        $location_settings = BusinessLocation::where('business_id', $business_id)
            ->orderBy('id')
            ->value('default_payment_accounts');

        $settings = $this->mergeLocationSettings(
            $business_id,
            $payment_types,
            $this->decodeSettings($location_settings),
            $accounts
        );
        if (isset($settings['other'])) {
            $settings['other']['is_enabled'] = 0;
        }

        return $settings;
    }

    public function ensureDefaultAccounts($business_id, $created_by = null): array
    {
        $created_by = $created_by ?: Business::whereKey($business_id)->value('owner_id');
        if (empty($created_by)) {
            throw new RuntimeException("Cannot provision default payment accounts for business {$business_id} without an owner.");
        }

        $accounts = [];
        foreach (self::DEFAULT_ACCOUNTS as $name) {
            $account = Account::where('business_id', $business_id)
                ->where('name', $name)
                ->where('is_closed', 0)
                ->first();

            if (empty($account)) {
                $account = Account::create([
                    'business_id' => $business_id,
                    'name' => $name,
                    'account_number' => $name,
                    'created_by' => $created_by,
                ]);
            }

            $accounts[$name] = $account;
        }

        return $accounts;
    }

    public function ensureLocationDefaults(BusinessLocation $location, array $payment_types, ?array $accounts = null): bool
    {
        $accounts = $accounts ?: $this->ensureDefaultAccounts($location->business_id);
        $settings = $this->mergeLocationSettings(
            $location->business_id,
            $payment_types,
            $this->decodeSettings($location->default_payment_accounts),
            $accounts
        );

        $encoded_settings = json_encode($settings, JSON_THROW_ON_ERROR);
        if ($location->default_payment_accounts === $encoded_settings) {
            return false;
        }

        $location->default_payment_accounts = $encoded_settings;
        $location->save();

        return true;
    }

    private function mergeLocationSettings($business_id, array $payment_types, array $existing, array $accounts): array
    {
        $account_ids = $this->accountIdsByMethod($payment_types, $accounts);
        $valid_account_ids = Account::where('business_id', $business_id)
            ->where('is_closed', 0)
            ->pluck('id')
            ->mapWithKeys(function ($id) {
                return [(string) $id => true];
            })
            ->all();

        $settings = [];
        foreach ($payment_types as $method => $label) {
            $method_settings = $existing[$method] ?? [];
            if (! is_array($method_settings)) {
                $method_settings = [];
            }

            $account_id = $method_settings['account'] ?? null;
            if (empty($account_id) || ! isset($valid_account_ids[(string) $account_id])) {
                $account_id = $account_ids[$method] ?? null;
            }

            $settings[$method] = array_merge($method_settings, [
                'is_enabled' => array_key_exists('is_enabled', $method_settings)
                    ? $method_settings['is_enabled']
                    : (in_array($method, ['custom_pay_1', 'custom_pay_2'], true)
                        ? 1
                        : (str_starts_with($method, 'custom_pay_') || $method === 'other' ? 0 : 1)),
                'account' => $account_id,
            ]);
        }

        return $settings;
    }

    private function accountIdsByMethod(array $payment_types, array $accounts): array
    {
        $method_accounts = [
            'cash' => 'Cash',
            'card' => 'Card Clearing',
            'cheque' => 'Bank Account',
            'bank_transfer' => 'Bank Account',
            'other' => 'Mobile Money',
            'custom_pay_1' => 'Mobile Money',
            'custom_pay_2' => 'Mobile Money',
        ];

        $account_ids = [];
        foreach ($payment_types as $method => $label) {
            $account_name = $method_accounts[$method] ?? $this->matchingAccountName($label, $accounts);
            if (! empty($account_name) && isset($accounts[$account_name])) {
                $account_ids[$method] = $accounts[$account_name]->id;
            }
        }

        return $account_ids;
    }

    private function matchingAccountName($label, array $accounts)
    {
        $normalize = static function ($value) {
            return trim(preg_replace('/\s+/', ' ', strtolower(str_replace('_', ' ', $value))));
        };
        $normalized_label = $normalize($label);

        foreach (array_keys($accounts) as $account_name) {
            if ($normalized_label === $normalize($account_name)) {
                return $account_name;
            }
        }

        return null;
    }

    private function decodeSettings($settings): array
    {
        if (empty($settings)) {
            return [];
        }

        $decoded = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
