<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            $table->unsignedInteger('contaminated_bags')->default(0);
            $table->date('expected_harvest_on')->nullable()->index();
            $table->index('stage');
            $table->index(['updated_at', 'id']);
        });
        Schema::table('farms', function (Blueprint $table) {
            $table->decimal('fresh_stock_alert_kg', 10, 3)->nullable();
        });
        foreach (['harvests' => 'harvested_on', 'sales' => 'sold_on', 'expenses' => 'spent_on'] as $name => $date) {
            Schema::table($name, function (Blueprint $table) use ($date) {
                $table->index($date);
                $table->index(['created_at', 'id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['harvests' => 'harvested_on', 'sales' => 'sold_on', 'expenses' => 'spent_on'] as $name => $date) {
            Schema::table($name, function (Blueprint $table) use ($date) {
                $table->dropIndex([$date]);
                $table->dropIndex(['created_at', 'id']);
            });
        }
        Schema::table('farms', fn (Blueprint $table) => $table->dropColumn('fresh_stock_alert_kg'));
        Schema::table('batches', function (Blueprint $table) {
            $table->dropIndex(['expected_harvest_on']);
            $table->dropIndex(['stage']);
            $table->dropIndex(['updated_at', 'id']);
            $table->dropColumn(['contaminated_bags', 'expected_harvest_on']);
        });
    }
};
