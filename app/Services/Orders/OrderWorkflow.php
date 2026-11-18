<?php

namespace App\Services\Orders;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Cash\CashSessions;
use App\Services\Catalog\CatalogInput;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\Qty;
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

    public const REQUEST_PRICE_MESSAGE = 'Isi dulu harga permintaan di luar menu. Pembayaran baru bisa diterima setelah totalnya final.';

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

        return $this->settle($actor, $order, 'cash', $tendered, $context);
    }

    /**
     * Kasir mengonfirmasi pembayaran lewat QRIS statis milik outlet setelah mengecek dana masuk
     * di aplikasi bank/QRIS (konfirmasi manual; belum ada payment gateway, EXT-001). Tanpa sesi
     * kas dan kembalian; nomor antrean, potong stok, dan audit sama dengan tunai. Idempotent.
     *
     * @return array{orderNumber: ?string, change: ?int, alreadyPaid: bool}
     */
    public function confirmQris(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'payment.confirm_cash', $order->outlet_id);
        if (DB::table('outlets')->where('id', $order->outlet_id)->value('qris_image_path') === null) {
            throw new AccessException('invalid', 'QRIS belum diatur untuk outlet ini.');
        }

        return $this->settle($actor, $order, 'qris', null, $context);
    }

    /** Pelunasan bersama tunai/QRIS: kunci order, nomor antrean, potong stok, audit. */
    private function settle(User $actor, object $order, string $method, ?int $tendered, AuditContext $context): array
    {
        $result = DB::transaction(function () use ($actor, $order, $method, $tendered, $context) {
            // Kunci baris order: pembayaran bersamaan untuk order yang sama antre di sini.
            $locked = DB::selectOne('select *, (expires_at is not null and expires_at <= now()) as is_expired from orders where id = ? for update', [$order->id]);
            if ($locked->payment_status === 'paid') {
                return ['orderNumber' => $locked->order_number, 'change' => null, 'alreadyPaid' => true];
            }
            // Keputusan owner 17 November 2026: permintaan di luar menu diberi harga kasir dulu.
            if ($locked->custom_request_status === 'pending') {
                throw new AccessException('conflict', self::REQUEST_PRICE_MESSAGE);
            }
            if ($locked->pay_on_delivery) {
                return $this->settleOnDelivery($actor, $locked, $method, $tendered, $context);
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
            $cashSession = $this->cashSessionFor($method, $tendered, $total, $locked->outlet_id);

            // Potong stok produk jadi lebih dulu (keputusan owner 9 November 2026, PRD §21–22):
            // stok kurang → seluruh pembayaran dibatalkan (rollback), uang tidak dianggap diterima.
            // Bahan resep/BOM ikut dipotong tanpa pernah menolak pembayaran (kekurangan dicatat).
            $sales = InventoryLedger::postSale($locked->id, $locked->outlet_id, $actor->id);

            $queue = self::nextQueueNumber($locked->outlet_id);
            $change = $method === 'cash' ? $tendered - $total : null;
            // Diambil SETELAH lock counter: urutan paid_at sama dengan urutan nomor antrean.
            $paidAt = DB::selectOne('select clock_timestamp() as t')->t;
            $this->recordPayment($locked, $actor, $method, $total, $cashSession, $tendered, $change, $paidAt);

            DB::table('orders')->where('id', $locked->id)->update([
                'status' => 'queued',
                'payment_status' => 'paid',
                'paid_at' => $paidAt,
                'business_date' => $queue['businessDate'],
                'queue_number' => $queue['queueNumber'],
                'order_number' => $queue['orderNumber'],
                'updated_at' => DB::raw('now()'),
            ]);

            $base = $this->auditBase($locked, $actor);
            $this->audit->record([...$base, 'action' => 'payment.confirmed', 'after' => $method === 'cash'
                ? ['method' => 'cash', 'amount' => $total, 'tendered' => $tendered, 'change' => $change]
                : ['method' => $method, 'amount' => $total, 'manualConfirmation' => true]], $context);
            $this->audit->record([...$base, 'action' => 'order.queued', 'before' => ['status' => $locked->status], 'after' => ['status' => 'queued', 'orderNumber' => $queue['orderNumber'], 'businessDate' => $queue['businessDate']]], $context);
            if ($sales !== []) {
                $this->audit->record([...$base, 'action' => 'inventory.sale_posted', 'after' => ['orderNumber' => $queue['orderNumber'], 'items' => self::stockLines($sales)]], $context);
            }
            OrderEngine::notify(self::topics($locked));

            return ['orderNumber' => $queue['orderNumber'], 'change' => $change, 'alreadyPaid' => false];
        });

        if ($result === 'expired') {
            throw new AccessException('conflict', self::EXPIRED_MESSAGE);
        }

        return $result;
    }

    /**
     * Bayar saat diantar (keputusan owner 11 November 2026): pesanan sudah di antrean sejak dibuat
     * (stok sudah dipotong, nomor antrean sudah ada), jadi di sini hanya uang yang dicatat. Status
     * produksi tidak diubah. Dipanggil di dalam transaksi settle() dengan baris order terkunci.
     *
     * @return array{orderNumber: ?string, change: ?int, alreadyPaid: bool}
     */
    private function settleOnDelivery(User $actor, object $locked, string $method, ?int $tendered, AuditContext $context): array
    {
        if ($locked->payment_status !== 'pending' || ! in_array($locked->status, OrderFlow::ACTIVE_QUEUE, true)) {
            throw new AccessException('conflict', 'Pesanan ini tidak sedang menunggu pembayaran.');
        }
        $total = (int) $locked->total;
        $cashSession = $this->cashSessionFor($method, $tendered, $total, $locked->outlet_id);
        $change = $method === 'cash' ? $tendered - $total : null;
        $paidAt = DB::selectOne('select clock_timestamp() as t')->t;
        $this->recordPayment($locked, $actor, $method, $total, $cashSession, $tendered, $change, $paidAt);
        DB::table('orders')->where('id', $locked->id)->update([
            'payment_status' => 'paid',
            'paid_at' => $paidAt,
            'updated_at' => DB::raw('now()'),
        ]);
        $this->audit->record([...$this->auditBase($locked, $actor), 'action' => 'payment.confirmed', 'after' => $method === 'cash'
            ? ['method' => 'cash', 'amount' => $total, 'tendered' => $tendered, 'change' => $change, 'payOnDelivery' => true]
            : ['method' => $method, 'amount' => $total, 'manualConfirmation' => true, 'payOnDelivery' => true]], $context);
        OrderEngine::notify(self::topics($locked));

        return ['orderNumber' => $locked->order_number, 'change' => $change, 'alreadyPaid' => false];
    }

    /**
     * Harga permintaan di luar menu (keputusan owner 17 November 2026: "kasir isi harga dulu"):
     * request masuk sebagai baris tanpa harga; kasir mengisi harganya atau menandai tidak tersedia
     * (Rp0), baru pembayaran bisa diterima. Total pesanan dan tagihan yang menunggu dibayar ikut
     * berubah; batas bayar dihitung ulang dari sekarang agar pelanggan sempat membayar total final.
     * Bisa dikoreksi selama belum lunas. Izin sama dengan menerima pembayaran.
     *
     * @return array{status: string, price: int, total: int}
     */
    public function priceCustomRequest(User $actor, array $raw, AuditContext $context): array
    {
        $orderId = CatalogInput::uuid($raw['orderId'] ?? null);
        $action = $raw['action'] ?? null;
        if (! in_array($action, ['price', 'decline'], true)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $price = $action === 'decline' ? 0 : Money::amount($raw['price'] ?? null, 'Harga permintaan', max: 10_000_000);
        $order = $this->loadForStaff($actor, $orderId);
        $this->assertAt($actor, 'payment.confirm_cash', $order->outlet_id);

        $result = DB::transaction(function () use ($actor, $order, $action, $price, $context) {
            $locked = DB::selectOne('select *, (expires_at is not null and expires_at <= now()) as is_expired from orders where id = ? for update', [$order->id]);
            if ($locked->custom_request === null) {
                throw new AccessException('invalid', 'Pesanan ini tidak punya permintaan di luar menu.');
            }
            if ($locked->payment_status !== 'pending' || in_array($locked->status, OrderFlow::TERMINAL, true)) {
                throw new AccessException('conflict', 'Harga permintaan hanya bisa diisi sebelum pesanan dibayar.');
            }
            if ($locked->status === 'pending_payment' && $locked->is_expired) {
                DB::select('select public.expire_pending_orders(1, ?::uuid)', [$locked->id]);

                return 'expired';
            }
            $status = $action === 'decline' ? 'declined' : 'priced';
            $total = (int) $locked->subtotal - (int) $locked->discount_total + $price;
            $ttl = (int) DB::table('outlets')->where('id', $locked->outlet_id)->value('pending_payment_ttl_minutes');
            DB::update(
                'update orders set custom_request_status = ?, custom_request_price = ?, custom_request_priced_by = ?,
                    custom_request_priced_at = clock_timestamp(), total = ?, updated_at = now(),
                    expires_at = case when expires_at is null then null else greatest(expires_at, now() + make_interval(mins => ?::int)) end
                 where id = ?',
                [$status, $price, $actor->id, $total, $ttl, $locked->id],
            );
            // Tagihan yang menunggu dibayar mengikuti total final.
            DB::table('payments')->where('order_id', $locked->id)->where('status', 'pending')->update(['amount' => $total, 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                ...$this->auditBase($locked, $actor),
                'action' => 'order.custom_request_priced',
                'before' => ['status' => $locked->custom_request_status, 'price' => $locked->custom_request_price === null ? null : (int) $locked->custom_request_price, 'total' => (int) $locked->total],
                'after' => ['status' => $status, 'price' => $price, 'total' => $total],
            ], $context);
            OrderEngine::notify(self::topics($locked));

            return ['status' => $status, 'price' => $price, 'total' => $total];
        });

        if ($result === 'expired') {
            throw new AccessException('conflict', self::EXPIRED_MESSAGE);
        }

        return $result;
    }

    /** Tunai hanya diterima saat laci outlet terbuka; tutup sesi menunggu kunci ini. QRIS tanpa laci. */
    private function cashSessionFor(string $method, ?int $tendered, int $total, string $outletId): ?object
    {
        if ($method !== 'cash') {
            return null;
        }
        if ($tendered < $total) {
            throw new AccessException('invalid', 'Uang diterima kurang dari total '.Money::rupiah($total).'.');
        }

        return CashSessions::lockOpen($outletId, 'share');
    }

    private function recordPayment(object $locked, User $actor, string $method, int $total, ?object $cashSession, ?int $tendered, ?int $change, mixed $paidAt): void
    {
        $updated = DB::table('payments')
            ->where('order_id', $locked->id)
            ->where('status', 'pending')
            ->update([
                'method' => $method,
                'status' => 'paid',
                'cash_session_id' => $cashSession?->id,
                'tendered_amount' => $method === 'cash' ? $tendered : null,
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
                'method' => $method,
                'status' => 'paid',
                'amount' => $total,
                'cash_session_id' => $cashSession?->id,
                'tendered_amount' => $method === 'cash' ? $tendered : null,
                'change_amount' => $change,
                'paid_at' => $paidAt,
                'confirmed_by' => $actor->id,
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * Nomor antrean harian per outlet. Upsert mengunci baris counter sehingga transaksi bersamaan
     * mendapat nomor berurutan dan tidak pernah sama. Dipakai saat bayar, atau saat pesanan
     * bayar-saat-diantar dibuat.
     *
     * @return array{businessDate: string, queueNumber: int, orderNumber: string}
     */
    public static function nextQueueNumber(string $outletId): array
    {
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'timezone', 'order_number_prefix']);
        $counter = DB::selectOne(
            'insert into order_number_counters (outlet_id, business_date, last_number)
             values (?, (clock_timestamp() at time zone ?)::date, 1)
             on conflict (outlet_id, business_date) do update set last_number = order_number_counters.last_number + 1
             returning business_date, last_number',
            [$outlet->id, $outlet->timezone],
        );
        $queueNumber = (int) $counter->last_number;

        return [
            'businessDate' => (string) $counter->business_date,
            'queueNumber' => $queueNumber,
            'orderNumber' => OrderFlow::orderNumber($outlet->order_number_prefix, $queueNumber),
        ];
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
            // Bayar saat diantar: boleh dibuat sebelum lunas, tetapi tidak boleh ditutup sebelum uang diterima.
            $payLater = (bool) $locked->pay_on_delivery && $locked->payment_status === 'pending';
            if ($locked->payment_status !== 'paid' && ! $payLater) {
                throw new AccessException('conflict', 'Pesanan belum dibayar.');
            }
            if ($payLater && $to === 'completed') {
                throw new AccessException('conflict', 'Terima pembayaran dulu sebelum menandai pesanan selesai.');
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
                // Bayar saat diantar: stok sudah dipotong saat masuk antrean, jadi dikembalikan.
                $reversed = $locked->pay_on_delivery
                    ? InventoryLedger::reverseSale($locked->id, $actor->id, 'Pembatalan pesanan '.($locked->order_number ?? OrderEngine::reference($locked->id)))
                    : [];
                DB::table('orders')->where('id', $locked->id)->update([
                    'status' => 'cancelled',
                    'payment_status' => 'expired',
                    'cancelled_at' => DB::raw('clock_timestamp()'),
                    'cancel_reason' => $reason,
                    'updated_at' => DB::raw('now()'),
                ]);
                DB::table('payments')->where('order_id', $locked->id)->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => DB::raw('now()')]);
                $this->audit->record([...$base, 'action' => 'order.cancelled', 'before' => ['status' => $locked->status], 'after' => ['status' => 'cancelled', 'paid' => false], 'reason' => $reason], $context);
                if ($reversed !== []) {
                    $this->audit->record([...$base, 'action' => 'inventory.sale_reversed', 'after' => ['items' => self::stockLines($reversed)]], $context);
                }
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
                // Produk jadi belum diserahkan → stok dikembalikan lewat reversal ledger.
                $reversed = InventoryLedger::reverseSale($locked->id, $actor->id, 'Pembatalan pesanan '.($locked->order_number ?? OrderEngine::reference($locked->id)));
                if ($reversed !== []) {
                    $this->audit->record([...$base, 'action' => 'inventory.sale_reversed', 'after' => ['items' => self::stockLines($reversed)]], $context);
                }
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

    /** Ringkasan movement stok untuk log aktivitas: nama, jumlah, saldo sesudah, kekurangan bahan resep. */
    public static function stockLines(array $posted): array
    {
        return array_map(fn (array $m) => [
            'item' => $m['name'], 'quantity' => Qty::toDb($m['after'] - $m['before']), 'stockAfter' => Qty::toDb($m['after']), 'unit' => $m['unit'],
            ...(($m['shortfall'] ?? 0) > 0 ? ['shortfall' => Qty::toDb($m['shortfall'])] : []),
        ], $posted);
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
