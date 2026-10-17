<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvests', function (Blueprint $t) {
            $t->decimal('allocated_cost', 24, 6)->nullable();
            $t->decimal('cost_per_kg', 24, 6)->nullable();
            $t->foreignId('valued_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('valued_at')->nullable();
        });
        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });
        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('expense_category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('currency', 3)->nullable();
            $t->string('treatment')->default('operating');
            $t->text('notes')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->softDeletes();
            $t->index(['spent_on', 'treatment']);
        });
        Schema::create('expense_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('expense_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('harvests', function (Blueprint $t) {
            $t->dropForeign(['valued_by']);
            $t->dropColumn(['allocated_cost', 'cost_per_kg', 'valued_by', 'valued_at']);
        });
        Schema::dropIfExists('expense_audits');
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropForeign(['expense_category_id']);
            $t->dropForeign(['batch_id']);
            $t->dropForeign(['user_id']);
            $t->dropIndex(['spent_on', 'treatment']);
            $t->dropColumn(['expense_category_id', 'batch_id', 'user_id', 'currency', 'treatment', 'notes', 'revision', 'deleted_at']);
        });
        Schema::dropIfExists('expense_categories');
    }
};
