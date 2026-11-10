<?php

require __DIR__.'\eis_stock_outbound_checks.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\EIS\Services\Utilities\EisUtilitiesClient;
use Modules\EIS\Services\Utilities\UtilityOperations;
use Modules\EIS\Services\Utilities\UtilityRequestService;
use Modules\EIS\Services\Utilities\VoidReceiptSynchronizer;

$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$app->instance('translator', $translator);
$app->instance('validator', new Illuminate\Validation\Factory($translator, $app));
$migration = require dirname(__DIR__, 2).'\Modules\EIS\Database\Migrations\2026_10_24_020000_create_eis_utility_requests.php';
$migration->up();
$setting = Modules\EIS\Models\EisSetting::findOrFail(1);
Http::swap(new Illuminate\Http\Client\Factory());
Http::preventStrayRequests();
Http::fake();
$service = new UtilityRequestService(new EisUtilitiesClient());
$checks = 0;
function disabledCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    ++$checks;
}
function disabledReject(callable $work): void
{
    try { $work(); }
    catch (ValidationException $e) {
        disabledCheck(str_contains($e->errors()['operation'][0], 'disabled'), 'Explicit disabled-operation error');
        return;
    }
    throw new RuntimeException('Disabled EIS void operation was allowed');
}
foreach (['cancel-receipt', 'get-void-receipts'] as $operation) {
    disabledCheck(!array_key_exists($operation, UtilityOperations::definitions()), 'Disabled operation not advertised');
    disabledReject(fn () => UtilityOperations::path($operation));
    disabledReject(fn () => UtilityOperations::payload($setting, $operation, []));
    disabledReject(fn () => (new EisUtilitiesClient())->send($setting, $operation, []));
}
disabledReject(fn () => $service->prepare($setting, 'cancel-receipt', ['receiptNumber' => 'OLD', 'reason' => 'Test'], 5));
disabledCheck(DB::table('eis_utility_requests')->count() === 0, 'No cancellation request prepared');
$id = DB::table('eis_utility_requests')->insertGetId([
    'business_id' => $setting->business_id, 'location_id' => $setting->location_id,
    'eis_setting_id' => $setting->id, 'created_by' => 5, 'operation' => 'cancel-receipt',
    'status' => 'prepared', 'endpoint' => 'https://eis.invalid/api/v1',
    'tin' => 'TEST', 'site_id' => 'SITE-A', 'device_id' => 'TERM-A',
    'identity' => str_repeat('a', 64), 'payload' => '{"receiptNumber":"OLD","reason":"Test"}',
    'created_at' => now(), 'updated_at' => now(),
]);
$before = DB::table('eis_utility_requests')->find($id);
disabledReject(fn () => $service->submit($setting, $id, 5));
disabledCheck(DB::table('eis_utility_requests')->find($id) == $before, 'Historical request unchanged and not claimed');
disabledReject(fn () => (new VoidReceiptSynchronizer())->sync($setting, []));
Http::assertNothingSent();
disabledCheck(UtilityOperations::path('ping') === 'utilities/ping', 'Other utilities remain available');
$root = dirname(__DIR__, 2);
foreach (['sell\index.blade.php', 'sale_pos\index.blade.php'] as $file) {
    $source = file_get_contents($root.'\resources\views\\'.$file);
    disabledCheck(!str_contains($source, 'partial'.'s.eis_cancel_receipt')
        && !str_contains($source, 'partial'.'s.eis_void_tools')
        && !str_contains($source, 'eis_void_status'), 'Sales page has no void controls or columns');
}
$source = file_get_contents($root.'\Modules\EIS\Services\Sales\SaleAdjustmentService.php');
disabledCheck(str_contains($source, 'Full fiscal returns are unavailable')
    && !str_contains($source, "->prepare(\$setting, 'cancel-receipt'")
    && !str_contains($source, 'DB::afterCommit('), 'Full returns cannot enqueue or send cancellation');
$start = strpos($source, '        $isFullReturn =');
$end = strpos($source, '        if (!$last', $start);
$fullReturnGuard = substr($source, $start, $end - $start);
$lines = [['quantity' => 2, 'quantity_returned' => 2]];
$sale = (object) ['final_total' => 100];
$return = (object) ['final_total' => 100];
try {
    DB::transaction(function () use ($fullReturnGuard, $lines, $sale, $return) {
        DB::table('eis_utility_requests')->where('id', 1)->update(['status' => 'changed']);
        eval($fullReturnGuard);
    });
    throw new RuntimeException('Full fiscal return guard did not block');
} catch (InvalidArgumentException $exception) {
    disabledCheck(str_contains($exception->getMessage(), 'disabled'), 'Full return reports disabled cancellation');
    disabledCheck(DB::table('eis_utility_requests')->find($id) == $before, 'Full-return failure rolls back the outer transaction');
}
$return->final_total = 50;
$lines[0]['quantity_returned'] = 1;
eval($fullReturnGuard);
disabledCheck(!$isFullReturn, 'Partial returns pass the full-return cancellation guard');
$controller = file_get_contents($root.'\app\Http\Controllers\SellController.php');
disabledCheck(!str_contains($controller, 'class="eis-cancel-receipt"')
    && !str_contains($controller, 'class="eis-void-sync"'), 'Sale actions exclude void features');
echo "PASS: {$checks} disabled EIS void checks (SQLite, no network)\n";
