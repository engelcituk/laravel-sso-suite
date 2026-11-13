<?php

namespace LaravelSso\Tests;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\GlobalSessions;
use LaravelSso\Tests\Fixtures\CentralUser;

final class SessionTest extends TestCase
{
    public function test_idle_absolute_expiry_and_suspension_are_enforced(): void
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        $values = ['subject' => (string) $user->id, 'auth_time' => time(), 'last_seen' => time(),
            'expires_at' => time() + 3600, 'revoked' => false];
        DB::table('sso_sessions')->insert(['sid' => 'valid'] + $values);
        DB::table('sso_sessions')->insert(['sid' => 'absolute', 'expires_at' => time() - 1] + $values);
        DB::table('sso_sessions')->insert(['sid' => 'idle', 'last_seen' => time() - 1801] + $values);
        $sessions = app(GlobalSessions::class);
        $this->assertNotNull($sessions->valid('valid'));
        $this->assertNull($sessions->valid('absolute'));
        $this->assertNull($sessions->valid('idle'));
        $sessions->revokeAll((string) $user->id);
        $this->assertNull($sessions->valid('valid'));
    }

    public function test_native_central_guard_logout_revokes_its_global_session(): void
    {
        $user = CentralUser::create(['name' => 'Ada', 'email' => 'ada@example.test', 'password' => bcrypt('secret')]);
        DB::table('sso_sessions')->insert(['sid' => 'global', 'subject' => (string) $user->id,
            'auth_time' => time(), 'last_seen' => time(), 'expires_at' => time() + 3600, 'revoked' => false]);
        $this->app['router']->middleware('web')->post('/native-logout', function () {
            Auth::guard('web')->logout();

            return response()->noContent();
        });
        $this->actingAs($user, 'web')->withSession(['sso.sid' => 'global'])->post('/native-logout')->assertNoContent();
        $this->assertEquals(1, DB::table('sso_sessions')->where('sid', 'global')->value('revoked'));
    }
}
