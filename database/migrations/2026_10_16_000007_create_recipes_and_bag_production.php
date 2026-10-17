<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('variety', 100);
            $table->unsignedInteger('reference_bags');
            $table->unsignedInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->unique(['recipe_id', 'material_id']);
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->foreignId('recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('recipe_name', 120)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('production_status', 20)->default('legacy')->index();
            $table->uuid('request_id')->nullable()->unique();
            $table->char('payload_hash', 64)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('material_cost', 24, 6)->nullable();
            $table->decimal('labour_cost', 14, 2)->nullable();
            $table->decimal('additional_cost', 14, 2)->nullable();
            $table->decimal('total_cost', 24, 6)->nullable();
            $table->decimal('cost_per_bag', 24, 6)->nullable();
            $table->unsignedInteger('sold_bags')->default(0);
            $table->unsignedInteger('damaged_bags')->default(0);
        });
        Schema::create('production_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_unit_id')->constrained('material_units')->restrictOnDelete();
            $table->string('material_name', 120);
            $table->string('input_unit', 20);
            $table->string('stock_unit', 20);
            $table->decimal('recipe_quantity', 18, 6);
            $table->unsignedInteger('reference_bags');
            $table->decimal('quantity', 18, 6);
            $table->decimal('stock_quantity', 18, 6);
            $table->decimal('unit_cost', 24, 6);
            $table->decimal('total_cost', 24, 6);
            $table->timestamps();
        });
        Schema::create('batch_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 30);
            $table->string('reason', 500);
            $table->json('before');
            $table->json('after');
            $table->timestamps();
        });
        Schema::table('material_movements', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('material_movements', function (Blueprint $table) {
            $table->dropForeign(['batch_id']);
            $table->dropColumn('batch_id');
        });
        Schema::dropIfExists('batch_audits');
        Schema::dropIfExists('production_ingredients');
        Schema::table('batches', function (Blueprint $table) {
            $table->dropForeign(['recipe_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['recipe_id', 'user_id', 'recipe_name', 'reference', 'production_status', 'request_id', 'payload_hash', 'currency', 'material_cost', 'labour_cost', 'additional_cost', 'total_cost', 'cost_per_bag', 'sold_bags', 'damaged_bags']);
        });
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
