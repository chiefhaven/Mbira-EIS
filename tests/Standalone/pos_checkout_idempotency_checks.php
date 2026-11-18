<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\Sales\PosCheckoutIdempotency;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$db = $capsule->getConnection();
$db->getSchemaBuilder()->create('pos_checkout_operations', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('business_id');
    $table->integer('user_id');
    $table->string('operation_id');
    $table->string('payload_hash');
    $table->integer('transaction_id')->nullable();
    $table->timestamps();
    $table->unique(['business_id', 'operation_id']);
});

function checkoutCheck($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = new PosCheckoutIdempotency();
$operationId = (string) Illuminate\Support\Str::uuid();
$payload = ['status' => 'final', 'products' => [['variation_id' => 3, 'quantity' => '2']]];
$db->beginTransaction();
$claim = $service->claim($db, 1, 7, $operationId, $payload);
checkoutCheck($claim['replay'] === false, 'First checkout request claims its operation key');
$service->complete($db, 1, $operationId, 42);
$db->commit();

$replay = $service->lookup($db, 1, 7, $operationId, [
    'products' => [['variation_id' => 3, 'quantity' => '2']],
    'status' => 'final',
]);
checkoutCheck(!empty($replay['replay']) && $replay['transaction_id'] === 42,
    'Same key and payload resolve to the original sale');
$db->beginTransaction();
$claimedReplay = $service->claim($db, 1, 7, $operationId, $payload);
$db->rollBack();
checkoutCheck(!empty($claimedReplay['replay']) && $claimedReplay['transaction_id'] === 42,
    'Transactional retry replays the committed sale instead of creating another');
checkoutCheck(!empty($service->lookup($db, 1, 7, $operationId, [
    'status' => 'final',
    'products' => [['variation_id' => 3, 'quantity' => '3']],
])['conflict']), 'Same key cannot be reused with changed sale details');
checkoutCheck(!empty($service->lookup($db, 1, 8, $operationId, $payload)['conflict']),
    'Operation key cannot be replayed by a different user');

$rolledBackOperation = (string) Illuminate\Support\Str::uuid();
$db->beginTransaction();
$service->claim($db, 1, 7, $rolledBackOperation, $payload);
$db->rollBack();
checkoutCheck($service->lookup($db, 1, 7, $rolledBackOperation, $payload) === null,
    'Rolled-back checkout releases the operation key for a safe retry');

echo "POS checkout idempotency checks passed\n";
