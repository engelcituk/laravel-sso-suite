<?php

declare(strict_types=1);

namespace LaravelSso\Contracts;

/** Explicit installer configuration; no framework dependency and no user/database access. */
final class InstallationConfiguration
{
    public function url(string $value, bool $allowHttp = false, bool $origin = false): string
    {
        $value = $origin ? rtrim(trim($value), '/') : trim($value);
        $parts = parse_url($value);
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', $allowHttp ? ['https', 'http'] : ['https'], true)
            || ($origin && (isset($parts['path']) || isset($parts['query'])))) {
            throw new \RuntimeException($origin ? 'Provide a dedicated HTTPS issuer origin without a path or credentials.' : 'Provide an exact HTTPS endpoint without credentials or fragment.');
        }

        return $value;
    }

    /** @param array<string, string> $values */
    public function write(string $file, array $values): void
    {
        foreach ($values as $key => $value) {
            if (! preg_match('/^SSO_[A-Z0-9_]+$/D', $key) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \RuntimeException('Only single-line SSO environment values may be configured.');
            }
        }
        $handle = @fopen($file, 'r+');
        if ($handle === false) {
            throw new \RuntimeException('An existing writable application environment file is required.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock application environment configuration.');
            }
            $content = stream_get_contents($handle);
            if ($content === false) {
                throw new \RuntimeException('Cannot read application environment configuration.');
            }
            $newline = str_contains($content, "\r\n") ? "\r\n" : "\n";
            foreach ($values as $key => $value) {
                $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';
                if (preg_match_all($pattern, $content) > 1) {
                    throw new \RuntimeException('Remove duplicate SSO environment keys before configuring.');
                }
                $line = $key.'="'.strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$']).'"';
                if (preg_match($pattern, $content)) {
                    $content = preg_replace_callback($pattern, static fn () => $line, $content);
                } else {
                    $content = rtrim($content, "\r\n").$newline.$line.$newline;
                }
            }
            rewind($handle);
            if (! ftruncate($handle, 0) || fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                throw new \RuntimeException('Cannot write application environment configuration.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
