<?php

namespace App\Services\Orders;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\Storefront;
use App\Support\Access\AccessScope;
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

    private const OUTLET_COLUMNS = ['id', 'code', 'name', 'company_id', 'brand_id', 'timezone', 'order_number_prefix', 'pending_payment_ttl_minutes', 'is_active'];

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
        // Belum ada payment gateway (EXT-001): hanya tunai di kasir.
        if ($input['paymentMethod'] !== 'cash') {
            throw new AccessException('invalid', 'Pembayaran online belum tersedia. Silakan pilih tunai.');
        }

        $row = isset($outlet['id'])
            ? $this->loadOutlet('id', CatalogInput::uuid($outlet['id']))
            : $this->loadOutlet('code', strtoupper(trim($outlet['code'])));
        if ($row === null || ! $row->is_active) {
            throw new AccessException('invalid', 'Outlet tidak ditemukan atau sedang tutup.');
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
        $token = $isPos ? null : GuestTokens::create();
        $requestHash = CheckoutInput::requestHash($input);

        return DB::transaction(function () use ($channel, $row, $actor, $input, $quote, $subtotal, $token, $requestHash, $context) {
            $inserted = DB::selectOne(
                'insert into orders (company_id, brand_id, outlet_id, channel, type, status, payment_status,
                    idempotency_key, request_hash, customer_name, customer_phone, table_number, location_note,
                    delivery_address, notes, subtotal, discount_total, total, access_token_hash, expires_at, created_by)
                 values (?, ?, ?, ?::order_channel, ?::order_type, \'pending_payment\', \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?,
                    now() + make_interval(mins => ?::int), ?)
                 on conflict (outlet_id, idempotency_key) do nothing
                 returning id',
                [
                    $row->company_id, $row->brand_id, $row->id, $channel, $input['type'],
                    $input['idempotencyKey'], $requestHash, $input['customerName'], $input['customerPhone'],
                    $input['type'] === 'dine_in' ? $input['tableNumber'] : null,
                    $input['type'] === 'dine_in' ? $input['locationNote'] : null,
                    $input['type'] === 'delivery' ? $input['deliveryAddress'] : null,
                    $input['notes'], $subtotal, $subtotal, $token['hash'] ?? null,
                    $row->pending_payment_ttl_minutes, $actor?->id,
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
                'amount' => $subtotal,
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
                    'total' => $subtotal,
                    'items' => array_sum(array_column($quote['lines'], 'quantity')),
                    'paymentMethod' => $input['paymentMethod'],
                ],
            ], $context);
            // Sinyal realtime tanpa data; ikut transaksi sehingga terkirim hanya bila commit.
            DB::select("select realtime.send('{}'::jsonb, 'changed', ?, false)", [self::outletTopic($row->id)]);

            return ['orderId' => $orderId, 'reference' => self::reference($orderId), 'total' => $subtotal, 'replayed' => false, 'accessToken' => $token['token'] ?? null];
        });
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

        return [
            ...$this->publicOrder($order),
            'outletId' => $order->outlet_id,
            'customerPhone' => $order->customer_phone,
            'locationNote' => $order->location_note,
            'deliveryAddress' => $order->delivery_address,
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
            ->when($params['status'] === 'active', fn ($q) => $q->whereIn('orders.status', ['queued', 'preparing', 'ready']))
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
            ->first(['orders.*', 'outlets.name as outlet_name', 'outlets.code as outlet_code', 'outlets.timezone']);
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
            'lines' => $this->lines([$order->id])[$order->id] ?? [],
        ];
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
