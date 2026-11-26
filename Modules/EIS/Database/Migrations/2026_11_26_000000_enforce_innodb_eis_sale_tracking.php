<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        foreach (['eis_sales', 'eis_failed_transactions'] as $table) {
            if (!$connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $engine = $connection->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->value('ENGINE');
            if (strtoupper((string) $engine) !== 'INNODB') {
                $connection->statement('ALTER TABLE `'.$table.'` ENGINE=InnoDB');
            }
        }
    }

    public function down(): void
    {
        // Keep sale tracking transactional when application migrations are rolled back.
    }
};
