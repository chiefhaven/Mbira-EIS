<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        \App\InvoiceLayout::copySampleSettingsToAllBusinessDefaults();
    }

    public function down(): void
    {
        // Existing business invoice layouts are intentionally not restored.
    }
};
