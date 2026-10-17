<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 40)->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('request_id')->unique();
            $t->char('payload_hash', 64);
            $t->date('sold_on')->index();
            $t->string('channel', 20);
            $t->string('sale_type', 20);
            $t->string('status', 20)->default('confirmed');
            $t->char('currency', 3);
            $t->json('seller_snapshot');
            $t->json('customer_snapshot');
            $t->decimal('subtotal', 18, 2);
            $t->decimal('discount', 18, 2);
            $t->decimal('total', 18, 2);
            $t->text('notes')->nullable();
            $t->text('void_reason')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('voided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('product_name', 150);
            $t->string('sku', 80);
            $t->string('variety', 100);
            $t->string('type', 30);
            $t->string('unit', 10);
            $t->unsignedInteger('pack_size_grams')->nullable();
            $t->decimal('quantity', 18, 3);
            $t->bigInteger('stock_quantity');
            foreach (['selling_price', 'subtotal', 'discount', 'total'] as $column) {
                $t->decimal($column, 18, 2);
            }
            $t->unique(['invoice_id', 'product_id']);
        });
        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id')->unique();
            $t->char('payload_hash', 64);
            $t->string('type', 20);
            $t->string('method', 20);
            $t->date('paid_on')->index();
            $t->decimal('amount', 18, 2);
            $t->string('reference')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_returns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id')->unique();
            $t->char('payload_hash', 64);
            $t->date('returned_on')->index();
            $t->string('reason', 1000);
            $t->decimal('credit', 18, 2);
            $t->timestamps();
        });
        Schema::create('invoice_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_line_id')->constrained()->restrictOnDelete();
            $t->bigInteger('stock_quantity');
            $t->decimal('quantity', 18, 3);
            $t->decimal('credit', 18, 2);
            $t->boolean('restock');
            $t->unique(['invoice_return_id', 'invoice_line_id']);
        });
        // Provenance allocations refer to existing inventory, rather than creating another stock balance.
        Schema::create('invoice_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('harvest_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('packaging_run_id')->nullable()->constrained()->restrictOnDelete();
            $t->bigInteger('quantity');
            $t->decimal('unit_cost', 24, 6)->nullable();
        });
        Schema::create('invoice_return_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_return_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_allocation_id')->constrained()->restrictOnDelete();
            $t->bigInteger('quantity');
            $t->unique(['invoice_return_line_id', 'invoice_allocation_id'], 'invoice_return_allocation_unique');
        });
        Schema::table('finished_stock_movements', function (Blueprint $t) {
            $t->foreignId('packaging_run_id')->nullable()->change();
            $t->foreignId('source_packaging_run_id')->nullable()->constrained('packaging_runs')->restrictOnDelete();
            $t->foreignId('invoice_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_return_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('moved_on')->nullable()->index();
        });
        DB::table('finished_stock_movements')->orderBy('id')->each(function ($row) {
            DB::table('finished_stock_movements')->where('id', $row->id)->update(['moved_on' => DB::table('packaging_runs')->where('id', $row->packaging_run_id)->value('packaged_on')]);
        });
        Schema::table('customer_account_entries', function (Blueprint $t) {
            $t->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('invoice_return_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::table('sales', function (Blueprint $t) {
            $t->uuid('request_id')->nullable()->unique();
            $t->char('payload_hash', 64)->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropUnique(['request_id']);
            $t->dropColumn(['request_id', 'payload_hash']);
        });
        Schema::table('customer_account_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('invoice_payment_id');
            $t->dropConstrainedForeignId('invoice_return_id');
            $t->dropConstrainedForeignId('invoice_id');
        });
        Schema::table('finished_stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('source_packaging_run_id');
            $t->dropConstrainedForeignId('invoice_line_id');
            $t->dropConstrainedForeignId('invoice_return_line_id');
            $t->dropColumn('moved_on');
        });
        foreach (['invoice_return_allocations', 'invoice_allocations', 'invoice_return_lines', 'invoice_returns', 'invoice_payments', 'invoice_lines', 'invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
