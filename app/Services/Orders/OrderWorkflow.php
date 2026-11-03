<?php

namespace App\Services\Orders;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use App\Support\Money;
use App\Support\Orders\OrderFlow;
use Illuminate\Support\Facades\DB;

/**
 * Pembayaran tunai, antrean, status produksi (KDS), pembatalan, dan refund,
 * padanan bagian M4 lib/orders/engine.ts. Setiap operasi memakai transaksi dan
 * row lock pada order, sehingga double click, retry, dan request bersamaan
 * tidak menghasilkan pembayaran, nomor antrean, atau refund ganda.
 */
final class OrderWorkflow
{
    public const EXPIRED_MESSAGE = 'Batas waktu pembayaran pesanan ini sudah habis, pesanan dibatalkan otomatis. Buat pesanan baru.';

    public function __construct(private AuditLogger $audit) {}

    // --- Pembayaran dan antrean -------------------------------------------------------

    /**
     * Kasir mengonfirmasi uang tunai sudah diterima (PRD §43). Idempotent: order
     * yang sudah lunas tidak mendapat nomor antrean kedua.
     *
     * Race dengan kedaluwarsa: baris order dikunci di sini, dan fungsi expiry memakai
     * SKIP LOCKED, jadi hanya salah satu yang menang. Order yang sudah lewat
     * expires_at (waktu DB) ditolak dan langsung dikedaluwarsakan.
     *
     * @return array{orderNumber: ?string, change: ?int, alreadyPaid: bool}
     */
    public function confirmCash(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $tendered = Money::amount($raw['tenderedAmount'] ?? null, 'Uang diterima', max: 100_000_000);
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'payment.confirm_cash', $order->outlet_id);

        $result = DB::transaction(function () use ($actor, $order, $tendered, $context) {
            // Kunci baris order: pembayaran bersamaan untuk order yang sama antre di sini.
            $locked = DB::selectOne('select *, (expires_at is not null and expires_at <= now()) as is_expired from orders where id = ? for update', [$order->id]);
            if ($locked->payment_status === 'paid') {
                return ['orderNumber' => $locked->order_number, 'change' => null, 'alreadyPaid' => true];
            }
            if ($locked->status === 'pending_payment' && $locked->is_expired) {
                // Commit pembatalan otomatis (fungsi yang sama dengan pg_cron), lalu tolak.
                DB::select('select public.expire_pending_orders(1, ?::uuid)', [$locked->id]);

                return 'expired';
            }
            if ($locked->status !== 'pending_payment') {
                throw new AccessException('conflict', $locked->payment_status === 'expired' ? self::EXPIRED_MESSAGE : 'Pesanan ini tidak sedang menunggu pembayaran.');
            }
            $total = (int) $locked->total;
            if ($tendered < $total) {
                throw new AccessException('invalid', 'Uang diterima kurang dari total '.Money::rupiah($total).'.');
            }
            // Pemotongan stok produk jadi (M5) belum ada di Laravel. Produk yang tertaut stok
            // ditolak sebelum ada tagihan lunas, kas, atau nomor antrean, agar stok tidak salah diam-diam.
            $this->assertNoLinkedStock($locked);

            // Uang tunai hanya diterima saat laci terbuka; tutup sesi menunggu kunci ini.
            $cashSession = CashSessions::lockOpen($locked->outlet_id, 'share');

            $outlet = DB::table('outlets')->where('id', $locked->outlet_id)->first(['id', 'timezone', 'order_number_prefix']);
            // Counter harian per outlet. Upsert mengunci baris counter sehingga transaksi
            // bersamaan mendapat nomor berurutan dan tidak pernah sama.
            $counter = DB::selectOne(
                'insert into order_number_counters (outlet_id, business_date, last_number)
                 values (?, (clock_timestamp() at time zone ?)::date, 1)
                 on conflict (outlet_id, business_date) do update set last_number = order_number_counters.last_number + 1
                 returning business_date, last_number',
                [$outlet->id, $outlet->timezone],
            );
            $queueNumber = (int) $counter->last_number;
            $orderNumber = OrderFlow::orderNumber($outlet->order_number_prefix, $queueNumber);
            $change = $tendered - $total;
            // Diambil SETELAH lock counter: urutan paid_at sama dengan urutan nomor antrean.
            $paidAt = DB::selectOne('select clock_timestamp() as t')->t;

            $updated = DB::table('payments')
                ->where('order_id', $locked->id)
                ->where('status', 'pending')
                ->update([
                    'method' => 'cash',
                    'status' => 'paid',
                    'cash_session_id' => $cashSession->id,
                    'tendered_amount' => $tendered,
                    'change_amount' => $change,
                    'paid_at' => $paidAt,
                    'confirmed_by' => $actor->id,
                    'updated_at' => DB::raw('now()'),
                ]);
            if ($updated === 0) {
                DB::table('payments')->insert([
                    'company_id' => $locked->company_id,
                    'brand_id' => $locked->brand_id,
                    'outlet_id' => $locked->outlet_id,
                    'order_id' => $locked->id,
                    'method' => 'cash',
                    'status' => 'paid',
                    'amount' => $total,
                    'cash_session_id' => $cashSession->id,
                    'tendered_amount' => $tendered,
                    'change_amount' => $change,
                    'paid_at' => $paidAt,
                    'confirmed_by' => $actor->id,
                    'created_by' => $actor->id,
                ]);
            }

            DB::table('orders')->where('id', $locked->id)->update([
                'status' => 'queued',
                'payment_status' => 'paid',
                'paid_at' => $paidAt,
                'business_date' => $counter->business_date,
                'queue_number' => $queueNumber,
                'order_number' => $orderNumber,
                'updated_at' => DB::raw('now()'),
            ]);

            $base = $this->auditBase($locked, $actor);
            $this->audit->record([...$base, 'action' => 'payment.confirmed', 'after' => ['method' => 'cash', 'amount' => $total, 'tendered' => $tendered, 'change' => $change]], $context);
            $this->audit->record([...$base, 'action' => 'order.queued', 'before' => ['status' => $locked->status], 'after' => ['status' => 'queued', 'orderNumber' => $orderNumber, 'businessDate' => $counter->business_date]], $context);
            OrderEngine::notify(self::topics($locked));

            return ['orderNumber' => $orderNumber, 'change' => $change, 'alreadyPaid' => false];
        });

        if ($result === 'expired') {
            throw new AccessException('conflict', self::EXPIRED_MESSAGE);
        }

        return $result;
    }

    // --- Status produksi (KDS) ------------------------------------------------------------

    /**
     * Pindah satu langkah produksi. `from` adalah status yang dilihat user, sehingga
     * double click tidak menggeser dua langkah.
     */
    public function updateStatus(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $from = $raw['from'] ?? null;
        $to = $raw['to'] ?? null;
        if (! in_array($from, OrderFlow::STATUSES, true) || ! in_array($to, OrderFlow::STATUSES, true)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'order.update_status', $order->outlet_id);
        $kind = OrderFlow::transitionKind($from, $to) ?? throw new AccessException('invalid', 'Perubahan status ini tidak diizinkan.');
        if ($kind === 'override') {
            $this->assertAt($actor, 'kds.manage', $order->outlet_id);
        }

        return DB::transaction(function () use ($actor, $order, $from, $to, $kind, $context) {
            $locked = DB::selectOne('select * from orders where id = ? for update', [$order->id]);
            if ($locked->status !== $from) {
                throw new AccessException('conflict', 'Status pesanan sudah berubah. Tampilan dimuat ulang.');
            }
            if ($locked->payment_status !== 'paid') {
                throw new AccessException('conflict', 'Pesanan belum dibayar.');
            }

            $set = ['status' => $to, 'updated_at' => DB::raw('now()')];
            if ($kind === 'normal' && isset(OrderFlow::TIMESTAMPS[$to])) {
                $set[OrderFlow::TIMESTAMPS[$to]] = DB::raw('clock_timestamp()');
            }
            if ($kind === 'override' && isset(OrderFlow::TIMESTAMPS[$from])) {
                // Mundur: hapus waktu tahap yang dibatalkan.
                $set[OrderFlow::TIMESTAMPS[$from]] = null;
            }
            DB::table('orders')->where('id', $locked->id)->update($set);
            $this->audit->record([
                ...$this->auditBase($locked, $actor),
                'action' => $kind === 'override' ? 'order.status_override' : "order.{$to}",
                'before' => ['status' => $from],
                'after' => ['status' => $to, 'orderNumber' => $locked->order_number],
            ], $context);
            OrderEngine::notify(self::topics($locked));

            return ['status' => $to];
        });
    }

    // --- Pembatalan (PRD §48) ---------------------------------------------------------------

    /**
     * Order belum dibayar langsung dibatalkan. Order lunas hanya diajukan (approval
     * Manager); pembatalan tidak berarti uang dikembalikan.
     *
     * @return array{result: 'cancelled'|'approval_requested'}
     */
    public function cancel(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $reason = Money::reason($raw['reason'] ?? null, 300, 'Alasan wajib diisi.', 'Alasan minimal 3 karakter.');
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'order.void', $order->outlet_id);

        return DB::transaction(function () use ($actor, $order, $reason, $context) {
            $locked = DB::selectOne('select * from orders where id = ? for update', [$order->id]);
            if (in_array($locked->status, OrderFlow::TERMINAL, true)) {
                throw new AccessException('conflict', 'Pesanan sudah selesai atau dibatalkan.');
            }
            $base = $this->auditBase($locked, $actor);

            if ($locked->payment_status !== 'paid') {
                DB::table('orders')->where('id', $locked->id)->update([
                    'status' => 'cancelled',
                    'payment_status' => 'expired',
                    'cancelled_at' => DB::raw('clock_timestamp()'),
                    'cancel_reason' => $reason,
                    'updated_at' => DB::raw('now()'),
                ]);
                DB::table('payments')->where('order_id', $locked->id)->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => DB::raw('now()')]);
                $this->audit->record([...$base, 'action' => 'order.cancelled', 'before' => ['status' => $locked->status], 'after' => ['status' => 'cancelled', 'paid' => false], 'reason' => $reason], $context);
                OrderEngine::notify(self::topics($locked));

                return ['result' => 'cancelled'];
            }

            $pending = DB::table('approvals')->where('type', 'void')->where('reference_id', $locked->id)->where('status', 'pending')->exists();
            if ($pending) {
                throw new AccessException('conflict', 'Pembatalan sudah diajukan dan menunggu persetujuan.');
            }
            $approvalId = DB::table('approvals')->insertGetId([
                'company_id' => $locked->company_id,
                'brand_id' => $locked->brand_id,
                'outlet_id' => $locked->outlet_id,
                'type' => 'void',
                'reference_id' => $locked->id,
                'reason' => $reason,
                'requested_by' => $actor->id,
            ]);
            $this->audit->record([...$base, 'action' => 'order.cancel_requested', 'after' => ['approvalId' => $approvalId, 'orderNumber' => $locked->order_number], 'reason' => $reason], $context);
            OrderEngine::notify([OrderEngine::outletTopic($locked->outlet_id)]);

            return ['result' => 'approval_requested'];
        });
    }

    /** Manager menyetujui atau menolak pembatalan order lunas. Pemohon tidak bisa memutuskan sendiri. */
    public function decideCancellation(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = $raw['note'] ?? null;
        if ($note !== null && (! is_string($note) || mb_strlen(trim($note)) > 300)) {
            throw new AccessException('invalid', 'Catatan maksimal 300 karakter.');
        }
        $note = $note === null || trim($note) === '' ? null : trim($note);

        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'void')->first()
            ?? throw new AccessException('not_found', 'Pesanan tidak ditemukan.');
        $this->assertAt($actor, $approve ? 'approval.approve' : 'approval.reject', $approval->outlet_id);
        if ($approval->requested_by === $actor->id) {
            throw AccessException::forbidden('Anda tidak bisa memutuskan pengajuan Anda sendiri.');
        }

        return DB::transaction(function () use ($actor, $approval, $approve, $note, $context) {
            $decided = DB::selectOne(
                "update approvals set status = ?::approval_status, decided_by = ?, decision_note = ?, decided_at = clock_timestamp(), updated_at = now()
                 where id = ? and status = 'pending' returning id",
                [$approve ? 'approved' : 'rejected', $actor->id, $note, $approval->id],
            );
            if ($decided === null) {
                throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            }
            $locked = DB::selectOne('select * from orders where id = ? for update', [$approval->reference_id]);
            $base = $this->auditBase($locked, $actor);

            if ($approve) {
                if (in_array($locked->status, OrderFlow::TERMINAL, true)) {
                    throw new AccessException('conflict', 'Pesanan sudah selesai/dibatalkan; gunakan proses refund terpisah.');
                }
                DB::table('orders')->where('id', $locked->id)->update([
                    'status' => 'cancelled',
                    'cancelled_at' => DB::raw('clock_timestamp()'),
                    'cancel_reason' => $approval->reason,
                    'updated_at' => DB::raw('now()'),
                ]);
                // Status pembayaran tetap "paid": pengembalian uang adalah proses refund.
                $this->audit->record([...$base, 'action' => 'order.cancelled', 'before' => ['status' => $locked->status], 'after' => ['status' => 'cancelled', 'paid' => true, 'approvalId' => $approval->id], 'reason' => $approval->reason], $context);
            }
            $this->audit->record([...$base, 'action' => $approve ? 'approval.approved' : 'approval.rejected', 'after' => ['approvalId' => $approval->id, 'type' => 'void'], 'reason' => $note], $context);
            OrderEngine::notify(self::topics($locked));

            return ['approved' => $approve];
        });
    }

    // --- Refund ----------------------------------------------------------------------------

    /**
     * Refund tunai penuh untuk order lunas yang pembatalannya sudah disetujui. Dicatat
     * terpisah karena penyerahan uang adalah kejadian fisik; uangnya keluar dari sesi
     * kas yang sedang terbuka, bukan dari sesi saat order dibayar.
     */
    public function refundCash(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'payment.refund', $order->outlet_id);

        return DB::transaction(function () use ($actor, $order, $context) {
            $locked = DB::selectOne('select * from orders where id = ? for update', [$order->id]);
            if ($locked->payment_status === 'refunded') {
                throw new AccessException('conflict', 'Refund pesanan ini sudah tercatat.');
            }
            if ($locked->status !== 'cancelled' || $locked->payment_status !== 'paid') {
                throw new AccessException('conflict', 'Refund hanya untuk pesanan lunas yang pembatalannya sudah disetujui.');
            }
            $payment = DB::selectOne("select * from payments where order_id = ? and status = 'paid' for update", [$locked->id]);
            if ($payment === null || $payment->method !== 'cash') {
                throw new AccessException('conflict', 'Refund otomatis hanya tersedia untuk pembayaran tunai.');
            }

            $session = CashSessions::lockOpen($locked->outlet_id, 'update');
            $expected = CashSessions::totals($session)['expected'];
            $amount = (int) $payment->amount;
            if ($amount > $expected) {
                throw new AccessException('invalid', 'Saldo laci '.Money::rupiah($expected).' tidak cukup untuk refund.');
            }
            $approvalId = DB::table('approvals')->where('type', 'void')->where('reference_id', $locked->id)->where('status', 'approved')->value('id');
            $reason = $locked->cancel_reason ?? 'Pembatalan pesanan';
            $tenant = ['company_id' => $locked->company_id, 'brand_id' => $locked->brand_id, 'outlet_id' => $locked->outlet_id];

            $refundId = DB::table('refunds')->insertGetId([
                ...$tenant,
                'order_id' => $locked->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'reason' => $reason,
                'approval_id' => $approvalId,
                'refunded_at' => DB::raw('clock_timestamp()'),
                'created_by' => $actor->id,
            ]);
            DB::table('cash_movements')->insert([
                ...$tenant,
                'cash_session_id' => $session->id,
                'type' => 'refund',
                'amount' => $amount,
                'reason' => $reason,
                'refund_id' => $refundId,
                'created_by' => $actor->id,
            ]);
            DB::table('payments')->where('id', $payment->id)->update(['status' => 'refunded', 'updated_at' => DB::raw('now()')]);
            DB::table('orders')->where('id', $locked->id)->update(['payment_status' => 'refunded', 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                ...$this->auditBase($locked, $actor),
                'action' => 'payment.refunded',
                'before' => ['paymentStatus' => 'paid'],
                'after' => ['paymentStatus' => 'refunded', 'amount' => $amount, 'refundId' => $refundId, 'cashSessionId' => $session->id],
                'reason' => $reason,
            ], $context);
            OrderEngine::notify(self::topics($locked));

            return ['amount' => $amount];
        });
    }

    // --- Bantuan ------------------------------------------------------------------------

    /**
     * Penjagaan stok: tolak bila ada produk order yang di outlet ini tertaut ke
     * inventory item. Tautan tidak diubah; pembayaran saja yang tidak diproses.
     */
    private function assertNoLinkedStock(object $order): void
    {
        $names = DB::table('order_items')
            ->join('outlet_products', fn ($join) => $join->on('outlet_products.product_id', '=', 'order_items.product_id')->where('outlet_products.outlet_id', '=', $order->outlet_id))
            ->where('order_items.order_id', $order->id)
            ->whereNotNull('outlet_products.inventory_item_id')
            ->distinct()
            ->orderBy('order_items.product_name')
            ->pluck('order_items.product_name')
            ->all();
        if ($names !== []) {
            throw new AccessException('conflict', 'Pembayaran belum bisa diproses: '.implode(', ', $names).' terhubung ke stok, dan pemotongan stok otomatis belum tersedia. Pesanan tetap menunggu pembayaran. Hubungi Manager.');
        }
    }

    private function loadForStaff(User $actor, string $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->where('company_id', $actor->company_id)->first(['id', 'outlet_id'])
            ?? throw new AccessException('not_found', 'Pesanan tidak ditemukan.');
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }

    private function auditBase(object $order, User $actor): array
    {
        return [
            'company_id' => $order->company_id,
            'brand_id' => $order->brand_id,
            'outlet_id' => $order->outlet_id,
            'actor_id' => $actor->id,
            'entity_type' => 'order',
            'entity_id' => $order->id,
        ];
    }

    /** @return list<string> */
    private static function topics(object $order): array
    {
        return array_values(array_filter([
            OrderEngine::outletTopic($order->outlet_id),
            $order->access_token_hash === null ? null : GuestTokens::topic($order->access_token_hash),
        ]));
    }
}
