<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_logout_deliveries', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('sid', 64);
            $table->uuid('client_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('next_attempt');
            $table->unsignedBigInteger('delivered_at')->nullable();
            $table->unique(['sid', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_logout_deliveries');
    }
};
