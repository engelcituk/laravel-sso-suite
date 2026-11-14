<?php

namespace LaravelSso\Tests\Database;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\DeviceSessions;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Tests\Fixtures\CentralUser;
use LaravelSso\Tests\TestCase;

final class RememberConcurrencyTest extends TestCase
{
    private function browser(?string $credential = null): Request
    {
        Auth::forgetGuards();
        $session = app('session')->driver();
        $session->invalidate();
        $request = Request::create('https://identity.test/sso/devices', cookies: $credential !== null
            ? [app(DeviceSessions::class)->cookieName() => $credential] : []);
        $request->setLaravelSession($session);
        app()->instance('request', $request);

        return $request;
    }

    private function remembered(?CentralUser $user = null): array
    {
        $user ??= CentralUser::create(['name' => 'Concurrent owner', 'email' => 'remember@example.test', 'password' => bcrypt('password')]);
        $request = $this->browser();
        $request->session()->put('sso.auth_time', time() - 100);
        $device = app(DeviceSessions::class)->register($request, $user, true);
        $cookie = Cookie::queued(app(DeviceSessions::class)->cookieName())->getValue();

        return [$device, $cookie, $user];
    }

    public function test_eight_uses_of_one_credential_allow_one_rotation_and_replay_revokes_the_family(): void
    {
        [$device, $cookie] = $this->remembered();
        $results = Parallel::run(8, function () use ($cookie): bool {
            return app(DeviceSessions::class)->restore($this->browser($cookie));
        });
        $this->assertSame(1, count(array_filter($results)));
        $this->assertNotNull(DB::table('sso_devices')->where('id', $device->id)->value('revoked_at'));
        $this->assertSame(2, DB::table('sso_remember_tokens')->where('device_id', $device->id)->count());
        $this->assertSame(1, DB::table('sso_sessions')->where('device_id', $device->id)->count());
        $this->assertSame(0, DB::table('sso_sessions')->where('device_id', $device->id)->where('revoked', false)->count());
    }

    public function test_revocation_committed_before_rotation_prevents_remembered_login(): void
    {
        [$device, $cookie] = $this->remembered();
        $results = Parallel::run(2, function (int $index, string $directory) use ($device, $cookie): bool {
            if ($index === 1) {
                Parallel::wait($directory.'/credential-read');
                app(DeviceSessions::class)->revoke($device->id);
                touch($directory.'/revocation-committed');

                return true;
            }
            $paused = false;
            DB::listen(function (QueryExecuted $query) use ($directory, &$paused): void {
                if (! $paused && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'sso_remember_tokens')
                    && ! str_contains($query->sql, 'for update')) {
                    $paused = true;
                    touch($directory.'/credential-read');
                    Parallel::wait($directory.'/revocation-committed');
                }
            });

            return app(DeviceSessions::class)->restore($this->browser($cookie));
        });
        $this->assertSame([false, true], $results);
        $this->assertSame(0, DB::table('sso_sessions')->count());
        $this->assertNotNull(DB::table('sso_devices')->where('id', $device->id)->value('revoked_at'));
    }

    public function test_revoke_with_an_old_snapshot_includes_the_newly_restored_session(): void
    {
        [$device, $cookie] = $this->remembered();
        $results = Parallel::run(2, function (int $index, string $directory) use ($device, $cookie): bool {
            if ($index === 0) {
                Parallel::wait($directory.'/old-snapshot');
                $restored = app(DeviceSessions::class)->restore($this->browser($cookie));
                touch($directory.'/rotation-committed');

                return $restored;
            }

            return DB::transaction(function () use ($directory, $device): bool {
                DB::table('sso_sessions')->count();
                touch($directory.'/old-snapshot');
                Parallel::wait($directory.'/rotation-committed');

                return app(DeviceSessions::class)->revoke($device->id);
            }, 5);
        });
        $this->assertSame([true, true], $results);
        $sid = DB::table('sso_sessions')->where('device_id', $device->id)->value('sid');
        $this->assertNotNull($sid);
        $this->assertTrue((bool) DB::table('sso_sessions')->where('sid', $sid)->value('revoked'));
        $this->assertNull(app(GlobalSessions::class)->valid($sid));
    }

    public function test_eight_separate_devices_can_restore_without_revoking_each_other(): void
    {
        [$first, $cookie, $user] = $this->remembered();
        $cookies = [$cookie];
        for ($i = 1; $i < 8; $i++) {
            [, $cookie] = $this->remembered($user);
            $cookies[] = $cookie;
        }
        $results = Parallel::run(8, function (int $index) use ($cookies): bool {
            return app(DeviceSessions::class)->restore($this->browser($cookies[$index]));
        });
        $this->assertSame(array_fill(0, 8, true), $results);
        $this->assertSame(8, DB::table('sso_devices')->whereNull('revoked_at')->count());
        $this->assertSame(8, DB::table('sso_sessions')->where('revoked', false)->count());
        $this->assertSame(8, DB::table('sso_remember_tokens')->whereNotNull('used_at')->count());
        $this->assertSame(16, DB::table('sso_remember_tokens')->count());
    }
}
