<?php

namespace Tests\E2E;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/** Klien HTTP dengan cookie jar sendiri, seperti satu browser. Redirect tidak diikuti. */
final class Browser
{
    public CookieJar $jar;

    private Client $client;

    public function __construct(private string $base)
    {
        $this->jar = new CookieJar;
        $this->client = new Client([
            'base_uri' => $base,
            'cookies' => $this->jar,
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 60,
        ]);
    }

    public function get(string $path): ResponseInterface
    {
        return $this->client->get($path, ['headers' => ['Accept' => 'text/html']]);
    }

    /** POST dengan token CSRF dari cookie XSRF-TOKEN, sama seperti klien Inertia. */
    public function post(string $path, array $data = [], bool $withCsrf = true, string $method = 'POST'): ResponseInterface
    {
        if ($withCsrf && $this->cookie('XSRF-TOKEN') === null) {
            $this->get('/login');
        }
        $headers = ['Accept' => 'text/html', 'Origin' => $this->base];
        if ($withCsrf) {
            $headers['X-XSRF-TOKEN'] = urldecode((string) $this->cookie('XSRF-TOKEN'));
        }

        return $this->client->request($method, $path, ['json' => $data, 'headers' => $headers]);
    }

    /**
     * Form multipart dengan file (seperti router.post({ forceFormData: true }) Inertia).
     *
     * @param  array<string, string>  $fields
     * @param  array<string, array{name: string, bytes: string, type: string}>  $files
     */
    public function multipart(string $path, array $fields, array $files = []): ResponseInterface
    {
        if ($this->cookie('XSRF-TOKEN') === null) {
            $this->get('/login');
        }
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }
        foreach ($files as $name => $file) {
            $parts[] = ['name' => $name, 'contents' => $file['bytes'], 'filename' => $file['name'], 'headers' => ['Content-Type' => $file['type']]];
        }

        return $this->client->request('POST', $path, [
            'multipart' => $parts,
            'headers' => ['Accept' => 'text/html', 'Origin' => $this->base, 'X-XSRF-TOKEN' => urldecode((string) $this->cookie('XSRF-TOKEN'))],
        ]);
    }

    /** fetch() JSON seperti placeOrder() di browser: body mentah, header bisa ditimpa. */
    public function json(string $path, array|string $body, array $headers = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && $this->cookie('XSRF-TOKEN') === null) {
            $this->get('/login');
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Origin' => $this->base,
            ...($withCsrf ? ['X-XSRF-TOKEN' => urldecode((string) $this->cookie('XSRF-TOKEN'))] : []),
            ...$headers,
        ];

        return $this->client->request('POST', $path, ['body' => is_string($body) ? $body : json_encode($body), 'headers' => $headers]);
    }

    /**
     * Partial reload Inertia seperti router.reload({ only }) — dipakai juga untuk memuat
     * deferred props. Mengembalikan objek halaman JSON.
     *
     * @param  array{component: string, version?: string|null}  $page  halaman yang sedang tampil
     * @param  list<string>  $only
     * @return array<string, mixed>|null
     */
    public function partial(string $path, array $page, array $only): ?array
    {
        $response = $this->client->get($path, ['headers' => [
            'Accept' => 'text/html, application/xhtml+xml',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) ($page['version'] ?? ''),
            'X-Inertia-Partial-Component' => $page['component'],
            'X-Inertia-Partial-Data' => implode(',', $only),
        ]]);

        return $response->getStatusCode() === 200 ? json_decode((string) $response->getBody(), true) : null;
    }

    public function cookie(string $name): ?string
    {
        return $this->jar->getCookieByName($name)?->getValue();
    }

    /** Salinan cookie, misalnya cookie sesi yang dicuri. */
    public function copy(): self
    {
        $copy = new self($this->base);
        foreach ($this->jar as $cookie) {
            $copy->jar->setCookie(clone $cookie);
        }

        return $copy;
    }

    /** @return array{component: string, props: array<string, mixed>, url: string}|null */
    public static function page(ResponseInterface $response): ?array
    {
        $html = (string) $response->getBody();
        if (! preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $html, $match)) {
            return null;
        }

        return json_decode($match[1], true);
    }

    public static function location(ResponseInterface $response): string
    {
        return (string) parse_url($response->getHeaderLine('Location'), PHP_URL_PATH)
            .(($query = parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY)) ? "?{$query}" : '');
    }
}
