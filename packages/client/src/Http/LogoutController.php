<?php

declare(strict_types=1);

namespace LaravelSso\Client\Http;

use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LaravelSso\Client\Audit;
use LaravelSso\Client\Exceptions\ProtocolException;
use LaravelSso\Client\LogoutTokenValidator;
use LaravelSso\Client\Provider;
use LaravelSso\Client\TrustedHttp;

final class LogoutController
{
    public function redirect(Request $request, Provider $provider, TrustedHttp $http): RedirectResponse
    {
        $hint = $request->session()->get('sso.id_token');
        DB::table('sso_local_sessions')->where('session_hash', hash('sha256', $request->session()->getId()))->update(['revoked' => true]);
        /** @var SessionGuard $guard */
        $guard = Auth::guard(config('sso-client.guard'));
        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        app(Audit::class)->record('logout.local');
        $metadata = $provider->metadata();
        $url = $metadata['end_session_endpoint'] ?? null;
        if (! is_string($hint) || ! is_string($url)) {
            throw new ProtocolException('Local logout completed; global logout is unavailable.');
        }
        $http->request($url);

        return redirect()->away($url.'?'.http_build_query(['id_token_hint' => $hint], '', '&', PHP_QUERY_RFC3986));
    }

    public function backchannel(Request $request, Provider $provider, TrustedHttp $http, LogoutTokenValidator $validator): JsonResponse
    {
        try {
            return $this->receiveBackchannel($request, $provider, $http, $validator);
        } catch (ProtocolException|ValidationException $error) {
            app(Audit::class)->record('logout.rejected');
            throw $error;
        }
    }

    private function receiveBackchannel(Request $request, Provider $provider, TrustedHttp $http, LogoutTokenValidator $validator): JsonResponse
    {
        $input = $request->validate(['logout_token' => 'required|string|max:16384']);
        $metadata = $provider->metadata();
        $claims = $validator->validate($input['logout_token'], $http->get($metadata['jwks_uri']));
        DB::transaction(function () use ($claims): void {
            $inserted = DB::table('sso_logout_receipts')->insertOrIgnore([
                'token_hash' => hash('sha256', $claims['iss']."\0".$claims['jti']), 'received_at' => time(),
            ]);
            if ($inserted !== 1) {
                app(Audit::class)->record('logout.duplicate', ['issuer' => $claims['iss']]);

                return; // Acknowledged duplicate, never re-apply an old logout to new sessions.
            }
            $sessions = DB::table('sso_local_sessions')->where('issuer', $claims['iss']);
            if (isset($claims['sid'])) {
                $sessions->where('sid', $claims['sid']);
            }
            if (isset($claims['sub'])) {
                $sessions->where('subject', $claims['sub']);
            }
            $count = $sessions->where('revoked', false)->update(['revoked' => true]);
            app(Audit::class)->record('logout.received', ['issuer' => $claims['iss']], ['count' => $count]);
        }, 5);

        return response()->json((object) [])->header('Cache-Control', 'no-store');
    }
}
