<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->collation('utf8mb4_bin');
            }
            $table->id();
            $table->string('sso_issuer', 191);
            $table->string('sso_subject', 191);
            $table->unique(['sso_issuer', 'sso_subject']);
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
        Schema::create('report_permissions', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_permissions');
        Schema::dropIfExists('users');
    }
};
