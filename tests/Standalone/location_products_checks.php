<?php

// Location deactivation product retirement against SQLite memory; never loads .env or the kernel.
require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Services\LocationProducts;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

$app = new Container();
Container::setInstance($app);
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
$schema = $capsule->getConnection()->getSchemaBuilder();
$app->instance('db.schema', $schema);

$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
    $checks++;
}

$schema->create('business_locations', function (Blueprint $table) {
    $table->increments('id'); $table->integer('business_id'); $table->boolean('is_active');
});
$schema->create('products', function (Blueprint $table) {
    $table->increments('id'); $table->integer('business_id'); $table->softDeletes();
});
$schema->create('product_locations', function (Blueprint $table) {
    $table->integer('product_id'); $table->integer('location_id');
});
$migration = require dirname(__DIR__, 2).'\database\migrations\2026_11_05_130000_add_retired_by_location_id_to_products.php';
$migration->up();

DB::table('business_locations')->insert([
    ['id' => 1, 'business_id' => 1, 'is_active' => 1],
    ['id' => 2, 'business_id' => 1, 'is_active' => 1],
    ['id' => 3, 'business_id' => 1, 'is_active' => 0],
    ['id' => 4, 'business_id' => 2, 'is_active' => 1],
]);
DB::table('products')->insert([
    ['id' => 10, 'business_id' => 1, 'deleted_at' => null], // only at 1
    ['id' => 11, 'business_id' => 1, 'deleted_at' => null], // at 1 and active 2
    ['id' => 12, 'business_id' => 1, 'deleted_at' => null], // at 1 and inactive 3
    ['id' => 13, 'business_id' => 1, 'deleted_at' => '2026-01-01 00:00:00'], // manually deleted, at 1
    ['id' => 14, 'business_id' => 1, 'deleted_at' => null], // not listed at 1
    ['id' => 15, 'business_id' => 2, 'deleted_at' => null], // other tenant
]);
DB::table('product_locations')->insert([
    ['product_id' => 10, 'location_id' => 1], ['product_id' => 11, 'location_id' => 1], ['product_id' => 11, 'location_id' => 2],
    ['product_id' => 12, 'location_id' => 1], ['product_id' => 12, 'location_id' => 3], ['product_id' => 13, 'location_id' => 1],
    ['product_id' => 14, 'location_id' => 2], ['product_id' => 15, 'location_id' => 1], ['product_id' => 15, 'location_id' => 4],
]);
$deleted = fn (int $id) => DB::table('products')->where('id', $id)->value('deleted_at') !== null;
$products = new LocationProducts();

DB::table('business_locations')->where('id', 1)->update(['is_active' => 0]);
check($products->retire(1, 1) === 2, 'Deactivation retires exactly the products exclusive to the location');
check($deleted(10) && $deleted(12), 'Products listed only here or at inactive locations are soft-deleted');
check(!$deleted(11), 'Products still listed at another active location are kept');
check(!$deleted(14) && !$deleted(15), 'Unlisted and other-tenant products are untouched');
check(DB::table('products')->where('id', 13)->value('deleted_at') === '2026-01-01 00:00:00'
    && DB::table('products')->where('id', 13)->value('retired_by_location_id') === null,
    'Already deleted products keep their original deletion');
check(DB::table('product_locations')->count() === 9, 'Listings are kept so reactivation can restore them');

DB::table('business_locations')->where('id', 2)->update(['is_active' => 0]);
$products->retire(1, 2);
check($deleted(11) && $deleted(14), 'Deactivating the last active location retires its remaining products');

DB::table('business_locations')->where('id', 1)->update(['is_active' => 1]);
check($products->restore(1, 1) === 3, 'Reactivation restores retired products listed at the location');
check(!$deleted(10) && !$deleted(11) && !$deleted(12), 'Products retired by any location deactivation are restored when listed here');
check($deleted(13), 'Manually deleted products are not restored');
check($deleted(14), 'Retired products not listed at the reactivated location stay deleted');
check(DB::table('products')->whereIn('id', [10, 11, 12])->whereNotNull('retired_by_location_id')->count() === 0,
    'Restored products clear the retirement marker');

$migration->down();
check(!$schema->hasColumn('products', 'retired_by_location_id'), 'Migration rolls back');

echo "PASS: {$checks} location product retirement checks (SQLite memory only).".PHP_EOL;
