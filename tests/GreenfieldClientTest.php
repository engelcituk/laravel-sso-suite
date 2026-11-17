<?php

namespace LaravelSso\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LaravelSso\Client\EloquentProvisioner;
use LaravelSso\Client\Events\IdentityProvisioned;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\IdentityLinks;
use LaravelSso\Contracts\Identity;
use LaravelSso\Server\Http\ProtocolController;
use LaravelSso\Server\SigningKeys;
use LaravelSso\Tests\Fixtures\GreenfieldUser;

final class GreenfieldClientTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('sso-client.provision', 'greenfield');
        $app['config']->set('sso-client.create_user', null);
        $app['config']->set('sso-client.user_model', GreenfieldUser::class);
        $app['config']->set('auth.providers.locals.model', GreenfieldUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Own in-memory fixture: the conventional Laravel migration, including NOT NULL password.
        Schema::drop('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    private function identity(string $subject = 'central', array $attributes = []): Identity
    {
        return new Identity('https://identity.test', $subject, 'sid', time(), $attributes + [
            'name' => 'Central account', 'email' => 'person@example.test', 'email_verified' => true,
        ]);
    }

    private function rejected(Identity $identity): void
    {
        try {
            app(EloquentProvisioner::class)->provision($identity);
            $this->fail('Unsafe provisioning must be rejected.');
        } catch (ProtocolException) {
            $this->assertSame(0, DB::transactionLevel());
        }
    }

    public function test_first_login_creates_and_links_standard_user_without_privilege_or_password_claims(): void
    {
        $columns = Schema::getColumnListing('users');
        $user = app(EloquentProvisioner::class)->provision($this->identity(attributes: [
            'email' => 'Person@EXAMPLE.TEST', 'password' => 'central-password', 'is_admin' => true, 'role' => 'admin',
            'id' => 999, 'remember_token' => 'remote-recaller',
        ]));
        $this->assertInstanceOf(GreenfieldUser::class, $user);
        $this->assertSame('person@example.test', $user->email);
        $this->assertSame('Central account', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame(999, $user->id);
        $this->assertNull($user->remember_token);
        $this->assertArrayNotHasKey('is_admin', $user->getAttributes());
        $this->assertArrayNotHasKey('role', $user->getAttributes());
        $this->assertFalse(Hash::check('central-password', $user->getAuthPassword()));
        $this->assertFalse(Hash::needsRehash($user->getAuthPassword()));
        $this->assertSame($user->id, app(IdentityLinks::class)->find('https://identity.test', 'central')->id);
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(1, DB::table('sso_identities')->count());
        $this->assertSame($columns, Schema::getColumnListing('users'));
    }

    public function test_subsequent_logins_preserve_the_entire_linked_row_including_hash_and_verification(): void
    {
        $user = app(EloquentProvisioner::class)->provision($this->identity());
        $before = $user->fresh()->getAttributes();
        $again = app(EloquentProvisioner::class)->provision($this->identity(attributes: [
            'email' => 'new@example.test', 'name' => 'Changed profile', 'email_verified' => false, 'password' => 'replacement',
        ]));
        $this->assertSame($user->id, $again->id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(1, DB::table('sso_identities')->count());
    }

    public function test_email_collision_never_adopts_or_modifies_an_existing_account_even_with_case_difference(): void
    {
        $user = GreenfieldUser::create(['name' => 'Existing local', 'email' => 'Person@Example.Test',
            'password' => Hash::make('existing-local-password')]);
        $before = $user->fresh()->getAttributes();
        Event::fake([IdentityProvisioned::class]);
        $this->rejected($this->identity());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Hash::check('existing-local-password', $user->fresh()->password));
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(0, DB::table('sso_identities')->count());
        Event::assertNotDispatched(IdentityProvisioned::class);
    }

    public function test_different_subject_cannot_claim_email_of_an_automatically_created_owner(): void
    {
        $owner = app(EloquentProvisioner::class)->provision($this->identity());
        $before = $owner->fresh()->getAttributes();
        $this->rejected($this->identity('another-subject'));
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(1, DB::table('sso_identities')->count());
        $this->assertNull(app(IdentityLinks::class)->find('https://identity.test', 'another-subject'));
    }

    public function test_explicitly_linked_existing_account_is_reused_without_any_profile_or_credential_writes(): void
    {
        $user = GreenfieldUser::create(['name' => 'Existing local', 'email' => 'local@example.test',
            'password' => Hash::make('existing-local-password')]);
        app(IdentityLinks::class)->link($user, 'https://identity.test', 'central');
        $before = $user->fresh()->getAttributes();
        $resolved = app(EloquentProvisioner::class)->provision($this->identity());
        $this->assertSame($user->id, $resolved->id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(1, GreenfieldUser::count());
    }

    public function test_invalid_or_missing_email_creates_neither_user_nor_mapping(): void
    {
        foreach ([null, '', 'invalid', ['array'], str_repeat('a', 250).'@example.test'] as $email) {
            $this->rejected($this->identity(attributes: ['email' => $email]));
            $this->assertSame(0, GreenfieldUser::count());
            $this->assertSame(0, DB::table('sso_identities')->count());
        }
    }

    public function test_only_boolean_verified_claim_marks_new_email_and_missing_name_uses_email(): void
    {
        foreach ([true, false, 'true', 1] as $index => $verified) {
            $identity = new Identity('https://identity.test', 'person-'.$index, 'sid', time(), [
                'email' => 'person'.$index.'@example.test', 'email_verified' => $verified,
            ]);
            $user = app(EloquentProvisioner::class)->provision($identity);
            $this->assertSame('person'.$index.'@example.test', $user->name);
            $this->assertSame($verified === true, $user->email_verified_at !== null);
        }
    }

    public function test_host_denial_rolls_back_new_user_mapping_and_events_while_existing_rows_stay_intact(): void
    {
        $existing = GreenfieldUser::create(['name' => 'Existing', 'email' => 'other@example.test', 'password' => Hash::make('keep')]);
        $before = $existing->fresh()->getAttributes();
        Event::fake([IdentityProvisioned::class]);
        config(['sso-client.allowed' => fn () => false]);
        $this->rejected($this->identity());
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(0, DB::table('sso_identities')->count());
        $this->assertSame($before, $existing->fresh()->getAttributes());
        Event::assertNotDispatched(IdentityProvisioned::class);
    }

    public function test_deleted_link_owner_is_not_silently_recreated_or_reassigned(): void
    {
        $user = app(EloquentProvisioner::class)->provision($this->identity());
        $user->delete();
        $this->rejected($this->identity());
        $this->assertSame(0, GreenfieldUser::count());
        $this->assertSame(1, DB::table('sso_identities')->where('user_id', (string) $user->id)->count());
    }

    public function test_doctor_detects_missing_unique_email_constraint_without_altering_schema(): void
    {
        $this->assertSame(0, Artisan::call('sso:doctor', ['--offline' => true, '--json' => true]));
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['email']));
        $this->assertSame(1, Artisan::call('sso:doctor', ['--offline' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $check = array_values(array_filter($report['checks'], fn ($check) => $check['code'] === 'greenfield_model'))[0];
        $this->assertSame('fail', $check['status']);
        $this->rejected($this->identity());
        $this->assertSame(0, GreenfieldUser::count());
        $this->assertFalse(Schema::hasIndex('users', ['email'], 'unique'));
    }

    private function completeSsoLogin(bool $validSignature = true, string $userinfoSubject = 'central'): void
    {
        Http::swap(new Factory);
        $metadata = app(ProtocolController::class)->discovery()->getData(true);
        Http::fake([
            'https://identity.test/.well-known/openid-configuration' => Http::response($metadata),
            'https://identity.test/oidc/jwks' => Http::response(app(SigningKeys::class)->jwks()),
            'https://identity.test/oidc/userinfo' => Http::response(['sub' => $userinfoSubject, 'name' => 'Central account',
                'email' => 'person@example.test', 'email_verified' => true]),
        ]);
        $this->get('/sso/login')->assertRedirect();
        $state = session('sso.transaction.state');
        $token = app(SigningKeys::class)->sign(['iss' => 'https://identity.test', 'sub' => 'central',
            'aud' => config('sso-client.client_id'), 'iat' => time(), 'exp' => time() + 300, 'auth_time' => time(),
            'nonce' => session('sso.transaction.nonce'), 'sid' => 'sid']);
        if (! $validSignature) {
            $segments = explode('.', $token);
            $segments[2][0] = $segments[2][0] === 'A' ? 'B' : 'A';
            $token = implode('.', $segments);
        }
        Http::fake(['https://identity.test/oidc/token' => Http::response([
            'access_token' => 'token', 'expires_in' => 300, 'id_token' => $token,
        ])]);
        $response = $this->get('/sso/callback?'.http_build_query(['state' => $state, 'code' => 'code', 'iss' => 'https://identity.test']));
        if ($validSignature && $userinfoSubject === 'central') {
            $response->assertRedirect('/');
        } else {
            $response->assertBadRequest();
        }
    }

    public function test_validated_callback_creates_one_user_and_native_session_then_reuses_both_identity_and_profile(): void
    {
        $this->completeSsoLogin();
        $user = GreenfieldUser::sole();
        $before = $user->fresh()->getAttributes();
        $this->assertAuthenticatedAs($user, 'local');
        $this->assertSame($user->id, app(IdentityLinks::class)->find('https://identity.test', 'central')->id);
        $this->completeSsoLogin();
        $this->assertSame(1, GreenfieldUser::count());
        $this->assertSame(1, DB::table('sso_identities')->count());
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_invalid_signature_or_userinfo_subject_never_provisions_or_authenticates(): void
    {
        $this->completeSsoLogin(false);
        $this->assertGuest('local');
        $this->completeSsoLogin(userinfoSubject: 'attacker');
        $this->assertGuest('local');
        $this->assertSame(0, GreenfieldUser::count());
        $this->assertSame(0, DB::table('sso_identities')->count());
        $this->assertSame(0, DB::table('sso_local_sessions')->count());
    }
}
