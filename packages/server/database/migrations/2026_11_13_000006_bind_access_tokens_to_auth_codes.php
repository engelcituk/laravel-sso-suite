<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sso_token_sessions', function (Blueprint $table): void {
            $table->char('code_id', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sso_token_sessions', function (Blueprint $table): void {
            $table->dropIndex(['code_id']);
            $table->dropColumn('code_id');
        });
    }
};
