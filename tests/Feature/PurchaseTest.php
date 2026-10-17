<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\MaterialUnit;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\MaterialService;
use App\Services\MaterialStockService;
use App\Services\PurchaseAmounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['role' => 'owner'])->save();
        Farm::create(['owner_id' => $this->owner->id, 'name' => 'Farm', 'mode' => 'simple']);
        $this->supplier = Supplier::create(['name' => 'Supplier']);
        $category = MaterialCategory::create(['name' => 'Inputs', 'is_active' => true]);
        $this->material = app(MaterialService::class)->save($this->owner, [
            'name' => 'Sawdust', 'sku' => 'SAW', 'material_category_id' => $category->id,
            'material_unit_id' => MaterialUnit::where('symbol', 'kg')->value('id'), 'supply_type' => 'growing',
            'quantity' => '0', 'unit_cost' => '10', 'minimum_stock' => '0', 'is_active' => true,
        ]);
        $this->actingAs($this->owner);
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'supplier_id' => $this->supplier->id, 'purchased_on' => today()->toDateString(),
            'reference' => 'INV-'.Str::random(8), 'request_id' => (string) Str::uuid(),
            'lines' => [['material_id' => $this->material->id, 'material_unit_id' => $this->material->material_unit_id, 'quantity' => '10', 'unit_price' => '10.00', 'discount' => '0.00']],
        ], $overrides);
    }

    private function purchase(array $overrides = [], bool $receive = false): Purchase
    {
        $this->post('/purchases', $this->input($overrides))->assertSessionHasNoErrors();
        $purchase = Purchase::latest('id')->firstOrFail();
        if ($receive) {
            $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasNoErrors();
        }

        return $purchase->refresh();
    }

    private function payment(array $overrides = []): array
    {
        return array_replace(['type' => 'payment', 'amount' => '50.00', 'paid_on' => today()->toDateString(), 'reference' => 'Bank transfer', 'request_id' => (string) Str::uuid()], $overrides);
    }

    private function returned(Purchase $purchase, array $overrides = []): array
    {
        return array_replace(['purchase_line_id' => $purchase->lines()->firstOrFail()->id, 'quantity' => '2', 'returned_on' => today()->toDateString(), 'reason' => 'Damaged material', 'request_id' => (string) Str::uuid()], $overrides);
    }

    public function test_empty_screens_and_supplier_crud_reports_and_archiving(): void
    {
        $this->get('/purchases')->assertOk()->assertSee('No purchases recorded.');
        $this->get('/purchases/create')->assertOk()->assertSee('Save pending purchase');
        $this->get('/suppliers')->assertOk();
        $this->get('/suppliers/create')->assertOk();
        $this->post('/suppliers', ['name' => 'New supplier', 'email' => 'invalid'])->assertSessionHasErrors('email');
        $this->post('/suppliers', ['name' => 'New supplier', 'phone' => '123', 'email' => 'supplier@example.com', 'address' => 'Farm Road', 'notes' => 'Contact first'])->assertRedirect('/suppliers');
        $supplier = Supplier::latest('id')->firstOrFail();
        $this->get('/suppliers/'.$supplier->id.'/edit')->assertOk();
        $this->put('/suppliers/'.$supplier->id, ['name' => 'Updated supplier'])->assertRedirect('/suppliers');
        $this->get('/suppliers/'.$supplier->id)->assertOk()->assertSee('Supplier report');
        $this->delete('/suppliers/'.$supplier->id)->assertRedirect('/suppliers');
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->get('/suppliers/'.$supplier->id)->assertOk();
        $this->post('/purchases', $this->input(['supplier_id' => $supplier->id]))->assertNotFound();
    }

    public function test_multiline_receiving_converts_units_and_recalculates_weighted_average_without_expenses(): void
    {
        app(MaterialStockService::class)->adjust($this->owner, $this->material, [
            'quantity' => 10, 'material_unit_id' => $this->material->material_unit_id, 'type' => 'add', 'reason' => 'Opening count', 'request_id' => (string) Str::uuid(),
        ]);
        $other = app(MaterialService::class)->save($this->owner, [
            'name' => 'Liquid input', 'sku' => 'LIQ', 'material_category_id' => $this->material->material_category_id,
            'material_unit_id' => MaterialUnit::where('symbol', 'L')->value('id'), 'supply_type' => 'growing',
            'quantity' => 0, 'unit_cost' => 0, 'minimum_stock' => 0, 'is_active' => true,
        ]);
        $purchase = $this->purchase(['lines' => [
            ['material_id' => $this->material->id, 'material_unit_id' => MaterialUnit::where('symbol', 'g')->value('id'), 'quantity' => 5000, 'unit_price' => '0.02', 'discount' => 10],
            ['material_id' => $other->id, 'material_unit_id' => $other->material_unit_id, 'quantity' => 2, 'unit_price' => 20, 'discount' => 0],
        ]]);
        $this->assertSame('130.00', $purchase->total);
        $this->assertSame('10.000000', $this->material->fresh()->quantity);
        $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasNoErrors();
        $material = $this->material->fresh();
        $this->assertSame('15.000000', $material->quantity);
        $this->assertSame('190.000000', $material->inventory_value);
        $this->assertSame('12.666667', $material->average_cost);
        $this->assertSame('12.67', $material->unit_cost);
        $this->assertSame('2.000000', $other->fresh()->quantity);
        $this->assertSame('0.02', $purchase->lines()->first()->unit_price);
        $this->assertDatabaseCount('expenses', 0);
        $this->get('/purchases/'.$purchase->id)->assertOk()->assertSee('Original purchase lines');
    }

    public function test_duplicate_purchase_submission_and_receiving_never_double_stock(): void
    {
        $input = $this->input();
        $this->post('/purchases', $input)->assertSessionHasNoErrors();
        $this->post('/purchases', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchases', 1);
        $purchase = Purchase::firstOrFail();
        $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasNoErrors();
        $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasNoErrors();
        $this->assertSame('10.000000', $this->material->fresh()->quantity);
        $this->assertDatabaseCount('material_movements', 1);
        $this->post('/purchases', array_replace($input, ['request_id' => (string) Str::uuid()]))->assertSessionHasErrors('reference');
        $this->post('/purchases', array_replace($input, ['reference' => 'Different']))->assertSessionHasErrors('reference');
        app(MaterialStockService::class)->receivePurchase($this->owner, $this->material, $purchase->lines()->first());
        $this->assertDatabaseCount('material_movements', 1);
    }

    public function test_receiving_failure_rolls_back_all_stock_and_status(): void
    {
        $other = app(MaterialService::class)->save($this->owner, [
            'name' => 'Spawn', 'sku' => 'SPAWN', 'material_category_id' => $this->material->material_category_id,
            'material_unit_id' => $this->material->material_unit_id, 'supply_type' => 'growing', 'quantity' => 0, 'unit_cost' => 1, 'minimum_stock' => 0, 'is_active' => true,
        ]);
        $input = $this->input();
        $input['lines'][] = array_replace($input['lines'][0], ['material_id' => $other->id]);
        $this->post('/purchases', $input)->assertSessionHasNoErrors();
        $other->update(['is_active' => false]);
        $purchase = Purchase::firstOrFail();
        $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('material_movements', 0);
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertSame('0.000000', $this->material->fresh()->quantity);
        $this->assertSame('0.000000', $this->material->fresh()->inventory_value);
    }

    public function test_partial_full_payments_overpayment_and_duplicate_payment(): void
    {
        $purchase = $this->purchase();
        $url = '/purchases/'.$purchase->id.'/payments';
        $payment = $this->payment();
        $this->post($url, $payment)->assertSessionHasNoErrors();
        $this->post($url, $payment)->assertSessionHasNoErrors();
        $this->assertSame('partial', PurchaseAmounts::summary($purchase)['payment_status']);
        $this->assertDatabaseCount('purchase_payments', 1);
        $this->post($url, array_replace($payment, ['amount' => 40]))->assertSessionHasErrors('amount');
        $this->post($url, $this->payment())->assertSessionHasNoErrors();
        $this->assertSame('paid', PurchaseAmounts::summary($purchase)['payment_status']);
        $this->post($url, $this->payment(['amount' => 1]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('purchase_payments', 2);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_partial_returns_supplier_credit_and_refunds_are_idempotent(): void
    {
        $purchase = $this->purchase([], true);
        $this->post('/purchases/'.$purchase->id.'/payments', $this->payment(['amount' => 100]))->assertSessionHasNoErrors();
        $returned = $this->returned($purchase);
        $url = '/purchases/'.$purchase->id.'/returns';
        $this->post($url, $returned)->assertSessionHasNoErrors();
        $this->post($url, $returned)->assertSessionHasNoErrors();
        $this->assertSame('8.000000', $this->material->fresh()->quantity);
        $this->assertSame('80.000000', $this->material->fresh()->inventory_value);
        $this->assertSame('10.000000', $this->material->fresh()->average_cost);
        $this->assertSame('20.00', PurchaseReturn::firstOrFail()->credit);
        $this->assertSame('credit_due', PurchaseAmounts::summary($purchase)['payment_status']);
        $refund = $this->payment(['type' => 'refund', 'amount' => 20]);
        $this->post('/purchases/'.$purchase->id.'/payments', $refund)->assertSessionHasNoErrors();
        $this->post('/purchases/'.$purchase->id.'/payments', $refund)->assertSessionHasNoErrors();
        $this->assertSame('0.00', PurchaseAmounts::summary($purchase)['balance']);
        $this->post('/purchases/'.$purchase->id.'/payments', $this->payment(['type' => 'refund', 'amount' => 1]))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertDatabaseCount('material_movements', 2);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_returns_cannot_exceed_purchased_or_available_stock(): void
    {
        $purchase = $this->purchase([], true);
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase, ['quantity' => 11]))->assertSessionHasErrors('quantity');
        app(MaterialStockService::class)->adjust($this->owner, $this->material, [
            'quantity' => 9, 'material_unit_id' => $this->material->material_unit_id, 'type' => 'remove', 'reason' => 'Consumed', 'request_id' => (string) Str::uuid(),
        ]);
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase))->assertSessionHasErrors('quantity');
        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertSame('1.000000', $this->material->fresh()->quantity);
        $this->assertSame('10.000000', $this->material->fresh()->inventory_value);
    }

    public function test_return_rounding_preserves_full_discounted_credit_and_carrying_price_variance(): void
    {
        $input = $this->input();
        $input['lines'][0] = array_replace($input['lines'][0], ['quantity' => 3, 'unit_price' => 1, 'discount' => 2]);
        $purchase = $this->purchase(['lines' => $input['lines']], true);
        foreach (range(1, 3) as $index) {
            $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase, ['quantity' => 1]))->assertSessionHasNoErrors();
        }
        $this->assertSame(['0.33', '0.34', '0.33'], PurchaseReturn::orderBy('id')->get()->pluck('credit')->all());
        $this->assertSame('1.00', PurchaseAmounts::summary($purchase)['returns']);
        $this->assertSame('0.000000', $this->material->fresh()->inventory_value);
        $this->assertSame('0.000000', $this->material->fresh()->quantity);
        $this->assertSame('1.00', $purchase->lines()->first()->unit_price);
    }

    public function test_supplier_report_filters_and_archived_history_keep_original_prices(): void
    {
        $purchase = $this->purchase([], true);
        $this->post('/purchases/'.$purchase->id.'/payments', $this->payment())->assertSessionHasNoErrors();
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase))->assertSessionHasNoErrors();
        $this->get('/purchases?payment_status=partial')->assertOk()->assertSee($purchase->reference);
        $this->get('/purchases?payment_status=paid')->assertOk()->assertDontSee($purchase->reference);
        $this->get('/suppliers/'.$this->supplier->id)->assertOk()->assertViewHas('totals', fn ($totals) => $totals['total'] == 100 && $totals['returns'] == 20 && $totals['paid'] == 50 && $totals['balance'] == 30);
        $this->delete('/suppliers/'.$this->supplier->id)->assertRedirect('/suppliers');
        $this->get('/purchases/'.$purchase->id)->assertOk()->assertSee('Supplier');
        $this->get('/suppliers/'.$this->supplier->id)->assertOk()->assertSee($purchase->reference);
    }

    public function test_purchase_and_transaction_validation_and_backend_permissions(): void
    {
        $input = $this->input();
        $input['lines'][0]['discount'] = 101;
        $this->post('/purchases', $input)->assertSessionHasErrors('lines.0.discount');
        $input = $this->input();
        $input['lines'][0]['material_unit_id'] = MaterialUnit::where('symbol', 'L')->value('id');
        $this->post('/purchases', $input)->assertSessionHasErrors('material_unit_id');
        $this->assertDatabaseCount('purchases', 0);
        $purchase = $this->purchase();
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase))->assertSessionHasErrors('returned_on');
        Role::whereIn('name', ['manager', 'worker'])->update(['is_active' => true]);
        $manager = User::factory()->create();
        $manager->forceFill(['role' => 'manager'])->save();
        $this->actingAs($manager)->get('/purchases')->assertOk();
        $this->get('/suppliers')->assertOk();
        $this->post('/purchases/'.$purchase->id.'/receive')->assertSessionHasNoErrors();
        $this->post('/purchases/'.$purchase->id.'/payments', $this->payment())->assertForbidden();
        $this->get('/suppliers/'.$this->supplier->id)->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/purchases')->assertForbidden();
        $this->post('/purchases', $this->input())->assertForbidden();
        $this->post('/purchases/'.$purchase->id.'/receive')->assertForbidden();
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase))->assertForbidden();
        $this->get('/suppliers')->assertForbidden();
    }

    public function test_original_purchase_lines_cannot_be_repriced(): void
    {
        $purchase = $this->purchase();
        $this->expectException(\LogicException::class);
        $purchase->lines()->first()->update(['unit_price' => 1]);
    }

    public function test_returns_use_weighted_carrying_cost_and_preserve_original_price_variance(): void
    {
        app(MaterialStockService::class)->adjust($this->owner, $this->material, [
            'quantity' => 10, 'material_unit_id' => $this->material->material_unit_id, 'type' => 'add', 'reason' => 'Initial count', 'request_id' => (string) Str::uuid(),
        ]);
        $input = $this->input();
        $input['lines'][0]['unit_price'] = 20;
        $purchase = $this->purchase(['lines' => $input['lines']], true);
        $this->assertSame('15.000000', $this->material->fresh()->average_cost);
        $this->post('/purchases/'.$purchase->id.'/returns', $this->returned($purchase, ['quantity' => 5]))->assertSessionHasNoErrors();
        $return = PurchaseReturn::firstOrFail();
        $this->assertSame('100.00', $return->credit);
        $this->assertSame('75.000000', $return->carrying_value);
        $this->assertSame('25.000000', $return->price_variance);
        $this->assertSame('225.000000', $this->material->fresh()->inventory_value);
        $this->assertSame('15.000000', $this->material->fresh()->average_cost);
        $this->assertSame('20.00', $purchase->lines()->first()->unit_price);
        $this->assertDatabaseCount('expenses', 0);
    }
}
