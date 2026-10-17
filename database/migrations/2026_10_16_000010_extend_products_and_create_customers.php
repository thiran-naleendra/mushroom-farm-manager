<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('type', 30)->default('packaged')->index();
            $table->decimal('weight', 12, 3)->nullable();
            $table->string('unit', 10)->default('g');
            $table->decimal('selling_price', 14, 2)->nullable();
            $table->unsignedInteger('pack_size_grams')->nullable()->change();
        });
        DB::table('products')->update(['weight' => DB::raw('pack_size_grams')]);
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 30)->index();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->char('currency', 3);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount_paid', 18, 2)->nullable();
        });
        Schema::create('customer_account_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('type', 30);
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3);
            $table->string('reason', 1000);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_entries');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('amount_paid');
        });
        Schema::dropIfExists('customers');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['type', 'weight', 'unit', 'selling_price']));
    }
};
