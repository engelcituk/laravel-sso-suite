<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

final class AuthorizationInput
{
    /** @param array<string, string> $input */
    public function error(array $input): ?string
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
            || ($input['code_challenge_method'] ?? null) !== 'S256'
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $input['code_challenge'] ?? '') !== 1) {
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
