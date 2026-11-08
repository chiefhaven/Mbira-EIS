<?php

use App\Business;
use App\BusinessLocation;
use App\Services\DefaultPaymentAccountService;
use App\Utils\Util;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $defaults = app(DefaultPaymentAccountService::class);
        $util = app(Util::class);

        DB::transaction(function () use ($defaults, $util) {
            Business::query()->orderBy('id')->each(function ($business) use ($defaults, $util) {
                $payment_types = $util->payment_types(null, false, $business->id);
                $accounts = $defaults->ensureDefaultAccounts($business->id, $business->owner_id);

                BusinessLocation::where('business_id', $business->id)
                    ->orderBy('id')
                    ->get()
                    ->each(function ($location) use ($defaults, $payment_types, $accounts) {
                        $defaults->ensureLocationDefaults($location, $payment_types, $accounts);
                    });
            });
        });
    }

    public function down()
    {
        // Default accounts and location assignments may have been used or customized after migration.
    }
};
