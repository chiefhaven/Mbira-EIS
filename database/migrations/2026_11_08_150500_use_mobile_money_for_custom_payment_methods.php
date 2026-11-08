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
                $accounts = $defaults->ensureDefaultAccounts($business->id, $business->owner_id);
                $mobile_money_account_id = $accounts['Mobile Money']->id;

                BusinessLocation::where('business_id', $business->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function ($location) use ($mobile_money_account_id) {
                        $settings = ! empty($location->default_payment_accounts)
                            ? json_decode($location->default_payment_accounts, true, 512, JSON_THROW_ON_ERROR)
                            : [];
                        if (! is_array($settings)) {
                            $settings = [];
                        }

                        foreach (['custom_pay_1', 'custom_pay_2'] as $method) {
                            $method_settings = $settings[$method] ?? [];
                            if (! is_array($method_settings)) {
                                $method_settings = [];
                            }
                            $method_settings['is_enabled'] = array_key_exists('is_enabled', $method_settings)
                                ? $method_settings['is_enabled']
                                : 1;
                            $method_settings['account'] = $mobile_money_account_id;
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
        // Location payment-account choices are operational settings and should not be reverted automatically.
    }
};
