<?php

namespace App\Services\Cash;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sesi kas (PRD §56–58), padanan lib/cash/engine.ts. Satu laci per outlet: paling
 * banyak satu sesi terbuka (unique index cash_sessions_one_open_per_outlet).
 *
 * - Penjualan tunai dihitung dari payments yang terikat ke sesi.
 * - Kas masuk, kas keluar, dan refund dicatat di cash_movements.
 * - Saldo seharusnya selalu dihitung ulang, tidak pernah disimpan berjalan.
 */
final class CashSessions
{
    public const NO_SESSION_MESSAGE = 'Sesi kas belum dibuka. Buka sesi kas dulu sebelum menerima uang tunai.';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Kunci sesi terbuka di dalam transaksi pemanggil. `share` untuk pembayaran
     * (boleh bersamaan), `update` untuk operasi yang memeriksa saldo. Tutup sesi
     * memakai `update`, jadi pembayaran yang sedang berjalan selesai lebih dulu.
     */
    public static function lockOpen(string $outletId, string $mode): object
    {
        $lock = $mode === 'update' ? 'for update' : 'for share';
        $session = DB::selectOne("select * from cash_sessions where outlet_id = ? and status = 'open' {$lock}", [$outletId]);

        return $session ?? throw new AccessException('conflict', self::NO_SESSION_MESSAGE);
    }

    /**
     * Refund tetap dihitung sebagai penjualan masuk; uangnya keluar lewat movement.
     *
     * @return array{openingAmount: int, cashSales: int, cashIn: int, cashOut: int, refunds: int, expected: int}
     */
    public static function totals(object $session): array
    {
        $sales = (int) DB::table('payments')
            ->where('cash_session_id', $session->id)
            ->where('method', 'cash')
            ->whereIn('status', ['paid', 'refunded'])
            ->sum('amount');
        $movements = DB::table('cash_movements')
            ->where('cash_session_id', $session->id)
            ->groupBy('type')
            ->selectRaw('type, sum(amount) as total')
            ->get()
            ->pluck('total', 'type');
        $sum = fn (string $type) => (int) ($movements[$type] ?? 0);
        $opening = (int) $session->opening_amount;

        return [
            'openingAmount' => $opening,
            'cashSales' => $sales,
            'cashIn' => $sum('cash_in'),
            'cashOut' => $sum('cash_out'),
            'refunds' => $sum('refund'),
            'expected' => $opening + $sales + $sum('cash_in') - $sum('cash_out') - $sum('refund'),
        ];
    }

    /** @return array{id: string} */
    public function open(User $actor, array $raw, AuditContext $context): array
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $openingAmount = Money::amount($raw['openingAmount'] ?? null, 'Modal awal');
        $this->assertAt($actor, 'cash.open', $outletId);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'company_id', 'brand_id'])
            ?? throw new AccessException('not_found', 'Outlet tidak ditemukan.');

        try {
            return DB::transaction(function () use ($actor, $outlet, $openingAmount, $context) {
                $id = DB::table('cash_sessions')->insertGetId([
                    'company_id' => $outlet->company_id,
                    'brand_id' => $outlet->brand_id,
                    'outlet_id' => $outlet->id,
                    'opened_by' => $actor->id,
                    'opening_amount' => $openingAmount,
                ]);
                $this->audit->record([
                    'company_id' => $outlet->company_id,
                    'brand_id' => $outlet->brand_id,
                    'outlet_id' => $outlet->id,
                    'actor_id' => $actor->id,
                    'action' => 'cash.session_opened',
                    'entity_type' => 'cash_session',
                    'entity_id' => $id,
                    'after' => ['openingAmount' => $openingAmount],
                ], $context);

                return ['id' => $id];
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw new AccessException('conflict', 'Sesi kas outlet ini sudah dibuka.');
            }
            throw $error;
        }
    }

    /** Kas masuk atau keluar dengan alasan. Kas keluar tidak boleh melebihi saldo laci. */
    public function recordMovement(User $actor, array $raw, AuditContext $context): array
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $type = $raw['type'] ?? null;
        if (! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $amount = Money::amount($raw['amount'] ?? null, 'Jumlah', 1, minMessage: 'Jumlah harus lebih dari 0.');
        $reason = Money::reason($raw['reason'] ?? null, 200);
        $this->assertAt($actor, 'cash.close', $outletId);

        return DB::transaction(function () use ($actor, $outletId, $type, $amount, $reason, $context) {
            $session = self::lockOpen($outletId, 'update');
            if ($session->company_id !== $actor->company_id) {
                throw new AccessException('not_found', 'Outlet tidak ditemukan.');
            }
            if ($type === 'cash_out') {
                $expected = self::totals($session)['expected'];
                if ($amount > $expected) {
                    throw new AccessException('invalid', 'Kas keluar melebihi saldo laci '.Money::rupiah($expected).'.');
                }
            }
            $id = DB::table('cash_movements')->insertGetId([
                'company_id' => $session->company_id,
                'brand_id' => $session->brand_id,
                'outlet_id' => $session->outlet_id,
                'cash_session_id' => $session->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);
            $this->audit->record([
                'company_id' => $session->company_id,
                'brand_id' => $session->brand_id,
                'outlet_id' => $session->outlet_id,
                'actor_id' => $actor->id,
                'action' => $type === 'cash_in' ? 'cash.money_in' : 'cash.money_out',
                'entity_type' => 'cash_session',
                'entity_id' => $session->id,
                'after' => ['movementId' => $id, 'amount' => $amount],
                'reason' => $reason,
            ], $context);

            return ['id' => $id];
        });
    }

    /**
     * Tutup sesi dengan uang fisik hasil hitung. Selisih wajib beralasan.
     *
     * @return array{expected: int, actual: int, variance: int}
     */
    public function close(User $actor, array $raw, AuditContext $context): array
    {
        $sessionId = CatalogInput::uuid($raw['sessionId'] ?? null);
        $actual = Money::amount($raw['actualAmount'] ?? null, 'Uang fisik');
        $reason = $raw['reason'] ?? null;
        if ($reason !== null && ! is_string($reason)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 300) {
            throw new AccessException('invalid', 'Alasan maksimal 300 karakter.');
        }

        return DB::transaction(function () use ($actor, $sessionId, $actual, $reason, $context) {
            $session = DB::selectOne('select * from cash_sessions where id = ? and company_id = ? for update', [$sessionId, $actor->company_id])
                ?? throw new AccessException('not_found', 'Sesi kas tidak ditemukan.');
            $this->assertAt($actor, 'cash.close', $session->outlet_id);
            if ($session->status !== 'open') {
                throw new AccessException('conflict', 'Sesi kas ini sudah ditutup.');
            }

            $totals = self::totals($session);
            $variance = $actual - $totals['expected'];
            if ($variance !== 0 && ($reason === null || mb_strlen($reason) < 3)) {
                throw new AccessException('invalid', 'Ada selisih '.Money::rupiah($variance).'. Alasan wajib diisi.');
            }
            DB::table('cash_sessions')->where('id', $session->id)->update([
                'status' => 'closed',
                'closed_by' => $actor->id,
                'closed_at' => DB::raw('clock_timestamp()'),
                'expected_amount' => $totals['expected'],
                'actual_amount' => $actual,
                'variance_reason' => $variance === 0 ? null : $reason,
                'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->record([
                'company_id' => $session->company_id,
                'brand_id' => $session->brand_id,
                'outlet_id' => $session->outlet_id,
                'actor_id' => $actor->id,
                'action' => 'cash.session_closed',
                'entity_type' => 'cash_session',
                'entity_id' => $session->id,
                'before' => ['status' => 'open'],
                'after' => ['status' => 'closed', ...$totals, 'actual' => $actual, 'variance' => $variance],
                'reason' => $variance === 0 ? null : $reason,
            ], $context);

            return ['expected' => $totals['expected'], 'actual' => $actual, 'variance' => $variance];
        });
    }

    /** Sesi aktif, rinciannya, dan riwayat 15 sesi terakhir. Saldo dihitung saat dibaca. */
    public function overview(User $actor, array $outlet): array
    {
        $this->assertAt($actor, 'cash.view', $outlet['id']);
        $sessions = DB::table('cash_sessions')
            ->join('users', 'users.id', '=', 'cash_sessions.opened_by')
            ->where('cash_sessions.outlet_id', $outlet['id'])
            ->where('cash_sessions.company_id', $actor->company_id)
            ->orderByDesc('cash_sessions.opened_at')
            ->limit(15)
            ->get(['cash_sessions.*', 'users.full_name as opened_by_name']);
        $current = $sessions->firstWhere('status', 'open');
        $today = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $iso = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->toIso8601String();

        $movements = $current === null ? collect() : DB::table('cash_movements')
            ->leftJoin('users', 'users.id', '=', 'cash_movements.created_by')
            ->where('cash_movements.cash_session_id', $current->id)
            ->orderByDesc('cash_movements.created_at')
            ->get(['cash_movements.id', 'cash_movements.type', 'cash_movements.amount', 'cash_movements.reason', 'cash_movements.created_at', 'users.full_name as created_by_name']);

        return [
            'current' => $current === null ? null : [
                'id' => $current->id,
                'openedAt' => $iso($current->opened_at),
                'openedByName' => $current->opened_by_name,
                // Sesi lupa ditutup kemarin: kasir diminta menghitung dan menutupnya.
                'fromEarlierDay' => CarbonImmutable::parse($current->opened_at)->setTimezone($outlet['timezone'])->toDateString() !== $today,
                'totals' => self::totals($current),
            ],
            'movements' => $movements->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => (int) $m->amount,
                'reason' => $m->reason,
                'createdAt' => $iso($m->created_at),
                'createdByName' => $m->created_by_name,
            ])->all(),
            'history' => $sessions->where('status', 'closed')->values()->map(fn ($s) => [
                'id' => $s->id,
                'openedAt' => $iso($s->opened_at),
                'closedAt' => $iso($s->closed_at),
                'openedByName' => $s->opened_by_name,
                'expected' => (int) $s->expected_amount,
                'actual' => (int) $s->actual_amount,
                'reason' => $s->variance_reason,
            ])->all(),
        ];
    }

    /** Untuk POS: cukup tahu laci terbuka atau belum, tanpa rincian kas. */
    public static function hasOpen(string $outletId): bool
    {
        return DB::table('cash_sessions')->where('outlet_id', $outletId)->where('status', 'open')->exists();
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
