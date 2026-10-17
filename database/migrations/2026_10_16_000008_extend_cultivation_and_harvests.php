<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedInteger('discarded_bags')->default(0);
            $table->text('growth_notes')->nullable();
            foreach (['prepared_on', 'incubating_on', 'fruiting_on', 'harvesting_on', 'completed_on'] as $date) {
                $table->date($date)->nullable();
            }
            $table->string('stage')->default('prepared')->change();
        });
        DB::table('batches')->update(['prepared_on' => DB::raw('started_on')]);
        Schema::table('harvests', function (Blueprint $table) {
            $table->foreignId('batch_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('variety', 100)->nullable()->index();
            $table->uuid('request_id')->nullable()->unique();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedInteger('cycle')->nullable();
            $table->decimal('gross_weight', 10, 3)->nullable();
            $table->decimal('rejected_weight', 10, 3)->default(0);
            $table->bigInteger('gross_grams')->default(0);
            $table->bigInteger('rejected_grams')->default(0);
            $table->bigInteger('saleable_grams')->default(0);
            $table->text('notes')->nullable();
            $table->index(['batch_id', 'cycle']);
        });
        DB::table('harvests')->update([
            'gross_weight' => DB::raw('weight'), 'gross_grams' => DB::raw('ROUND(weight * 1000)'),
            'saleable_grams' => DB::raw('ROUND(weight * 1000)'),
            'variety' => DB::raw('(SELECT variety FROM batches WHERE batches.id = harvests.batch_id)'),
        ]);
        Schema::table('sales', fn (Blueprint $table) => $table->bigInteger('weight_grams')->default(0));
        DB::table('sales')->update(['weight_grams' => DB::raw('ROUND(weight * 1000)')]);
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('weight_grams'));
        Schema::table('harvests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['batch_id', 'cycle']);
            $table->dropColumn(['user_id', 'variety', 'request_id', 'payload_hash', 'cycle', 'gross_weight', 'rejected_weight', 'gross_grams', 'rejected_grams', 'saleable_grams', 'notes']);
            // batch_id stays nullable so rolling back never deletes unlinked harvest history.
        });
        Schema::table('batches', function (Blueprint $table) {
            $table->dropColumn(['discarded_bags', 'growth_notes', 'prepared_on', 'incubating_on', 'fruiting_on', 'harvesting_on', 'completed_on']);
            $table->string('stage')->default('incubating')->change();
        });
    }
};
