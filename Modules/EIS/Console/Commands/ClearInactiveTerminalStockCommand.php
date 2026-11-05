<?php

namespace Modules\EIS\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\EIS\Services\Terminal\InactiveTerminalStock;

class ClearInactiveTerminalStockCommand extends Command
{
    protected $signature = 'eis:clear-inactive-stock
                            {--business-id= : Restrict cleanup to a business}
                            {--dry-run : Report affected stock rows without changing quantities}';

    protected $description = 'Soft-delete stock records at EIS locations with no locally enabled terminals';

    public function handle(InactiveTerminalStock $stock): int
    {
        $businessId = $this->option('business-id');
        if ($businessId !== null && (!ctype_digit((string) $businessId) || (int) $businessId <= 0)) {
            $this->error('Business ID must be a positive integer.');
            return 1;
        }

        $locations = DB::table('eis_settings')->where('status', 0)->whereNotNull('location_id')->whereNull('deleted_at')
            ->when($businessId !== null, fn ($query) => $query->where('business_id', $businessId))
            ->select('business_id', 'location_id')->distinct()->orderBy('business_id')->orderBy('location_id')->get();
        $total = 0;
        foreach ($locations as $location) {
            $count = $stock->clear((int) $location->business_id, (int) $location->location_id, (bool) $this->option('dry-run'));
            $total += $count;
            $this->line("Business {$location->business_id}, location {$location->location_id}: {$count} stock rows.");
        }
        $this->info(($this->option('dry-run') ? 'Dry run: ' : 'Soft-deleted: ').$total.' stock rows.');

        return 0;
    }
}
