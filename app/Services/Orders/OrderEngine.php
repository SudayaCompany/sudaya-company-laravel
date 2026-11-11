<?php

namespace App\Services\Orders;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Services\Catalog\Storefront;
use App\Services\Inventory\InventoryLedger;
use App\Services\Outlets\OutletSettings;
use App\Support\Access\AccessScope;
use App\Support\Orders\OrderFlow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unified Order Engine (PRD §105), padanan lib/orders/engine.ts. Semua channel
 * (POS, QR, web, kiosk) membuat order lewat create().
 *
 * - Harga selalu dihitung ulang dari DB.
 * - Pembuatan order atomik dalam satu transaksi; duplikat dicegah unique index
 *   (outlet_id, idempotency_key).
 * - Order baru selalu pending_payment. Konfirmasi pembayaran, nomor antrean, dan
 *   status produksi ada di modul pembayaran dan KDS (M4), bukan di sini.
 * - Order belum dibayar kedaluwarsa (expires_at = now() DB + TTL outlet);
 *   pembatalan massal oleh pg_cron lewat public.expire_pending_orders.
 */
final class OrderEngine
{
    public const CONFLICT_MESSAGE = 'Pesanan ini sudah tercatat dengan data berbeda, jadi perubahan tidak disimpan. Cek daftar pesanan atau tanyakan ke kasir dulu. Kirim lagi hanya jika memang perlu pesanan baru.';

    public const ORDER_PAGE_SIZE = 25;

    private const OUTLET_COLUMNS = ['id', 'code', 'name', 'company_id', 'brand_id', 'timezone', 'order_number_prefix', 'pending_payment_ttl_minutes', 'is_active', 'qris_image_path'];

    public function __construct(
        private AuditLogger $audit,
        private AccessLoader $access,
        private Storefront $storefront,
    ) {}

    /** Kode referensi pendek untuk dicari kasir sebelum nomor antrean ada. */
    public static function reference(string $orderId): string
    {
        return strtoupper(substr(str_replace('-', '', $orderId), 0, 6));
    }

    public static function outletTopic(string $outletId): string
    {
        return "outlet-orders:{$outletId}";
    }

    /** Sinyal realtime tanpa data; dipanggil di dalam transaksi sehingga terkirim hanya bila commit. */
    public static function notify(array $topics): void
    {
        foreach ($topics as $topic) {
            DB::select("select realtime.send('{}'::jsonb, 'changed', ?, false)", [$topic]);
        }
    }

    /**
     * Outlet yang boleh diakses actor untuk permission tertentu.
     *
     * @return list<array<string, mixed>>
     */
    public function outletsWithPermission(User $actor, string $permission): array
    {
        return array_values(array_filter(
            $this->access->accessibleOutlets($actor),
            fn (array $o) => $actor->can($permission, AccessScope::outlet($o['id'])),
        ));
    }

    // --- Membuat order -------------------------------------------------------------

    /**
     * @param  array{code: string}|array{id: string}  $outlet
     * @return array{orderId: string, reference: string, total: int, replayed: bool, accessToken: ?string}
     */
    public function create(string $channel, array $outlet, ?User $actor, mixed $raw, AuditContext $context): array
    {
        $input = CheckoutInput::parse($raw);
        $isPos = $channel === 'pos';

        if ($isPos && $actor === null) {
            throw new AccessException('unauthenticated', 'Sesi berakhir. Silakan login ulang.');
        }
        if (! $isPos && ! in_array($channel, CheckoutInput::PUBLIC_CHANNELS, true)) {
            throw new AccessException('invalid', 'Channel tidak valid.');
        }
        if (! $isPos && ($nameError = CheckoutInput::requireCustomerName($input)) !== null) {
            throw new AccessException('invalid', $nameError);
        }
        $row = isset($outlet['id'])
            ? $this->loadOutlet('id', CatalogInput::uuid($outlet['id']))
            : $this->loadOutlet('code', strtoupper(trim($outlet['code'])));
        if ($row === null || ! $row->is_active) {
            throw new AccessException('invalid', 'Outlet tidak ditemukan atau sedang tutup.');
        }
        // Belum ada payment gateway (EXT-001): tunai di kasir, atau QRIS statis milik outlet yang
        // gambarnya diunggah admin — pembayaran QRIS dikonfirmasi manual oleh kasir.
        if ($input['paymentMethod'] === 'qris' && $row->qris_image_path === null) {
            throw new AccessException('invalid', 'QRIS belum tersedia di outlet ini. Silakan pilih tunai.');
        }
        if ($isPos) {
            if ($row->company_id !== $actor->company_id) {
                throw new AccessException('not_found', 'Pesanan tidak ditemukan.');
            }
            if (! $actor->can('order.create', AccessScope::outlet($row->id))) {
                throw AccessException::forbidden();
            }
        }

        // PRD §100: validasi ulang produk, ketersediaan, dan modifier dari DB.
        $menu = $this->storefront->load($row->code, withStock: true);
        if ($menu === null) {
            throw new AccessException('invalid', 'Outlet tidak ditemukan atau sedang tutup.');
        }
        $quote = Storefront::quote($menu, array_map(fn ($l) => array_diff_key($l, ['notes' => 1]), $input['lines']));
        foreach ($quote['lines'] as $line) {
            if (! $line['ok']) {
                throw new AccessException('invalid', $line['name'] !== null ? "{$line['name']}: {$line['error']}" : $line['error']);
            }
        }
        $subtotal = $quote['total'];
        $total = $subtotal;
        // Bayar saat diantar (keputusan owner 11 November 2026): pesanan QR meja yang diantar dan dibayar
        // tunai langsung masuk antrean barista; barista menerima uang di meja lalu menandai lunas.
        $payOnDelivery = $channel === 'qr_table' && $input['type'] === 'dine_in' && $input['paymentMethod'] === 'cash';
        $token = $isPos ? null : GuestTokens::create();
        $requestHash = CheckoutInput::requestHash($input);

        return DB::transaction(function () use ($channel, $row, $actor, $input, $quote, $subtotal, $total, $payOnDelivery, $token, $requestHash, $context) {
            $inserted = DB::selectOne(
                'insert into orders (company_id, brand_id, outlet_id, channel, type, status, payment_status,
                    idempotency_key, request_hash, customer_name, customer_phone, table_number, location_note,
                    delivery_address, notes, subtotal, discount_total, total, pay_on_delivery, access_token_hash, expires_at, created_by)
                 values (?, ?, ?, ?::order_channel, ?::order_type, \'pending_payment\', \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?::boolean, ?,
                    case when ?::boolean then null else now() + make_interval(mins => ?::int) end, ?)
                 on conflict (outlet_id, idempotency_key) do nothing
                 returning id',
                [
                    $row->company_id, $row->brand_id, $row->id, $channel, $input['type'],
                    $input['idempotencyKey'], $requestHash, $input['customerName'], $input['customerPhone'],
                    $input['type'] === 'dine_in' ? $input['tableNumber'] : null,
                    $input['type'] === 'dine_in' ? $input['locationNote'] : null,
                    $input['type'] === 'delivery' ? $input['deliveryAddress'] : null,
                    $input['notes'], $subtotal, $total, $payOnDelivery, $token['hash'] ?? null,
                    $payOnDelivery, $row->pending_payment_ttl_minutes, $actor?->id,
                ],
            );

            if ($inserted === null) {
                // PRD §102: request yang sama (double click atau retry) menghasilkan order yang sama.
                $existing = DB::table('orders')
                    ->where('outlet_id', $row->id)
                    ->where('idempotency_key', $input['idempotencyKey'])
                    ->first(['id', 'total', 'channel', 'request_hash']);
                if ($existing->request_hash !== null && $existing->request_hash !== $requestHash) {
                    throw new AccessException('conflict', self::CONFLICT_MESSAGE);
                }
                $accessToken = null;
                if ($token !== null && $existing->channel !== 'pos') {
                    // Token lama tidak disimpan; terbitkan ulang untuk client yang retry.
                    DB::table('orders')->where('id', $existing->id)->update(['access_token_hash' => $token['hash']]);
                    $accessToken = $token['token'];
                }

                return ['orderId' => $existing->id, 'reference' => self::reference($existing->id), 'total' => (int) $existing->total, 'replayed' => true, 'accessToken' => $accessToken];
            }

            $orderId = $inserted->id;
            // QR meja (EXT-026): meja harus terdaftar dan aktif di outlet ini. Dicek setelah insert
            // agar retry order lama tetap dikembalikan; order baru yang gagal ikut di-rollback.
            // POS, kiosk, web, dan takeaway dari QR meja tidak terpengaruh.
            if ($channel === 'qr_table' && $input['type'] === 'dine_in') {
                if ($input['tableNumber'] === null) {
                    throw new AccessException('invalid', 'Nomor meja wajib untuk pesanan dari QR meja.');
                }
                if (! OutletSettings::isActiveTable($row->id, $input['tableNumber'])) {
                    throw new AccessException('invalid', "Meja {$input['tableNumber']} tidak terdaftar atau sedang tidak aktif. Silakan tanyakan kasir.");
                }
            }
            // Id item dibuat di sini agar item dan modifier masuk dalam satu insert masing-masing.
            $items = [];
            $modifiers = [];
            foreach ($quote['lines'] as $index => $line) {
                $itemId = (string) Str::uuid();
                $items[] = [
                    'id' => $itemId,
                    'order_id' => $orderId,
                    'product_id' => $line['productId'],
                    'product_name' => $line['name'],
                    'unit_price' => $line['basePrice'],
                    'quantity' => $line['quantity'],
                    'modifiers_total' => $line['unitPrice'] - $line['basePrice'],
                    'line_total' => $line['lineTotal'],
                    'notes' => $input['lines'][$index]['notes'],
                ];
                foreach ($line['modifiers'] as $modifier) {
                    $modifiers[] = [
                        'order_item_id' => $itemId,
                        'modifier_id' => $modifier['modifierId'],
                        'group_name' => $modifier['groupName'],
                        'modifier_name' => $modifier['name'],
                        'price_delta' => $modifier['priceDelta'],
                    ];
                }
            }
            DB::table('order_items')->insert($items);
            if ($modifiers !== []) {
                DB::table('order_item_modifiers')->insert($modifiers);
            }

            // Tagihan yang menunggu dibayar, bukan pembayaran: status pending, tanpa sesi kas.
            DB::table('payments')->insert([
                'company_id' => $row->company_id,
                'brand_id' => $row->brand_id,
                'outlet_id' => $row->id,
                'order_id' => $orderId,
                'method' => $input['paymentMethod'],
                'status' => 'pending',
                'amount' => $total,
                'created_by' => $actor?->id,
            ]);

            $this->audit->record([
                'company_id' => $row->company_id,
                'brand_id' => $row->brand_id,
                'outlet_id' => $row->id,
                'actor_id' => $actor?->id,
                'action' => 'order.created',
                'entity_type' => 'order',
                'entity_id' => $orderId,
                'after' => [
                    'channel' => $channel,
                    'type' => $input['type'],
                    'total' => $total,
                    'payOnDelivery' => $payOnDelivery,
                    'items' => array_sum(array_column($quote['lines'], 'quantity')),
                    'paymentMethod' => $input['paymentMethod'],
                ],
            ], $context);
            if ($payOnDelivery) {
                $this->enqueueForDelivery($orderId, $row, $actor, $context);
            }
            self::notify([self::outletTopic($row->id)]);

            return ['orderId' => $orderId, 'reference' => self::reference($orderId), 'total' => $total, 'replayed' => false, 'accessToken' => $token['token'] ?? null];
        });
    }

    /**
     * Bayar saat diantar: pesanan langsung masuk antrean barista sebelum dibayar. Stok produk jadi
     * dipotong sekarang (stok kurang → seluruh checkout dibatalkan, sama seperti saat bayar) dan
     * nomor antrean dibagikan. Uang dicatat kemudian oleh barista/kasir lewat OrderWorkflow.
     */
    private function enqueueForDelivery(string $orderId, object $outlet, ?User $actor, AuditContext $context): void
    {
        $sales = InventoryLedger::postSale($orderId, $outlet->id, $actor?->id);
        $queue = OrderWorkflow::nextQueueNumber($outlet->id);
        DB::table('orders')->where('id', $orderId)->update([
            'status' => 'queued',
            'business_date' => $queue['businessDate'],
            'queue_number' => $queue['queueNumber'],
            'order_number' => $queue['orderNumber'],
            'updated_at' => DB::raw('now()'),
        ]);
        $base = [
            'company_id' => $outlet->company_id,
            'brand_id' => $outlet->brand_id,
            'outlet_id' => $outlet->id,
            'actor_id' => $actor?->id,
            'entity_type' => 'order',
            'entity_id' => $orderId,
        ];
        $this->audit->record([...$base, 'action' => 'order.queued', 'before' => ['status' => 'pending_payment'], 'after' => ['status' => 'queued', 'orderNumber' => $queue['orderNumber'], 'businessDate' => $queue['businessDate'], 'payOnDelivery' => true]], $context);
        if ($sales !== []) {
            $this->audit->record([...$base, 'action' => 'inventory.sale_posted', 'after' => ['orderNumber' => $queue['orderNumber'], 'items' => OrderWorkflow::stockLines($sales)]], $context);
        }
    }

    // --- Query staf ----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function detail(User $actor, mixed $orderId): array
    {
        $id = CatalogInput::isUuid($orderId) ? strtolower($orderId) : throw new AccessException('invalid', 'ID tidak valid.');
        $order = DB::table('orders')->where('id', $id)->where('company_id', $actor->company_id)->first()
            ?? throw new AccessException('not_found', 'Pesanan tidak ditemukan.');
        if (! $actor->can('order.view', AccessScope::outlet($order->outlet_id))) {
            throw AccessException::forbidden();
        }

        $outlet = $this->loadOutlet('id', $order->outlet_id);
        $payments = DB::table('payments')
            ->leftJoin('users as confirmer', 'confirmer.id', '=', 'payments.confirmed_by')
            ->where('payments.order_id', $order->id)
            ->orderBy('payments.created_at')
            ->get(['payments.id', 'payments.method', 'payments.status', 'payments.amount', 'payments.tendered_amount', 'payments.change_amount', 'payments.paid_at', 'confirmer.full_name as confirmed_by_name']);
        $history = DB::table('audit_logs')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'audit_logs.actor_id')
            ->where('audit_logs.company_id', $actor->company_id)
            ->where('audit_logs.entity_type', 'order')
            ->where('audit_logs.entity_id', $order->id)
            ->orderBy('audit_logs.created_at')
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.created_at', 'audit_logs.reason', 'actor_user.full_name as actor_name']);
        $creator = $order->created_by === null ? null : DB::table('users')->where('id', $order->created_by)->value('full_name');
        $approval = DB::table('approvals')
            ->join('users as requester', 'requester.id', '=', 'approvals.requested_by')
            ->where('approvals.type', 'void')
            ->where('approvals.reference_id', $order->id)
            ->where('approvals.status', 'pending')
            ->first(['approvals.id', 'approvals.reason', 'approvals.requested_by', 'requester.full_name as requested_by_name', 'approvals.created_at']);

        return [
            ...$this->publicOrder($order),
            'outletId' => $order->outlet_id,
            'paidAt' => $this->iso($order->paid_at),
            'pendingApproval' => $approval === null ? null : [
                'id' => $approval->id,
                'reason' => $approval->reason,
                'requestedBy' => $approval->requested_by,
                'requestedByName' => $approval->requested_by_name,
                'createdAt' => $this->iso($approval->created_at),
            ],
            'customerPhone' => $order->customer_phone,
            'locationNote' => $order->location_note,
            'deliveryAddress' => $order->delivery_address,
            'payOnDelivery' => (bool) $order->pay_on_delivery,
            'cancelReason' => $order->cancel_reason,
            'expiresAt' => $this->iso($order->expires_at),
            'outlet' => ['id' => $outlet->id, 'name' => $outlet->name, 'timezone' => $outlet->timezone],
            'lines' => $this->lines([$order->id])[$order->id] ?? [],
            'payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'method' => $p->method,
                'status' => $p->status,
                'amount' => (int) $p->amount,
                'tenderedAmount' => $p->tendered_amount === null ? null : (int) $p->tendered_amount,
                'changeAmount' => $p->change_amount === null ? null : (int) $p->change_amount,
                'paidAt' => $this->iso($p->paid_at),
                'confirmedByName' => $p->confirmed_by_name,
            ])->all(),
            'history' => $history->map(fn ($h) => [
                'id' => $h->id,
                'action' => $h->action,
                'createdAt' => $this->iso($h->created_at),
                'reason' => $h->reason,
                'actorName' => $h->actor_name,
            ])->all(),
            'createdByName' => $creator,
        ];
    }

    /**
     * Daftar order hari ini untuk POS, per outlet dan filter tampilan.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pageCount: int}
     */
    public function list(User $actor, array $outlet, array $params): array
    {
        if (! $actor->can('order.view', AccessScope::outlet($outlet['id']))) {
            throw AccessException::forbidden();
        }
        $today = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $query = DB::table('orders')
            ->where('orders.outlet_id', $outlet['id'])
            ->where('orders.company_id', $actor->company_id)
            ->whereRaw('(orders.created_at at time zone ?)::date = ?::date', [$outlet['timezone'], $params['date'] ?? $today])
            ->when($params['status'] === 'pending_payment', fn ($q) => $q->where('orders.status', 'pending_payment'))
            ->when($params['status'] === 'active', fn ($q) => $q->whereIn('orders.status', OrderFlow::ACTIVE_QUEUE))
            // Transaksi lunas hari ini, termasuk yang kemudian di-refund (tetap tercatat).
            ->when($params['status'] === 'paid', fn ($q) => $q->whereIn('orders.payment_status', ['paid', 'refunded']))
            ->when(($params['q'] ?? null) !== null, function ($q) use ($params) {
                $pattern = '%'.addcslashes($params['q'], '\\%_').'%';
                $prefix = addcslashes(strtoupper($params['q']), '\\%_').'%';
                $q->where(fn ($w) => $w->where('orders.order_number', 'ilike', $pattern)
                    ->orWhere('orders.customer_name', 'ilike', $pattern)
                    ->orWhereRaw("upper(replace(orders.id::text, '-', '')) like ?", [$prefix]));
            });

        $total = (clone $query)->count();
        $pageCount = max(1, (int) ceil($total / self::ORDER_PAGE_SIZE));
        $page = min(max((int) ($params['page'] ?? 1), 1), $pageCount);
        $rows = $query->orderByDesc('orders.created_at')->orderByDesc('orders.id')
            ->limit(self::ORDER_PAGE_SIZE)->offset(($page - 1) * self::ORDER_PAGE_SIZE)
            ->get(['orders.id', 'orders.order_number', 'orders.channel', 'orders.type', 'orders.status', 'orders.payment_status', 'orders.customer_name', 'orders.table_number', 'orders.total', 'orders.created_at']);

        return [
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'reference' => self::reference($r->id),
                'orderNumber' => $r->order_number,
                'channel' => $r->channel,
                'type' => $r->type,
                'status' => $r->status,
                'paymentStatus' => $r->payment_status,
                'customerName' => $r->customer_name,
                'tableNumber' => $r->table_number,
                'total' => (int) $r->total,
                'createdAt' => $this->iso($r->created_at),
            ])->all(),
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
        ];
    }

    /**
     * Pengajuan pembatalan pesanan lunas yang menunggu keputusan di satu outlet, terlama
     * dulu. `canDecide` hanya untuk menampilkan tombol; keputusan diperiksa ulang di server.
     *
     * @param  array{id: string}  $outlet
     * @return list<array<string, mixed>>
     */
    public function pendingApprovals(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('approval.approve', $scope) || $actor->can('approval.reject', $scope);

        return DB::table('approvals')
            ->join('orders', 'orders.id', '=', 'approvals.reference_id')
            ->leftJoin('users', 'users.id', '=', 'approvals.requested_by')
            ->where('approvals.company_id', $actor->company_id)
            ->where('approvals.outlet_id', $outlet['id'])
            ->where('approvals.type', 'void')
            ->where('approvals.status', 'pending')
            ->where('orders.company_id', $actor->company_id)
            ->orderBy('approvals.created_at')->orderBy('approvals.id')
            ->limit(100)
            ->get([
                'approvals.id', 'approvals.reason', 'approvals.requested_by', 'approvals.created_at',
                'users.full_name as requested_by_name',
                'orders.id as order_id', 'orders.order_number', 'orders.customer_name', 'orders.total', 'orders.status as order_status', 'orders.paid_at',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'reason' => $r->reason,
                'requestedByName' => $r->requested_by_name,
                'createdAt' => $this->iso($r->created_at),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
                'order' => [
                    'id' => $r->order_id,
                    'reference' => self::reference($r->order_id),
                    'orderNumber' => $r->order_number,
                    'customerName' => $r->customer_name,
                    'total' => (int) $r->total,
                    'status' => $r->order_status,
                    'paidAt' => $this->iso($r->paid_at),
                ],
            ])->all();
    }

    // --- Guest ---------------------------------------------------------------------

    /** Order milik guest, hanya jika token cocok. Mengembalikan data publik saja. */
    public function guestOrder(mixed $orderId, ?string $token): ?array
    {
        if (! CatalogInput::isUuid($orderId) || $token === null) {
            return null;
        }
        $order = DB::table('orders')
            ->join('outlets', 'outlets.id', '=', 'orders.outlet_id')
            ->where('orders.id', strtolower($orderId))
            ->first(['orders.*', 'outlets.name as outlet_name', 'outlets.code as outlet_code', 'outlets.timezone', 'outlets.qris_image_path']);
        if ($order === null || ! GuestTokens::verify($token, $order->access_token_hash)) {
            return null;
        }
        $method = DB::table('payments')->where('order_id', $order->id)->orderBy('created_at')->value('method');

        return [
            ...$this->publicOrder($order),
            'deliveryAddress' => $order->delivery_address,
            'expiresAt' => $this->iso($order->expires_at),
            'outletName' => $order->outlet_name,
            'outletCode' => $order->outlet_code,
            'timezone' => $order->timezone,
            'paymentMethod' => $method,
            'payOnDelivery' => (bool) $order->pay_on_delivery,
            'locationNote' => $order->location_note,
            // QRIS statis outlet ditampilkan selama pesanan QRIS menunggu dibayar.
            'qrisImageUrl' => $method === 'qris' && $order->payment_status === 'pending' ? MenuImages::url($order->qris_image_path) : null,
            'lines' => $this->lines([$order->id])[$order->id] ?? [],
            // Topik realtime turunan hash token: hanya pemegang token yang tahu.
            'topic' => GuestTokens::topic($order->access_token_hash),
        ];
    }

    // --- KDS -------------------------------------------------------------------------

    /**
     * Papan KDS (PRD §38): order lunas yang belum selesai, urut FIFO waktu masuk antrean,
     * ditambah 12 order yang selesai dalam 3 jam terakhir. Order belum dibayar tidak tampil,
     * kecuali pesanan bayar-saat-diantar (masuk antrean sejak dibuat, ditandai belum bayar).
     */
    public function kdsBoard(User $actor, array $outlet): array
    {
        if (! $actor->can('kds.view', AccessScope::outlet($outlet['id']))) {
            throw AccessException::forbidden();
        }
        $columns = ['id', 'order_number', 'queue_number', 'status', 'payment_status', 'pay_on_delivery', 'total', 'type', 'channel', 'customer_name', 'customer_phone', 'table_number', 'location_note', 'notes', 'paid_at', 'created_at', 'preparing_at', 'ready_at', 'completed_at'];
        $active = DB::table('orders')
            ->where('outlet_id', $outlet['id'])
            ->where('company_id', $actor->company_id)
            ->where(fn ($q) => $q->where('payment_status', 'paid')
                ->orWhere(fn ($q) => $q->where('pay_on_delivery', true)->where('payment_status', 'pending')))
            ->whereIn('status', OrderFlow::ACTIVE_QUEUE)
            ->orderByRaw('case when pay_on_delivery then created_at else paid_at end')->orderBy('queue_number')
            ->get($columns);
        $completed = DB::table('orders')
            ->where('outlet_id', $outlet['id'])
            ->where('company_id', $actor->company_id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', DB::raw("now() - interval '3 hours'"))
            ->orderByDesc('completed_at')
            ->limit(12)
            ->get($columns);
        $rows = $active->concat($completed);
        $lines = $this->lines($rows->pluck('id')->all());

        return $rows->map(fn ($o) => [
            'id' => $o->id,
            'orderNumber' => $o->order_number,
            'queueNumber' => $o->queue_number === null ? null : (int) $o->queue_number,
            'status' => $o->status,
            'type' => $o->type,
            'channel' => $o->channel,
            'customerName' => $o->customer_name,
            'customerPhone' => $o->customer_phone,
            'tableNumber' => $o->table_number,
            'locationNote' => $o->location_note,
            'notes' => $o->notes,
            'paymentStatus' => $o->payment_status,
            'payOnDelivery' => (bool) $o->pay_on_delivery,
            'total' => (int) $o->total,
            'paidAt' => $this->iso($o->paid_at),
            // Waktu masuk antrean: saat dibayar, atau saat dibuat untuk pesanan bayar-saat-diantar.
            'queuedAt' => $this->iso($o->pay_on_delivery ? $o->created_at : $o->paid_at),
            'preparingAt' => $this->iso($o->preparing_at),
            'readyAt' => $this->iso($o->ready_at),
            'completedAt' => $this->iso($o->completed_at),
            'lines' => $lines[$o->id] ?? [],
        ])->values()->all();
    }

    // --- Bantuan -------------------------------------------------------------------

    /** Field yang aman untuk staf maupun guest pemilik order. */
    private function publicOrder(object $order): array
    {
        return [
            'id' => $order->id,
            'reference' => self::reference($order->id),
            'orderNumber' => $order->order_number,
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'type' => $order->type,
            'channel' => $order->channel,
            'tableNumber' => $order->table_number,
            'customerName' => $order->customer_name,
            'notes' => $order->notes,
            'total' => (int) $order->total,
            'createdAt' => $this->iso($order->created_at),
        ];
    }

    /** @return array<string, list<array<string, mixed>>> item per order (snapshot harga saat order dibuat) */
    private function lines(array $orderIds): array
    {
        $items = DB::table('order_items')->whereIn('order_id', $orderIds)->orderBy('id')->get();
        $mods = $items->isEmpty() ? collect() : DB::table('order_item_modifiers')->whereIn('order_item_id', $items->pluck('id'))->get()->groupBy('order_item_id');
        $map = [];
        foreach ($items as $item) {
            $map[$item->order_id][] = [
                'id' => $item->id,
                'productName' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (int) $item->unit_price,
                'modifiersTotal' => (int) $item->modifiers_total,
                'lineTotal' => (int) $item->line_total,
                'notes' => $item->notes,
                'modifiers' => ($mods[$item->id] ?? collect())->map(fn ($m) => [
                    'groupName' => $m->group_name,
                    'name' => $m->modifier_name,
                    'priceDelta' => (int) $m->price_delta,
                ])->values()->all(),
            ];
        }

        return $map;
    }

    private function loadOutlet(string $column, string $value): ?object
    {
        return DB::table('outlets')->where($column, $value)->first(self::OUTLET_COLUMNS);
    }

    private function iso(?string $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp)->toIso8601String();
    }
}
