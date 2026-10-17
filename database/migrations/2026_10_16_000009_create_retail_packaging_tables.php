<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('sku', 80)->unique();
            $table->string('variety', 100);
            $table->unsignedInteger('pack_size_grams');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('packaging_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('harvest_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id')->unique();
            $table->char('payload_hash', 64);
            $table->date('packaged_on')->index();
            $table->date('expires_on')->nullable()->index();
            $table->string('product_name', 150);
            $table->string('product_sku', 80);
            $table->string('variety', 100);
            $table->unsignedInteger('pack_size_grams');
            $table->unsignedInteger('packet_count');
            $table->bigInteger('packed_grams');
            $table->bigInteger('wastage_grams');
            $table->bigInteger('fresh_grams');
            $table->char('currency', 3);
            $table->decimal('fresh_cost_per_kg', 14, 2)->nullable();
            foreach (['fresh_cost', 'materials_cost', 'packaging_cost', 'total_cost', 'cost_per_packet'] as $cost) {
                $table->decimal($cost, 24, 6)->nullable();
            }
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('packaging_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 20);
            $table->string('material_name');
            $table->string('unit_symbol', 30);
            $table->decimal('quantity', 18, 6);
            $table->decimal('total_cost', 24, 6);
            $table->unique(['packaging_run_id', 'material_id']);
        });
        Schema::create('finished_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('packaging_run_id')->unique()->constrained()->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost', 24, 6)->nullable();
            $table->timestamps();
        });
        Schema::table('material_movements', function (Blueprint $table) {
            $table->foreignId('packaging_run_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('packaging_run_id'));
        Schema::dropIfExists('finished_stock_movements');
        Schema::dropIfExists('packaging_materials');
        Schema::dropIfExists('packaging_runs');
        Schema::dropIfExists('products');
    }
};
