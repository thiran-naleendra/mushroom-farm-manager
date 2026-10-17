<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerAccountEntry;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\FreshStockService;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class ProductCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'owner']);
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->actingAs($this->owner);
    }

    private function customer(array $changes = []): array
    {
        return array_replace(['name' => 'Buyer shop', 'type' => 'grocery', 'phone' => '0771234567', 'email' => 'buyer@example.com', 'credit_limit' => '1000.00', 'is_active' => true, 'notes' => 'Customer note'], $changes);
    }

    private function product(array $changes = []): array
    {
        return array_replace(['name' => 'Oyster fresh', 'sku' => 'OYS-FRESH', 'type' => 'fresh', 'variety' => 'Oyster', 'weight' => '1.000', 'unit' => 'kg', 'selling_price' => '500.00', 'is_active' => true], $changes);
    }

    private function sale(Customer $customer, array $changes = []): array
    {
        return array_replace(['customer_id' => $customer->id, 'sold_on' => today()->toDateString(), 'weight' => '1.000', 'price_per_kg' => '100.00', 'amount_paid' => '40.00'], $changes);
    }

    public function test_products_support_all_types_units_prices_and_empty_inventory(): void
    {
        $this->post('/products', $this->product())->assertSessionHasNoErrors();
        $this->post('/products', $this->product(['name' => 'Growing bags', 'sku' => 'BAGS', 'type' => 'cultivation_bag', 'unit' => 'bag', 'weight' => null]))->assertSessionHasNoErrors();
        $this->post('/products', $this->product(['name' => 'Retail pack', 'sku' => 'PACK', 'type' => 'packaged', 'pack_size_grams' => 250, 'unit' => 'g']))->assertSessionHasNoErrors();
        $fresh = Product::where('type', 'fresh')->first();
        $this->assertNull($fresh->pack_size_grams);
        $this->assertSame('500.00', $fresh->selling_price);
        $pack = Product::where('type', 'packaged')->first();
        $this->assertSame('250.000', $pack->weight);
        $this->assertSame(250, $pack->pack_size_grams);
        $this->get('/products')->assertOk()->assertSee('Fresh mushrooms by weight')->assertSee('0.000 kg')->assertSee('0 bags')->assertSee('0 packets');
        $this->assertDatabaseCount('finished_stock_movements', 0);
        $this->assertDatabaseCount('material_movements', 0);
        $this->get('/products?type=fresh')->assertSee('Oyster fresh')->assertDontSee('Retail pack');
    }

    public function test_product_availability_reuses_actual_fresh_and_healthy_bag_stock(): void
    {
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '10.000']);
        Sale::create(['customer' => 'Walk in', 'sold_on' => today()->toDateString(), 'weight' => '2.000', 'price_per_kg' => 100]);
        $batch = Batch::create(['code' => 'INTERNAL', 'variety' => 'Oyster', 'location' => 'Room', 'bags' => 100, 'started_on' => today()->toDateString(), 'stage' => 'prepared']);
        $batch->forceFill(['sold_bags' => 10, 'damaged_bags' => 5, 'contaminated_bags' => 4, 'discarded_bags' => 1])->save();
        $service = app(ProductService::class);
        $fresh = $service->save($this->owner, $this->product());
        $grams = $service->save($this->owner, $this->product(['sku' => 'FRESH-G', 'unit' => 'g']));
        $bag = $service->save($this->owner, $this->product(['sku' => 'BAG', 'type' => 'cultivation_bag', 'unit' => 'bag']));
        $stock = $service->stockLevels(collect([$fresh, $grams, $bag]));
        $this->assertSame(['quantity' => '8.000', 'unit' => 'kg'], $stock[$fresh->id]);
        $this->assertSame(['quantity' => '8000', 'unit' => 'g'], $stock[$grams->id]);
        $this->assertSame(['quantity' => '80', 'unit' => 'bags'], $stock[$bag->id]);
        $batch->update(['stage' => 'completed']);
        $this->assertSame('0', $service->stockLevels(collect([$bag]))[$bag->id]['quantity']);
        $this->delete('/products/'.$fresh->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('harvests', 1);
        $this->assertDatabaseCount('batches', 1);
    }

    public function test_invalid_product_types_units_weights_and_prices_are_rejected(): void
    {
        $this->post('/products', $this->product(['type' => 'invalid']))->assertSessionHasErrors('type');
        $this->post('/products', $this->product(['selling_price' => '-1']))->assertSessionHasErrors('selling_price');
        $this->post('/products', $this->product(['type' => 'cultivation_bag', 'unit' => 'kg']))->assertSessionHasErrors('unit');
        $this->post('/products', $this->product(['type' => 'packaged', 'unit' => 'g']))->assertSessionHasErrors('pack_size_grams');
        $this->post('/products', $this->product(['weight' => '0.0001']))->assertSessionHasErrors('weight');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_product_form_submits_one_unit_and_recovers_packet_fields_after_validation_errors(): void
    {
        $this->withSession(['_old_input' => ['type' => 'packaged', 'unit' => 'bag', 'name' => 'Oyster 200g', 'sku' => '0002']]);
        $response = $this->get('/products/create')->assertOk()->assertSee('Oyster 200g')->assertDontSee('@js(');
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $units = $xpath->query('//input[@name="unit"] | //select[@name="unit"]');
        $this->assertSame(1, $units->length);
        $this->assertSame('g', $units->item(0)->getAttribute('value'));
        $this->assertSame('200', $xpath->query('//input[@name="pack_size_grams"]')->item(0)->getAttribute('value'));
        $this->assertStringContainsString('productType', $xpath->query('//select[@name="type"]')->item(0)->getAttribute('x-model'));
        $this->post('/products', ['name' => 'Oyster 200g', 'sku' => '0002', 'type' => 'packaged', 'variety' => 'Oyster', 'pack_size_grams' => 200, 'unit' => 'g', 'selling_price' => '170', 'is_active' => true])->assertSessionHasNoErrors();
        $product = Product::where('sku', '0002')->firstOrFail();
        $this->assertSame('g', $product->unit);
        $this->assertSame(200, $product->pack_size_grams);
        $this->assertSame('170.00', $product->selling_price);
    }

    public function test_customer_crud_search_filters_contact_validation_and_archive_history(): void
    {
        $this->get('/customers/create')->assertOk();
        $this->post('/customers', $this->customer())->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->get('/customers?type=grocery&status=active&search=077123')->assertOk()->assertSee('Buyer shop');
        $this->get('/customers?type=restaurant')->assertOk()->assertSee('No customers found.');
        $this->get('/customers/'.$customer->id)->assertOk()->assertSee('No linked sales recorded.');
        $this->get('/customers/'.$customer->id.'/edit')->assertOk();
        $this->put('/customers/'.$customer->id, $this->customer(['name' => 'Renamed', 'is_active' => false]))->assertSessionHasNoErrors();
        $this->get('/customers?status=inactive')->assertOk()->assertSee('Renamed');
        $this->post('/customers', $this->customer(['email' => 'invalid']))->assertSessionHasErrors('email');
        $this->post('/customers', $this->customer(['type' => 'invalid']))->assertSessionHasErrors('type');
        $this->post('/customers', $this->customer(['credit_limit' => '-1']))->assertSessionHasErrors('credit_limit');
        $this->delete('/customers/'.$customer->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted($customer);
        $this->get('/customers/'.$customer->id)->assertOk()->assertSee('Archived');
    }

    public function test_explicit_opening_balance_is_recorded_once_and_cannot_be_overwritten_on_edit(): void
    {
        $this->post('/customers', $this->customer(['opening_balance' => '123.45', 'opening_reason' => 'Migrated from signed account']))->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertSame('123.45', (string) app(CustomerService::class)->balance($customer));
        $this->assertDatabaseCount('customer_account_entries', 1);
        $this->get('/customers/'.$customer->id)->assertOk()->assertSee('123.45')->assertSee('Migrated from signed account');
        $this->put('/customers/'.$customer->id, $this->customer(['opening_balance' => '1']))->assertSessionHasErrors('opening_balance');
        $this->delete('/customers/'.$customer->id)->assertSessionHasErrors('customer');
        $this->put('/customers/'.$customer->id, $this->customer(['credit_limit' => '100']))->assertSessionHasErrors('credit_limit');
        $this->assertSame('1000.00', $customer->fresh()->credit_limit);
        $this->post('/customers', $this->customer(['opening_balance' => 1]))->assertSessionHasErrors('opening_reason');
        $this->post('/customers', $this->customer(['opening_balance' => 1001, 'opening_reason' => 'Existing debt']))->assertSessionHasErrors('credit_limit');
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_audited_adjustments_are_exact_idempotent_and_bounded(): void
    {
        $customer = app(CustomerService::class)->save($this->owner, $this->customer());
        $data = ['request_id' => (string) Str::uuid(), 'direction' => 'increase', 'amount' => '0.10', 'reason' => 'Documented correction'];
        $this->post('/customers/'.$customer->id.'/balance', $data)->assertSessionHasNoErrors();
        $this->post('/customers/'.$customer->id.'/balance', $data)->assertSessionHasNoErrors();
        $this->assertSame('0.10', (string) app(CustomerService::class)->balance($customer));
        $this->post('/customers/'.$customer->id.'/balance', array_replace($data, ['amount' => '0.20']))->assertSessionHasErrors('request_id');
        $this->post('/customers/'.$customer->id.'/balance', array_replace($data, ['request_id' => (string) Str::uuid(), 'direction' => 'decrease', 'amount' => '0.11']))->assertSessionHasErrors('credit_limit');
        $this->post('/customers/'.$customer->id.'/balance', array_replace($data, ['request_id' => (string) Str::uuid(), 'direction' => 'decrease']))->assertSessionHasNoErrors();
        $this->assertSame('0.00', (string) app(CustomerService::class)->balance($customer));
        $this->assertDatabaseCount('customer_account_entries', 2);
        $this->delete('/customers/'.$customer->id)->assertSessionHasNoErrors();
        $this->get('/customers/'.$customer->id)->assertOk()->assertSee('Documented correction');
    }

    public function test_linked_sales_update_history_and_balances_without_guessing_legacy_payments(): void
    {
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '10.000']);
        $customer = app(CustomerService::class)->save($this->owner, $this->customer());
        $this->get('/sales/legacy')->assertOk()->assertSee('Buyer shop')->assertSee('Amount paid at sale');
        $this->post('/sales', $this->sale($customer))->assertSessionHasNoErrors();
        $this->assertSame('60.00', (string) app(CustomerService::class)->balance($customer));
        $sale = Sale::firstOrFail();
        app(CustomerService::class)->recordSale($this->owner, $customer, $sale);
        $this->assertDatabaseCount('customer_account_entries', 1);
        $this->put('/customers/'.$customer->id, $this->customer(['name' => 'Renamed buyer']))->assertSessionHasNoErrors();
        $this->get('/customers/'.$customer->id)->assertOk()->assertSee('Buyer shop')->assertSee('60.00');
        $this->post('/sales', ['customer' => 'Buyer shop', 'sold_on' => today()->toDateString(), 'weight' => 1, 'price_per_kg' => 100])->assertSessionHasNoErrors();
        $this->assertSame(1, $customer->sales()->count());
        $this->assertSame('60.00', (string) app(CustomerService::class)->balance($customer));
    }

    public function test_linked_sale_restrictions_roll_back_stock_and_accounts(): void
    {
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '10.000']);
        $customer = app(CustomerService::class)->save($this->owner, $this->customer(['credit_limit' => '50']));
        $this->post('/sales', $this->sale($customer, ['amount_paid' => null]))->assertSessionHasErrors('amount_paid');
        $this->post('/sales', $this->sale($customer, ['amount_paid' => 101]))->assertSessionHasErrors('amount_paid');
        $this->post('/sales', $this->sale($customer))->assertSessionHasErrors('credit_limit');
        $customer->update(['is_active' => false]);
        $this->post('/sales', $this->sale($customer, ['amount_paid' => 100]))->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customer_account_entries', 0);
        $this->assertSame(10000, app(FreshStockService::class)->totals()['available']);
    }

    public function test_cash_only_customers_allow_paid_sales_and_do_not_accrue_debt(): void
    {
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '1.000']);
        $customer = app(CustomerService::class)->save($this->owner, $this->customer(['credit_limit' => 0]));
        $this->post('/sales', $this->sale($customer, ['amount_paid' => 100]))->assertSessionHasNoErrors();
        $this->assertSame('0.00', (string) app(CustomerService::class)->balance($customer));
        $this->delete('/customers/'.$customer->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('customer_account_entries', 1);
    }

    public function test_manager_can_manage_contacts_and_products_but_only_owner_changes_balances(): void
    {
        Role::where('name', 'manager')->update(['is_active' => true]);
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/customers')->assertOk();
        $this->post('/customers', $this->customer())->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->post('/customers/'.$customer->id.'/balance', ['request_id' => (string) Str::uuid(), 'direction' => 'increase', 'amount' => 1, 'reason' => 'Correction'])->assertForbidden();
        $this->post('/customers', $this->customer(['opening_balance' => 1, 'opening_reason' => 'Opening']))->assertSessionHasErrors('opening_balance');
        $this->delete('/customers/'.$customer->id)->assertForbidden();
        $this->post('/products', $this->product())->assertSessionHasNoErrors();
        Role::where('name', 'worker')->update(['is_active' => true]);
        $this->actingAs(User::factory()->create(['role' => 'worker', 'can_work_harvest' => true]));
        $this->get('/customers')->assertForbidden();
        $this->get('/customers/'.$customer->id)->assertForbidden();
        $this->post('/customers', $this->customer())->assertForbidden();
        $this->get('/products')->assertForbidden();
        $this->post('/products', $this->product())->assertForbidden();
        $this->get('/')->assertDontSee('href="'.route('customers.index').'"', false);
    }

    public function test_customer_history_and_linked_sales_cannot_be_deleted_or_rewritten(): void
    {
        Harvest::create(['variety' => 'Oyster', 'harvested_on' => today()->toDateString(), 'weight' => '1.000']);
        $customer = app(CustomerService::class)->save($this->owner, $this->customer());
        $this->post('/sales', $this->sale($customer))->assertSessionHasNoErrors();
        foreach ([Sale::first(), CustomerAccountEntry::first()] as $record) {
            try {
                $record->delete();
                $this->fail('History was deleted.');
            } catch (LogicException) {
                $this->assertTrue($record->exists);
            }
        }
        $this->expectException(LogicException::class);
        Sale::first()->update(['price_per_kg' => 1]);
    }
}
