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
            $table->text('post_logout_redirect_uris')->nullable();
            $table->text('backchannel_logout_uri')->nullable();
        });
        Schema::create('sso_sessions', function (Blueprint $table): void {
            $table->string('sid', 64)->primary();
            $table->string('subject')->index();
            $table->unsignedBigInteger('auth_time');
            $table->unsignedBigInteger('last_seen');
            $table->unsignedBigInteger('expires_at');
            $table->boolean('revoked')->default(false);
        });
        Schema::create('sso_auth_contexts', function (Blueprint $table): void {
            $table->char('code_id', 80)->primary();
            $table->string('nonce', 255);
            $table->string('sid', 64)->index();
        });
        Schema::create('sso_token_sessions', function (Blueprint $table): void {
            $table->char('token_id', 80)->primary();
            $table->string('sid', 64)->index();
            $table->uuid('client_id')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_token_sessions');
        Schema::dropIfExists('sso_auth_contexts');
        Schema::dropIfExists('sso_sessions');
        Schema::dropIfExists('sso_clients');
    }
};
