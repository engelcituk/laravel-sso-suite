<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Examples\LocalUser;

require dirname(__DIR__).'/vendor/autoload.php';
$profile = $argv[1] ?? '';
if (getenv('SSO_DEPLOYMENT_LAB') !== '1' || dirname(__DIR__) !== '/app'
    || ! in_array($profile, ['fpm', 'apache'], true)) {
    throw new RuntimeException('Use only a disposable deployment fixture.');
}
$app = require '/app/.runtime/deployment-lab/'.$profile.'/two/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Upgrade rehearsal failed: '.$error::class.' '.$error->getMessage()."\n");
    exit(1);
});
$connection = DB::getDefaultConnection();
// A fresh table namespace, never the serving apps' tables or a host installation.
$prefix = 'pilot_'.bin2hex(random_bytes(5)).'_';
config(['database.connections.'.$connection.'.prefix' => $prefix]);
config(['database.connections.'.$connection.'.prefix_indexes' => true]);
DB::purge($connection);
if (DB::connection()->getTablePrefix() !== $prefix) {
    throw new RuntimeException('Disposable namespace not selected.');
}
(require '/app/examples/shared/client-migration.php')->up();
$password = bin2hex(random_bytes(32));
$user = LocalUser::create(['name' => 'Synthetic existing account', 'email' => 'upgrade@example.test', 'password' => Hash::make($password)]);
DB::table('report_permissions')->insert(['user_id' => $user->getKey()]);
Schema::create('migrations', function (Blueprint $table): void {
    $table->increments('id');
    $table->string('migration');
    $table->integer('batch');
});
$migrationName = pathinfo(glob('/app/packages/client/database/migrations/*.php')[0], PATHINFO_FILENAME);
DB::table('migrations')->insert(['migration' => $migrationName, 'batch' => 1]);
// Client-owned schema before optional Auth (848b35c); no identity columns in users.
Schema::create('sso_local_sessions', function (Blueprint $table): void {
    $table->char('session_hash', 64)->primary();
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
$issuer = config('sso-client.issuer');
$subject = 'operator-verified-synthetic-subject';
DB::table('sso_local_sessions')->insert(['session_hash' => hash('sha256', 'legacy-session'),
    'issuer' => $issuer, 'subject' => $subject, 'sid' => 'legacy-sid', 'expires_at' => time() + 300]);
DB::table('sso_logout_receipts')->insert(['token_hash' => hash('sha256', 'old-receipt'), 'received_at' => time()]);
$beforeUser = $user->fresh()->getAttributes();
$beforeSchema = Schema::getColumns('users');
$beforeAcl = DB::table('report_permissions')->get()->all();
$beforeReceipts = DB::table('sso_logout_receipts')->get()->all();
$arguments = ['--path' => '/app/packages/client/database/migrations', '--realpath' => true, '--force' => true];
if (Artisan::call('migrate', $arguments) !== 0 || Schema::hasTable('sso_identities')) {
    throw new RuntimeException('The recorded initial migration should not be reapplied.');
}
// Explicit rehearsal of the reviewed SSO-only adaptation. Never migrate:fresh.
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
Schema::table('sso_local_sessions', function (Blueprint $table): void {
    $table->string('user_type', 191)->nullable();
    $table->string('user_id', 191)->nullable();
});
DB::table('sso_local_sessions')->update(['revoked' => true]);
app(IdentityLinks::class)->link($user, $issuer, $subject);
app(IdentityLinks::class)->link($user, $issuer, $subject);
$resolved = app(IdentityLinks::class)->find($issuer, $subject);
if ($beforeUser !== $user->fresh()->getAttributes() || $beforeSchema !== Schema::getColumns('users')
    || $beforeAcl != DB::table('report_permissions')->get()->all()
    || $beforeReceipts != DB::table('sso_logout_receipts')->get()->all()
    || ! Hash::check($password, $user->fresh()->getAuthPassword())
    || $resolved?->getKey() !== $user->getKey() || DB::table('sso_identities')->count() !== 1
    || DB::table('sso_local_sessions')->where('revoked', false)->exists()
    || DB::table('migrations')->count() !== 1 || Artisan::call('migrate', $arguments) !== 0) {
    throw new RuntimeException('Upgrade rehearsal violated preservation/idempotency.');
}
$report = ['checked_at_utc' => gmdate(DATE_ATOM), 'profile' => $profile, 'driver' => DB::getDriverName(),
    'passed' => true, 'isolated_namespace' => true, 'recorded_migration_not_reapplied' => true,
    'adaptation_only_sso_tables' => true, 'legacy_sessions_revoked' => true, 'logout_receipts_preserved' => true,
    'explicit_link_idempotent' => true, 'users_schema_and_all_attributes_unchanged' => true,
    'local_password_valid_and_unchanged' => true, 'acl_unchanged' => true,
    'new_package_migrations' => 0, 'production_upgrade_tool' => false];
file_put_contents('/results/pilot-upgrade-'.$profile.'.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
// Only the just-created namespace is cleaned up; no real app tables are involved.
foreach (['sso_identities', 'sso_local_sessions', 'sso_logout_receipts', 'migrations', 'report_permissions', 'users'] as $table) {
    Schema::drop($table);
}
echo 'Controlled SSO-only upgrade rehearsal passed for '.$profile."; disposable namespace removed.\n";
