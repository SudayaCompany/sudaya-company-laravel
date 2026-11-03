<?php

namespace App\Services\Security;

use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Batas login menghitung KEGAGALAN, bukan semua percobaan, agar orang lain tidak
 * bisa mengunci akun hanya dengan mengirim percobaan sampah:
 * - email + IP tepercaya: menahan tebak password dari satu jaringan;
 * - IP tepercaya: longgar, karena staf satu outlet berbagi Wi-Fi;
 * - email saja: menahan serangan terdistribusi, dilewati dari jaringan yang
 *   pernah login sukses, sehingga pemilik akun tidak ikut terkunci.
 * Tanpa IP tepercaya hanya ada batas email, jadi lockout oleh pihak lain masih
 * mungkin di konfigurasi itu.
 */
final class LoginLimits
{
    public const WINDOW_SECONDS = 15 * 60;

    public const KNOWN_NETWORK_SECONDS = 30 * 24 * 60 * 60;

    /** Kegagalan per email + IP per 15 menit (per email bila IP tidak tersedia). */
    public const ACCOUNT_IP = 10;

    /** Kegagalan per IP per 15 menit. */
    public const IP = 30;

    /** Kegagalan per email dari jaringan baru per 15 menit. */
    public const ACCOUNT = 50;

    public function __construct(private RateLimits $limits, private AuditLogger $audit) {}

    public static function rules(string $email, ?string $ip, bool $knownNetwork = false): array
    {
        $window = self::WINDOW_SECONDS;
        if ($ip === null) {
            return [['key' => "login:email:{$email}", 'limit' => self::ACCOUNT_IP, 'windowSeconds' => $window]];
        }
        $rules = [
            ['key' => "login:email-ip:{$email}:{$ip}", 'limit' => self::ACCOUNT_IP, 'windowSeconds' => $window],
            ['key' => "login:ip:{$ip}", 'limit' => self::IP, 'windowSeconds' => $window],
        ];
        if (! $knownNetwork) {
            $rules[] = ['key' => "login:email:{$email}", 'limit' => self::ACCOUNT, 'windowSeconds' => $window];
        }

        return $rules;
    }

    public static function knownNetworkRule(string $email, string $ip): array
    {
        return ['key' => "login:known:{$email}:{$ip}", 'limit' => 1, 'windowSeconds' => self::KNOWN_NETWORK_SECONDS];
    }

    /** Pesan sama untuk email terdaftar maupun tidak (tanpa enumerasi akun). */
    public static function message(int $retryAfterSeconds): string
    {
        return 'Terlalu banyak percobaan login. Coba lagi dalam '.(int) ceil($retryAfterSeconds / 60).' menit.';
    }

    /**
     * Peek dulu agar percobaan yang ditolak tidak menaikkan counter atau memperpanjang
     * jendela. Reservasi menutup celah request paralel yang lolos peek bersamaan.
     */
    public function begin(string $email, ?string $ip): array
    {
        $known = $ip !== null && ! $this->limits->peek([self::knownNetworkRule($email, $ip)])['ok'];
        $rules = self::rules($email, $ip, $known);
        $seen = $this->limits->peek($rules);
        if (! $seen['ok']) {
            return [...$seen, 'rules' => $rules];
        }
        $reserved = $this->limits->consume($rules);
        if (! $reserved['ok']) {
            $this->limits->release($rules);
        }

        return [...$reserved, 'rules' => $rules];
    }

    public function release(array $attempt): void
    {
        $this->limits->release($attempt['rules']);
    }

    public function rememberNetwork(string $email, ?string $ip): void
    {
        if ($ip !== null) {
            $this->limits->consume([self::knownNetworkRule($email, $ip)]);
        }
    }

    /** audit_logs wajib punya company, jadi email yang tidak dikenal tidak dicatat. */
    public function recordFailure(string $email, array $attempt, AuditContext $context): void
    {
        $account = DB::table('users')
            ->join('auth.users as auth_user', 'auth_user.id', '=', 'users.id')
            ->whereRaw('lower(auth_user.email) = ?', [$email])
            ->select('users.id', 'users.company_id')
            ->first();
        if ($account === null) {
            return;
        }

        $entry = [
            'company_id' => $account->company_id,
            'actor_id' => null,
            'entity_type' => 'user',
            'entity_id' => $account->id,
        ];
        $this->audit->record([...$entry, 'action' => 'auth.login_failed'], $context);

        // Setelah batas tercapai percobaan tidak dihitung lagi, jadi baris ini tidak mengisi log per request.
        $accountRules = array_values(array_filter(
            $attempt['rules'],
            fn (array $rule) => str_starts_with($rule['key'], 'login:email'),
        ));
        if (! $this->limits->peek($accountRules)['ok']) {
            $this->audit->record([...$entry, 'action' => 'auth.login_limited'], $context);
        }
    }
}
