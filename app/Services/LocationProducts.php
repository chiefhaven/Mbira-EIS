<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LocationProducts
{
    /**
     * Soft-delete the location's products that no other active location of the business lists.
     */
    public function retire(int $businessId, int $locationId): int
    {
        return DB::table('products')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->whereExists(fn ($query) => $query->from('product_locations')
                ->whereColumn('product_locations.product_id', 'products.id')
                ->where('product_locations.location_id', $locationId))
            ->whereNotExists(fn ($query) => $query->from('product_locations')
                ->join('business_locations', 'business_locations.id', '=', 'product_locations.location_id')
                ->whereColumn('product_locations.product_id', 'products.id')
                ->where('business_locations.business_id', $businessId)
                ->where('business_locations.id', '!=', $locationId)
                ->where('business_locations.is_active', 1))
            ->update(['deleted_at' => now(), 'retired_by_location_id' => $locationId]);
    }

    /**
     * Restore products listed at the location that were retired by a location deactivation.
     */
    public function restore(int $businessId, int $locationId): int
    {
        return DB::table('products')
            ->where('business_id', $businessId)
            ->whereNotNull('deleted_at')
            ->whereNotNull('retired_by_location_id')
            ->whereExists(fn ($query) => $query->from('product_locations')
                ->whereColumn('product_locations.product_id', 'products.id')
                ->where('product_locations.location_id', $locationId))
            ->update(['deleted_at' => null, 'retired_by_location_id' => null]);
    }
}
