<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Kunci VAPID untuk Web Push. Dari `.env` (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY) bila diisi; bila tidak,
 * dibuat sekali lalu disimpan di storage (folder ini dipakai bersama antar-rilis di VPS, jadi kunci
 * tidak berganti setiap deploy). Kunci yang berganti membuat langganan lama tidak berlaku: perangkat
 * berlangganan ulang otomatis saat aplikasi kasir dibuka.
 */
final class PushKeys
{
    private const FILE = 'app/private/webpush-vapid.json';

    /** @var array{publicKey: string, privateKey: string}|null */
    private ?array $cached = null;

    /** @return array{publicKey: string, privateKey: string} */
    public function keys(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $public = (string) config('services.webpush.public_key');
        $private = (string) config('services.webpush.private_key');
        if ($public !== '' && $private !== '') {
            return $this->cached = ['publicKey' => $public, 'privateKey' => $private];
        }

        $path = storage_path(self::FILE);
        $stored = self::read($path);
        if ($stored !== null) {
            return $this->cached = $stored;
        }
        $fresh = self::generate();
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }
        // Dua proses bisa membuat bersamaan: yang pertama menang, yang lain memakai milik yang pertama.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            fwrite($handle, (string) json_encode($fresh));
            fclose($handle);

            return $this->cached = $fresh;
        }

        return $this->cached = self::read($path) ?? throw new RuntimeException('Kunci Web Push tidak bisa disimpan.');
    }

    /** Kunci publik untuk browser; null bila server tidak bisa membuat kunci (push dimatikan, aplikasi tetap jalan). */
    public function publicKey(): ?string
    {
        try {
            return $this->keys()['publicKey'];
        } catch (Throwable $error) {
            Log::warning('web_push_keys_unavailable', ['message' => $error->getMessage()]);

            return null;
        }
    }

    /** Kontak pengirim untuk layanan push: VAPID_SUBJECT, atau alamat web https, atau mailto bawaan. */
    public function subject(): string
    {
        $subject = (string) config('services.webpush.subject');
        if ($subject !== '') {
            return $subject;
        }
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? rtrim($url, '/') : 'mailto:admin@sudaya.invalid';
    }

    /** @return array{publicKey: string, privateKey: string}|null */
    private static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && is_string($data['publicKey'] ?? null) && is_string($data['privateKey'] ?? null)
            ? ['publicKey' => $data['publicKey'], 'privateKey' => $data['privateKey']]
            : null;
    }

    /**
     * Pasangan kunci P-256 dalam format VAPID (base64url: titik publik tak terkompresi 65 byte, kunci
     * privat 32 byte). PHP di Windows sering tidak menemukan openssl.cnf: coba file bawaan PHP.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generate(): array
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options);
        if ($key === false && ($config = self::opensslConfig()) !== null) {
            $key = openssl_pkey_new($options + ['config' => $config]);
        }
        while (openssl_error_string() !== false) {
            // Kosongkan antrean error OpenSSL dari percobaan pertama.
        }
        if ($key === false) {
            throw new RuntimeException('OpenSSL tidak bisa membuat kunci EC. Atur OPENSSL_CONF ke openssl.cnf.');
        }
        $ec = openssl_pkey_get_details($key)['ec'] ?? null;
        if (! is_array($ec)) {
            throw new RuntimeException('Kunci EC tidak terbaca.');
        }
        $pad = fn (string $bytes) => str_pad($bytes, 32, "\0", STR_PAD_LEFT);

        return [
            'publicKey' => self::base64url("\x04".$pad($ec['x']).$pad($ec['y'])),
            'privateKey' => self::base64url($pad($ec['d'])),
        ];
    }

    private static function opensslConfig(): ?string
    {
        foreach ([getenv('OPENSSL_CONF') ?: null, dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
