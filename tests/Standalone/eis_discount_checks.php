<?php

require dirname(__DIR__, 2).'\vendor\autoload.php';

use App\Transaction;
use App\TransactionSellLine;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use Modules\EIS\Models\EisSetting;
use Modules\EIS\Models\EisTaxRate;
use Modules\EIS\Services\Http\EisSaleClient;
use Modules\EIS\Services\Sales\InvoiceNumberGenerator;
use Modules\EIS\Services\Sales\OfflineSignatureService;
use Modules\EIS\Services\Sales\SaleTransformer;

$app = new Container();
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('log', new Psr\Log\NullLogger());
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$capsule->schema()->create('tax_rates', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('business_id');
    $table->string('tax_rate_id');
    $table->decimal('rate', 8, 3);
});
$capsule->table('tax_rates')->insert([
    ['id' => 1, 'business_id' => 6, 'tax_rate_id' => 'A', 'rate' => 17.5],
    ['id' => 2, 'business_id' => 6, 'tax_rate_id' => 'Z', 'rate' => 0],
    ['id' => 3, 'business_id' => 7, 'tax_rate_id' => 'A', 'rate' => 50],
]);
$transformer = new SaleTransformer(new EisTaxRate(), new OfflineSignatureService(), new InvoiceNumberGenerator());
$settings = new EisSetting(['business_id' => 6, 'tpin' => 'FIXTURE', 'branch_id' => 'FIXTURE-SITE']);
$checks = 0;
function discountCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function discountPayload(SaleTransformer $transformer, EisSetting $settings, array $attributes, float $total): array
{
    $lines = [];
    foreach ($attributes as $index => $values) {
        $line = new TransactionSellLine();
        $line->setRawAttributes(['id' => $index + 1, 'product_id' => $index + 1] + $values);
        $line->exists = true;
        $line->setRelation('product', null)->setRelation('variations', null)->setRelation('modifiers', collect());
        $lines[] = $line;
    }
    $transaction = new Transaction();
    $transaction->setRawAttributes(['id' => 1, 'business_id' => 6, 'transaction_date' => '2026-11-08 10:00:00', 'final_total' => $total]);
    $transaction->setRelation('business', null)->setRelation('location', null)->setRelation('contact', null)
        ->setRelation('sell_lines', new Illuminate\Database\Eloquent\Collection($lines));
    $before = array_map(fn ($line) => $line->getAttributes(), $lines);
    $payload = $transformer->transform($transaction, $settings, 'FIXTURE-1');
    discountCheck($before === array_map(fn ($line) => $line->getAttributes(), $lines), 'Transformation must not mutate saved POS amounts');
    discountCheck(isset($payload['invoiceSummary']['amountTendered'])
        && $payload['invoiceSummary']['amountTendered'] === round($total, 2),
        'Every sale summary must include numeric amountTendered equal to the paid final total');
    return $payload;
}
$first = ['quantity' => 1, 'unit_price_before_discount' => 851.06, 'unit_price' => 751.06,
    'unit_price_inc_tax' => 900, 'item_tax' => 148.94, 'line_discount_type' => 'fixed', 'line_discount_amount' => 100, 'tax_id' => 1];
$second = ['quantity' => 1, 'unit_price_before_discount' => 7234.04, 'unit_price' => 6234.04,
    'unit_price_inc_tax' => 7500, 'item_tax' => 1265.96, 'line_discount_type' => 'fixed', 'line_discount_amount' => 1000, 'tax_id' => 1];
$payload = discountPayload($transformer, $settings, [$first, $second], 8400);
discountCheck(array_column($payload['invoiceLineItems'], 'unitPrice') === [1000.0, 8500.0], 'EIS unit prices must precede the line discount');
discountCheck(array_column($payload['invoiceLineItems'], 'total') === [900.0, 7500.0], 'Discount must be applied exactly once');
discountCheck(array_column($payload['invoiceLineItems'], 'totalVAT') === [134.04, 1117.02], 'Discounted VAT must use the owning business rate');
discountCheck($payload['invoiceSummary']['taxBreakDown'] === [
    ['rateId' => 'A', 'taxableAmount' => 7148.94, 'taxAmount' => 1251.06],
], 'Tax breakdown must match discounted line amounts');

foreach (['fixed', 'percentage'] as $type) {
    foreach ([1, 3, 1.5] as $quantity) {
        $line = ['quantity' => $quantity, 'unit_price_before_discount' => 100, 'unit_price' => 90,
            'unit_price_inc_tax' => 107.5, 'item_tax' => 17.5, 'line_discount_type' => $type, 'line_discount_amount' => 10, 'tax_id' => 1];
        $payload = discountPayload($transformer, $settings, [$line], round(107.5 * $quantity, 2));
        $item = $payload['invoiceLineItems'][0];
        discountCheck($item['discount'] === round(10 * $quantity, 2), 'Fixed and percentage discounts must be monetary line totals');
        discountCheck($item['total'] === round($item['unitPrice'] * $item['quantity'] - $item['discount'], 2), 'Serialized line arithmetic must reconcile');
        discountCheck($payload['invoiceSummary']['totalVAT'] === $item['totalVAT'], 'Summary VAT must equal line VAT');
        discountCheck($payload['invoiceSummary']['invoiceTotal'] === $item['total'], 'Summary total must equal line total');
    }
}
$zero = $first;
$zero['tax_id'] = 2;
$zero['item_tax'] = 0;
$payload = discountPayload($transformer, $settings, [$first, $zero], 1800);
discountCheck($payload['invoiceLineItems'][1]['totalVAT'] === 0.0
    && count($payload['invoiceSummary']['taxBreakDown']) === 2, 'Mixed-rate discounted lines must remain separate, including zero rates');
$plain = $first;
$plain['line_discount_amount'] = 0;
$plain['unit_price_inc_tax'] = 1000;
$payload = discountPayload($transformer, $settings, [$plain], 1000);
discountCheck($payload['invoiceLineItems'][0]['totalVAT'] === 148.94
    && $payload['invoiceLineItems'][0]['discount'] === 0.0, 'Non-discounted lines must preserve stored VAT');
$percentage = $first;
$percentage['line_discount_type'] = 'percentage';
$percentage['line_discount_amount'] = 20;
$percentage['unit_price_inc_tax'] = 829.788;
$percentage['quantity'] = 2;
$payload = discountPayload($transformer, $settings, [$percentage], 1659.58);
discountCheck($payload['invoiceLineItems'][0]['discount'] === 340.42,
    'Percentage discount must use the saved pre-discount price, not the percentage value as money');
$capsule->table('tax_rates')->where('id', 1)->delete();
try {
    discountPayload($transformer, $settings, [$first], 900);
    throw new RuntimeException('Missing business rate must not silently use another business rate');
} catch (InvalidArgumentException $exception) {
    discountCheck(str_contains($exception->getMessage(), 'valid EIS tax rate'), 'Missing rates must surface an explicit fiscal validation error');
}

$client = (new ReflectionClass(EisSaleClient::class))->newInstanceWithoutConstructor();
$parse = new ReflectionMethod(EisSaleClient::class, 'parseErrorMessage');
$response = new Illuminate\Http\Client\Response(new GuzzleHttp\Psr7\Response(400, [], json_encode([
    'errors' => ['' => ['Tax breakdown entries do not match computed line item taxable amounts or tax amounts.']],
])));
discountCheck($parse->invoke($client, $response) === 'Tax breakdown entries do not match computed line item taxable amounts or tax amounts.',
    'Field-keyed EIS errors must not be replaced with generic HTTP errors');

$app->instance('config', new Illuminate\Config\Repository(['eis' => ['base_url' => 'https://eis.invalid/api/v1']]));
$access = Mockery::mock(Modules\EIS\Services\EisSubscriptionAccess::class);
$access->shouldReceive('assertAllowed')->with(6)->andReturnNull();
$app->instance(Modules\EIS\Services\EisSubscriptionAccess::class, $access);
$health = Mockery::mock(Modules\EIS\Services\Http\EisHealthService::class);
$health->shouldReceive('isOnline')->with(6, 'fixture-token')->andReturnTrue();
$http = new Illuminate\Http\Client\Factory();
Illuminate\Support\Facades\Http::swap($http);
$http->preventStrayRequests();
$http->fake(['https://eis.invalid/api/v1/sales/submit-sales-transaction' => $http->response(['statusCode' => 1], 200)]);
$settings->jwt_token = 'fixture-token';
$settings->secret_key = 'fixture-secret';
$httpClient = new EisSaleClient($health);
$result = $httpClient->submit($payload, $settings);
discountCheck($result['success'] === true, 'The real HTTP submission client must send the sale successfully');
Illuminate\Support\Facades\Http::assertSent(function (Illuminate\Http\Client\Request $request) use ($payload) {
    $json = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
    return $request->url() === 'https://eis.invalid/api/v1/sales/submit-sales-transaction'
        && array_key_exists('amountTendered', $json['invoiceSummary'])
        && is_numeric($json['invoiceSummary']['amountTendered'])
        && $json['invoiceSummary']['amountTendered'] == $payload['invoiceSummary']['invoiceTotal'];
});
discountCheck(true, 'Serialized outgoing HTTP JSON must retain invoiceSummary.amountTendered');
Mockery::close();

echo 'EIS discount checks passed: '.$checks." checks\n";
