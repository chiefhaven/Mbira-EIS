<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'customer_authorization_code')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('customer_authorization_code', 1000)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transactions', 'customer_authorization_code')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('customer_authorization_code');
            });
        }
    }
};
