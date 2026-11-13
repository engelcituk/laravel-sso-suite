<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_clients', function (Blueprint $table): void {
            $table->boolean('require_pkce')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sso_clients', function (Blueprint $table): void {
            $table->dropColumn('require_pkce');
        });
    }
};
