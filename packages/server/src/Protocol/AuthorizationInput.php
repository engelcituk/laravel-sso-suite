<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

final class AuthorizationInput
{
    /** Only error delivery metadata is read; this never validates/accepts a Request Object.
     * @param  array<string, string>  $input
     */
    public function unsupportedRequestErrorMode(array $input): string
    {
        if (isset($input['response_mode'])) {
            return $input['response_mode'];
        }
        $object = $input['request'] ?? '';
        if (strlen($object) > 8192 || count($parts = explode('.', $object)) !== 3
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[1]) !== 1) {
            return 'query';
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        try {
            $claims = $payload !== false ? json_decode($payload, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            return 'query';
        }

        // The untrusted value can select only the transport of a fixed rejection.
        // Client, redirect, state, identity, scopes and PKCE always remain outer-only.
        return is_array($claims) && ($claims['response_mode'] ?? null) === 'form_post' ? 'form_post' : 'query';
    }

    /** @param array<string, string> $input */
    public function error(array $input, bool $requirePkce = true): ?string
    {
        foreach (['request' => 'request_not_supported', 'request_uri' => 'request_uri_not_supported',
            'registration' => 'registration_not_supported'] as $key => $error) {
            if (array_key_exists($key, $input)) {
                return $error;
            }
        }
        if (($input['response_type'] ?? '') === '') {
            return 'invalid_request';
        }
        if ($input['response_type'] !== 'code') {
            return 'unsupported_response_type';
        }
        $scopes = explode(' ', $input['scope'] ?? '');
        if (! in_array('openid', $scopes, true) || array_diff($scopes, ['openid', 'profile', 'email']) !== []) {
            return 'invalid_scope';
        }
        $prompts = $this->prompts($input);
        if (array_diff($prompts, ['none', 'login', 'consent', 'select_account']) !== []
            || (in_array('none', $prompts, true) && count($prompts) !== 1)
            || (isset($input['max_age']) && (! ctype_digit($input['max_age']) || strlen($input['max_age']) > 10 || (int) $input['max_age'] > 2147483647))
            || (isset($input['nonce']) && ($input['nonce'] === '' || strlen($input['nonce']) > 255))
            || (isset($input['state']) && strlen($input['state']) > 512)
            || (($requirePkce || isset($input['code_challenge']) || isset($input['code_challenge_method']))
                && (($input['code_challenge_method'] ?? null) !== 'S256'
                    || preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'] ?? '') !== 1))) {
            return 'invalid_request';
        }

        return null;
    }

    /** @param array<string, string> $input
     * @return list<string>
     */
    public function prompts(array $input): array
    {
        return isset($input['prompt']) && $input['prompt'] !== '' ? explode(' ', $input['prompt']) : [];
    }
}
