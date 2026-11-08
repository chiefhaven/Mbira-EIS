<?php

use App\BusinessLocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::transaction(function () {
            BusinessLocation::query()->orderBy('id')->each(function ($location) {
                $settings = ! empty($location->default_payment_accounts)
                    ? json_decode($location->default_payment_accounts, true, 512, JSON_THROW_ON_ERROR)
                    : [];
                if (! is_array($settings)) {
                    $settings = [];
                }

                for ($method_number = 1; $method_number <= 7; $method_number++) {
                    $method = 'custom_pay_'.$method_number;
                    $method_settings = $settings[$method] ?? [];
                    if (! is_array($method_settings)) {
                        $method_settings = [];
                    }

                    $method_settings['is_enabled'] = 0;
                    $settings[$method] = $method_settings;
                }

                $location->default_payment_accounts = json_encode($settings, JSON_THROW_ON_ERROR);
                $location->save();
            });
        });
    }

    public function down()
    {
        // Payment methods are explicitly configurable per location; do not re-enable methods automatically.
    }
};
