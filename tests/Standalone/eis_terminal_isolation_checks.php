<?php

require __DIR__.'\eis_location_terminal_checks.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\EIS\Services\EisSubscriptionAccess;
use Modules\EIS\Services\Terminal\TerminalContext;

// Exercise real activation persistence without calling the activation API.
$app->instance(EisSubscriptionAccess::class, new class extends EisSubscriptionAccess {
    public function assertAllowed(int $businessId): void {}
});
$app['config']['eis.base_url'] = 'https://eis.invalid';
$baseUrlMigration = require dirname(__DIR__, 2).'\Modules\EIS\Database\Migrations\2026_10_23_171800_restore_eis_settings_base_url.php';
$baseUrlMigration->up();
locationCheck($schema->hasColumn('eis_settings', 'base_url')
    && DB::table('eis_settings')->whereNull('base_url')->count() === 0, 'Compatibility migration restores missing endpoint column and backfills settings');
DB::table('eis_settings')->where('id', 2)->update(['base_url' => 'https://custom.invalid']);
$baseUrlMigration->up();
locationCheck(DB::table('eis_settings')->where('id', 2)->value('base_url') === 'https://custom.invalid',
    'Repeated endpoint migration preserves existing terminal URLs');
$baseUrlMigration->down();
locationCheck($schema->hasColumn('eis_settings', 'base_url'), 'Compatibility rollback retains original-schema endpoint column');
$app->instance('cache', new class {
    private $store;
    public function __construct() { $this->store = new Illuminate\Cache\ArrayStore(); }
    public function lock($name, $seconds) { return new Illuminate\Cache\ArrayLock($this->store, $name, $seconds); }
});
$schema->create('business', function (Blueprint $table) {
    $table->increments('id'); $table->string('name'); $table->string('tax_label_1')->nullable(); $table->string('tax_number_1')->nullable(); $table->boolean('enable_inline_tax')->default(0);
    $table->text('common_settings')->nullable();
    foreach (['sell_price_tax', 'default_profit_percent', 'keyboard_shortcuts', 'ref_no_prefixes', 'custom_labels'] as $column) {
        $table->text($column)->nullable();
    }
    $table->timestamps();
});
DB::beginTransaction();
$registered = (new App\Utils\BusinessUtil())->createNewBusiness([
    'name' => 'Registration fixture', 'common_settings' => ['is_enabled_export' => 0, 'fixture_option' => 'keep'],
]);
locationCheck($registered->fresh()->common_settings === ['is_enabled_export' => 1, 'fixture_option' => 'keep'],
    'Business registration enables export by default without losing other common settings');
DB::rollBack();
DB::table('business')->insert([['id' => 1, 'name' => 'Business one'], ['id' => 2, 'name' => 'Business two']]);
$schema->create('products', function (Blueprint $table) {
    $table->increments('id'); $table->integer('business_id');
});
$schema->create('variation_location_details', function (Blueprint $table) {
    $table->increments('id'); $table->integer('product_id'); $table->integer('location_id');
    $table->decimal('qty_available', 22, 4); $table->timestamps();
});
$stockSoftDeletes = require dirname(__DIR__, 2).'\database\migrations\2026_11_03_150000_add_soft_deletes_to_variation_location_details.php';
$stockSoftDeletes->up();
DB::table('products')->insert([['id' => 1, 'business_id' => 1], ['id' => 2, 'business_id' => 2]]);
$schema->create('product_locations', function (Blueprint $table) {
    $table->integer('product_id'); $table->integer('location_id');
});
$schema->create('eis_product_maps', function (Blueprint $table) {
    $table->increments('id'); $table->integer('business_id'); $table->integer('product_id');
    $table->string('eis_product_id'); $table->softDeletes();
});
DB::table('products')->insert(['id' => 3, 'business_id' => 1]);
DB::table('eis_product_maps')->insert(['business_id' => 1, 'product_id' => 1, 'eis_product_id' => 'P1']);
DB::table('product_locations')->insert([
    ['product_id' => 1, 'location_id' => 1], ['product_id' => 1, 'location_id' => 2],
    ['product_id' => 3, 'location_id' => 2], ['product_id' => 2, 'location_id' => 3],
]);
DB::table('variation_location_details')->insert([
    ['product_id' => 1, 'location_id' => 1, 'qty_available' => 12],
    ['product_id' => 1, 'location_id' => 2, 'qty_available' => 25],
    ['product_id' => 2, 'location_id' => 3, 'qty_available' => 40],
]);
$schema->create('users', function (Blueprint $table) {
    $table->increments('id'); $table->integer('business_id'); $table->softDeletes();
});
DB::table('users')->insert(['id' => 1, 'business_id' => 1]);
$gate = Mockery::mock(Illuminate\Contracts\Auth\Access\Gate::class);
$gate->shouldReceive('forUser')->andReturnSelf();
$allLocations = true;
$gate->shouldReceive('check')->andReturnUsing(function () use (&$allLocations) { return $allLocations; });
$app->instance(Illuminate\Contracts\Auth\Access\Gate::class, $gate);
$schema->table('business_locations', function (Blueprint $table) {
    foreach (['location_id', 'country', 'state', 'city', 'zip_code', 'invoice_scheme_id', 'sale_invoice_scheme_id',
        'invoice_layout_id', 'sale_invoice_layout_id', 'default_payment_accounts', 'mobile', 'email', 'landmark'] as $column) {
        $table->text($column)->nullable();
    }
    $table->boolean('is_active')->default(1);
});
$schema->table('products', function (Blueprint $table) {
    $table->softDeletes(); $table->unsignedInteger('retired_by_location_id')->nullable();
});
foreach (['invoice_schemes', 'invoice_layouts'] as $name) {
    $schema->create($name, function (Blueprint $table) {
        $table->increments('id'); $table->integer('business_id'); $table->string('name');
        $table->boolean('is_default'); $table->timestamps();
    });
    DB::table($name)->insert(['business_id' => 1, 'name' => 'Default', 'is_default' => true]);
}
$schema->table('invoice_schemes', function (Blueprint $table) {
    foreach (['scheme_type', 'prefix', 'start_number', 'total_digits'] as $column) { $table->text($column)->nullable(); }
});
$schema->create('permissions', function (Blueprint $table) {
    $table->increments('id'); $table->string('name'); $table->string('guard_name'); $table->timestamps();
});
$schema->create('model_has_permissions', function (Blueprint $table) {
    $table->integer('permission_id'); $table->integer('model_id'); $table->string('model_type');
});
$app['config']['permission.models.permission'] = Spatie\Permission\Models\Permission::class;
$app['config']['permission.table_names.model_has_permissions'] = 'model_has_permissions';
$app['config']['permission.column_names.model_morph_key'] = 'model_id';
Spatie\Permission\PermissionRegistrar::$pivotPermission = 'permission_id';
DB::table('business_locations')->where('id', 1)->update([
    'default_payment_accounts' => '{"cash":{"is_enabled":1,"account":null}}', 'country' => 'Malawi',
]);
foreach ([
    'eis_settings' => ['secret_key', 'last_sync_at', 'sync_status', 'sync_error', 'successful_syncs', 'failed_syncs'],
    'eis_configurations' => ['global_version', 'terminal_version', 'taxpayer_version', 'tpin', 'is_vat_registered', 'tax_office_code', 'tax_office_name', 'raw_response', 'last_synced_at'],
    'eis_terminal_configurations' => ['version', 'terminal_label', 'email_address', 'phone_number', 'trading_name',
        'max_transaction_age', 'max_cummulative_amount', 'address_lines', 'raw_data', 'last_synced_at',
        'activated_at', 'activated_by', 'deactivated_at', 'deactivated_by', 'deactivation_reason', 'toggled_at',
        'toggled_by', 'activation_code', 'activation_environment', 'activation_date', 'jwt_token', 'secret_key',
        'confirmed_at', 'confirmation_response'],
] as $name => $columns) {
    $schema->table($name, function (Blueprint $table) use ($columns) {
        foreach ($columns as $column) { $table->text($column)->nullable(); }
    });
}
foreach ([
    'eis_terminal_sites' => ['site_id', 'site_name', 'raw_data', 'last_synced_at'],
    'eis_offline_limits' => ['max_transaction_age_hours', 'max_cumulative_amount', 'raw_data', 'last_synced_at'],
] as $name => $columns) {
    $schema->create($name, function (Blueprint $table) use ($columns) {
        $table->increments('id'); $table->integer('terminal_configuration_id');
        foreach ($columns as $column) { $table->text($column)->nullable(); }
        $table->timestamps();
    });
}
$sync = new class extends Modules\EIS\Services\Configuration\ConfigurationSyncService {
    public function __construct() {}
};
$activation = new Modules\EIS\Services\Terminal\EisTerminalActivationService($sync);
$process = new ReflectionMethod($activation, 'processActivationResponse');
$process->setAccessible(true);
function activationResponse(string $device, string $site, int $position, ?string $tradingName = 'EIS Trading Name'): object {
    return (object) ['statusCode' => 1, 'data' => (object) [
        'activatedTerminal' => (object) ['terminalId' => $device, 'terminalPosition' => $position, 'taxpayerId' => 100,
            'terminalCredentials' => (object) ['jwtToken' => 'jwt-'.$device, 'secretKey' => 'secret-'.$device]],
        'configuration' => (object) [
            'terminalConfiguration' => (object) ['terminalId' => $device, 'isActiveTerminal' => true,
                'tradingName' => $tradingName,
                'terminalSite' => (object) ['siteId' => $site, 'siteName' => 'Site '.$site],
                'offlineLimit' => (object) ['maxTransactionAgeInHours' => 24, 'maxCummulativeAmount' => 100000]],
            'taxpayerConfiguration' => (object) ['tin' => 'TIN1', 'isVATRegistered' => true],
        ],
    ]];
}
$beforeSettings = DB::table('eis_settings')->count();
$beforeLocations = DB::table('business_locations')->get()->toJson();
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => 1]);
$result = $process->invoke($activation, 1, activationResponse('A2', 'A', 3), 'CODE', [], 1, 1);
$newId = $result['terminal_setting_id'];
locationCheck((int) DB::table('business_locations')->where('id', 1)->value('default_eis_setting_id') === 1,
    'Activating another terminal keeps the location\'s existing valid default');
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => null]);
locationCheck($result['success'] && DB::table('eis_settings')->count() === $beforeSettings + 1, 'New terminal adds rather than replaces another device');
locationCheck(TerminalContext::settings(1, null, 1)->device_id === 'A1', 'Old device identity and historical sale ownership survive activation');
locationCheck((int) TerminalContext::settings(1, null, $newId)->location_id === 1, 'New terminal belongs to the chosen existing location');
locationCheck(DB::table('business_locations')->count() === 5
    && DB::table('business_locations')->where('id', 1)->value('name') === 'Site A',
    'Activation reuses the exact business/site location and syncs its name');
locationCheck(DB::table('business')->where('id', 1)->value('name') === 'EIS Trading Name'
    && DB::table('business')->where('id', 2)->value('name') === 'Business two',
    'Successful activation updates only the owning business name to the EIS trading name');
locationCheck(DB::table('business')->where('id', 1)->value('tax_number_1') === 'TIN1'
    && DB::table('business')->where('id', 1)->value('tax_label_1') === 'TIN'
    && DB::table('business')->where('id', 2)->value('tax_number_1') === null,
    'Successful activation updates only the owning business tax number to the EIS TIN');
locationCheck((int) DB::table('business')->where('id', 1)->value('enable_inline_tax') === 1
    && (int) DB::table('business')->where('id', 2)->value('enable_inline_tax') === 0,
    'Successful activation enables inline tax only for the owning business');
locationCheck(DB::table('eis_terminal_sites')->count() === 1 && DB::table('eis_offline_limits')->count() === 1, 'Activation persists site and offline configuration');
locationCheck(DB::table('eis_settings')->where('id', $newId)->value('base_url') === 'https://eis.invalid', 'New settings include required base URL');
$again = $process->invoke($activation, 1, activationResponse('A2', 'A', 3), 'CODE', [], 1, 1);
locationCheck($again['terminal_setting_id'] === $newId && DB::table('eis_settings')->count() === $beforeSettings + 1, 'Reactivation updates the same terminal without duplicating settings');
$confirmedTerminal = Modules\EIS\Models\EisTerminalConfiguration::where('terminal_id', 'A2')->firstOrFail();
$confirmedTerminal->update(['confirmed_at' => now(), 'confirmation_response' => '{"statusCode":1}']);
locationCheck($confirmedTerminal->fresh()->is_confirmed === true && $confirmedTerminal->fresh()->confirmation_response === '{"statusCode":1}',
    'EIS confirmation is stored and reported as confirmed');
$process->invoke($activation, 1, activationResponse('A2', 'A', 3), 'CODE', [], 1, 1);
locationCheck($confirmedTerminal->fresh()->is_confirmed === false && $confirmedTerminal->fresh()->confirmation_response === null,
    'Reactivation clears the previous confirmation until EIS confirms again');
DB::beginTransaction();
DB::table('business_locations')->where('id', 2)->update(['is_active' => 0]);
DB::table('products')->where('id', 3)->update(['deleted_at' => now(), 'retired_by_location_id' => 2]);
DB::table('products')->where('id', 1)->update(['deleted_at' => '2026-01-01 00:00:00']);
$process->invoke($activation, 1, activationResponse('B-REOPEN', 'B', 11), 'CODE', [], 1, 1);
locationCheck((int) DB::table('business_locations')->where('id', 2)->value('is_active') === 1,
    'Activating a terminal at a deactivated location reactivates the location');
locationCheck(DB::table('products')->where('id', 3)->value('deleted_at') === null
    && DB::table('products')->where('id', 1)->value('deleted_at') !== null,
    'Location reactivation by terminal activation restores only products retired by deactivation');
DB::rollBack();
// Activation must also support installations that use only the environment endpoint.
DB::beginTransaction();
$schema->table('eis_settings', fn (Blueprint $table) => $table->dropColumn('base_url'));
$withoutEndpoint = $process->invoke($activation, 1, activationResponse('NO-DB-URL', 'A', 9), 'CODE', [], 1, 1);
locationCheck($withoutEndpoint['success']
    && TerminalContext::settings(1, null, $withoutEndpoint['terminal_setting_id'])->device_id === 'NO-DB-URL',
    'Activation creates settings successfully when legacy base_url column is absent');
DB::rollBack();
function activationSnapshot(): array {
    $snapshot = [];
    foreach (['business', 'business_locations', 'eis_settings', 'eis_configurations',
        'eis_terminal_configurations', 'eis_terminal_sites', 'eis_offline_limits'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    return $snapshot;
}
$identityBefore = activationSnapshot();
foreach (['new', 'reactivation'] as $kind) {
    $device = $kind === 'new' ? 'FOREIGN' : 'A2';
    $foreignTin = activationResponse($device, 'FOREIGN-SITE', 3);
    $foreignTin->data->configuration->taxpayerConfiguration->tin = 'TIN-OTHER';
    locationReject(fn () => $process->invoke($activation, 1, $foreignTin, 'CODE', [], 1),
        'Different taxpayer TIN is rejected during '.$kind);
    $foreignTaxpayer = activationResponse($device, 'FOREIGN-SITE', 3);
    $foreignTaxpayer->data->activatedTerminal->taxpayerId = 999;
    locationReject(fn () => $process->invoke($activation, 1, $foreignTaxpayer, 'CODE', [], 1),
        'Different taxpayer identifier is rejected during '.$kind);
    locationReject(fn () => $process->invoke($activation, 1,
        activationResponse($device, 'FOREIGN-SITE', 3, 'Another Trading Name'), 'CODE', [], 1),
        'Different trading name with the same TIN is rejected during '.$kind);
}
foreach ([null, '', '   '] as $missingName) {
    locationReject(fn () => $process->invoke($activation, 1,
        activationResponse('MISSING-NAME', 'FOREIGN-SITE', 3, $missingName), 'CODE', [], 1),
        'Unverifiable trading name blocks registration');
}
$missingTin = activationResponse('MISSING-TIN', 'FOREIGN-SITE', 3);
unset($missingTin->data->configuration->taxpayerConfiguration->tin);
locationReject(fn () => $process->invoke($activation, 1, $missingTin, 'CODE', [], 1),
    'Unverifiable taxpayer TIN blocks registration');
foreach ([false, 0, '0', 'false'] as $vatFlag) {
    foreach (['NOT-VAT', 'A2'] as $device) {
        DB::beginTransaction();
        $notVat = activationResponse($device, 'A', 3);
        $notVat->data->configuration->taxpayerConfiguration->isVATRegistered = $vatFlag;
        $notVatResult = $process->invoke($activation, 1, $notVat, 'CODE', [], 1);
        locationCheck($notVatResult['success'],
            'Non-VAT registered taxpayers can activate and reactivate terminals');
        locationCheck(Modules\EIS\Models\EisConfiguration::where('eis_setting_id', $notVatResult['terminal_setting_id'])
            ->firstOrFail()->is_vat_registered === false,
            'Non-VAT activation persists a false registration flag, including string false');
        DB::rollBack();
    }
}
foreach ([null, '', 'unknown', 2, []] as $vatFlag) {
    $invalidVat = activationResponse('INVALID-VAT', 'A', 3);
    $invalidVat->data->configuration->taxpayerConfiguration->isVATRegistered = $vatFlag;
    locationReject(fn () => $process->invoke($activation, 1, $invalidVat, 'CODE', [], 1),
        'Invalid VAT registration status still blocks activation');
}
$missingVat = activationResponse('MISSING-VAT', 'FOREIGN-SITE', 3);
unset($missingVat->data->configuration->taxpayerConfiguration->isVATRegistered);
locationReject(fn () => $process->invoke($activation, 1, $missingVat, 'CODE', [], 1),
    'Unknown VAT registration blocks activation');
$vatAsString = activationResponse('A1', 'A', 1);
$vatAsString->data->configuration->taxpayerConfiguration->isVATRegistered = '1';
DB::beginTransaction();
locationCheck($process->invoke($activation, 1, $vatAsString, 'CODE', [], 1)['success'],
    'VAT registration reported as "1" is accepted');
locationCheck(Modules\EIS\Models\EisConfiguration::where('eis_setting_id', 1)
    ->firstOrFail()->is_vat_registered === true,
    'VAT-registered activation persists a true registration flag');
DB::rollBack();
locationCheck(activationSnapshot() === $identityBefore,
    'Rejected identities preserve business names, sites, credentials and all terminal configuration');
DB::beginTransaction();
DB::table('eis_settings')->where('business_id', 1)->update(['status' => false]);
DB::table('eis_terminal_configurations')->update(['is_active' => false]);
locationReject(fn () => $process->invoke($activation, 1,
    activationResponse('FOREIGN-INACTIVE', 'FOREIGN-SITE', 3, 'Another Trading Name'), 'CODE', [], 1),
    'Inactive terminals still anchor ERP account identity');
DB::rollBack();
$crossAccountBefore = activationSnapshot();
DB::beginTransaction();
// Give account 2 no EIS identity so only cross-account ownership can reject it.
$account2Settings = DB::table('eis_settings')->where('business_id', 2)->pluck('id');
$account2Configs = DB::table('eis_configurations')->where('business_id', 2)->pluck('id');
DB::table('business_locations')->where('business_id', 2)->update(['default_eis_setting_id' => null]);
DB::table('eis_terminal_configurations')->whereIn('configuration_id', $account2Configs)->delete();
DB::table('eis_configurations')->whereIn('id', $account2Configs)->delete();
DB::table('eis_settings')->whereIn('id', $account2Settings)->delete();
$crossAccountRejects = function (object $response, string $label) use ($process, $activation) {
    try {
        $process->invoke($activation, 2, $response, 'CODE', [], null);
        locationCheck(false, $label);
    } catch (Throwable $e) {
        locationCheck(str_contains($e->getMessage(), 'already registered in another ERP account'), $label);
    }
};
$crossAccountRejects(activationResponse('OTHER-ACCOUNT', 'A', 4, ' EIS TRADING NAME '),
    'A business already registered in one ERP account cannot activate terminals in another account');
$byTaxpayer = activationResponse('OTHER-ACCOUNT-TAXPAYER', 'A', 5);
$byTaxpayer->data->configuration->taxpayerConfiguration->tin = 'TIN-RENAMED';
$crossAccountRejects($byTaxpayer, 'Cross-account ownership also matches the EIS taxpayer identifier');
DB::table('eis_settings')->where('business_id', 1)->update(['status' => false]);
DB::table('eis_terminal_configurations')->update(['is_active' => false]);
$crossAccountRejects(activationResponse('OTHER-ACCOUNT-INACTIVE', 'A', 6),
    'Inactive terminals still keep the business in its original ERP account');
$now = date('Y-m-d H:i:s');
foreach (['eis_settings', 'eis_configurations'] as $table) {
    DB::table($table)->where('business_id', 1)->update(['deleted_at' => $now]);
}
DB::table('eis_terminal_configurations')->update(['deleted_at' => $now]);
$afterDelete = $process->invoke($activation, 2, activationResponse('A2', 'A', 7), 'CODE', [], null);
locationCheck($afterDelete['success'] && (int) DB::table('eis_settings')->where('id', $afterDelete['terminal_setting_id'])->value('business_id') === 2
    && DB::table('eis_settings')->where('device_id', 'A2')->where('business_id', 1)->whereNotNull('deleted_at')->exists(),
    'Soft-deleted terminals release the device and business identity for activation in another account');
DB::rollBack();
locationCheck(activationSnapshot() === $crossAccountBefore,
    'Cross-account rejection leaves both ERP accounts unchanged');
DB::beginTransaction();
$matchingName = $process->invoke($activation, 1,
    activationResponse('MATCHING-NAME', 'B', 8, '  eis trading name  '), 'CODE', [], 1);
locationCheck($matchingName['success']
    && (int) TerminalContext::settings(1, null, $matchingName['terminal_setting_id'])->location_id === 2,
    'Matching identity permits another site and ignores trading-name casing and surrounding whitespace');
DB::rollBack();
DB::beginTransaction();
DB::table('business')->insert(['id' => 4, 'name' => 'Initial ERP Name',
    'common_settings' => json_encode(['is_enabled_export' => 0, 'fixture_option' => 'keep'])]);
$first = $process->invoke($activation, 4,
    activationResponse('FIRST-IDENTITY', 'FIRST-SITE', 1, 'First EIS Identity'), 'CODE', [], null);
locationCheck($first['success'] && DB::table('business')->where('id', 4)->value('name') === 'First EIS Identity',
    'First terminal establishes identity without requiring the initial ERP name to match');
locationCheck(App\Business::findOrFail(4)->common_settings === ['is_enabled_export' => 1, 'fixture_option' => 'keep'],
    'First terminal enables export and preserves unrelated business settings');
DB::table('business')->where('id', 4)->update([
    'common_settings' => json_encode(['is_enabled_export' => 0, 'fixture_option' => 'keep']),
]);
$firstLocation = (int) DB::table('eis_settings')->where('id', $first['terminal_setting_id'])->value('location_id');
locationCheck((int) DB::table('business_locations')->where('id', $firstLocation)->value('default_eis_setting_id')
    === (int) $first['terminal_setting_id'],
    'First terminal activated at a location becomes its default');
DB::table('eis_settings')->where('id', $first['terminal_setting_id'])->update(['deleted_at' => now()]);
$replacement = $process->invoke($activation, 4,
    activationResponse('REPLACEMENT', 'FIRST-SITE', 3, 'First EIS Identity'), 'CODE', [], null);
locationCheck((int) DB::table('business_locations')->where('id', $firstLocation)->value('default_eis_setting_id')
    === (int) $replacement['terminal_setting_id'],
    'A soft-deleted default is replaced by the next activated terminal');
locationCheck(App\Business::findOrFail(4)->common_settings === ['is_enabled_export' => 0, 'fixture_option' => 'keep'],
    'Later activation preserves a manually disabled export setting even when the first terminal was deleted');
DB::table('eis_settings')->where('id', $first['terminal_setting_id'])->update(['deleted_at' => null]);
locationReject(fn () => $process->invoke($activation, 4,
    activationResponse('SECOND-IDENTITY', 'FIRST-SITE', 2, 'Other EIS Identity'), 'CODE', [], null),
    'Subsequent terminal must match the identity established by the first terminal');
DB::rollBack();
locationCheck(DB::table('eis_configurations')->where('eis_setting_id', $newId)->count() === 1, 'Each terminal has exactly one configuration');
DB::beginTransaction();
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => $newId]);
$moved = $process->invoke($activation, 1, activationResponse('A2', 'B', 3), 'CODE', [], 1);
locationCheck($moved['terminal_setting_id'] === $newId && (int) TerminalContext::settings(1, null, $newId)->location_id === 2,
    'API reassignment moves the existing terminal to its assigned site without duplicating identity');
locationCheck(DB::table('business_locations')->where('id', 1)->value('default_eis_setting_id') === null,
    'Moving a default terminal clears its old location default rather than pointing across sites');
locationCheck((int) DB::table('eis_sales')->where('id', 1)->value('location_id') === 1,
    'Terminal reassignment does not rewrite historical fiscal location');
DB::rollBack();
DB::beginTransaction();
$process->invoke($activation, 1, activationResponse('A1', 'B', 1), 'CODE', [], 1);
$historical = new App\Transaction();
$historical->setRawAttributes(['id' => 1, 'business_id' => 1, 'location_id' => 1]);
locationCheck((int) DB::table('eis_sales')->where('id', 1)->value('location_id') === 1
    && (int) DB::table('eis_sales')->where('id', 1)->value('eis_setting_id') === 1,
    'Moving a terminal with fiscal history preserves its original sale ownership');
locationReject(fn () => TerminalContext::forSale($historical),
    'Historical retry cannot silently submit using its moved terminal new site');
DB::rollBack();
locationReject(fn () => $process->invoke($activation, 2, activationResponse('A2', 'A', 3), 'CODE', [], 1, 3), 'A terminal cannot be stolen by another business');
DB::beginTransaction();
$created = $process->invoke($activation, 1, activationResponse('NEW', 'NEW-SITE', 4), 'CODE', [], 1);
$createdLocation = TerminalContext::settings(1, null, $created['terminal_setting_id'])->location;
locationCheck($createdLocation->eis_site_id === 'NEW-SITE' && $createdLocation->name === 'Site NEW-SITE',
    'Unknown API site creates the assigned business location automatically');
locationCheck((int) $createdLocation->invoice_scheme_id === 1 && (int) $createdLocation->invoice_layout_id === 1
    && $createdLocation->default_payment_accounts === DB::table('business_locations')->where('id', 1)->value('default_payment_accounts'),
    'API-created location inherits business invoice and payment defaults');
locationCheck(DB::table('permissions')->where('name', 'location.'.$createdLocation->id)->exists(),
    'API-created location has a corresponding role permission');
$process->invoke($activation, 1, activationResponse('NEW-SECOND', 'NEW-SITE', 5), 'CODE', [], 1);
locationCheck(DB::table('business_locations')->where('business_id', 1)->where('eis_site_id', 'NEW-SITE')->count() === 1,
    'Another terminal at the same API site reuses its location');
DB::rollBack();
$allLocations = false;
$beforePermissionCheck = DB::table('business_locations')->count();
locationReject(fn () => $process->invoke($activation, 1, activationResponse('FORBIDDEN', 'NEW-SITE', 6), 'CODE', [], 1),
    'Restricted users cannot create a new API location without all-location access');
locationReject(fn () => $process->invoke($activation, 1, activationResponse('FORBIDDEN', 'B', 6), 'CODE', [], 1),
    'Restricted users cannot attach terminals to an unpermitted API site');
locationCheck(DB::table('business_locations')->count() === $beforePermissionCheck,
    'Forbidden API site activation creates no partial location');
$allLocations = true;
DB::beginTransaction();
$freshLocation = $sync->locationForActivation(2, activationResponse('FRESH', 'FIRST', 1)->data->configuration);
locationCheck($freshLocation->business_id === 2 && $freshLocation->invoice_scheme_id && $freshLocation->invoice_layout_id,
    'Businesses without invoice defaults receive usable default schemes and layouts');
DB::rollBack();
DB::beginTransaction();
DB::table('business')->insert(['id' => 4, 'name' => 'Business four']);
DB::table('business_locations')->insert([
    ['id' => 90, 'business_id' => 4, 'eis_site_id' => null, 'name' => 'Default shop', 'location_id' => 'BL0001',
        'mobile' => '', 'email' => '', 'landmark' => 'Old landmark', 'city' => 'Blantyre'],
    ['id' => 91, 'business_id' => 4, 'eis_site_id' => null, 'name' => 'Second shop', 'location_id' => 'BL0002',
        'mobile' => '', 'email' => '', 'landmark' => null, 'city' => 'Blantyre'],
]);
DB::table('eis_settings')->insert(['id' => 90, 'business_id' => 4, 'branch_id' => 'OLD', 'device_id' => 'OLD4',
    'jwt_token' => 'token90', 'tpin' => 'TIN4', 'status' => 1, 'deleted_at' => now()]);
$firstData = activationResponse('FIRST4', 'EIS-SITE', 1)->data->configuration;
$firstData->terminalConfiguration->phoneNumber = '0888000000';
$firstData->terminalConfiguration->emailAddress = 'site@example.test';
$firstData->terminalConfiguration->addressLines = ['Plot 12', ' City Centre '];
$beforeFirst = DB::table('business_locations')->count();
$adopted = $sync->locationForActivation(4, $firstData);
$adoptedRow = DB::table('business_locations')->where('id', 90)->first();
locationCheck((int) $adopted->id === 90 && DB::table('business_locations')->count() === $beforeFirst,
    'First terminal activation adopts the default business location instead of creating another');
locationCheck($adoptedRow->eis_site_id === 'EIS-SITE' && $adoptedRow->location_id === 'EIS-SITE'
    && $adoptedRow->name === 'Site EIS-SITE' && $adoptedRow->mobile === '0888000000'
    && $adoptedRow->email === 'site@example.test' && $adoptedRow->landmark === 'Plot 12, City Centre'
    && $adoptedRow->city === 'Blantyre',
    'First activation updates default location details to the EIS site data');
locationCheck(DB::table('business_locations')->where('id', 91)->value('name') === 'Second shop',
    'First activation leaves other business locations untouched');
DB::table('eis_settings')->insert(['id' => 91, 'business_id' => 4, 'branch_id' => 'EIS-SITE', 'device_id' => 'FIRST4',
    'jwt_token' => 'token91', 'tpin' => 'TIN4', 'status' => 1, 'location_id' => 90]);
$secondSite = $sync->locationForActivation(4, activationResponse('SECOND4', 'OTHER-SITE', 2)->data->configuration);
locationCheck((int) $secondSite->id !== 90 && $secondSite->eis_site_id === 'OTHER-SITE'
    && DB::table('business_locations')->where('id', 90)->value('eis_site_id') === 'EIS-SITE',
    'Later activations for a new site create a location without repurposing the default');
DB::rollBack();
$missingSite = activationResponse('MISSING', 'A', 6);
$missingSite->data->configuration->terminalConfiguration->terminalSite->siteName = '';
locationReject(fn () => $process->invoke($activation, 1, $missingSite, 'CODE', [], 1),
    'Missing API site name is rejected before location creation');
$duplicateSite = activationResponse('DUPLICATE', 'D', 6);
locationReject(fn () => $process->invoke($activation, 3, $duplicateSite, 'CODE', [], null),
    'Duplicate business locations with one API site ID are not resolved arbitrarily');
$mismatch = activationResponse('NEW', 'A', 4);
$mismatch->data->configuration->terminalConfiguration->terminalId = 'OTHER';
locationReject(fn () => $process->invoke($activation, 1, $mismatch, 'CODE', [], 1, 1), 'Activation response must consistently identify one terminal');
locationCheck(DB::table('eis_settings')->count() === $beforeSettings + 1, 'Rejected activation writes no partial terminal');
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => $newId]);
locationCheck(TerminalContext::forLocation(1, 1, true)->id === $newId, 'New sales can use another designated terminal at the same location');
$sale = new App\Transaction();
$sale->setRawAttributes(['id' => 1, 'business_id' => 1, 'location_id' => 1]);
locationCheck(TerminalContext::forSale($sale)->id === 1, 'Historical retry does not follow a default-terminal change');
$deactivated = $activation->deactivate(1, 'Test', 1, $newId);
locationCheck($deactivated['success'], 'Deactivation targets the selected terminal');
locationCheck((float) DB::table('variation_location_details')->where('location_id', 1)->value('qty_available') === 12.0,
    'Deactivation preserves shared location stock while another terminal remains locally enabled');
DB::beginTransaction();
$historyBeforeCleanup = DB::table('transactions')->orderBy('id')->get()->toJson();
$soleDeactivated = $activation->deactivate(1, 'Stock cleanup test', 1, 2);
locationCheck($soleDeactivated['success']
    && (float) DB::table('variation_location_details')->where('location_id', 2)->value('qty_available') === 25.0
    && App\VariationLocationDetails::where('location_id', 2)->count() === 0
    && App\VariationLocationDetails::onlyTrashed()->where('location_id', 2)->count() === 1,
    'Deactivating the last enabled terminal soft-deletes stock without changing its quantity');
locationCheck(!DB::table('product_locations')->where('product_id', 1)->where('location_id', 2)->exists()
    && DB::table('product_locations')->where('product_id', 3)->where('location_id', 2)->exists()
    && DB::table('product_locations')->where('product_id', 1)->where('location_id', 1)->exists()
    && DB::table('product_locations')->where('location_id', 3)->exists(),
    'Deactivation unlinks EIS products from its location only, keeping manual products and other locations');
locationCheck(DB::table('products')->count() === 3
    && DB::table('transactions')->orderBy('id')->get()->toJson() === $historyBeforeCleanup
    && (float) DB::table('variation_location_details')->where('location_id', 1)->value('qty_available') === 12.0
    && (float) DB::table('variation_location_details')->where('location_id', 3)->value('qty_available') === 40.0,
    'Stock cleanup preserves products, transaction history, other locations and tenants');
DB::table('variation_location_details')->where('location_id', 2)->update(['qty_available' => -5, 'deleted_at' => null]);
$cleanup = new Modules\EIS\Services\Terminal\InactiveTerminalStock();
locationCheck($cleanup->clear(1, 2, true) === 1
    && (float) DB::table('variation_location_details')->where('location_id', 2)->value('qty_available') === -5.0,
    'Backfill dry run reports stock without mutating negative or positive quantities');
locationCheck($activation->deactivate(1, null, 1, 2)['success']
    && (float) DB::table('variation_location_details')->where('location_id', 2)->value('qty_available') === -5.0
    && App\VariationLocationDetails::where('location_id', 2)->count() === 0
    && $cleanup->clear(1, 2) === 0,
    'Repeated deactivation soft-deletes legacy stock idempotently and retains negative quantities');
locationCheck($activation->toggle(1, 'token2', 1, 2)['success']
    && App\VariationLocationDetails::where('location_id', 2)->count() === 0,
    'Reactivation does not automatically restore soft-deleted stock');
DB::table('variation_location_details')->where('location_id', 2)->update(['qty_available' => 0, 'deleted_at' => null]);
locationCheck($activation->toggle(1, 'token2', 1, 2)['success']
    && App\VariationLocationDetails::onlyTrashed()->where('location_id', 2)->count() === 1,
    'Toggle deactivation also soft-deletes zero-quantity stock records');
DB::rollBack();
locationReject(fn () => TerminalContext::forLocation(1, 1, true), 'Deactivated default blocks new submissions');
locationCheck(TerminalContext::forSale($sale)->id === 1 && TerminalContext::forLocation(1, 2, true)->id === 2, 'Deactivation leaves other terminal/location sales available');
locationCheck(!TerminalContext::settings(1, null, $newId)->status, 'Deactivation disables scheduled synchronization for the selected settings');
$selectedConfiguration = Modules\EIS\Models\EisConfiguration::where('eis_setting_id', $newId)->firstOrFail();
DB::table('business')->where('id', 1)->update(['name' => 'Locally edited name', 'tax_label_1' => 'TPIN', 'tax_number_1' => 'stale']);
$sync->syncTerminalConfiguration($selectedConfiguration, activationResponse('A2', 'A', 3)->data->configuration);
locationCheck(DB::table('business')->where('id', 1)->value('name') === 'EIS Trading Name',
    'Latest terminal configuration refreshes the business name from EIS');
locationCheck(DB::table('business')->where('id', 1)->value('tax_number_1') === 'TIN1'
    && DB::table('business')->where('id', 1)->value('tax_label_1') === 'TPIN',
    'Latest terminal configuration refreshes the tax number and keeps a custom tax label');
DB::table('business')->where('id', 1)->update(['name' => 'Locally edited name']);
$withoutTradingName = activationResponse('A2', 'A', 3, null)->data->configuration;
$sync->syncTerminalConfiguration($selectedConfiguration, $withoutTradingName);
locationCheck(DB::table('business')->where('id', 1)->value('name') === 'Locally edited name',
    'Configuration without an EIS trading name preserves the business name');
$sync->syncTerminalConfiguration($selectedConfiguration, activationResponse('A2', 'A', 3)->data->configuration);
locationReject(fn () => TerminalContext::forLocation(1, 1, true), 'Remote configuration refresh cannot undo local deactivation');
$previousEnvironment = $app['config']->get('eis.environment', 'development');
DB::beginTransaction();
DB::table('eis_settings')->where('id', $newId)->update(['status' => true]);
$inactiveResponse = activationResponse('A2', 'A', 3)->data->configuration;
$inactiveResponse->terminalConfiguration->isActiveTerminal = false;
$sync->syncTerminalConfiguration($selectedConfiguration, $inactiveResponse);
foreach (['development', 'dev', 'sandbox', 'production', 'invalid', ''] as $mode) {
    $app['config']['eis.environment'] = $mode;
    $development = in_array($mode, ['development', 'dev', 'sandbox'], true);
    $status = $activation->getStatus(1, $newId);
    locationCheck($status['success'] && $status['is_active'] === $development
        && $status['data']['is_active'] === $development
        && $status['data']['status'] === ($development ? 'Active' : 'Inactive'),
        'Terminal API status consistently applies environment policy: '.$mode);
    if ($development) {
        locationCheck(TerminalContext::forLocation(1, 1, true)->id === $newId,
            'Development can submit through locally enabled terminal despite inactive API flag: '.$mode);
    } else {
        locationReject(fn () => TerminalContext::forLocation(1, 1, true),
            'Non-development rejects remotely inactive terminals: '.$mode);
    }
}
locationCheck(!Modules\EIS\Models\EisTerminalConfiguration::where('configuration_id', $selectedConfiguration->id)->firstOrFail()->is_active,
    'Environment exception preserves the original remote inactive flag');
$app['config']['eis.environment'] = 'development';
$toggled = $activation->toggle(1, TerminalContext::settings(1, null, $newId)->jwt_token, 1, $newId);
locationCheck($toggled['success'] && !$activation->getStatus(1, $newId)['is_active'],
    'Toggle deactivates an operational development terminal even when remote flag is false');
$sync->syncTerminalConfiguration($selectedConfiguration, $inactiveResponse);
locationReject(fn () => TerminalContext::forLocation(1, 1, true),
    'Development exception never bypasses local deactivation after configuration refresh');
DB::rollBack();
$app['config']['eis.environment'] = $previousEnvironment;
locationCheck(!$activation->getStatus(1, $newId)['is_active'], 'Status reports local deactivation even if remote terminal flag is active');
locationCheck($activation->toggle(1, 'jwt-A2', 1, $newId)['success']
    && TerminalContext::forLocation(1, 1, true)->id === $newId, 'Explicit legacy toggle updates both local status flags');
$activation->deactivate(1, 'Test', 1, $newId);

$callActivation = new ReflectionMethod($activation, 'callActivationAPI');
$callActivation->setAccessible(true);
$http = new Illuminate\Http\Client\Factory();
$http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response([
    'statusCode' => 1, 'data' => ['activatedTerminal' => ['terminalId' => 'TEST']],
]));
foreach ([
    'Win32' => 'Windows', 'Win64' => 'Windows', 'Windows' => 'Windows',
    'MacIntel' => 'MacOS', 'MacOS' => 'MacOS', 'Linux x86_64' => 'Linux',
    'Linux armv8l Android' => 'Android', 'iPhone' => 'iOS', 'iOS' => 'iOS',
] as $inputOs => $expectedOs) {
    $callActivation->invoke($activation, 1, 'TEST', ['platform' => ['osName' => $inputOs]]);
    $request = Illuminate\Support\Facades\Http::recorded()->last()[0];
    locationCheck($request['environment']['platform']['osName'] === $expectedOs,
        'Activation HTTP payload normalizes '.$inputOs.' to '.$expectedOs);
}
$app->instance('request', Request::create('http://localhost/eis/terminals/activate', 'POST', [], [], [], [
    'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
]));
foreach ([[], ['platform' => ['osName' => 'Web']]] as $environment) {
    $callActivation->invoke($activation, 1, 'TEST', $environment);
    locationCheck(Illuminate\Support\Facades\Http::recorded()->last()[0]['environment']['platform']['osName'] === 'Windows',
        'Omitted/browser-placeholder OS is identified from request user agent');
}
$requestsBefore = count(Illuminate\Support\Facades\Http::recorded());
locationReject(fn () => $callActivation->invoke($activation, 1, 'TEST', ['platform' => ['osName' => 'UnrecognizedOS']]),
    'Unsupported explicit OS fails locally');
locationCheck(count(Illuminate\Support\Facades\Http::recorded()) === $requestsBefore, 'Unsupported OS never reaches EIS');

$app['config']['app.name'] = 'Development POS';
$app['config']['eis.environment'] = 'development';
$app['config']['eis.product_id'] = null;
$app['config']['eis.product_version'] = '2.0.0';
$callActivation->invoke($activation, 1, 'TEST', ['pos' => ['productID' => 'browser-override']]);
$pos = Illuminate\Support\Facades\Http::recorded()->last()[0]['environment']['pos'];
locationCheck($pos === ['productID' => 'Development POS', 'productVersion' => '2.0.0'],
    'Development accepts null product ID but sends required configured POS fields');
$app['config']['eis.environment'] = 'production';
$requestsBefore = count(Illuminate\Support\Facades\Http::recorded());
locationReject(fn () => $callActivation->invoke($activation, 1, 'TEST', ['pos' => ['productID' => 'browser-override']]),
    'Production requires server-configured registered product ID');
locationCheck(count(Illuminate\Support\Facades\Http::recorded()) === $requestsBefore, 'Missing production product ID blocks HTTP');
$app['config']['eis.product_id'] = 'REGISTERED-POS';
$callActivation->invoke($activation, 1, 'TEST', ['pos' => ['productID' => 'browser-override', 'productVersion' => 'wrong']]);
locationCheck(Illuminate\Support\Facades\Http::recorded()->last()[0]['environment']['pos']
    === ['productID' => 'REGISTERED-POS', 'productVersion' => '2.0.0'], 'Production sends registered product ID and version, ignoring browser overrides');
$app['config']['eis.environment'] = 'invalid';
locationReject(fn () => $callActivation->invoke($activation, 1, 'TEST', []), 'Unknown EIS mode is rejected');
$app['config']['eis.environment'] = 'development';

$http = new Illuminate\Http\Client\Factory();
$http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response(
    json_decode(json_encode(activationResponse('PUBLIC-FOREIGN', 'FOREIGN-SITE', 3, 'Other Business')), true)
));
$publicBefore = activationSnapshot();
$activationSession = new Illuminate\Session\Store('activation', new Illuminate\Session\ArraySessionHandler(120));
$app->instance('session', $activationSession);
$activationSession->put('business', ['id' => 1, 'name' => 'Stale business name', 'enable_inline_tax' => 0]);
$activationSession->put('user', ['id' => 1, 'business_id' => 1]);
$publicRejected = $activation->activateTerminal(1, 'TEST', ['platform' => ['osName' => 'Windows']], 1);
locationCheck(!$publicRejected['success'] && $publicRejected['status_code'] === 422
    && str_contains($publicRejected['message'], 'different trading name'),
    'Public activation returns an explicit ownership rejection');
locationCheck(activationSnapshot() === $publicBefore,
    'Public activation rejection leaves local business and terminal state unchanged');
locationCheck($activationSession->get('business.name') === 'Stale business name',
    'Rejected activation does not overwrite the business session');
locationCheck(count(Illuminate\Support\Facades\Http::recorded()) === 1
    && str_ends_with(Illuminate\Support\Facades\Http::recorded()->first()[0]->url(), '/onboarding/activate-terminal'),
    'Rejected public activation sends no remote activation confirmation');

foreach ([200, 503] as $confirmationStatus) {
    DB::beginTransaction();
    $activationSession->put('business', ['id' => 1, 'name' => 'Stale business name', 'enable_inline_tax' => 0]);
    $http = new Illuminate\Http\Client\Factory();
    $http->preventStrayRequests();
    Illuminate\Support\Facades\Http::swap($http);
    $publicResponse = activationResponse('A2', 'A', 3);
    $publicResponse->remark = 'Terminal Activated, pending for confirmation request';
    Illuminate\Support\Facades\Http::fake([
        '*/onboarding/activate-terminal' => Illuminate\Support\Facades\Http::response(
            json_decode(json_encode($publicResponse), true)
        ),
        '*/onboarding/terminal-activated-confirmation' => Illuminate\Support\Facades\Http::response(
            ['statusCode' => $confirmationStatus === 200 ? 1 : 0], $confirmationStatus
        ),
    ]);
    $publicResult = $activation->activateTerminal(1, 'TEST', ['platform' => ['osName' => 'Windows']], 1);
    $confirmed = $confirmationStatus === 200;
    locationCheck($publicResult['success'] && $publicResult['confirmed'] === $confirmed
        && $publicResult['data']['is_confirmed'] === $confirmed,
        'Public activation reports the automatic confirmation outcome');
    locationCheck($confirmed
        ? $publicResult['message'] === 'Terminal activated and automatically confirmed successfully.'
        : str_contains($publicResult['message'], 'EIS did not acknowledge the activation confirmation'),
        'Activation replaces the stale pending remark only after confirmation succeeds');
    locationCheck(Modules\EIS\Models\EisTerminalConfiguration::where('terminal_id', 'A2')->firstOrFail()->is_confirmed === $confirmed,
        'Activation confirmation message matches persisted confirmation state');
    locationCheck($activationSession->get('business.name') === 'EIS Trading Name'
        && (int) $activationSession->get('business.enable_inline_tax') === 1
        && $activationSession->get('business.tax_number_1') === 'TIN1'
        && $activationSession->get('user.id') === 1,
        'Successful activation refreshes business settings without logging the user out');
    DB::rollBack();
}

$http = new Illuminate\Http\Client\Factory();
$http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response([
    'data' => ['jwtToken' => 'renewed-jwt', 'secretKey' => 'renewed-secret'],
]));
$renewed = $activation->regenerateCredentials(1, 'jwt-A2', $newId);
$terminalConfigurationId = DB::table('eis_configurations')->where('eis_setting_id', $newId)->value('id');
locationCheck($renewed['success'] && TerminalContext::settings(1, null, $newId)->jwt_token === 'renewed-jwt', 'Token renewal updates only the selected settings');
locationCheck(DB::table('eis_terminal_configurations')->where('configuration_id', $terminalConfigurationId)->value('secret_key') === 'renewed-secret', 'Token renewal keeps terminal credentials consistent with settings');
locationCheck(TerminalContext::settings(1, null, 1)->jwt_token === 'token1' && TerminalContext::settings(1, null, 2)->jwt_token === 'token2', 'Token renewal leaves other terminals unchanged');
$http = new Illuminate\Http\Client\Factory(); $http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response(['data' => ['jwtToken' => 'incomplete']]));
$renewed = $activation->regenerateCredentials(1, 'renewed-jwt', $newId);
locationCheck(!$renewed['success'] && TerminalContext::settings(1, null, $newId)->jwt_token === 'renewed-jwt', 'Incomplete renewal leaves both stored credentials unchanged');
$renewed = $activation->regenerateCredentials(1, 'token2', $newId);
locationCheck(!$renewed['success'] && count(Illuminate\Support\Facades\Http::recorded()) === 1, 'Foreign renewal token fails before HTTP');

$configurationClient = new class extends Modules\EIS\Services\Configuration\EisConfigurationClient {
    public object $data;
    public int $calls = 0;
    public function __construct() {}
    public function latest(string $token, int $businessId): Modules\EIS\Services\Configuration\EISConfigurationResponse {
        ++$this->calls;
        return new Modules\EIS\Services\Configuration\EISConfigurationResponse((object) ['statusCode' => 1, 'data' => $this->data]);
    }
};
$configurationClient->data = activationResponse('B1', 'B', 2)->data->configuration;
$configurationClient->data->globalConfiguration = (object) ['versionNo' => 1];
$configurationClient->data->terminalConfiguration->versionNo = 1;
$configurationClient->data->taxpayerConfiguration->versionNo = 1;
DB::table('eis_configurations')->where('id', 2)->update([
    'global_version' => 1, 'terminal_version' => 1, 'taxpayer_version' => 1,
    'tpin' => 'TIN1', 'is_vat_registered' => 1,
    'raw_response' => json_encode(['data' => $configurationClient->data]),
]);
$configurationValidator = Mockery::mock(Modules\EIS\Services\Configuration\Validators\ConfigurationValidator::class);
$configurationValidator->shouldReceive('validate')->times(6)->andReturnNull();
$configService = new class($configurationClient, $configurationValidator)
    extends Modules\EIS\Services\Configuration\ConfigurationSyncService {
    public array $productSyncRequests = [];

    protected function dispatchProductsSyncAfterConfigurationChange(int $businessId, int $terminalId): void
    {
        $this->productSyncRequests[] = [$businessId, $terminalId];
    }
};
DB::table('eis_settings')->where('id', 7)->update(['jwt_token' => 'token2']);
locationCheck($configService->sync(1, 'token2', false, 2)->id === 2, 'Latest configuration uses explicit terminal selection even when JWTs are shared');
$otherLocations = DB::table('business_locations')->where('id', '!=', 2)->orderBy('id')->get()->toJson();
$configurationClient->data->terminalConfiguration->terminalSite->siteName = 'Renamed EIS site';
$productSyncCount = count($configService->productSyncRequests);
$renamed = $configService->sync(1, 'token2', false, 2);
locationCheck(count($configService->productSyncRequests) === $productSyncCount + 1
    && end($configService->productSyncRequests) === [1, 2],
    'Unversioned configuration changes schedule product resync for the selected terminal');
$storedResponse = $renamed->raw_response;
if (is_string($storedResponse)) { $storedResponse = json_decode($storedResponse, true, 512, JSON_THROW_ON_ERROR); }
locationCheck(data_get($storedResponse, 'data.terminalConfiguration.terminalSite.siteName') === 'Renamed EIS site',
    'Site-name-only changes refresh the stored response without an EIS version increment');
$terminalConfigurationId = DB::table('eis_terminal_configurations')->where('configuration_id', 2)->value('id');
locationCheck(DB::table('eis_terminal_sites')->where('terminal_configuration_id', $terminalConfigurationId)->value('site_name') === 'Renamed EIS site',
    'Site-name-only refresh updates normalized terminal site details');
locationCheck(DB::table('business_locations')->where('id', 2)->value('name') === 'Renamed EIS site',
    'Site-name-only refresh updates the assigned business location');
locationCheck(DB::table('business_locations')->where('id', '!=', 2)->orderBy('id')->get()->toJson() === $otherLocations,
    'Site rename preserves other locations and tenants');
 $productSyncCount = count($configService->productSyncRequests);
locationCheck($configService->sync(1, 'token2', false, 2)->id === 2
    && count($configService->productSyncRequests) === $productSyncCount,
    'Repeated unchanged configuration still skips validation and duplicate persistence');
$configurationClient->data->terminalConfiguration->terminalLabel = 'Updated till label';
$configurationClient->data->terminalConfiguration->tradingName = 'Updated trading name';
$configurationClient->data->terminalConfiguration->emailAddress = 'site@example.invalid';
$configurationClient->data->terminalConfiguration->phoneNumber = '0999000000';
$configurationClient->data->terminalConfiguration->addressLines = ['Updated EIS address'];
$configurationClient->data->terminalConfiguration->isActiveTerminal = false;
$configurationClient->data->terminalConfiguration->offlineLimit->maxTransactionAgeInHours = 12;
$configurationClient->data->terminalConfiguration->offlineLimit->maxCummulativeAmount = 4500;
$configurationClient->data->globalConfiguration->newGlobalField = 'updated';
$configurationClient->data->taxpayerConfiguration->taxOffice = (object) ['name' => 'Updated tax office'];
$refreshed = $configService->sync(1, 'token2', false, 2);
locationCheck(data_get($refreshed->raw_response, 'data.globalConfiguration.newGlobalField') === 'updated'
    && data_get($refreshed->raw_response, 'data.taxpayerConfiguration.taxOffice.name') === 'Updated tax office',
    'All global and taxpayer fields refresh at unchanged versions and remain accessible without double JSON decoding');
$terminalRow = DB::table('eis_terminal_configurations')->where('configuration_id', 2)->first();
locationCheck($terminalRow->terminal_label === 'Updated till label' && $terminalRow->trading_name === 'Updated trading name'
    && $terminalRow->email_address === 'site@example.invalid' && $terminalRow->phone_number === '0999000000'
    && json_decode($terminalRow->address_lines, true) === ['Updated EIS address'] && !$terminalRow->is_active,
    'Unversioned terminal labels, contact details, addresses and remote activity refresh');
$limitRow = DB::table('eis_offline_limits')->where('terminal_configuration_id', $terminalRow->id)->first();
locationCheck((int) $limitRow->max_transaction_age_hours === 12 && (float) $limitRow->max_cumulative_amount === 4500.0,
    'Unversioned offline limit changes refresh normalized limits');
$locationRow = DB::table('business_locations')->where('id', 2)->first();
locationCheck($locationRow->mobile === '0999000000' && $locationRow->email === 'site@example.invalid'
    && (string) $locationRow->eis_site_id === 'B', 'Location contact projection refreshes without moving its site');
locationCheck(DB::table('business_locations')->where('id', '!=', 2)->orderBy('id')->get()->toJson() === $otherLocations,
    'Full configuration refresh leaves other locations and tenants unchanged');
$configurationClient->data->terminalConfiguration->emailAddress = null;
$configurationClient->data->terminalConfiguration->addressLines = [];
$configService->sync(1, 'token2', false, 2);
locationCheck(DB::table('business_locations')->where('id', 2)->value('email') === null
    && json_decode(DB::table('eis_terminal_configurations')->where('configuration_id', 2)->value('address_lines'), true) === [],
    'Explicit EIS field clearing is detected even without version changes');
DB::table('business')->where('id', 1)->update(['name' => 'Locally edited name', 'tax_number_1' => 'stale']);
locationCheck($configService->sync(1, 'token2', false, 2)->id === 2
    && DB::table('business')->where('id', 1)->value('name') === 'Updated trading name'
    && DB::table('business')->where('id', 1)->value('tax_number_1') !== 'stale',
    'Unchanged latest configuration still restores the EIS trading name and tax number');
$schema->create('tax_rates', function (Blueprint $table) {
    $table->increments('id');
    foreach (['business_id', 'configuration_id', 'tax_rate_id', 'name', 'charge_mode', 'ordinal',
        'rate', 'amount', 'is_activated', 'activation_id'] as $column) { $table->text($column)->nullable(); }
    $table->timestamps();
});
$configurationClient->data->globalConfiguration->taxrates = [
    (object) ['id' => 'VAT', 'name' => 'Updated VAT', 'rate' => 16.5, 'chargeMode' => 'Item', 'ordinal' => 1],
];
$configurationClient->data->taxpayerConfiguration->activatedTaxRateIds = ['VAT'];
DB::table('eis_settings')->where('id', 2)->update(['status' => 0]);
$productSyncCount = count($configService->productSyncRequests);
$configService->sync(1, 'token2', false, 2);
$vat = DB::table('tax_rates')->where('configuration_id', 2)->where('tax_rate_id', 'VAT')->first();
locationCheck($vat && (float) $vat->rate === 16.5 && (bool) $vat->is_activated,
    'Global tax rate and taxpayer activation changes synchronize even at unchanged versions');
locationCheck(count($configService->productSyncRequests) === $productSyncCount + 1,
    'Changed global tax rates schedule product and price resynchronization');
$configurationClient->data->globalConfiguration->taxrates[0]->rate = 17;
$configurationClient->data->taxpayerConfiguration->activatedTaxRateIds = [];
$productSyncCount = count($configService->productSyncRequests);
$configService->sync(1, 'token2', false, 2);
$vat = DB::table('tax_rates')->where('configuration_id', 2)->where('tax_rate_id', 'VAT')->first();
locationCheck((float) $vat->rate === 17.0 && !$vat->is_activated && DB::table('tax_rates')->count() === 1,
    'Existing rate amount and activation update without duplicate rate rows');
locationCheck(count($configService->productSyncRequests) === $productSyncCount + 1,
    'Updated tax rate and activation schedule another product resync');
locationCheck(!DB::table('eis_settings')->where('id', 2)->value('status'),
    'Full configuration refresh never re-enables a locally deactivated terminal');
$schema->table('products', function (Blueprint $table) { $table->integer('tax')->nullable(); });
DB::table('tax_rates')->where('configuration_id', 2)->delete();
$legacyVat = DB::table('tax_rates')->insertGetId(['business_id' => 0, 'configuration_id' => 2, 'tax_rate_id' => 'VAT', 'rate' => 1]);
$removedRate = DB::table('tax_rates')->insertGetId(['business_id' => 0, 'configuration_id' => 2, 'tax_rate_id' => 'OLD', 'rate' => 0]);
$unusedRate = DB::table('tax_rates')->insertGetId(['business_id' => 1, 'configuration_id' => 2, 'tax_rate_id' => 'GONE', 'rate' => 0]);
$vatProduct = DB::table('products')->insertGetId(['business_id' => 1, 'tax' => $legacyVat]);
$oldProduct = DB::table('products')->insertGetId(['business_id' => 1, 'tax' => $removedRate]);
$configService->sync(1, 'token2', true, 2);
$vat = DB::table('tax_rates')->where('id', $legacyVat)->first();
locationCheck((int) $vat->business_id === 1 && (float) $vat->rate === 17.0
    && DB::table('tax_rates')->where('configuration_id', 2)->where('tax_rate_id', 'VAT')->count() === 1
    && (int) DB::table('products')->where('id', $vatProduct)->value('tax') === $legacyVat,
    'Legacy tax rate rows are updated in place instead of duplicated');
locationCheck(DB::table('tax_rates')->where('id', $removedRate)->exists()
    && (int) DB::table('products')->where('id', $oldProduct)->value('tax') === $removedRate
    && !DB::table('tax_rates')->where('id', $unusedRate)->exists(),
    'Removed tax rates still used by products are kept; unused ones are deleted');
DB::table('products')->whereIn('id', [$vatProduct, $oldProduct])->delete();
DB::table('tax_rates')->where('id', $removedRate)->delete();
DB::table('eis_settings')->where('id', 2)->update(['status' => 1]);
$configurationClient->data->terminalConfiguration->terminalSite->siteId = 'A';
$snapshot = DB::table('eis_configurations')->get()->toJson();
try {
    $configService->sync(1, 'token2', false, 2);
    throw new RuntimeException('Foreign configuration must be rejected');
} catch (Modules\EIS\Exceptions\SyncException $e) {
    locationCheck(str_contains($e->getMessage(), 'site and TIN') && DB::table('eis_configurations')->get()->toJson() === $snapshot,
        'Foreign latest-configuration site is rejected before any ownership changes');
}
DB::table('eis_settings')->where('id', 7)->update(['jwt_token' => 'token7']);

// Every account, including an administrator, is constrained by permitted locations.
$user = new class extends App\User {
    public $locations = [1];
    public function permitted_locations($business_id = null) { return $this->locations; }
};
$user->business_id = 1;
$middleware = new Modules\EIS\Http\Middleware\EnforceTerminalBusiness();
function isolationRequest(string $path, array $body = []): Request {
    global $user;
    $request = Request::create('http://localhost/'.$path, 'POST', $body);
    $request->setUserResolver(fn () => $user);
    return $request;
}
function isolationStatus(callable $work, int $status, string $message): void {
    try { $work(); } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        locationCheck($e->getStatusCode() === $status, $message); return;
    }
    throw new RuntimeException($message);
}
locationCheck($middleware->handle(isolationRequest('api/v1/terminal/deactivate', ['terminal_id' => 1]), fn () => true), 'Permitted terminal action passes middleware');
isolationStatus(fn () => $middleware->handle(isolationRequest('api/v1/terminal/deactivate', ['terminal_id' => 2]), fn () => true), 403, 'Unpermitted location terminal is forbidden');
isolationStatus(fn () => $middleware->handle(isolationRequest('api/v1/terminal/deactivate'), fn () => true), 422, 'Multi-terminal API requests require explicit selection');
isolationStatus(fn () => $middleware->handle(isolationRequest('api/v1/terminal/activate', ['location_id' => 3]), fn () => true), 403, 'Foreign business location is forbidden');
isolationStatus(fn () => $middleware->handle(isolationRequest('api/v1/terminal/activate', ['business_id' => 2]), fn () => true), 403, 'Body cannot change business ownership');
isolationStatus(fn () => $middleware->handle(isolationRequest('api/v1/terminal/deactivate', ['terminal_id' => [1]]), fn () => true), 422, 'Invalid terminal selector is rejected');
$user->locations = 'all';
locationCheck($middleware->handle(isolationRequest('api/v1/terminal/deactivate', ['terminal_id' => 2]), fn () => true), 'All-locations permission is honored within the business');
$anonymous = isolationRequest('api/v1/terminal/activate'); $anonymous->setUserResolver(fn () => null);
// Non-API session path does not consult the host application's API guard.
$anonymous = isolationRequest('eis/terminals/activate'); $anonymous->setUserResolver(fn () => null);
isolationStatus(fn () => $middleware->handle($anonymous, fn () => true), 401, 'Anonymous session management is forbidden');
$webhook = isolationRequest('api/v1/webhooks/configuration-update'); $webhook->setUserResolver(fn () => null);
locationCheck($middleware->handle($webhook, fn () => true), 'Existing machine webhook authentication contract is unchanged');

$http = new Illuminate\Http\Client\Factory(); $http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response(['ok' => true]));
$health = Mockery::mock(Modules\EIS\Services\Http\EisHealthService::class);
$httpClient = new Modules\EIS\Services\Http\EisHttpClient(new Modules\EIS\Services\Security\EisSignatureService(), $health);
$endpointSetting = new Modules\EIS\Models\EisSetting([
    'base_url' => 'https://stale.invalid', 'business_id' => 1, 'jwt_token' => 'test-token',
    'secret_key' => 'test-secret', 'tpin' => 'TIN1',
]);
$httpClient->get('/endpoint-check', $endpointSetting);
locationCheck(Illuminate\Support\Facades\Http::recorded()->last()[0]->url() === 'https://eis.invalid/endpoint-check',
    'Signed GET uses environment config rather than stored terminal endpoint');
$authSetting = Mockery::mock(Modules\EIS\Models\EisSetting::class)->makePartial();
$authSetting->setRawAttributes(['base_url' => 'https://stale.invalid', 'refresh_token' => 'test-refresh']);
$authSetting->shouldReceive('update')->once()->andReturn(true);
$http = new Illuminate\Http\Client\Factory(); $http->preventStrayRequests();
Illuminate\Support\Facades\Http::swap($http);
Illuminate\Support\Facades\Http::fake(fn () => Illuminate\Support\Facades\Http::response(['access_token' => 'refreshed']));
(new Modules\EIS\Services\Auth\EisAuthService())->refresh($authSetting);
locationCheck(Illuminate\Support\Facades\Http::recorded()->last()[0]->url() === 'https://eis.invalid/auth/refresh',
    'Credential refresh uses environment config rather than stored terminal endpoint');
$app['config']['eis.base_url'] = '';
$requestCount = count(Illuminate\Support\Facades\Http::recorded());
locationReject(fn () => new Modules\EIS\Services\Http\EisHttpClient(new Modules\EIS\Services\Security\EisSignatureService(), $health),
    'Signed client requires configured EIS endpoint');
locationReject(fn () => (new Modules\EIS\Services\Auth\EisAuthService())->refresh($authSetting),
    'Credential refresh requires configured EIS endpoint');
locationCheck(count(Illuminate\Support\Facades\Http::recorded()) === $requestCount, 'Missing endpoint never sends HTTP');
$app['config']['eis.base_url'] = 'https://eis.invalid';

$schema->table('transactions', function (Blueprint $table) {
    foreach (['type', 'status', 'payment_status', 'invoice_no', 'transaction_date'] as $column) { $table->text($column)->nullable(); }
});
DB::table('transactions')->insert([
    ['id' => 3, 'business_id' => 1, 'location_id' => 1, 'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid', 'invoice_no' => 'SALE3'],
    ['id' => 4, 'business_id' => 1, 'location_id' => 2, 'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid', 'invoice_no' => 'SALE4'],
]);
$select = new ReflectionMethod(Modules\EIS\Jobs\DispatchAllUnsubmittedSalesJob::class, 'getUnsubmittedTransactionIds');
$select->setAccessible(true);
locationCheck($select->invoke(new Modules\EIS\Jobs\DispatchAllUnsubmittedSalesJob(1, 50, null, null, [1])) === [3], 'User bulk submission is restricted to permitted locations');
locationCheck($select->invoke(new Modules\EIS\Jobs\DispatchAllUnsubmittedSalesJob(1, 50, null, null, [])) === [], 'No-location users cannot queue another location sales');
locationCheck($select->invoke(new Modules\EIS\Jobs\DispatchAllUnsubmittedSalesJob(1)) === [3, 4], 'System dispatcher retains business-wide processing');
$compiler = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), sys_get_temp_dir());
token_get_all($compiler->compileString(file_get_contents(dirname(__DIR__, 2).'\resources\views\layouts\partials\header.blade.php')), TOKEN_PARSE);
locationCheck(true, 'Multi-terminal header Blade compiles');
Mockery::close();
echo "PASS: {$checks} cumulative terminal isolation checks (SQLite, no network).\n";
