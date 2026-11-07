<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eis_settings') && !Schema::hasColumn('eis_settings', 'products_last_synced_at')) {
            Schema::table('eis_settings', function (Blueprint $table) {
                $table->timestamp('products_last_synced_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('eis_settings') && Schema::hasColumn('eis_settings', 'products_last_synced_at')) {
            Schema::table('eis_settings', function (Blueprint $table) {
                $table->dropColumn('products_last_synced_at');
            });
        }
    }
};
