<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('request_id')->nullable()->unique();
            $table->char('payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['request_id']);
            $table->dropColumn(['request_id', 'payload_hash']);
        });
    }
};
