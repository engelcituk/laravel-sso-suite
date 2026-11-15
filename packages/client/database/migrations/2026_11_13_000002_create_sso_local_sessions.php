<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_identities', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->collation('utf8mb4_bin');
            }
            $table->id();
            $table->string('issuer', 255);
            $table->string('subject', 255);
            $table->string('user_type', 191);
            $table->string('user_id', 191)->nullable();
            $table->unique(['issuer', 'subject']);
            $table->index(['user_type', 'user_id']);
        });
        Schema::create('sso_local_sessions', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->collation('utf8mb4_bin');
            }
            $table->char('session_hash', 64)->primary();
            $table->string('user_type', 191)->nullable();
            $table->string('user_id', 191)->nullable();
            $table->string('issuer', 191);
            $table->string('subject', 191);
            $table->string('sid', 64);
            $table->boolean('revoked')->default(false);
            $table->unsignedBigInteger('expires_at')->nullable()->index();
            $table->index(['issuer', 'sid']);
            $table->index(['issuer', 'subject']);
        });
        Schema::create('sso_logout_receipts', function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->unsignedBigInteger('received_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_logout_receipts');
        Schema::dropIfExists('sso_local_sessions');
        Schema::dropIfExists('sso_identities');
    }
};
