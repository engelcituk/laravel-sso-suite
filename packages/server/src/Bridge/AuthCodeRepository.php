<?php

declare(strict_types=1);

namespace LaravelSso\Server\Bridge;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use LaravelSso\Server\GlobalSessions;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/** Compatibility surface: Passport 13 Bridge repository, covered by real grant tests. */
final class AuthCodeRepository extends \Laravel\Passport\Bridge\AuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $context = request()->attributes->get('sso.authorization');
        if (! is_array($context)) {
            throw OAuthServerException::serverError('Missing OIDC authorization context.');
        }
        DB::transaction(function () use ($authCodeEntity, $context): void {
            parent::persistNewAuthCode($authCodeEntity);
            DB::table('sso_auth_contexts')->insert([
                'code_id' => $authCodeEntity->getIdentifier(), 'nonce' => $context['nonce'], 'sid' => $context['sid'],
            ]);
        });
    }

    public function revokeAuthCode(string $codeId): void
    {
        $context = DB::table('sso_auth_contexts')->where('code_id', $codeId)->first();
        if (! $context || ! app(GlobalSessions::class)->valid($context->sid)) {
            throw OAuthServerException::invalidGrant('The global session is no longer active.');
        }
        // Atomic consume: concurrent exchanges cannot both commit a successful response.
        if (Passport::authCode()->newQuery()->whereKey($codeId)->where('revoked', false)->update(['revoked' => true]) !== 1) {
            throw OAuthServerException::invalidGrant('Authorization code already consumed.');
        }
        request()->attributes->set('sso.token_context', $context);
    }
}
