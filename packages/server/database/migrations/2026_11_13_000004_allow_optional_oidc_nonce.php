<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_auth_contexts', function (Blueprint $table): void {
            $table->string('nonce', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // An empty value is not emitted as a nonce by the new server. Preserve older rows.
        DB::table('sso_auth_contexts')->whereNull('nonce')->update(['nonce' => '']);
        Schema::table('sso_auth_contexts', function (Blueprint $table): void {
            $table->string('nonce', 255)->nullable(false)->change();
        });
    }
};
