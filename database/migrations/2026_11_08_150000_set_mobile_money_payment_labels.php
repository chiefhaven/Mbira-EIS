<?php

use App\Business;
use App\BusinessLocation;
use App\Services\DefaultPaymentAccountService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $defaults = app(DefaultPaymentAccountService::class);

        DB::transaction(function () use ($defaults) {
            Business::query()->orderBy('id')->each(function ($business) use ($defaults) {
                $custom_labels = ! empty($business->custom_labels)
                    ? json_decode($business->custom_labels, true, 512, JSON_THROW_ON_ERROR)
                    : [];
                if (! is_array($custom_labels)) {
                    $custom_labels = [];
                }
                if (! isset($custom_labels['payments']) || ! is_array($custom_labels['payments'])) {
                    $custom_labels['payments'] = [];
                }

                $custom_labels['payments']['custom_pay_1'] = 'Airtemoney';
                $custom_labels['payments']['custom_pay_2'] = 'TNM Mpamba';
                $business->custom_labels = json_encode($custom_labels, JSON_THROW_ON_ERROR);
                $business->save();

                $accounts = $defaults->ensureDefaultAccounts($business->id, $business->owner_id);
                BusinessLocation::where('business_id', $business->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function ($location) use ($accounts) {
                        $settings = ! empty($location->default_payment_accounts)
                            ? json_decode($location->default_payment_accounts, true, 512, JSON_THROW_ON_ERROR)
                            : [];
                        if (! is_array($settings)) {
                            $settings = [];
                        }

                        foreach (['custom_pay_1' => 'Airtemoney', 'custom_pay_2' => 'TNM Mpamba'] as $method => $account_name) {
                            $method_settings = $settings[$method] ?? [];
                            if (! is_array($method_settings)) {
                                $method_settings = [];
                            }
                            $method_settings['is_enabled'] = array_key_exists('is_enabled', $method_settings)
                                ? $method_settings['is_enabled']
                                : 1;
                            $method_settings['account'] = $accounts[$account_name]->id;
                            $settings[$method] = $method_settings;
                        }

                        $location->default_payment_accounts = json_encode($settings, JSON_THROW_ON_ERROR);
                        $location->save();
                    });
            });
        });
    }

    public function down()
    {
        // Labels and account assignments are business settings and should not be reverted automatically.
    }
};
