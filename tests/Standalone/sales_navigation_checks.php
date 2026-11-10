<?php

// No application boot, session, database, or network access.
require dirname(__DIR__, 2).'/vendor/autoload.php';

function auth() { return $GLOBALS['navigationUser']; }
function request() { return $GLOBALS['navigationRequest']; }
function action($action, array $parameters = []) { return $action[0].'@'.$action[1].http_build_query($parameters); }
function __($key) { return $key; }

class SalesNavigationFixture
{
    public array $items = [];
    public function dropdown($label, $callback, $options) { $callback($this); return $this; }
    public function url($url, $label, $options) { $this->items[$label] = ['url' => $url] + $options; }
    public function order($order) { return $this; }
}

$source = file_get_contents(dirname(__DIR__, 2).'/app/Http/Middleware/AdminSidebarMenu.php');
$start = strpos($source, '//Sell dropdown');
$end = strpos($source, '//Stock transfer dropdown', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Sales navigation block not found');
}
$block = substr($source, $start, $end - $start);
$checks = 0;
$all = ['lang_v1.sales_order', 'lang_v1.all_sales', 'sale.add_sale', 'sale.list_pos',
    'sale.pos_sale', 'lang_v1.add_draft', 'lang_v1.list_drafts', 'lang_v1.add_quotation',
    'lang_v1.list_quotations', 'lang_v1.list_sell_return', 'lang_v1.shipments',
    'lang_v1.discounts', 'lang_v1.subscriptions', 'lang_v1.import_sales'];
foreach ([
    [[], [], false, false, '/sells', [], null],
    [[], ['so.view_own'], false, true, '/sales-order', ['lang_v1.sales_order'], 'lang_v1.sales_order'],
    [[], ['so.create'], false, false, '/sells/create?sale_type=sales_order', [], null],
    [[], ['direct_sell.view'], false, false, '/sells', ['lang_v1.all_sales'], 'lang_v1.all_sales'],
    [[], ['direct_sell.access'], false, false, '/sells', ['lang_v1.all_sales'], 'lang_v1.all_sales'],
    [['add_sale'], ['direct_sell.access'], false, false, '/sells/create',
        ['lang_v1.all_sales', 'sale.add_sale', 'lang_v1.add_draft', 'lang_v1.add_quotation'], 'sale.add_sale'],
    [['add_sale'], ['direct_sell.access'], false, false, '/sells/create?status=draft',
        ['lang_v1.all_sales', 'sale.add_sale', 'lang_v1.add_draft', 'lang_v1.add_quotation'], 'lang_v1.add_draft'],
    [['add_sale'], ['direct_sell.access'], false, false, '/sells/create?status=quotation',
        ['lang_v1.all_sales', 'sale.add_sale', 'lang_v1.add_draft', 'lang_v1.add_quotation'], 'lang_v1.add_quotation'],
    [['add_sale', 'pos_sale', 'subscription'], ['*'], true, true, '/sells/create?sale_type=sales_order',
        $all, 'lang_v1.sales_order'],
    [['add_sale', 'pos_sale', 'subscription'], ['*'], true, true, '/pos/create', $all, 'sale.pos_sale'],
] as [$enabled_modules, $permissions, $is_admin, $ordersEnabled, $url, $expected, $active]) {
    $pos_settings = ['enable_sales_order' => $ordersEnabled];
    $GLOBALS['navigationRequest'] = Illuminate\Http\Request::create($url);
    $GLOBALS['navigationUser'] = new class($permissions) {
        public function __construct(private array $permissions) {}
        public function user() { return $this; }
        public function can($permission) {
            return in_array('*', $this->permissions) || in_array($permission, $this->permissions);
        }
        public function hasAnyPermission($permissions) {
            return in_array('*', $this->permissions) || (bool) array_intersect($permissions, $this->permissions);
        }
    };
    $menu = new SalesNavigationFixture();
    eval($block);
    if (array_keys($menu->items) !== $expected) {
        throw new RuntimeException('Incorrect sales navigation visibility for '.$url);
    }
    foreach ($menu->items as $label => $item) {
        if ($item['active'] !== ($label === $active)) {
            throw new RuntimeException('Incorrect active navigation: '.$label.' for '.$url);
        }
    }
    foreach (['lang_v1.all_sales' => 'index', 'sale.add_sale' => 'create',
        'lang_v1.sales_order' => 'index'] as $label => $method) {
        $controller = $label === 'lang_v1.sales_order' ? 'SalesOrderController' : 'SellController';
        if (isset($menu->items[$label]) && $menu->items[$label]['url'] !== 'App\\Http\\Controllers\\'.$controller.'@'.$method) {
            throw new RuntimeException('Incorrect navigation destination: '.$label);
        }
    }
    ++$checks;
}
echo "PASS: {$checks} sales navigation permission, module, destination and active-state scenarios.\n";
