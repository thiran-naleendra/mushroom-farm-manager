<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('material_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('symbol', 20)->unique();
            $table->string('dimension', 20);
            $table->decimal('factor', 12, 6)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        // Unit definitions are configuration, not business stock.
        foreach ([['Kilogram', 'kg', 'mass', 1], ['Gram', 'g', 'mass', '0.001'], ['Litre', 'L', 'volume', 1], ['Millilitre', 'ml', 'volume', '0.001'], ['Piece', 'pc', 'count', 1]] as [$name, $symbol, $dimension, $factor]) {
            DB::table('material_units')->insert(compact('name', 'symbol', 'dimension', 'factor') + ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->index();
            $table->string('sku', 60)->unique();
            $table->foreignId('material_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained()->restrictOnDelete();
            $table->string('supply_type', 20)->index();
            $table->decimal('quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('minimum_stock', 18, 6)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('material_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->string('type', 20);
            $table->decimal('input_quantity', 18, 6);
            $table->decimal('quantity', 18, 6);
            $table->bigInteger('quantity_micros');
            $table->decimal('balance_after', 18, 6);
            $table->decimal('unit_cost', 14, 2);
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['material_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_movements');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('material_units');
        Schema::dropIfExists('material_categories');
    }
};
