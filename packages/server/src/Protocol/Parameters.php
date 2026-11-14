<?php

declare(strict_types=1);

namespace LaravelSso\Server\Protocol;

use Illuminate\Http\Request;

final class Parameters
{
    /** Parse before PHP's duplicate-key and bracket normalization can hide ambiguity.
     * @return array<string, string>
     */
    public function read(Request $request): array
    {
        $raw = $request->isMethod('GET') ? $request->server('QUERY_STRING', '') : $request->getContent();
        $json = ! $request->isMethod('GET') && $request->isJson();
        if (strlen($raw) > 32768) {
            throw new InvalidRequest;
        }
        if ($raw !== '' && ! $json) {
            $values = [];
            foreach (explode('&', $raw) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $key = urldecode($key);
                if ($key === '' || strpbrk($key, "[]. \0") !== false || array_key_exists($key, $values)) {
                    throw new InvalidRequest;
                }
                $values[$key] = urldecode($value);
            }

            return $values;
        }
        $values = $request->isMethod('GET') ? $request->query->all() : $request->post();
        if ($json) {
            $values = $request->json()->all();
        }
        foreach ($values as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                throw new InvalidRequest;
            }
            $values[$key] = (string) $value;
        }

        return $values;
    }
}
