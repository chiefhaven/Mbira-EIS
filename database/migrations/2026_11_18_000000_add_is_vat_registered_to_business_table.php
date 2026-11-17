<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('business', 'is_vat_registered')) {
            Schema::table('business', function (Blueprint $table) {
                $table->boolean('is_vat_registered')->default(false)->after('enable_inline_tax');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('business', 'is_vat_registered')) {
            Schema::table('business', function (Blueprint $table) {
                $table->dropColumn('is_vat_registered');
            });
        }
    }
};
