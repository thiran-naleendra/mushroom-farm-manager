<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('timezone')->default('Asia/Colombo');
            $table->boolean('batch_tracking')->default(true);
            $table->boolean('qr_printing')->default(true);
            $table->boolean('grocery_sales')->default(false);
            $table->json('mushroom_varieties')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('farms', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'address', 'phone', 'email', 'timezone', 'batch_tracking', 'qr_printing', 'grocery_sales', 'mushroom_varieties']);
        });
    }
};
