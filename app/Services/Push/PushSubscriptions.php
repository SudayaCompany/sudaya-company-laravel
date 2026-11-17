<?php

namespace App\Services\Push;

use App\Exceptions\AccessException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Langganan Web Push per perangkat (satu endpoint = satu browser/aplikasi terpasang). */
final class PushSubscriptions
{
    /**
     * Host layanan push resmi browser (Chrome/Android, Firefox, Safari/iPhone, Edge/Windows). Server
     * hanya mengirim ke host ini, bukan ke alamat sembarang yang dikirim klien (cegah SSRF).
     */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'];

    public static function allowedEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Simpan atau perbarui langganan perangkat ini (endpoint yang sama dipindah ke user yang sedang
     * login, mis. HP kasir bergantian akun).
     *
     * @param  array{endpoint: string, p256dh: string, auth: string, contentEncoding: string}  $input
     */
    public function store(User $user, array $input, ?string $userAgent): void
    {
        if (! self::allowedEndpoint($input['endpoint'])) {
            throw new AccessException('invalid', 'Layanan notifikasi browser ini tidak didukung.');
        }
        $now = now();
        DB::table('push_subscriptions')->upsert([[
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'endpoint' => $input['endpoint'],
            'p256dh' => $input['p256dh'],
            'auth' => $input['auth'],
            'content_encoding' => $input['contentEncoding'],
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 300),
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['endpoint'], ['company_id', 'user_id', 'p256dh', 'auth', 'content_encoding', 'user_agent', 'updated_at']);
    }

    /** Keluar dari aplikasi: perangkat ini berhenti menerima notifikasi milik user tersebut. */
    public function delete(User $user, string $endpoint): void
    {
        DB::table('push_subscriptions')->where('endpoint', $endpoint)->where('user_id', $user->id)->delete();
    }
}
