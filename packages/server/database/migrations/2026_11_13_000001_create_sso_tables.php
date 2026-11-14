<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_clients', function (Blueprint $table): void {
            $table->uuid('client_id')->primary();
            $table->boolean('auto_approve')->default(false);
            $table->boolean('require_pkce')->default(true);
            $table->text('post_logout_redirect_uris')->nullable();
            $table->text('backchannel_logout_uri')->nullable();
        });
        Schema::create('sso_sessions', function (Blueprint $table): void {
            $table->string('sid', 64)->primary();
            $table->string('subject')->index();
            $table->unsignedBigInteger('auth_time');
            $table->unsignedBigInteger('last_seen');
            $table->unsignedBigInteger('expires_at')->index();
            $table->boolean('revoked')->default(false);
        });
        Schema::create('sso_auth_contexts', function (Blueprint $table): void {
            $table->char('code_id', 80)->primary();
            $table->string('nonce', 255)->nullable();
            $table->string('sid', 64)->index();
            $table->text('claims_request')->nullable();
        });
        Schema::create('sso_token_sessions', function (Blueprint $table): void {
            $table->char('token_id', 80)->primary();
            $table->string('sid', 64)->index();
            $table->uuid('client_id')->index();
            $table->char('code_id', 80)->nullable()->index();
            $table->text('claims_request')->nullable();
        });
        Schema::create('sso_logout_deliveries', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('sid', 64);
            $table->uuid('client_id');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('next_attempt');
            $table->unsignedBigInteger('delivered_at')->nullable();
            $table->unique(['sid', 'client_id']);
            $table->index(['delivered_at', 'next_attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_logout_deliveries');
        Schema::dropIfExists('sso_token_sessions');
        Schema::dropIfExists('sso_auth_contexts');
        Schema::dropIfExists('sso_sessions');
        Schema::dropIfExists('sso_clients');
    }
};
