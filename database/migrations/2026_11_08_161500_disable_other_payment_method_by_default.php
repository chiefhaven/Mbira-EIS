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

                $other_settings = $settings['other'] ?? [];
                if (! is_array($other_settings)) {
                    $other_settings = [];
                }
                $other_settings['is_enabled'] = 0;
                $settings['other'] = $other_settings;

                $location->default_payment_accounts = json_encode($settings, JSON_THROW_ON_ERROR);
                $location->save();
            });
        });
    }

    public function down()
    {
        // Payment methods are configurable per location; do not re-enable Other automatically.
    }
};
