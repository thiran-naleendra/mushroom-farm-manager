<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->boolean('is_active');
        });
        DB::table('roles')->insert([
            ['name' => 'owner', 'is_active' => true],
            ['name' => 'manager', 'is_active' => false],
            ['name' => 'worker', 'is_active' => false],
        ]);
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('worker');
            $table->foreign('role')->references('name')->on('roles');
            $table->boolean('is_active')->default(true);
            $table->boolean('can_work_production')->default(false);
            $table->boolean('can_work_harvest')->default(false);
        });
        DB::table('users')->whereIn('id', DB::table('farms')->select('owner_id'))->update(['role' => 'owner']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role']);
            $table->dropColumn(['role', 'is_active', 'can_work_production', 'can_work_harvest']);
        });
        Schema::dropIfExists('roles');
    }
};
