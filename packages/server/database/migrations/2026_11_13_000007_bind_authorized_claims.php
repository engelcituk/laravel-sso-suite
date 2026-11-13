<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sso_auth_contexts', 'sso_token_sessions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->text('claims_request')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sso_token_sessions', 'sso_auth_contexts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('claims_request');
            });
        }
    }
};
