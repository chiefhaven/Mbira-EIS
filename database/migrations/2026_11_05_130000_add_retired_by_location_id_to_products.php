<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Set when a location deactivation soft-deletes the product, so reactivation restores only those.
            $table->unsignedInteger('retired_by_location_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['retired_by_location_id']);
            $table->dropColumn('retired_by_location_id');
        });
    }
};
