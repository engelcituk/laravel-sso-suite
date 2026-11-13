<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_local_sessions', function (Blueprint $table): void {
            $table->char('session_hash', 64)->primary();
            $table->string('issuer', 191);
            $table->string('subject', 191);
            $table->string('sid', 64);
            $table->boolean('revoked')->default(false);
            $table->index(['issuer', 'sid']);
            $table->index(['issuer', 'subject']);
        });
        Schema::create('sso_logout_receipts', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->unsignedBigInteger('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_logout_receipts');
        Schema::dropIfExists('sso_local_sessions');
    }
};
