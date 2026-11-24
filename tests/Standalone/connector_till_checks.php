<?php

// Isolated SQLite/API fixtures; never boots the application or reads .env.
require __DIR__.'\eis_terminal_isolation_checks.php';

use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Connector\Http\Controllers\Api\TillController;
use Modules\Connector\Http\Middleware\ConnectorTill;
use Modules\Connector\Support\TillAssignment;
use Modules\EIS\Services\Terminal\TerminalContext;

if (!class_exists('Log')) {
    class_alias(Illuminate\Support\Facades\Log::class, 'Log');
}

$migration = require dirname(__DIR__, 2).'\Modules\Connector\Database\Migrations\2026_10_23_200000_create_connector_tills.php';
$migration->up();
$app['config']['eis.environment'] = 'production';
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$validator = new Illuminate\Validation\Factory($translator, $app);
$app->instance('validator', $validator);
Request::macro('validate', function ($rules) use ($validator) {
    return $validator->make($this->all(), $rules)->validate();
});
$responses = Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new Illuminate\Http\JsonResponse($data, $status));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $responses);
$actor = new class {
    public int $id = 1;
    public int $business_id = 1;
    public bool $manage = true;
    public $locations = [1];
    public function can($permission) { return $this->manage && $permission === 'business_settings.access'; }
    public function permitted_locations($businessId) { return $businessId === $this->business_id ? $this->locations : []; }
};
function tillRequest(array $input = [], ?string $header = null): Request
{
    global $actor;
    $request = Request::create('/connector/api/tills', 'POST', $input);
    $request->setUserResolver(fn () => $actor);
    if ($header !== null) { $request->headers->set('X-POS-Till', $header); }
    return $request;
}
function tillReject(callable $work, string $message): void
{
    try { $work(); }
    catch (ValidationException | Illuminate\Database\Eloquent\ModelNotFoundException | Symfony\Component\HttpKernel\Exception\HttpException $e) {
        locationCheck(true, $message);
        return;
    }
    throw new RuntimeException($message.' was accepted');
}
$controller = new TillController();
$till = $controller->store(tillRequest(['name' => 'Front till', 'location_id' => 1, 'eis_setting_id' => 1]))->getData(true)['data'];
$tillId = $till['id'];
locationCheck($till['name'] === 'Front till' && (int) $till['eis_setting_id'] === 1, 'Named till links to its own location terminal');
$controller->store(tillRequest(['name' => 'Front till', 'location_id' => 1, 'eis_setting_id' => 1]));
locationCheck(DB::table('connector_tills')->count() === 1, 'Saving same named till updates rather than duplicates it');
tillReject(fn () => $controller->store(tillRequest(['name' => 'Wrong', 'location_id' => 1, 'eis_setting_id' => 2])),
    'Till cannot reference a terminal at another site');
tillReject(fn () => $controller->store(tillRequest(['name' => 'Wrong', 'location_id' => 2, 'eis_setting_id' => 2])),
    'Admin location permissions are enforced for till creation');
tillReject(fn () => $controller->store(tillRequest(['name' => '   ', 'location_id' => 1, 'eis_setting_id' => 1])),
    'Blank till names fail explicitly');
$actor->manage = false;
tillReject(fn () => $controller->store(tillRequest(['name' => 'Cashier', 'location_id' => 1, 'eis_setting_id' => 1])),
    'Cashiers cannot create or reassign named tills');
locationCheck(count($controller->index(tillRequest())->getData(true)['data']['tills']) === 1,
    'Cashier can resolve permitted existing tills without settings permission');
$originalConfiguration = DB::table('eis_configurations')->where('eis_setting_id', 1)->first();
$originalBusinessVatRegistration = DB::table('business')->where('id', 1)->value('is_vat_registered');
DB::table('business')->where('id', 1)->update(['is_vat_registered' => true]);
DB::table('eis_configurations')->where('eis_setting_id', 1)->update([
    'is_vat_registered' => false,
    'tax_office_code' => 'OFFICE-1',
    'raw_response' => json_encode(['data' => ['taxpayerConfiguration' => [
        'isVATRegistered' => true, 'taxOffice' => ['name' => ' Central office '],
    ]]]),
]);
$terminal = collect($controller->index(tillRequest())->getData(true)['data']['terminals'])->firstWhere('id', 1);
locationCheck($terminal['tax_office'] === 'Central office' && $terminal['is_vat_registered'] === true,
    'Terminal response uses the business VAT status instead of configuration status');
DB::table('business')->where('id', 1)->update(['is_vat_registered' => false]);
DB::table('eis_configurations')->where('eis_setting_id', 1)->update([
    'is_vat_registered' => true,
    'raw_response' => json_encode(['data' => ['taxpayerConfiguration' => [
        'isVATRegistered' => false, 'taxOffice' => ['name' => ' '],
    ]]]),
]);
$terminal = collect($controller->index(tillRequest())->getData(true)['data']['terminals'])->firstWhere('id', 1);
locationCheck($terminal['tax_office'] === 'OFFICE-1' && $terminal['is_vat_registered'] === false,
    'Terminal response continues to use business VAT status when configuration differs');
DB::table('eis_configurations')->where('eis_setting_id', 1)->update([
    'is_vat_registered' => $originalConfiguration->is_vat_registered,
    'tax_office_code' => $originalConfiguration->tax_office_code,
    'raw_response' => $originalConfiguration->raw_response,
]);
DB::table('business')->where('id', 1)->update(['is_vat_registered' => $originalBusinessVatRegistration]);
$middleware = new ConnectorTill();
$request = tillRequest(['sells' => [['location_id' => 1]]], (string) $tillId);
locationCheck($middleware->handle($request, fn ($r) => $r->attributes->get('connector_till_id')) === $tillId,
    'Connector resolves header to server-owned till context');
foreach (['0', '-1', 'bad', (string) ($tillId + 100)] as $bad) {
    tillReject(fn () => $middleware->handle(tillRequest([], $bad), fn () => true), 'Invalid till header rejected: '.$bad);
}
tillReject(fn () => $middleware->handle(tillRequest(['sells' => [['location_id' => 2]]], (string) $tillId), fn () => true),
    'Bulk sale location must match the till');
$otherSiteRequest = Request::create('/connector/api/restaurant/retry-fiscal/4', 'POST');
$otherSiteRequest->setUserResolver(fn () => $actor);
$otherSiteRequest->headers->set('X-POS-Till', (string) $tillId);
$route = new Illuminate\Routing\Route('POST', 'connector/api/restaurant/retry-fiscal/{id}', fn () => null);
$route->bind($otherSiteRequest);
$otherSiteRequest->setRouteResolver(fn () => $route);
tillReject(fn () => $middleware->handle($otherSiteRequest, fn () => true),
    'Even fiscal retry must target a sale at the selected device till location');
$actor->locations = [2];
tillReject(fn () => TillAssignment::validate($actor, $tillId), 'Cashier cannot use a till at an unpermitted location');
$actor->locations = 'all';
$actor->business_id = 2;
tillReject(fn () => TillAssignment::validate($actor, $tillId), 'Another business cannot use the till');
$actor->business_id = 1;
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => null]);
DB::transaction(fn () => TillAssignment::bind($actor, 3, $tillId));
$sale = App\Transaction::findOrFail(3);
locationCheck((int) $sale->connector_till_id === $tillId && TerminalContext::forSale($sale)->id === 1,
    'Assigned till routes a new sale without requiring a location default');
$schema->table('transactions', function (Blueprint $table) {
    $table->decimal('final_total', 18, 4)->default(0);
});
$limits = Modules\EIS\Models\EisSale::checkOfflineLimits(1, 10, 1, $tillId);
locationCheck($limits['success'] && $limits['code'] !== 'CHECK_ERROR',
    'Offline-limit check uses assigned till rather than requiring the missing location default');
$settings = Modules\EIS\Models\EisSetting::findOrFail(1);
$configuration = Modules\EIS\Models\EisConfiguration::where('eis_setting_id', 1)->firstOrFail();
$terminalConfigurationId = DB::table('eis_terminal_configurations')
    ->where('configuration_id', $configuration->id)->value('id');
$terminalConfiguration = DB::table('eis_terminal_configurations')->where('id', $terminalConfigurationId)->first();
$invoiceGenerator = new Modules\EIS\Services\Sales\InvoiceNumberGenerator();
$highestCount = $invoiceGenerator->getDailyCount(1, (int) $settings->id);
$gapCount = $highestCount + 7;
$connectorInvoice = $invoiceGenerator->generateInvoiceNumber(
    1,
    $gapCount,
    (int) $terminalConfiguration->terminal_position,
    (int) $terminalConfiguration->taxpayer_id,
    (int) $settings->id
);
DB::table('eis_sales')->insert([
    'business_id' => 1,
    'transaction_id' => 999998,
    'eis_setting_id' => $settings->id,
    'location_id' => 1,
    'eis_invoice_number' => $connectorInvoice,
    'status' => 'failed',
    'created_at' => now(),
    'updated_at' => now(),
]);
locationCheck($invoiceGenerator->getNextDailyCount(1, (int) TerminalContext::forSale($sale)->id) === $gapCount + 1,
    'Connector till sale uses the decoded highest EIS invoice sequence plus one');
DB::table('eis_sales')->where('transaction_id', 999998)->delete();
$originalOfflineLimit = DB::table('eis_offline_limits')
    ->where('terminal_configuration_id', $terminalConfigurationId)->first();
DB::table('eis_offline_limits')->updateOrInsert(
    ['terminal_configuration_id' => $terminalConfigurationId],
    ['max_cumulative_amount' => 1, 'max_transaction_age_hours' => 0]
);
$health = Mockery::mock(\Modules\EIS\Services\Http\EisHealthService::class);
$health->shouldReceive('isOnline')->andReturn(false);
$app->instance(\Modules\EIS\Services\Http\EisHealthService::class, $health);
$aboveCumulativeLimit = Modules\EIS\Models\EisSale::checkOfflineLimits(1, 1000, 1, $tillId);
locationCheck($aboveCumulativeLimit['success'] && $aboveCumulativeLimit['code'] !== 'CHECK_ERROR',
    'Offline sale is allowed above the configured cumulative amount');

DB::table('eis_offline_limits')->where('terminal_configuration_id', $terminalConfigurationId)->update([
    'max_cumulative_amount' => 0,
    'max_transaction_age_hours' => 1,
]);
$oldPendingSaleId = DB::table('eis_sales')->insertGetId([
    'business_id' => 1,
    'transaction_id' => 999999,
    'eis_setting_id' => 1,
    'location_id' => 1,
    'status' => 'pending',
    'created_at' => now()->subHours(2),
    'updated_at' => now()->subHours(2),
]);
$agedSaleResult = Modules\EIS\Models\EisSale::checkOfflineLimits(1, 10, 1, $tillId);
locationCheck(!$agedSaleResult['success'] && $agedSaleResult['code'] === 'TRANSACTION_AGE_EXCEEDED',
    'Transaction-age limit continues to block sales with overdue unsubmitted EIS sales');
DB::table('eis_sales')->where('id', $oldPendingSaleId)->delete();
if ($originalOfflineLimit) {
    DB::table('eis_offline_limits')->where('terminal_configuration_id', $terminalConfigurationId)->update([
        'max_cumulative_amount' => $originalOfflineLimit->max_cumulative_amount,
        'max_transaction_age_hours' => $originalOfflineLimit->max_transaction_age_hours,
    ]);
} else {
    DB::table('eis_offline_limits')->where('terminal_configuration_id', $terminalConfigurationId)->delete();
}
$app->forgetInstance(\Modules\EIS\Services\Http\EisHealthService::class);
DB::table('eis_terminal_configurations')->where('configuration_id', $configuration->id)->update(['is_active' => false]);
$app['config']['eis.environment'] = 'development';
locationCheck(TerminalContext::forSale($sale)->id === 1, 'Development till accepts locally enabled remotely inactive terminal');
$app['config']['eis.environment'] = 'production';
locationReject(fn () => TerminalContext::forSale($sale), 'Unavailable terminal without usable default blocks submission explicitly');
DB::table('eis_settings')->where('id', $newId)->update(['status' => true]);
DB::table('eis_terminal_configurations')->where('configuration_id', $selectedConfiguration->id)->update(['is_active' => true]);
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => $newId]);
locationCheck(TerminalContext::forSale($sale)->id === $newId, 'Unavailable linked terminal falls back to same location default');
DB::table('eis_sales')->insert(['business_id' => 1, 'transaction_id' => 3, 'location_id' => 1, 'eis_setting_id' => $newId, 'status' => 'pending']);
DB::table('eis_terminal_configurations')->where('configuration_id', $configuration->id)->update(['is_active' => true]);
DB::table('business_locations')->where('id', 1)->update(['default_eis_setting_id' => 1]);
locationCheck(TerminalContext::forSale($sale)->id === $newId, 'Retry stays on recorded fallback terminal after linked terminal recovers');
DB::table('eis_settings')->where('id', $newId)->update(['status' => false]);
locationReject(fn () => TerminalContext::forSale($sale), 'Recorded inactive retry never falls back to a different terminal');
DB::beginTransaction();
tillReject(fn () => TillAssignment::bind($actor, 4, $tillId), 'Saved checkout cannot bind a till from another location');
DB::rollBack();
DB::table('connector_tills')->where('id', $tillId)->update(['is_active' => false]);
tillReject(fn () => $middleware->handle(tillRequest([], (string) $tillId), fn () => true), 'Locally disabled till is not accepted from a device');
DB::table('connector_tills')->where('id', $tillId)->update(['is_active' => true]);
DB::beginTransaction();
TillAssignment::bind($actor, 1, $tillId);
locationCheck(DB::table('transactions')->where('id', 1)->value('connector_till_id') === null, 'Binding cannot rewrite an already fiscal-tracked sale');
DB::rollBack();
$migration->down();
locationCheck(!$schema->hasTable('connector_tills') && !$schema->hasColumn('transactions', 'connector_till_id'), 'Till migration rolls back its own schema only');
Mockery::close();
echo "PASS: {$checks} cumulative Connector till checks (SQLite, no network).\n";
