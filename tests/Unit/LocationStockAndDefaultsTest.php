<?php

namespace Tests\Unit;

use App\BusinessLocation;
use App\InvoiceLayout;
use App\Product;
use App\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use PHPUnit\Framework\TestCase;

class LocationStockAndDefaultsTest extends TestCase
{
    private $db;

    private $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $this->db = new Capsule;
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->db->bootEloquent();

        $schema = $this->db->getConnection()->getSchemaBuilder();
        $schema->create('business', function (Blueprint $table) {
            $table->increments('id');
        });
        $this->db->getConnection()->table('business')->insert([
            ['id' => 1], ['id' => 2], ['id' => 3], ['id' => 5],
        ]);
        $schema->create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id');
            $table->string('eis_site_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('default_payment_accounts')->nullable();
        });
        foreach (['invoice_schemes', 'invoice_layouts'] as $name) {
            $schema->create($name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('business_id');
                $table->boolean('is_default');
                $table->string('name')->nullable();
                $table->string('design')->nullable();
                $table->text('footer_text')->nullable();
                $table->string('logo')->nullable();
                $table->timestamps();
            });
        }
        $schema->create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('business_id');
            $table->softDeletes();
        });
        $schema->create('variations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
        });
        $schema->create('variation_location_details', function (Blueprint $table) {
            $table->integer('variation_id');
            $table->integer('location_id');
            $table->decimal('qty_available', 22, 4);
            $table->softDeletes();
        });

        $this->db->getConnection()->table('business_locations')->insert([
            ['id' => 1, 'business_id' => 1, 'eis_site_id' => 'A'],
            ['id' => 2, 'business_id' => 1, 'eis_site_id' => 'B'],
            ['id' => 3, 'business_id' => 2, 'eis_site_id' => 'A'],
        ]);
        $this->db->getConnection()->table('products')->insert(['id' => 1, 'business_id' => 1]);
        $this->db->getConnection()->table('variations')->insert([
            ['id' => 1, 'product_id' => 1],
            ['id' => 2, 'product_id' => 1],
        ]);
        $this->db->getConnection()->table('variation_location_details')->insert([
            ['variation_id' => 1, 'location_id' => 1, 'qty_available' => 10],
            ['variation_id' => 1, 'location_id' => 2, 'qty_available' => 20],
            ['variation_id' => 2, 'location_id' => 1, 'qty_available' => -2],
            ['variation_id' => 2, 'location_id' => 2, 'qty_available' => 4],
            ['variation_id' => 1, 'location_id' => 3, 'qty_available' => 1000],
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->db->getConnection()->disconnect();
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    private function stock($permitted, $location = null)
    {
        return (float) Product::join('variations as v', 'v.product_id', '=', 'products.id')
            ->withStockLocationDetails(1, $location, $permitted)
            ->where('products.business_id', 1)
            ->selectRaw('COALESCE(SUM(vld.qty_available), 0) as current_stock')
            ->first()->current_stock;
    }

    public function testStockIsScopedBeforeAggregationAcrossVariationsAndSites(): void
    {
        $this->assertSame(8.0, $this->stock([1]));
        $this->assertSame(24.0, $this->stock([2]));
        $this->assertSame(32.0, $this->stock([1, 2]));
        $this->assertSame(32.0, $this->stock('all'));
        $this->assertSame(24.0, $this->stock('all', '2'));
        $this->assertSame(0.0, $this->stock([1], 2));
        $this->assertSame(0.0, $this->stock('all', 3));
        $this->assertSame(0.0, $this->stock([]));
        $this->assertSame(0.0, $this->stock('all', 'none'));
    }

    public function testAdminUsesTheSameLocationPermissionsWithoutRoleBypass(): void
    {
        $admin = Mockery::mock(User::class)->makePartial();
        $admin->shouldReceive('can')->twice()->with('access_all_locations')->andReturn(false);
        $admin->setRelation('permissions', new Collection([
            (object) ['name' => 'location.2'],
            (object) ['name' => 'location.3'],
        ]));
        $this->assertSame([2], $admin->permitted_locations(1));
        $this->assertSame(24.0, $this->stock($admin->permitted_locations(1)));
    }

    public function testArchivedStockIsExcludedWithoutChangingOtherLocationTotals(): void
    {
        $this->db->getConnection()->table('variation_location_details')->where('location_id', 1)
            ->update(['deleted_at' => '2026-11-03 15:00:00']);

        $this->assertSame(0.0, $this->stock('all', 1));
        $this->assertSame(24.0, $this->stock('all'));
        $this->assertSame(8.0, (float) $this->db->getConnection()->table('variation_location_details')
            ->where('location_id', 1)->sum('qty_available'));
    }

    public function testExplicitAllLocationsPermissionPreservesAllSemantics(): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('can')->once()->with('access_all_locations')->andReturn(true);
        $this->assertSame('all', $user->permitted_locations(1));
    }

    public function testCreationDefaultsReuseBusinessSchemeLayoutAndOriginalPaymentSetup(): void
    {
        foreach (['invoice_schemes', 'invoice_layouts'] as $name) {
            $this->db->getConnection()->table($name)->insert([
                ['id' => 10, 'business_id' => 2, 'is_default' => 1],
                ['id' => 11, 'business_id' => 1, 'is_default' => 0],
                ['id' => 12, 'business_id' => 1, 'is_default' => 1],
            ]);
        }
        $payments = ['cash' => ['is_enabled' => 0, 'account' => null],
            'card' => ['is_enabled' => 1, 'account' => 42]];
        $this->db->getConnection()->table('business_locations')->where('id', 1)
            ->update(['default_payment_accounts' => json_encode($payments)]);
        $defaults = BusinessLocation::creationDefaults(1, ['cash' => 'Cash', 'card' => 'Card']);
        $this->assertSame(12, $defaults['invoice_scheme_id']);
        $this->assertSame(12, $defaults['sale_invoice_scheme_id']);
        $this->assertSame(12, $defaults['invoice_layout_id']);
        $this->assertSame(12, $defaults['sale_invoice_layout_id']);
        $this->assertSame($payments, $defaults['default_payment_accounts']);
    }

    public function testMissingDefaultsRetainExistingEnableAllPaymentUx(): void
    {
        $defaults = BusinessLocation::creationDefaults(1, ['cash' => 'Cash']);
        $this->assertNull($defaults['invoice_scheme_id']);
        $this->assertNull($defaults['invoice_layout_id']);
        $this->assertSame(['cash' => ['is_enabled' => 1, 'account' => null]], $defaults['default_payment_accounts']);
    }

    public function testExplicitlyDisabledPaymentSetupIsNotReenabled(): void
    {
        $this->db->getConnection()->table('business_locations')->where('id', 1)
            ->update(['default_payment_accounts' => '{}']);
        $this->assertSame([], BusinessLocation::creationDefaults(1, ['cash' => 'Cash'])['default_payment_accounts']);
    }

    public function testSampleInvoiceSettingsApplyToEveryBusinessDefaultAndMissingDefaults(): void
    {
        $table = $this->db->getConnection()->table('invoice_layouts');
        $table->insert([
            ['id' => 5, 'business_id' => 5, 'name' => 'Default', 'is_default' => 1,
                'design' => 'slim', 'footer_text' => 'Sample footer', 'logo' => 'sample.png'],
            ['id' => 6, 'business_id' => 1, 'name' => 'Default', 'is_default' => 1,
                'design' => 'classic', 'footer_text' => 'Old footer', 'logo' => null],
            ['id' => 7, 'business_id' => 1, 'name' => 'Custom', 'is_default' => 0,
                'design' => 'classic', 'footer_text' => 'Custom footer', 'logo' => null],
        ]);

        InvoiceLayout::copySampleSettingsToAllBusinessDefaults();

        $existing = $this->db->getConnection()->table('invoice_layouts')->where('id', 6)->first();
        $custom = $this->db->getConnection()->table('invoice_layouts')->where('id', 7)->first();
        $newDefault = $this->db->getConnection()->table('invoice_layouts')
            ->where('business_id', 2)->where('is_default', 1)->first();
        $this->assertSame('slim', $existing->design);
        $this->assertSame('Sample footer', $existing->footer_text);
        $this->assertSame('Default', $existing->name);
        $this->assertSame('classic', $custom->design);
        $this->assertSame('Custom footer', $custom->footer_text);
        $this->assertSame('slim', $newDefault->design);
        $this->assertSame('sample.png', $newDefault->logo);
        $this->assertSame(5, $this->db->getConnection()->table('invoice_layouts')
            ->where('business_id', 5)->where('is_default', 1)->value('id'));
    }

    public function testNewBusinessDefaultCopiesCurrentSampleLayout(): void
    {
        $table = $this->db->getConnection()->table('invoice_layouts');
        $table->insert([
            'id' => 5, 'business_id' => 5, 'name' => 'Default', 'is_default' => 1,
            'design' => 'slim', 'footer_text' => 'Sample footer', 'logo' => 'sample.png',
        ]);

        $layout = InvoiceLayout::createDefaultFromSample(3);

        $this->assertSame(3, (int) $layout->business_id);
        $this->assertSame('Default', $layout->name);
        $this->assertTrue((bool) $layout->is_default);
        $this->assertSame('slim', $layout->design);
        $this->assertSame('Sample footer', $layout->footer_text);
        $this->assertSame('sample.png', $layout->logo);
    }
}
