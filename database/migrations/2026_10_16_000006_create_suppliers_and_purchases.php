<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('supplier_name', 120);
            $table->date('purchased_on')->index();
            $table->string('reference', 120);
            $table->uuid('request_id')->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('discount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'reference']);
        });
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained('material_units')->restrictOnDelete();
            $table->string('material_name', 120);
            $table->decimal('quantity', 18, 6);
            $table->decimal('stock_quantity', 18, 6);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('discount', 18, 2);
            $table->decimal('total', 18, 2);
            $table->timestamps();
        });
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('type', 20);
            $table->date('paid_on');
            $table->decimal('amount', 18, 2);
            $table->string('reference', 120);
            $table->timestamps();
        });
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->decimal('quantity', 18, 6);
            $table->decimal('stock_quantity', 18, 6);
            $table->decimal('credit', 18, 2);
            $table->decimal('carrying_value', 24, 6);
            $table->decimal('price_variance', 24, 6);
            $table->date('returned_on');
            $table->string('reason', 500);
            $table->timestamps();
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('inventory_value', 24, 6)->default(0);
            $table->decimal('average_cost', 24, 6)->default(0);
        });
        DB::table('materials')->update(['inventory_value' => DB::raw('quantity * unit_cost'), 'average_cost' => DB::raw('unit_cost')]);
        Schema::table('material_movements', function (Blueprint $table) {
            $table->foreignId('purchase_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('value_change', 24, 6)->nullable();
            $table->decimal('value_after', 24, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropForeign(['purchase_line_id']);
            $table->dropColumn(['purchase_line_id', 'value_change', 'value_after']);
        });
        Schema::table('materials', fn (Blueprint $table) => $table->dropColumn(['inventory_value', 'average_cost']));
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_payments');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
