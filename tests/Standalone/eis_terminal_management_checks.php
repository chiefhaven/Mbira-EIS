<?php

// Isolated SQLite fixture: no application boot, .env, persistent database, or EIS HTTP.
require dirname(__DIR__, 2).'\vendor\autoload.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Modules\EIS\Http\Controllers\TerminalManagementController;
use Modules\EIS\Services\Terminal\EisTerminalActivationService;
use Symfony\Component\HttpKernel\Exception\HttpException;

$app = new class extends Container {
    public function abort($code, $message = '', array $headers = [])
    {
        throw new HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);
$app->instance('config', new Illuminate\Config\Repository(['eis' => ['environment' => 'production']]));
$capsule = new Capsule($app);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
Facade::setFacadeApplication($app);
Facade::clearResolvedInstances();
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$validator = new Illuminate\Validation\Factory($translator, $app);
Request::macro('validate', function ($rules) use ($validator) {
    return $validator->make($this->all(), $rules)->validate();
});
$responses = Mockery::mock(Illuminate\Contracts\Routing\ResponseFactory::class);
$responses->shouldReceive('json')->andReturnUsing(fn ($data, $status = 200) => new JsonResponse($data, $status));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class, $responses);
$schema = $capsule->getConnection()->getSchemaBuilder();
foreach ([
    'business_locations' => ['business_id', 'name', 'default_eis_setting_id'],
    'eis_settings' => ['business_id', 'location_id', 'status', 'jwt_token', 'secret_key', 'device_id'],
    'eis_configurations' => ['business_id', 'eis_setting_id', 'location_id', 'raw_response', 'last_synced_at'],
    'eis_terminal_configurations' => ['configuration_id', 'terminal_label', 'is_active'],
] as $name => $columns) {
    $schema->create($name, function (Blueprint $table) use ($name, $columns) {
        $table->increments('id');
        foreach ($columns as $column) {
            $table->text($column)->nullable();
        }
        $table->timestamps();
        if (str_starts_with($name, 'eis_')) {
            $table->softDeletes();
        }
    });
}
$schema->create('tax_rates', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('configuration_id');
    $table->string('tax_rate_id');
    $table->decimal('rate', 10, 3)->default(0);
    $table->string('charge_mode')->default('Item');
    $table->softDeletes();
});
$schema->create('business', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('default_sales_tax')->nullable();
});
$schema->create('products', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('business_id');
    $table->integer('tax')->nullable();
    $table->foreign('tax')->references('id')->on('tax_rates');
});
$schema->create('transactions', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('business_id')->nullable();
    $table->integer('location_id')->nullable();
    $table->string('type')->nullable();
    $table->string('status')->nullable();
    $table->integer('tax_id')->nullable();
    $table->foreign('tax_id')->references('id')->on('tax_rates');
});
$schema->create('eis_sales', function (Blueprint $table) {
    $table->increments('id');
    $table->integer('business_id');
    $table->integer('transaction_id');
    $table->string('status');
});
$db = $capsule->getConnection();
$db->table('business_locations')->insert([
    ['id' => 10, 'business_id' => 1, 'name' => 'Allowed', 'default_eis_setting_id' => 101],
    ['id' => 11, 'business_id' => 1, 'name' => 'Restricted', 'default_eis_setting_id' => 103],
    ['id' => 20, 'business_id' => 2, 'name' => 'Other business', 'default_eis_setting_id' => 201],
]);
foreach ([[101, 1, 10, 1], [102, 1, 10, 0], [103, 1, 11, 1], [104, 1, null, 1], [201, 2, 20, 1]] as [$id, $business, $location, $active]) {
    $db->table('eis_settings')->insert([
        'id' => $id, 'business_id' => $business, 'location_id' => $location, 'status' => $active,
        'jwt_token' => 'fixture-token-'.$id, 'secret_key' => 'fixture-secret-'.$id, 'device_id' => 'DEVICE-'.$id,
    ]);
    $db->table('eis_configurations')->insert([
        'id' => $id + 1000, 'business_id' => $business, 'location_id' => $location, 'eis_setting_id' => $id,
        'raw_response' => json_encode(['data' => [
            'globalConfiguration' => ['versionNo' => 1, 'taxrates' => []],
            'terminalConfiguration' => ['versionNo' => 2, 'terminalLabel' => 'Label '.$id, 'jwtToken' => 'never-expose',
                'terminalSite' => ['siteId' => 'SITE-'.$id, 'siteName' => 'Site '.$id, 'secretKey' => 'nested-secret']],
            'taxpayerConfiguration' => ['versionNo' => 3, 'tin' => '12345678'],
        ]]),
    ]);
    $db->table('eis_terminal_configurations')->insert([
        'id' => $id + 2000, 'configuration_id' => $id + 1000,
        'terminal_label' => 'Label '.$id, 'is_active' => $active,
    ]);
}
$db->table('tax_rates')->insert([
    ['id' => 1, 'configuration_id' => 1101, 'tax_rate_id' => 'A'],
    ['id' => 2, 'configuration_id' => 1102, 'tax_rate_id' => 'A'],
    ['id' => 3, 'configuration_id' => 1103, 'tax_rate_id' => 'A'],
    ['id' => 4, 'configuration_id' => 1201, 'tax_rate_id' => 'A'],
    ['id' => 5, 'configuration_id' => 1102, 'tax_rate_id' => 'B'],
]);
$db->table('business')->insert(['id' => 1, 'default_sales_tax' => 2]);
$db->table('products')->insert([
    ['id' => 1, 'business_id' => 1, 'tax' => 2],
    ['id' => 2, 'business_id' => 1, 'tax' => 5],
    ['id' => 3, 'business_id' => 1, 'tax' => 1],
]);
$db->table('transactions')->insert(['id' => 1, 'tax_id' => 2]);
$user = new class {
    public int $id = 7;
    public int $business_id = 1;
    public bool $allowed = true;
    public bool $admin = true;
    public $locations = [10];
    public function can($permission): bool
    {
        return $permission === 'business_settings.access' && $this->allowed;
    }
    public function hasRole($role): bool
    {
        return $this->admin;
    }
    public function permitted_locations($businessId)
    {
        if ($businessId !== $this->business_id) {
            throw new RuntimeException('Wrong business passed to permitted_locations');
        }
        return $this->locations;
    }
};
$checks = 0;
function managementCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}
function managementRequest(array $input = [], $account = true): Request
{
    global $user;
    $request = Request::create('/eis/terminals', 'POST', $input);
    $request->setUserResolver(fn () => $account === true ? $user : $account);
    return $request;
}
function managementReject(callable $work, int $status, string $message): void
{
    try {
        $work();
    } catch (HttpException $e) {
        managementCheck($e->getStatusCode() === $status, $message);
        return;
    } catch (ModelNotFoundException $e) {
        managementCheck($status === 404, $message);
        return;
    }
    throw new RuntimeException($message.' was accepted');
}
$service = Mockery::mock(EisTerminalActivationService::class);
$controller = new TerminalManagementController($service);
managementCheck($controller->getMiddleware()[0]['middleware'] === 'auth', 'Controller requires a session user');
$response = $controller->index(managementRequest())->getData(true);
managementCheck($response['success'] && array_column($response['data']['terminals'], 'id') === [101, 102],
    'Restricted admin sees multiple terminals only at permitted locations');
managementCheck(array_column($response['data']['locations'], 'id') === [10], 'Activation dropdown contains permitted BusinessLocations');
managementCheck($response['data']['terminals'][0]['terminal_label'] === 'Label 101', 'Configuration resolves through canonical settings ID');
managementCheck($response['data']['terminals'][0]['is_default'] && !$response['data']['terminals'][1]['is_default'],
    'Location designated default is independent of terminal activation order');
managementCheck($response['data']['terminals'][0]['is_active'] && !$response['data']['terminals'][1]['is_active'],
    'Per-terminal active and inactive statuses are listed');
managementCheck(!str_contains(json_encode($response), 'fixture-token') && !str_contains(json_encode($response), 'fixture-secret'),
    'List never serializes terminal credentials');
$user->locations = 'all';
$response = $controller->index(managementRequest())->getData(true);
managementCheck(array_column($response['data']['terminals'], 'id') === [101, 102, 103], 'All sentinel includes only this business assigned terminals');
$user->locations = [];
managementCheck($controller->index(managementRequest())->getData(true)['data']['terminals'] === [], 'Empty permission set exposes no terminals');
$user->locations = [10];

foreach ([[0, 1], [1, 0], [0, 0], [1, 1]] as [$localActive, $remoteActive]) {
    $db->table('eis_settings')->where('id', 102)->update(['status' => $localActive]);
    $db->table('eis_terminal_configurations')->where('configuration_id', 1102)->update(['is_active' => $remoteActive]);
    $listed = $controller->index(managementRequest())->getData(true)['data']['terminals'][1];
    $expected = (bool) $localActive && (bool) $remoteActive;
    managementCheck($listed['is_active'] === $expected, 'Management status combines local and remote flags '.$localActive.'/'.$remoteActive);
    $result = $controller->setDefault(managementRequest(), 102);
    managementCheck($result->getStatusCode() === ($expected ? 200 : 422)
        && $result->getData(true)['success'] === $expected,
        'Default action requires both active flags '.$localActive.'/'.$remoteActive);
    managementCheck((int) $db->table('business_locations')->where('id', 10)->value('default_eis_setting_id') === ($expected ? 102 : 101),
        'Rejected inactive default preserves designation '.$localActive.'/'.$remoteActive);
}
$db->table('business_locations')->where('id', 10)->update(['default_eis_setting_id' => 101]);
$db->table('eis_settings')->where('id', 102)->update(['status' => 0]);
$db->table('eis_terminal_configurations')->where('configuration_id', 1102)->update(['is_active' => 0]);

foreach (['development', 'dev', 'sandbox', 'production', 'invalid', ''] as $mode) {
    $app['config']->set('eis.environment', $mode);
    foreach ([false, true] as $localActive) {
        $db->table('eis_settings')->where('id', 102)->update(['status' => $localActive]);
        $listed = $controller->index(managementRequest())->getData(true)['data']['terminals'][1];
        $expected = $localActive && in_array($mode, ['development', 'dev', 'sandbox'], true);
        managementCheck($listed['is_active'] === $expected, 'Inactive API flag uses environment and local status: '.$mode);
        $result = $controller->setDefault(managementRequest(), 102)->getData(true);
        managementCheck($result['success'] === $expected, 'Default selection uses operational status: '.$mode);
    }
}
$app['config']->set('eis.environment', 'development');
$db->table('eis_settings')->where('id', 102)->update(['status' => 1]);
$db->table('eis_terminal_configurations')->where('configuration_id', 1102)->delete();
managementCheck(!$controller->index(managementRequest())->getData(true)['data']['terminals'][1]['is_active'],
    'Development does not treat a missing terminal configuration as active');
managementCheck(!$controller->setDefault(managementRequest(), 102)->getData(true)['success'],
    'Development cannot default a terminal without configuration');
$db->table('eis_terminal_configurations')->insert(['id' => 2102, 'configuration_id' => 1102, 'terminal_label' => 'Label 102', 'is_active' => 0]);
$db->table('eis_settings')->where('id', 102)->update(['status' => 0]);
$db->table('business_locations')->where('id', 10)->update(['default_eis_setting_id' => 101]);
$app['config']->set('eis.environment', 'production');

foreach (['index', 'activate', 'latestConfig', 'configuration', 'deactivate', 'destroy', 'renewTokens', 'setDefault'] as $action) {
    managementReject(fn () => $controller->$action(managementRequest([], null), 101), 401, $action.' rejects anonymous users');
    $user->allowed = false;
    managementReject(fn () => $controller->$action(managementRequest(), 101), 403, $action.' requires business settings permission');
    $user->allowed = true;
}
foreach (['latestConfig', 'configuration', 'deactivate', 'destroy', 'renewTokens', 'setDefault'] as $action) {
    foreach ([103, 104, 201, 999] as $id) {
        managementReject(fn () => $controller->$action(managementRequest(), $id), 404, $action.' rejects inaccessible terminal '.$id);
    }
}
foreach ([
    [],
    ['location_id' => 10],
    ['location_id' => 10, 'terminal_activation_code' => ['invalid']],
    ['location_id' => 10, 'terminal_activation_code' => 'CODE', 'environment' => 'invalid'],
] as $invalid) {
    try {
        $controller->activate(managementRequest($invalid));
        throw new RuntimeException('Invalid activation accepted');
    } catch (ValidationException $e) {
        managementCheck(true, 'Invalid activation is rejected before service calls');
    }
}
$service->shouldReceive('activateTerminal')->once()->with(1, 'FIXTURE', ['pos' => ['productID' => 'Fixture']], 7)
    ->andReturn(['success' => true, 'message' => 'Activated', 'terminal_credentials' => ['secret_key' => 'not-for-browser']]);
$result = $controller->activate(managementRequest([
    'business_id' => 2, 'terminal_activation_code' => 'FIXTURE',
    'environment' => ['pos' => ['productID' => 'Fixture']],
]))->getData(true);
managementCheck($result === ['success' => true, 'message' => 'Activated', 'msg' => 'Activated'],
    'Activation uses authenticated tenant and returns existing message shape without secrets');
$mismatchMessage = 'This terminal belongs to a different trading name. Use a separate ERP business account.';
$service->shouldReceive('activateTerminal')->once()->with(1, 'FOREIGN', [], 7)
    ->andReturn(['success' => false, 'message' => $mismatchMessage, 'status_code' => 422]);
$mismatchResponse = $controller->activate(managementRequest(['terminal_activation_code' => 'FOREIGN']));
managementCheck($mismatchResponse->getStatusCode() === 422
    && $mismatchResponse->getData(true) === ['success' => false, 'message' => $mismatchMessage, 'msg' => $mismatchMessage],
    'Ownership rejection preserves the server error message for the activation SweetAlert');
$service->shouldReceive('getLatestConfig')->once()->with(1, 102)->andReturn(['success' => true, 'msg' => 'Synced']);
managementCheck($controller->latestConfig(managementRequest(['terminal_id' => 201]), 102)->getData(true)['message'] === 'Synced',
    'Latest config pins canonical route terminal ID, not a body override');
$shown = $controller->configuration(managementRequest(['terminal_id' => 201]), 102)->getData(true)['data'];
managementCheck($shown['terminal_id'] === 102 && $shown['terminalConfiguration']['terminalLabel'] === 'Label 102'
    && $shown['globalConfiguration']['versionNo'] === 1 && $shown['taxpayerConfiguration']['versionNo'] === 3,
    'Configuration viewer pins route terminal and includes all three groups with their own versions');
managementCheck(!str_contains(json_encode($shown), 'never-expose') && !isset($shown['terminalConfiguration']['jwtToken']),
    'Configuration viewer excludes credentials from saved API payload');
managementCheck(!str_contains(json_encode($shown), 'nested-secret') && $shown['terminalConfiguration']['terminalSite']['siteId'] === 'SITE-102',
    'Nested configuration fields are allowlisted without dropping documented site identity');
$stored = $db->table('eis_configurations')->where('id', 1102)->value('raw_response');
$db->table('eis_configurations')->where('id', 1102)->update(['raw_response' => json_encode($stored)]);
managementCheck($controller->configuration(managementRequest(), 102)->getData(true)['data']['globalConfiguration']['versionNo'] === 1,
    'Viewer supports legacy double-encoded configuration without rewriting storage');
$db->table('eis_configurations')->where('id', 1102)->update(['raw_response' => '{"data":{}}']);
managementReject(fn () => $controller->configuration(managementRequest(), 102), 409, 'Incomplete saved configuration requires an explicit refresh');
$db->table('eis_configurations')->where('id', 1102)->update(['raw_response' => $stored]);
$service->shouldReceive('deactivate')->once()->with(1, 'Retired', 7, 102)->andReturn(['success' => true]);
managementCheck($controller->deactivate(managementRequest(['reason' => 'Retired']), 102)->getData(true)['success'],
    'Deactivation pins tenant, actor, reason and terminal');
$service->shouldReceive('regenerateCredentials')->once()->with(1, 'fixture-token-101', 101)
    ->andReturn(['success' => true, 'data' => ['jwt_token' => 'rotated', 'secret_key' => 'rotated-secret']]);
$result = $controller->renewTokens(managementRequest(['token' => 'attacker-supplied']), 101)->getData(true);
managementCheck($result['success'] && !isset($result['data']), 'Renewal uses stored terminal token and keeps rotated credentials server-side');
$db->table('eis_settings')->where('id', 102)->update(['jwt_token' => null]);
managementCheck($controller->renewTokens(managementRequest(), 102)->getStatusCode() === 422, 'Renewal without credentials fails without service calls');
$service->shouldReceive('getLatestConfig')->once()->with(1, 101)->andReturn(['success' => false, 'message' => 'Unavailable']);
managementCheck($controller->latestConfig(managementRequest(), 101)->getStatusCode() === 422, 'Service failures return actionable error status');
$db->table('eis_settings')->where('id', 102)->update(['status' => 1]);
$db->table('eis_terminal_configurations')->where('configuration_id', 1102)->update(['is_active' => 1]);
$controller->setDefault(managementRequest(['location_id' => 20]), 102);
managementCheck((int) $db->table('business_locations')->where('id', 10)->value('default_eis_setting_id') === 102,
    'Setting default persists canonical terminal ID at its own location');
managementCheck((int) $db->table('business_locations')->where('id', 11)->value('default_eis_setting_id') === 103
    && (int) $db->table('business_locations')->where('id', 20)->value('default_eis_setting_id') === 201,
    'Setting default never modifies other locations or businesses');
$db->table('transactions')->insert(['id' => 99, 'business_id' => 1, 'location_id' => 10,
    'type' => 'sell', 'status' => 'final']);
$db->table('eis_sales')->insert(['business_id' => 1, 'transaction_id' => 99, 'status' => 'pending']);
$pendingDelete = $controller->destroy(managementRequest(), 102);
managementCheck($pendingDelete->getStatusCode() === 422
    && $pendingDelete->getData(true)['message'] === EisTerminalActivationService::UNSUBMITTED_SALES_MESSAGE
    && $db->table('eis_settings')->where('id', 102)->whereNull('deleted_at')->exists(),
    'Terminals with unsubmitted final sales cannot be deleted');
$db->table('eis_sales')->where('transaction_id', 99)->update(['status' => 'submitted']);
$activeDelete = $controller->destroy(managementRequest(), 102);
managementCheck($activeDelete->getStatusCode() === 422
    && $activeDelete->getData(true)['message'] === 'Deactivate the terminal before deleting it.'
    && $db->table('tax_rates')->where('configuration_id', 1102)->exists(),
    'Active terminals cannot be deleted and their tax rates are preserved');
$db->table('eis_settings')->where('id', 102)->update(['status' => 0]);
$db->table('eis_terminal_configurations')->where('configuration_id', 1102)->update(['is_active' => 0]);
$db->table('transactions')->insert(['id' => 100, 'business_id' => 1, 'location_id' => 10,
    'type' => 'sell', 'status' => 'final']);
$db->table('eis_sales')->insert(['business_id' => 1, 'transaction_id' => 100, 'status' => 'pending']);
$pendingInactiveDelete = $controller->destroy(managementRequest(), 102);
managementCheck($pendingInactiveDelete->getStatusCode() === 422
    && $pendingInactiveDelete->getData(true)['message'] === EisTerminalActivationService::UNSUBMITTED_SALES_MESSAGE
    && $db->table('eis_settings')->where('id', 102)->whereNull('deleted_at')->exists(),
    'An inactive terminal with unsubmitted final sales cannot be deleted');
$db->table('eis_sales')->where('transaction_id', 100)->update(['status' => 'submitted']);
$deleted = $controller->destroy(managementRequest(), 102);
managementCheck($deleted->getData(true)['success']
    && $db->table('eis_settings')->where('id', 102)->whereNotNull('deleted_at')->exists()
    && $db->table('eis_configurations')->where('id', 1102)->whereNotNull('deleted_at')->exists()
    && $db->table('eis_terminal_configurations')->where('id', 2102)->whereNotNull('deleted_at')->exists(),
    'Deleting an inactive terminal soft-deletes its settings and configuration records');
managementCheck(!$db->table('tax_rates')->where('configuration_id', 1102)->whereNull('deleted_at')->exists()
    && $db->table('tax_rates')->where('configuration_id', 1101)->exists()
    && $db->table('tax_rates')->where('configuration_id', 1103)->exists()
    && $db->table('tax_rates')->where('configuration_id', 1201)->exists(),
    'Deleting a terminal removes only its rates from active tax lookups');
managementCheck($db->table('tax_rates')->where('id', 2)->whereNotNull('deleted_at')->exists()
    && !$db->table('tax_rates')->where('id', 5)->exists(),
    'Referenced tax history is retained while an unused terminal tax rate is physically deleted');
managementCheck($db->table('products')->where('id', 1)->whereNull('tax')->exists()
    && $db->table('products')->where('id', 2)->whereNull('tax')->exists()
    && (int) $db->table('products')->where('id', 3)->value('tax') === 1
    && $db->table('business')->where('id', 1)->whereNull('default_sales_tax')->exists(),
    'Deleting a terminal clears its current product/default-tax references without altering other products');
managementCheck($db->table('business_locations')->where('id', 10)->value('default_eis_setting_id') === null,
    'Deleting a location default clears the location reference');
managementReject(fn () => $controller->destroy(managementRequest(), 102), 404,
    'Deleted terminals are no longer available for management');
$user->locations = 'all';
$service->shouldReceive('getLatestConfig')->once()->with(1, 103)->andReturn(['success' => true]);
managementCheck($controller->latestConfig(managementRequest(), 103)->getData(true)['success'], 'All sentinel authorizes actions within tenant');
Mockery::close();

$router = new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app), $app);
$app->instance('router', $router);
require dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php';
$routes = $router->getRoutes();
$routes->refreshNameLookups();
managementCheck($routes->getByName('eis.index') && $routes->getByName('eis.index')->uri() === 'eis',
    'EIS root route opens an authorized EIS page');
managementCheck($routes->getByName('eis.products.status')
    && $routes->getByName('eis.products.status')->uri() === 'eis/products/{productId}/status',
    'Mapped EIS product status has a dedicated route');
foreach ([
    'page' => ['GET', 'eis/terminals'],
    'index' => ['GET', 'eis/terminals/data'],
    'activate' => ['POST', 'eis/terminals/activate'],
    'latest-config' => ['POST', 'eis/terminals/{terminalId}/latest-config'],
    'configuration' => ['GET', 'eis/terminals/{terminalId}/configuration'],
    'request-new-token' => ['POST', 'eis/terminals/{terminalId}/request-new-token'],
    'deactivate' => ['POST', 'eis/terminals/{terminalId}/deactivate'],
    'delete' => ['POST', 'eis/terminals/{terminalId}/delete'],
    'renew-tokens' => ['POST', 'eis/terminals/{terminalId}/renew-tokens'],
    'default' => ['POST', 'eis/terminals/{terminalId}/default'],
] as $name => [$method, $uri]) {
    $route = $routes->getByName('eis.terminals.'.$name);
    managementCheck($route && $route->uri() === $uri && in_array($method, $route->methods()), 'Management route contract: '.$name);
    managementCheck(in_array('web', $route->middleware()) && in_array('auth', $route->middleware()), 'Session/CSRF middleware retained: '.$name);
}
$blade = new Illuminate\View\Compilers\BladeCompiler(new Illuminate\Filesystem\Filesystem(), dirname(__DIR__, 2).'\storage\framework\views');
foreach (['settings', 'partials\settings_mra_eis'] as $view) {
    $source = file_get_contents(dirname(__DIR__, 2).'\resources\views\business\\'.$view.'.blade.php');
    token_get_all($blade->compileString($source), TOKEN_PARSE);
    managementCheck(true, 'Blade compiles: '.$view);
}
foreach ([
    'terminals\index.blade.php',
    'terminals\script.blade.php',
    'shared\styles.blade.php',
    'shared\navigation.blade.php',
    'shared\request-form.blade.php',
    'shared\result-table.blade.php',
    'sales\index.blade.php',
    'shared\navigation.blade.php',
    'stock\tools.blade.php',
    'stock\outbound.blade.php',
    'stock\reconcile.blade.php',
] as $view) {
    $moduleView = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\\'.$view);
    token_get_all($blade->compileString($moduleView), TOKEN_PARSE);
    managementCheck(true, 'Blade compiles EIS view: '.$view);
}
foreach ([
    'sell\index.blade.php',
    'sell\partials\eis_cancel_receipt_modal.blade.php',
    'sell\partials\eis_cancel_receipt_javascript.blade.php',
    'product\index.blade.php',
] as $view) {
    $moduleView = file_get_contents(dirname(__DIR__, 2).'\resources\views\\'.$view);
    token_get_all($blade->compileString($moduleView), TOKEN_PARSE);
    managementCheck(true, 'Blade compiles integrated EIS UI: '.$view);
}
$terminalView = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\terminals\index.blade.php');
managementCheck(str_contains($terminalView, "business.partials.settings_mra_eis")
    && str_contains($terminalView, "'standalone' => true"),
    'Standalone terminal page renders the MRA EIS settings interface');
$terminalScript = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\terminals\script.blade.php');
$source = file_get_contents(dirname(__DIR__, 2).'\resources\views\business\settings.blade.php');
$taxSettingsView = file_get_contents(dirname(__DIR__, 2).'\resources\views\business\partials\settings_tax.blade.php');
token_get_all($blade->compileString($taxSettingsView), TOKEN_PARSE);
managementCheck(str_contains($taxSettingsView, "Form::checkbox('is_vat_registered', 1, (bool) \$business->is_vat_registered")
    && str_contains($taxSettingsView, "'disabled' => true")
    && str_contains($taxSettingsView, "__('business.is_vat_registered')"),
    'Tax settings show EIS VAT registration as a read-only checkbox');
managementCheck(!str_contains($source, "route('eis.terminal.") && !str_contains($source, 'eis_token')
    && !str_contains($source, 'getTerminalStatus'), 'Obsolete API/singleton JS and credentials removed');
managementCheck(str_contains($terminalScript, "(action === 'deactivate' || action === 'default') && !terminal.is_active"),
    'UI hides default action when combined terminal status is inactive');
managementCheck(str_contains($terminalScript, "action === 'delete' && terminal.is_active")
    && str_contains($terminalScript, "route('eis.terminals.delete'"),
    'UI offers deletion only for inactive terminals through the dedicated endpoint');
managementCheck(str_contains($terminalScript, "@json(route('eis.terminals.index'))")
    && str_contains(file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php'), "Route::get('data', [TerminalManagementController::class, 'index'])->name('index')"),
    'Terminal UI uses the dedicated JSON listing route');
managementCheck(str_contains($source, 'test_sms_btn') && str_contains($source, 'test_email_btn')
    && str_contains($source, 'tinymce.init'), 'Other business settings handlers retained');
$toolsView = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\stock\tools.blade.php');
managementCheck(str_contains($toolsView, 'EisRequestForms.collect')
    && !str_contains($toolsView, 'eis-tools-payload'),
    'EIS warehouse tools use guided fields instead of raw JSON editors');
$salesView = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\sales\index.blade.php');
$salesScript = substr($salesView, strpos($salesView, '<script>'));
managementCheck(str_contains($salesView, 'EisResultTables.render')
    && str_contains($toolsView, 'EisResultTables.render')
    && str_contains($terminalScript, 'EisResultTables.render'),
    'EIS API responses and terminal configuration render as tables');
managementCheck(!str_contains($salesView, 'Normal sales use the existing POS fiscal submission workflow')
    && !str_contains($salesView, 'eis-sales-terminal')
    && str_contains($salesView, 'Sync &amp; Print')
    && str_contains($salesScript, "window.open('', '_blank')")
    && str_contains($salesScript, 'printReceiptInWindow(printWindow, printResult.receipt, statusMessage)')
    && str_contains($salesScript, 'printWindow.print()'),
    'EIS sales syncs each sale and prints only its POS receipt in a new window');
managementCheck(str_contains($salesScript, "result.message === 'Terminal Not available for sync'")
    && str_contains($salesScript, 'xhr.status === 502')
    && str_contains($salesScript, 'xhr.status === 409')
    && str_contains($salesScript, "result.message === 'EIS did not confirm the invoice lookup. No local data was changed.'")
    && str_contains($salesScript, 'fetchAndPrintReceipt(button, printWindow,')
    && str_contains($salesScript, 'EIS is offline. Opening the saved POS receipt without EIS sync.')
    && str_contains($salesScript, 'EIS did not confirm the invoice lookup. Opening the saved POS receipt without EIS sync.')
    && str_contains($salesScript, 'Opening the saved POS receipt without EIS sync.'),
    'Unavailable terminals and offline EIS print the saved POS receipt without syncing');
$routeProviderSource = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Providers\RouteServiceProvider.php');
managementCheck(str_contains($routeProviderSource, "'SetSessionData'")
    && str_contains($routeProviderSource, "'AdminSidebarMenu'"),
    'EIS web routes initialize the business session and render the admin side menu');
$menuSource = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Http\Controllers\DataController.php');
managementCheck(str_contains($menuSource, '$menu->url')
    && str_contains($menuSource, 'route($homeRoute)')
    && !str_contains($menuSource, '$menu->dropdown'),
    'EIS sidebar uses one direct module link without a redundant submenu');
$navigationSource = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\shared\navigation.blade.php');
managementCheck(str_contains($navigationSource, 'navbar navbar-default bg-white')
    && !str_contains($navigationSource, 'eis.stock.outbound')
    && !str_contains($navigationSource, 'eis.stock.tools')
    && str_contains($navigationSource, "auth()->user()->can('business_settings.access')")
    && !str_contains(file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php'), "name('eis.stock.tools.")
    && !str_contains(file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php'), "name('eis.stock.outbound.index')")
    && str_contains(file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php'), "name('eis.stock.outbound.reasons')")
    && !str_contains(file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Routes\web.php'), 'eis/utilities')
    && !file_exists(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\utilities\index.blade.php'),
    'EIS module navigation excludes outbound and tools while retaining ERP adjustment-reason lookup');
$productControllerSource = file_get_contents(dirname(__DIR__, 2).'\app\Http\Controllers\ProductController.php');
$productViewSource = file_get_contents(dirname(__DIR__, 2).'\resources\views\product\index.blade.php');
$productStatusSource = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Http\Controllers\ProductStatusController.php');
managementCheck(str_contains($productControllerSource, 'eis-product-status')
    && str_contains($productControllerSource, 'eis_product_maps.eis_product_id')
    && str_contains($productViewSource, 'eis_product_status_modal')
    && str_contains($productStatusSource, 'UtilityOperations::payload($setting,')
    && str_contains($productStatusSource, "permitted_locations((int) \$user->business_id) === 'all'"),
    'Mapped EIS products have an all-location-guarded product status lookup in their action menu');
$sellControllerSource = file_get_contents(dirname(__DIR__, 2).'\app\Http\Controllers\SellController.php');
$sellPosControllerSource = file_get_contents(dirname(__DIR__, 2).'\app\Http\Controllers\SellPosController.php');
$posIndexSource = file_get_contents(dirname(__DIR__, 2).'\resources\views\sale_pos\index.blade.php');
$cancelScriptSource = file_get_contents(dirname(__DIR__, 2).'\resources\views\sell\partials\eis_cancel_receipt_javascript.blade.php');
$cancelModalSource = file_get_contents(dirname(__DIR__, 2).'\resources\views\sell\partials\eis_cancel_receipt_modal.blade.php');
managementCheck(!str_contains($sellControllerSource, 'eis-cancel-receipt')
    && !str_contains($sellControllerSource, 'eis-void-sync')
    && !str_contains($posIndexSource, "@include('sell.partials.eis_cancel_receipt_modal')")
    && !str_contains($posIndexSource, "@include('sell.partials.eis_cancel_receipt_javascript')")
    && !str_contains($posIndexSource, "@include('sell.partials.eis_void_tools_javascript')"),
    'Disabled EIS cancellation and void features have no POS actions, modal or scripts');
$moduleViews = [
    'terminals\index.blade.php', 'sales\index.blade.php',
    'stock\tools.blade.php', 'stock\outbound.blade.php', 'stock\reconcile.blade.php',
];
foreach ($moduleViews as $view) {
    $moduleView = file_get_contents(dirname(__DIR__, 2).'\Modules\EIS\Resources\views\\'.$view);
    managementCheck(str_contains($moduleView, "eis::shared.navigation"),
        'EIS page includes the shared module navigation: '.$view);
}
$settingsPartial = file_get_contents(dirname(__DIR__, 2).'\resources\views\business\partials\settings_mra_eis.blade.php');
managementCheck(str_contains($settingsPartial, "eis::shared.navigation")
    && !str_contains($settingsPartial, 'btn-default" href="{{ route(\'eis.'),
    'Business settings uses the shared EIS navigation without duplicate page links');
managementCheck(substr_count($source, '@if ($canUseEis)') === 3
    && str_contains($source, "@lang('lang_v1.mra_eis')")
    && str_contains($source, "@include('business.partials.settings_mra_eis')"),
    'MRA EIS settings tab and panel are both conditional on subscription');
$controllerSource = file_get_contents(dirname(__DIR__, 2).'\app\Http\Controllers\BusinessController.php');
managementCheck(str_contains($controllerSource, 'app(EisSubscriptionAccess::class)->allows((int) $business_id)')
    && str_contains($controllerSource, "'canUseEis'"),
    'Business settings receives the current business EIS subscription entitlement');
echo "OK: {$checks} terminal management checks\n";
