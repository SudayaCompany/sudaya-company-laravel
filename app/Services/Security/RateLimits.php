<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\DB;

/**
 * Rate limit fixed-window di Postgres: satu statement atomik (upsert) per
 * request, konsisten di semua worker PHP tanpa layanan tambahan. Key di-hash
 * sehingga tabel tidak menyimpan IP atau id perangkat mentah.
 *
 * Rule berbentuk array berisi key, limit, dan windowSeconds. Hasilnya berisi ok,
 * ditambah retryAfterSeconds dan key bila ditolak.
 */
final class RateLimits
{
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Menaikkan semua counter sekaligus lalu menilai hasilnya. */
    public function consume(array $rules): array
    {
        $byHash = $this->byHash($rules);
        if ($byHash === []) {
            return ['ok' => true];
        }

        $values = implode(', ', array_fill(0, count($byHash), '(?::text, ?::int)'));
        $bindings = [];
        foreach ($byHash as $hash => $rule) {
            array_push($bindings, $hash, $rule['windowSeconds']);
        }

        $rows = DB::select("
            insert into rate_limit_counters as c (key, window_start, count, expires_at)
            select v.key, now(), 1, now() + make_interval(secs => v.window_seconds)
            from (values {$values}) as v(key, window_seconds)
            on conflict (key) do update set
              window_start = case when c.expires_at <= now() then now() else c.window_start end,
              count = case when c.expires_at <= now() then 1 else c.count + 1 end,
              expires_at = case when c.expires_at <= now() then excluded.expires_at else c.expires_at end
            returning c.key, c.count,
              greatest(1, ceil(extract(epoch from c.expires_at - now())))::int as retry_after
        ", $bindings);

        $blocked = null;
        foreach ($rows as $row) {
            $rule = $byHash[$row->key] ?? null;
            if ($rule === null || (int) $row->count <= $rule['limit']) {
                continue;
            }
            $retryAfter = min((int) $row->retry_after, $rule['windowSeconds']);
            if ($blocked === null || $retryAfter > $blocked['retryAfterSeconds']) {
                $blocked = ['ok' => false, 'retryAfterSeconds' => $retryAfter, 'key' => $rule['key']];
            }
        }

        return $blocked ?? ['ok' => true];
    }

    /** Menilai counter tanpa menaikkannya: ditolak bila percobaan berikutnya melewati batas. */
    public function peek(array $rules): array
    {
        $byHash = $this->byHash($rules);
        if ($byHash === []) {
            return ['ok' => true];
        }

        $rows = DB::table('rate_limit_counters')
            ->whereIn('key', array_keys($byHash))
            ->whereRaw('expires_at > now()')
            ->selectRaw('key, count, greatest(1, ceil(extract(epoch from expires_at - now())))::int as retry_after')
            ->get();

        $blocked = null;
        foreach ($rows as $row) {
            $rule = $byHash[$row->key];
            if ((int) $row->count < $rule['limit']) {
                continue;
            }
            $retryAfter = (int) $row->retry_after;
            if ($blocked === null || $retryAfter > $blocked['retryAfterSeconds']) {
                $blocked = ['ok' => false, 'retryAfterSeconds' => $retryAfter, 'key' => $rule['key']];
            }
        }

        return $blocked ?? ['ok' => true];
    }

    /** Mengembalikan satu percobaan yang sudah dicatat; jendela waktu tidak berubah. */
    public function release(array $rules): void
    {
        if ($rules === []) {
            return;
        }
        DB::table('rate_limit_counters')
            ->whereIn('key', array_keys($this->byHash($rules)))
            ->whereRaw('expires_at > now()')
            ->update(['count' => DB::raw('greatest(count - 1, 0)')]);
    }

    private function byHash(array $rules): array
    {
        $byHash = [];
        foreach ($rules as $rule) {
            $byHash[self::hash($rule['key'])] = $rule;
        }

        return $byHash;
    }
}
