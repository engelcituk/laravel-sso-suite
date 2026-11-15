<?php

declare(strict_types=1);
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\ResponseInterface;

/** HTTP-only virtual user: protocol state and tokens remain in process memory. */
final class DeploymentBrowser
{
    private Client $http;

    public CookieJar $cookies;

    public array $metrics = [];

    public int $credentialSubmissions = 0;

    public function __construct(public readonly array $profile, private readonly array $user)
    {
        $this->cookies = new CookieJar;
        $this->http = new Client(['cookies' => $this->cookies, 'verify' => '/app/.runtime/deployment-lab/tls/ca.pem',
            'proxy' => '', 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 10, 'connect_timeout' => 3]);
    }

    public function request(string $operation, string $url, string $method = 'GET', array $form = []): ResponseInterface
    {
        $started = hrtime(true);
        try {
            $response = $this->http->request($method, $url, ['headers' => ['Accept' => str_contains($operation, 'protected') ? 'application/json' : 'text/html'],
                'form_params' => $form]);
            $status = $response->getStatusCode();
        } catch (Throwable $error) {
            $status = 0;
            throw new RuntimeException('HTTP transport failed for '.$operation, previous: $error);
        } finally {
            $this->metrics[$operation]['latencies'][] = (hrtime(true) - $started) / 1000000;
            $this->metrics[$operation]['statuses'][$status] = ($this->metrics[$operation]['statuses'][$status] ?? 0) + 1;
        }

        return $response;
    }

    public function follow(string $operation, string $url, string $method = 'GET', array $form = []): array
    {
        $response = $this->request($operation, $url, $method, $form);
        for ($i = 0; $response->getStatusCode() >= 300 && $response->getStatusCode() < 400; $i++) {
            if ($i > 12) {
                throw new RuntimeException('Redirect loop.');
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($response->getHeaderLine('Location')));
            $response = $this->request($operation, $url);
        }

        return [$url, $response];
    }

    public static function field(ResponseInterface $response, string $name): string
    {
        if (! preg_match('/name="'.preg_quote($name, '/').'"[^>]*value="([^"]+)"/', (string) $response->getBody(), $match)) {
            throw new RuntimeException('Expected form field missing.');
        }

        return html_entity_decode($match[1], ENT_QUOTES);
    }

    public function authenticate(string $role): void
    {
        [$url, $response] = $this->follow('login', $this->profile[$role].'/sso/login');
        if ($url === $this->profile['op'].'/login') {
            $this->credentialSubmissions++;
            [$url, $response] = $this->follow('credentials', $url, 'POST', $this->user + ['remember' => '1', '_token' => self::field($response, '_token')]);
        }
        if ($response->getStatusCode() !== 200 || rtrim($url, '/') !== $this->profile[$role]) {
            $code = $response->getStatusCode() === 503 ? 503 : 0;
            if ($response->getStatusCode() === 400 && str_starts_with($url, $this->profile[$role].'/sso/callback?')) {
                $code = 400; // The RP fails closed if the OP becomes unavailable during the callback.
            }
            throw new RuntimeException('OIDC authentication did not return to the registered RP.', $code);
        }
    }

    public function protected(string $role): int
    {
        return $this->request('protected_'.$role, $this->profile[$role].'/me')->getStatusCode();
    }

    public function logout(): void
    {
        $home = $this->request('logout_form', $this->profile['one'].'/');
        [$url, $confirmation] = $this->follow('logout', $this->profile['one'].'/sso/logout', 'POST', ['_token' => self::field($home, '_token')]);
        if (! str_starts_with($url, $this->profile['op'].'/oidc/logout?') || $confirmation->getStatusCode() !== 200) {
            throw new RuntimeException('Global logout confirmation unavailable.', $confirmation->getStatusCode() === 503 ? 503 : 0);
        }
        $response = $this->request('logout_confirm', $this->profile['op'].'/oidc/logout/confirm', 'POST', ['_token' => self::field($confirmation, '_token'),
            'transaction' => self::field($confirmation, 'transaction'), 'approve' => '1']);
        if ($response->getStatusCode() !== 200 || $this->protected('two') !== 401) {
            throw new RuntimeException('Global logout did not fail closed at the other RP.', $response->getStatusCode() === 503 ? 503 : 0);
        }
    }

    public function summary(): array
    {
        $summary = [];
        foreach ($this->metrics as $operation => $data) {
            sort($data['latencies']);
            $count = count($data['latencies']);
            $summary[$operation] = ['count' => $count, 'statuses' => $data['statuses'], 'total_ms' => array_sum($data['latencies']), 'p50_ms' => $data['latencies'][(int) floor(($count - 1) * .5)],
                'p95_ms' => $data['latencies'][(int) floor(($count - 1) * .95)], 'p99_ms' => $data['latencies'][(int) floor(($count - 1) * .99)], 'max_ms' => max($data['latencies'])];
        }

        return $summary;
    }
}
