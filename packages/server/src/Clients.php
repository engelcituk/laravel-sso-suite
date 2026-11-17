<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator as LaravelValidator;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use LaravelSso\Server\Contracts\ManagesClients;
use LaravelSso\Server\Contracts\RegistersClients;
use LaravelSso\Server\Protocol\RedirectUri;

/** Host-callable lifecycle; no console, HTTP response, environment or user mutations. */
final class Clients implements ManagesClients, RegistersClients
{
    public function __construct(private readonly ClientRepository $clients, private readonly RedirectUri $uris) {}

    /** @return LengthAwarePaginator<int, ClientDetails> */
    public function listing(string $status = 'active', int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        Validator::make(['status' => $status, 'per_page' => $perPage, 'page' => $page],
            ['status' => 'in:active,revoked,all', 'per_page' => 'integer|min:1|max:100', 'page' => 'integer|min:1|max:1000000'])->validate();
        $query = $this->query();
        if ($status !== 'all') {
            $query->where('oauth.revoked', $status === 'revoked');
        }
        $result = $query->orderBy('oauth.id')->paginate($perPage, ['*'], 'page', $page);
        $result->appends(['status' => $status, 'per_page' => $perPage]);
        $result->setCollection($result->getCollection()->map(fn (\stdClass $row): ClientDetails => $this->details($row)));

        return $result;
    }

    public function find(string $id): ClientDetails
    {
        $row = Str::isUuid($id) ? $this->query()->where('oauth.id', $id)->first() : null;
        if (! $row) {
            throw (new ModelNotFoundException)->setModel(Passport::clientModel(), [$id]);
        }

        return $this->details($row);
    }

    public function update(string $id, ClientChanges $changes): ClientDetails
    {
        $this->checkConnections();

        return Transactions::run(function () use ($id, $changes): ClientDetails {
            ClientLocks::acquire($id);
            $client = Passport::client()->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($client->getAttribute('revoked')) {
                throw new \RuntimeException('A revoked client cannot be edited or reactivated.');
            }
            $old = $this->find($id);
            $redirects = $changes->redirectUri !== null ? [$changes->redirectUri] : $old->redirectUris;
            $postLogout = $changes->postLogoutRedirectUri !== null
                ? ($changes->postLogoutRedirectUri === '' ? [] : [$changes->postLogoutRedirectUri]) : $old->postLogoutRedirectUris;
            $backchannel = $changes->backchannelLogoutUri === '' ? null : ($changes->backchannelLogoutUri ?? $old->backchannelLogoutUri);
            $definition = new ClientDefinition($changes->name ?? $old->name, $redirects[0] ?? '', $old->public,
                $changes->firstParty ?? $old->firstParty, $changes->requirePkce ?? $old->requirePkce,
                $changes->restrictUsers ?? $old->restrictUsers, $backchannel, $postLogout[0] ?? null);
            $this->validate($definition);
            $new = new ClientDetails($id, $definition->name, $redirects, $old->public, $definition->firstParty,
                $definition->requirePkce, $definition->restrictUsers, $backchannel, $postLogout, false);
            if ($old == $new) {
                return $old;
            }
            $client->forceFill(['name' => $new->name, 'redirect_uris' => $new->redirectUris])->save();
            DB::table('sso_clients')->where('client_id', $id)->update(['auto_approve' => $new->firstParty,
                'require_pkce' => $new->requirePkce, 'restrict_users' => $new->restrictUsers,
                'backchannel_logout_uri' => $new->backchannelLogoutUri,
                'post_logout_redirect_uris' => $new->postLogoutRedirectUris === [] ? null : json_encode($new->postLogoutRedirectUris, JSON_THROW_ON_ERROR)]);
            $this->invalidate($id);
            app(Audit::class)->record('client.updated', ['client' => $id]);

            return $new;
        });
    }

    public function revoke(string $id): ClientDetails
    {
        $this->checkConnections();

        return Transactions::run(function () use ($id): ClientDetails {
            ClientLocks::acquire($id);
            $client = Passport::client()->newQuery()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->invalidate($id);
            if (! $client->getAttribute('revoked')) {
                $client->forceFill(['revoked' => true])->save();
                app(Audit::class)->record('client.revoked', ['client' => $id]);
            }

            return $this->find($id);
        });
    }

    private function checkConnections(): void
    {
        foreach ([Passport::client(), Passport::authCode(), Passport::token(), Passport::refreshToken()] as $model) {
            if ($model->getConnection()->getName() !== DB::connection()->getName()) {
                throw new \RuntimeException('OAuth and SSO client records must use the default database connection.');
            }
        }
    }

    private function query(): Builder
    {
        $this->checkConnections();

        return DB::table(Passport::client()->getTable().' as oauth')->join('sso_clients as sso', 'sso.client_id', '=', 'oauth.id')
            ->select(['oauth.id', 'oauth.name', 'oauth.redirect_uris', 'oauth.revoked', 'sso.auto_approve',
                'sso.require_pkce', 'sso.restrict_users', 'sso.backchannel_logout_uri', 'sso.post_logout_redirect_uris'])
            ->selectRaw('CASE WHEN oauth.secret IS NULL THEN 1 ELSE 0 END AS is_public');
    }

    private function details(\stdClass $row): ClientDetails
    {
        return new ClientDetails((string) $row->id, $row->name, json_decode($row->redirect_uris, true, flags: JSON_THROW_ON_ERROR),
            (bool) $row->is_public, (bool) $row->auto_approve, (bool) $row->require_pkce, (bool) $row->restrict_users,
            $row->backchannel_logout_uri, json_decode($row->post_logout_redirect_uris ?? '[]', true, flags: JSON_THROW_ON_ERROR), (bool) $row->revoked);
    }

    private function invalidate(string $id): void
    {
        // Current writes include exchanges committed before the registration lock, even under RR.
        Passport::authCode()->newQuery()->where('client_id', $id)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', Passport::token()->newQuery()->where('client_id', $id)->select('id'))
            ->update(['revoked' => true]);
        Passport::token()->newQuery()->where('client_id', $id)->update(['revoked' => true]);
    }

    public function validate(ClientDefinition $definition, bool $trustBackchannel = false): void
    {
        $validator = Validator::make(['name' => $definition->name], ['name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/']]);
        $validator->after(function (LaravelValidator $validator) use ($definition, $trustBackchannel): void {
            if ($definition->public && ! $definition->requirePkce) {
                $validator->errors()->add('require_pkce', 'Public clients always require S256 PKCE.');
            }
            foreach (['redirect_uri' => $definition->redirectUri, 'backchannel_logout_uri' => $definition->backchannelLogoutUri,
                'post_logout_redirect_uri' => $definition->postLogoutRedirectUri] as $field => $url) {
                if ($url === null) {
                    continue;
                }
                if (! $this->uris->safe($url)) {
                    $validator->errors()->add($field, 'An exact HTTPS URL without credentials, fragment or control characters is required.');
                }
            }
            if ($definition->backchannelLogoutUri !== null && ! $trustBackchannel
                && ! in_array($definition->backchannelLogoutUri, config('sso-server.backchannel_allowed_urls', []), true)) {
                $validator->errors()->add('backchannel_logout_uri', 'The back-channel URL must match an operator-trusted outbound allowlist entry.');
            }
        });
        $validator->validate();
    }

    public function create(ClientDefinition $definition): CreatedClient
    {
        $this->validate($definition);
        $this->checkConnections();

        return Transactions::run(function () use ($definition): CreatedClient {
            $client = $this->clients->createAuthorizationCodeGrantClient($definition->name, [$definition->redirectUri], ! $definition->public);
            $id = $client->getKey();
            if (! $client->exists || ! is_string($id) || ! Str::isUuid($id)) {
                throw new \RuntimeException('SSO registration requires a persisted Passport client with a UUID.');
            }
            DB::table('sso_clients')->insert(['client_id' => $id, 'auto_approve' => $definition->firstParty,
                'require_pkce' => $definition->requirePkce, 'restrict_users' => $definition->restrictUsers,
                'backchannel_logout_uri' => $definition->backchannelLogoutUri,
                'post_logout_redirect_uris' => $definition->postLogoutRedirectUri !== null
                    ? json_encode([$definition->postLogoutRedirectUri], JSON_THROW_ON_ERROR) : null]);
            app(Audit::class)->record('client.registered', ['client' => $id]);

            return new CreatedClient($id, $definition->public ? null : $client->plainSecret);
        });
    }
}
