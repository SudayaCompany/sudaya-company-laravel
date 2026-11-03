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
    public function post(string $path, array $data = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && $this->cookie('XSRF-TOKEN') === null) {
            $this->get('/login');
        }
        $headers = ['Accept' => 'text/html', 'Origin' => $this->base];
        if ($withCsrf) {
            $headers['X-XSRF-TOKEN'] = urldecode((string) $this->cookie('XSRF-TOKEN'));
        }

        return $this->client->post($path, ['json' => $data, 'headers' => $headers]);
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
