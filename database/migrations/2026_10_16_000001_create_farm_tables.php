<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name');
            $table->string('mode')->default('simple');
            $table->string('currency', 3)->default('LKR');
            $table->timestamps();
        });
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('variety');
            $table->string('location');
            $table->unsignedInteger('bags');
            $table->date('started_on');
            $table->string('stage')->default('incubating');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('harvests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->restrictOnDelete();
            $table->date('harvested_on');
            $table->decimal('weight', 10, 3);
            $table->timestamps();
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('customer');
            $table->date('sold_on');
            $table->decimal('weight', 10, 3);
            $table->decimal('price_per_kg', 12, 2);
            $table->timestamps();
        });
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->date('spent_on');
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'sales', 'harvests', 'batches', 'farms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
