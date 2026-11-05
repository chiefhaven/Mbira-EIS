<?php

namespace Modules\EIS\Services\Terminal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InactiveTerminalStock
{
    public function clear(int $businessId, int $locationId, bool $dryRun = false): int
    {
        return DB::transaction(function () use ($businessId, $locationId, $dryRun) {
            if (!DB::table('business')->where('id', $businessId)->lockForUpdate()->first()
                || !DB::table('business_locations')->where('business_id', $businessId)
                    ->where('id', $locationId)->exists()) {
                throw new \InvalidArgumentException('EIS stock cleanup requires a location belonging to its business.');
            }

            // Deleted terminals are not deactivated terminals; they must never trigger stock cleanup.
            $terminals = DB::table('eis_settings')->where('business_id', $businessId)
                ->where('location_id', $locationId)->whereNull('deleted_at')->lockForUpdate()->get();
            if ($terminals->isEmpty() || $terminals->contains(fn ($terminal) => (bool) $terminal->status)) {
                return 0;
            }

            $stock = DB::table('variation_location_details')->where('location_id', $locationId)
                ->whereIn('product_id', DB::table('products')->select('id')->where('business_id', $businessId))
                ->whereNull('deleted_at');
            if ($dryRun) {
                return $stock->count();
            }

            $count = $stock->update(['deleted_at' => now(), 'updated_at' => now()]);
            Log::info('Soft-deleted stock at locally deactivated EIS terminal location', [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'stock_rows' => $count,
            ]);

            return $count;
        });
    }

    /**
     * Remove EIS-mapped product listings from a location once no active terminal serves it.
     */
    public function unlinkProducts(int $businessId, int $locationId): int
    {
        return DB::transaction(function () use ($businessId, $locationId) {
            $terminals = DB::table('eis_settings')->where('business_id', $businessId)
                ->where('location_id', $locationId)->whereNull('deleted_at')->lockForUpdate()->get();
            if ($terminals->contains(fn ($terminal) => (bool) $terminal->status)) {
                return 0;
            }

            $count = DB::table('product_locations')->where('location_id', $locationId)
                ->whereIn('product_id', DB::table('eis_product_maps')->select('product_id')
                    ->where('business_id', $businessId)->whereNull('deleted_at'))
                ->delete();
            Log::info('Removed EIS product listings at deactivated terminal location', [
                'business_id' => $businessId,
                'location_id' => $locationId,
                'product_locations' => $count,
            ]);

            return $count;
        });
    }
}
