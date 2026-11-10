<?php

namespace App\Services\Purchasing;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogInput;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\Qty;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Supplier & purchase order (PRD §26–27, Fase 2): Purchase Request → Approval → Purchase →
 * Receive Goods → Stock Update. Izin yang sudah ada: kelola supplier/PO dan terima barang =
 * inventory.receive (Manager, Admin); lihat = inventory.view; keputusan = approval.approve
 * (Owner/Manager, bukan pemohon). Penerimaan memakai ledger stok yang sama dengan Stok masuk.
 */
final class Purchasing
{
    public const STATUS_LABELS = [
        'submitted' => 'Menunggu persetujuan', 'approved' => 'Disetujui, siap diterima', 'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan', 'received' => 'Diterima', 'draft' => 'Draf',
    ];

    public function __construct(private AuditLogger $audit, private AccessLoader $access) {}

    // --- Supplier (level company) ---------------------------------------------------------

    public function canManage(User $actor): bool
    {
        return $actor->can('inventory.receive', AccessScope::anyOutlet());
    }

    public function suppliers(User $actor): array
    {
        if (! $actor->can('inventory.view', AccessScope::anyOutlet())) {
            throw AccessException::forbidden();
        }
        $stats = DB::table('purchase_orders')->where('company_id', $actor->company_id)
            ->groupBy('supplier_id')
            ->selectRaw("supplier_id, count(*) as orders, coalesce(sum(total) filter (where status = 'received'), 0) as received_total, max(created_at) as last_at")
            ->get()->keyBy('supplier_id');

        return DB::table('suppliers')->where('company_id', $actor->company_id)
            ->orderByDesc('is_active')->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'contactName' => $s->contact_name, 'phone' => $s->phone, 'email' => $s->email,
                'products' => $s->products, 'paymentTerm' => $s->payment_term, 'notes' => $s->notes, 'isActive' => (bool) $s->is_active,
                'orders' => (int) ($stats[$s->id]->orders ?? 0),
                'receivedTotal' => (int) ($stats[$s->id]->received_total ?? 0),
                'lastOrderAt' => AttendanceEngine::iso($stats[$s->id]->last_at ?? null),
            ])->all();
    }

    /** Tambah (id null) atau ubah supplier. */
    public function saveSupplier(User $actor, ?string $supplierId, array $raw, AuditContext $context): string
    {
        if (! $this->canManage($actor)) {
            throw AccessException::forbidden();
        }
        $text = function (mixed $v, int $max, string $label, int $min = 0): ?string {
            $v = is_string($v) ? trim($v) : '';
            if ($v === '' && $min === 0) {
                return null;
            }
            if (mb_strlen($v) < max(1, $min) || mb_strlen($v) > $max) {
                throw new AccessException('invalid', $min > 0 ? "{$label} {$min}–{$max} karakter." : "{$label} maksimal {$max} karakter.");
            }

            return $v;
        };
        $email = $text($raw['email'] ?? null, 120, 'Email');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new AccessException('invalid', 'Format email supplier tidak valid.');
        }
        $phone = $text($raw['phone'] ?? null, 30, 'Telepon');
        if ($phone !== null && ! preg_match('/^[0-9+\-\s()]{6,30}$/', $phone)) {
            throw new AccessException('invalid', 'Nomor telepon hanya angka, spasi, +, -, atau tanda kurung.');
        }
        $values = [
            'name' => $text($raw['name'] ?? null, 120, 'Nama supplier', 2),
            'contact_name' => $text($raw['contactName'] ?? null, 80, 'Nama kontak'),
            'phone' => $phone,
            'email' => $email,
            'products' => $text($raw['products'] ?? null, 300, 'Produk yang dipasok'),
            'payment_term' => $text($raw['paymentTerm'] ?? null, 60, 'Termin pembayaran'),
            'notes' => $text($raw['notes'] ?? null, 500, 'Catatan'),
            'is_active' => CatalogInput::boolean($raw['isActive'] ?? true),
        ];
        $duplicate = DB::table('suppliers')->where('company_id', $actor->company_id)->whereRaw('lower(name) = lower(?)', [$values['name']])
            ->when($supplierId !== null, fn ($q) => $q->where('id', '<>', $supplierId))->exists();
        if ($duplicate) {
            throw new AccessException('conflict', 'Nama supplier sudah dipakai.');
        }

        return DB::transaction(function () use ($actor, $supplierId, $values, $context) {
            if ($supplierId === null) {
                $id = DB::table('suppliers')->insertGetId([...$values, 'company_id' => $actor->company_id, 'created_by' => $actor->id]);
                $action = 'supplier.created';
            } else {
                $id = CatalogInput::uuid($supplierId);
                $updated = DB::table('suppliers')->where('id', $id)->where('company_id', $actor->company_id)->update([...$values, 'updated_at' => DB::raw('now()')]);
                if ($updated === 0) {
                    throw AccessException::notFound();
                }
                $action = 'supplier.updated';
            }
            $this->audit->record(['company_id' => $actor->company_id, 'actor_id' => $actor->id, 'action' => $action, 'entity_type' => 'supplier', 'entity_id' => $id,
                'after' => ['name' => $values['name'], 'isActive' => $values['is_active']]], $context);

            return $id;
        });
    }

    // --- Purchase order (level outlet) ----------------------------------------------------

    /** Outlet yang boleh dibuka (inventory.view). */
    public function outlets(User $actor): array
    {
        return array_values(array_filter($this->access->accessibleOutlets($actor), fn ($o) => $actor->can('inventory.view', AccessScope::outlet($o['id']))));
    }

    public function orders(User $actor, array $outlet, ?string $status): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);

        return DB::table('purchase_orders as po')
            ->join('suppliers', 'suppliers.id', '=', 'po.supplier_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'po.created_by')
            ->where('po.company_id', $actor->company_id)
            ->where('po.outlet_id', $outlet['id'])
            ->when($status !== null && isset(self::STATUS_LABELS[$status]), fn ($q) => $q->where('po.status', $status))
            ->orderByDesc('po.created_at')
            ->limit(200)
            ->get(['po.id', 'po.number', 'po.status', 'po.total', 'po.expected_date', 'po.created_at', 'po.received_at', 'suppliers.name as supplier', 'creator.full_name as creator'])
            ->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'statusLabel' => self::STATUS_LABELS[$r->status] ?? $r->status,
                'total' => (int) $r->total, 'expectedDate' => $r->expected_date, 'createdAt' => AttendanceEngine::iso($r->created_at),
                'receivedAt' => AttendanceEngine::iso($r->received_at), 'supplier' => $r->supplier, 'createdBy' => $r->creator,
            ])->all();
    }

    /** Pilihan untuk form PO: supplier aktif + item stok aktif outlet. */
    public function formOptions(User $actor, array $outlet): array
    {
        $this->assertAt($actor, 'inventory.receive', $outlet['id']);

        return [
            'suppliers' => DB::table('suppliers')->where('company_id', $actor->company_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'payment_term'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'paymentTerm' => $s->payment_term])->all(),
            'items' => DB::table('inventory_items')->where('company_id', $actor->company_id)->where('outlet_id', $outlet['id'])->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit', 'purchase_price'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'unit' => $i->unit, 'units' => Qty::compatible($i->unit), 'lastCost' => $i->purchase_price === null ? null : (float) $i->purchase_price])->all(),
        ];
    }

    /** Buat PO dan langsung ajukan ke Persetujuan. */
    public function create(User $actor, array $raw, AuditContext $context): array
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id', 'timezone'])
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'inventory.receive', $outlet->id);
        $supplier = DB::table('suppliers')->where('id', CatalogInput::uuid($raw['supplierId'] ?? null))->where('company_id', $actor->company_id)->where('is_active', true)->first(['id', 'name'])
            ?? throw new AccessException('invalid', 'Pilih supplier yang aktif.');
        $expected = $raw['expectedDate'] ?? null;
        $expected = is_string($expected) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) ? $expected : null;
        $notes = is_string($raw['notes'] ?? null) && trim($raw['notes']) !== '' ? mb_substr(trim($raw['notes']), 0, 500) : null;
        $rawLines = is_array($raw['lines'] ?? null) ? array_values($raw['lines']) : [];
        if ($rawLines === [] || count($rawLines) > 50) {
            throw new AccessException('invalid', 'Isi 1–50 baris barang.');
        }
        $lines = [];
        $total = 0.0;
        foreach ($rawLines as $line) {
            $item = DB::table('inventory_items')->where('id', CatalogInput::uuid($line['itemId'] ?? null))->where('company_id', $actor->company_id)
                ->where('outlet_id', $outlet->id)->where('is_active', true)->first(['id', 'name', 'unit'])
                ?? throw new AccessException('invalid', 'Pilih item stok yang aktif di outlet ini.');
            if (isset($lines[$item->id])) {
                throw new AccessException('invalid', "{$item->name} tercantum dua kali.");
            }
            $unit = is_string($line['unit'] ?? null) ? $line['unit'] : $item->unit;
            $milli = Qty::parse($line['quantity'] ?? null, $unit, $item->unit);
            $cost = Qty::unitCost($line['unitCost'] ?? null, $unit, $item->unit) ?? '0';
            $lines[$item->id] = ['item' => $item, 'milli' => $milli, 'cost' => $cost];
            $total += $milli / 1000 * (float) $cost;
        }

        return DB::transaction(function () use ($actor, $outlet, $supplier, $expected, $notes, $lines, $total, $context) {
            // Nomor berurutan per outlet per hari: kunci baris outlet agar dua PO bersamaan tidak bentrok.
            DB::selectOne('select id from outlets where id = ? for update', [$outlet->id]);
            $prefix = 'PO-'.CarbonImmutable::now($outlet->timezone)->format('ymd').'-';
            $next = DB::table('purchase_orders')->where('outlet_id', $outlet->id)->where('number', 'like', "{$prefix}%")->count() + 1;
            $number = $prefix.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
            $poId = DB::table('purchase_orders')->insertGetId([
                'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'supplier_id' => $supplier->id,
                'number' => $number, 'status' => 'submitted', 'expected_date' => $expected, 'notes' => $notes, 'total' => (int) round($total), 'created_by' => $actor->id,
            ]);
            foreach ($lines as $l) {
                DB::table('purchase_order_items')->insert(['purchase_order_id' => $poId, 'item_id' => $l['item']->id, 'quantity' => Qty::toDb($l['milli']), 'unit_cost' => $l['cost']]);
            }
            $approvalId = DB::table('approvals')->insertGetId([
                'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'type' => 'purchase_order',
                'reference_id' => $poId, 'reason' => mb_substr("{$number} — {$supplier->name}", 0, 500), 'requested_by' => $actor->id,
            ]);
            DB::table('purchase_orders')->where('id', $poId)->update(['approval_id' => $approvalId]);
            $this->audit->record([
                'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                'action' => 'purchase_order.submitted', 'entity_type' => 'purchase_order', 'entity_id' => $poId,
                'after' => ['number' => $number, 'supplier' => $supplier->name, 'total' => (int) round($total), 'lines' => count($lines), 'approvalId' => $approvalId],
            ], $context);

            return ['id' => $poId, 'number' => $number, 'approvalId' => $approvalId];
        });
    }

    public function get(User $actor, mixed $poId): array
    {
        $po = $this->load($actor, $poId);
        $this->assertAt($actor, 'inventory.view', $po->outlet_id);
        $lines = DB::table('purchase_order_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')
            ->where('l.purchase_order_id', $po->id)->orderBy('i.name')
            ->get(['l.item_id', 'l.quantity', 'l.unit_cost', 'l.received_quantity', 'i.name', 'i.unit', 'i.sku']);
        $approval = $po->approval_id === null ? null : DB::table('approvals')->leftJoin('users', 'users.id', '=', 'approvals.decided_by')
            ->where('approvals.id', $po->approval_id)->first(['approvals.status', 'approvals.decision_note', 'approvals.requested_by', 'users.full_name as decided_by']);

        return [
            'id' => $po->id, 'number' => $po->number, 'status' => $po->status, 'statusLabel' => self::STATUS_LABELS[$po->status] ?? $po->status,
            'outletId' => $po->outlet_id, 'supplier' => DB::table('suppliers')->where('id', $po->supplier_id)->value('name'),
            'expectedDate' => $po->expected_date, 'notes' => $po->notes, 'total' => (int) $po->total,
            'createdAt' => AttendanceEngine::iso($po->created_at), 'receivedAt' => AttendanceEngine::iso($po->received_at),
            'createdBy' => DB::table('users')->where('id', $po->created_by)->value('full_name'),
            'decidedBy' => $approval?->decided_by, 'decisionNote' => $approval?->decision_note,
            'canReceive' => $po->status === 'approved' && $actor->can('inventory.receive', AccessScope::outlet($po->outlet_id)),
            'canCancel' => $po->status === 'submitted' && $actor->can('inventory.receive', AccessScope::outlet($po->outlet_id)),
            'lines' => $lines->map(fn ($l) => [
                'itemId' => $l->item_id, 'name' => $l->name, 'sku' => $l->sku, 'unit' => $l->unit,
                'quantity' => Qty::fromDb($l->quantity), 'quantityText' => Qty::format(Qty::fromDb($l->quantity), $l->unit),
                'unitCost' => (float) $l->unit_cost, 'lineTotal' => (int) round(Qty::fromDb($l->quantity) / 1000 * (float) $l->unit_cost),
                'received' => $l->received_quantity === null ? null : Qty::format(Qty::fromDb($l->received_quantity), $l->unit),
                // Nilai input awal "diterima" dalam satuan item (mis. "2000" ml).
                'receiveDefault' => rtrim(rtrim(number_format(Qty::fromDb($l->quantity) / 1000, 3, '.', ''), '0'), '.'),
            ])->all(),
        ];
    }

    /** PO yang menunggu keputusan di satu outlet (kotak Persetujuan). */
    public function pending(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('approval.approve', $scope) || $actor->can('approval.reject', $scope);

        return DB::table('approvals')
            ->join('purchase_orders as po', 'po.id', '=', 'approvals.reference_id')
            ->join('suppliers', 'suppliers.id', '=', 'po.supplier_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'po.created_by')
            ->where('approvals.company_id', $actor->company_id)->where('approvals.outlet_id', $outlet['id'])
            ->where('approvals.type', 'purchase_order')->where('approvals.status', 'pending')
            ->orderBy('approvals.created_at')->limit(100)
            ->get(['approvals.id', 'approvals.requested_by', 'approvals.created_at', 'po.id as po_id', 'po.number', 'po.total', 'po.expected_date', 'suppliers.name as supplier', 'creator.full_name as creator'])
            ->map(fn ($r) => [
                'id' => $r->id, 'purchaseOrderId' => $r->po_id, 'number' => $r->number, 'supplier' => $r->supplier, 'total' => (int) $r->total,
                'expectedDate' => $r->expected_date, 'requestedByName' => $r->creator, 'createdAt' => AttendanceEngine::iso($r->created_at),
                'lines' => DB::table('purchase_order_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')->where('l.purchase_order_id', $r->po_id)
                    ->orderBy('i.name')->get(['i.name', 'i.unit', 'l.quantity'])->map(fn ($l) => $l->name.' '.Qty::format(Qty::fromDb($l->quantity), $l->unit))->all(),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
            ])->all();
    }

    public function decide(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = is_string($raw['note'] ?? null) && trim($raw['note']) !== '' ? mb_substr(trim($raw['note']), 0, 300) : null;
        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'purchase_order')->first()
            ?? throw AccessException::notFound();
        if (! $actor->can($approve ? 'approval.approve' : 'approval.reject', AccessScope::outlet($approval->outlet_id))) {
            throw AccessException::forbidden();
        }
        if ($approval->requested_by === $actor->id) {
            throw AccessException::forbidden('Anda tidak bisa memutuskan pengajuan Anda sendiri.');
        }

        return DB::transaction(function () use ($actor, $approval, $approve, $note, $context) {
            DB::selectOne(
                "update approvals set status = ?::approval_status, decided_by = ?, decision_note = ?, decided_at = clock_timestamp(), updated_at = now()
                 where id = ? and status = 'pending' returning id",
                [$approve ? 'approved' : 'rejected', $actor->id, $note, $approval->id],
            ) ?? throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            DB::table('purchase_orders')->where('id', $approval->reference_id)->where('status', 'submitted')
                ->update(['status' => $approve ? 'approved' : 'rejected', 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                'company_id' => $approval->company_id, 'brand_id' => $approval->brand_id, 'outlet_id' => $approval->outlet_id, 'actor_id' => $actor->id,
                'action' => $approve ? 'purchase_order.approved' : 'purchase_order.rejected', 'entity_type' => 'purchase_order',
                'entity_id' => $approval->reference_id, 'after' => ['approvalId' => $approval->id], 'reason' => $note,
            ], $context);

            return ['approved' => $approve];
        });
    }

    public function cancel(User $actor, mixed $poId, AuditContext $context): void
    {
        $po = $this->load($actor, $poId);
        $this->assertAt($actor, 'inventory.receive', $po->outlet_id);
        DB::transaction(function () use ($actor, $po, $context) {
            $updated = DB::table('purchase_orders')->where('id', $po->id)->where('status', 'submitted')->update(['status' => 'cancelled', 'updated_at' => DB::raw('now()')]);
            if ($updated === 0) {
                throw new AccessException('conflict', 'PO yang sudah diputuskan tidak bisa dibatalkan.');
            }
            DB::table('approvals')->where('id', $po->approval_id)->where('status', 'pending')->update(['status' => 'cancelled', 'decided_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('now()')]);
            $this->audit->record(['company_id' => $po->company_id, 'brand_id' => $po->brand_id, 'outlet_id' => $po->outlet_id, 'actor_id' => $actor->id,
                'action' => 'purchase_order.cancelled', 'entity_type' => 'purchase_order', 'entity_id' => $po->id, 'after' => ['number' => $po->number]], $context);
        });
    }

    /**
     * Terima barang dari PO yang disetujui: tiap baris yang diterima (> 0) dicatat sebagai stok
     * masuk "Pembelian" di ledger (idempotent per PO+item) dan harga beli item diperbarui.
     *
     * @param  array{lines?: array<string, mixed>}  $raw  lines[itemId] = jumlah diterima (satuan item)
     */
    public function receive(User $actor, mixed $poId, array $raw, AuditContext $context): array
    {
        $po = $this->load($actor, $poId);
        $this->assertAt($actor, 'inventory.receive', $po->outlet_id);
        $input = is_array($raw['lines'] ?? null) ? $raw['lines'] : [];

        return DB::transaction(function () use ($actor, $po, $input, $context) {
            $locked = DB::selectOne('select * from purchase_orders where id = ? for update', [$po->id]);
            if ($locked->status !== 'approved') {
                throw new AccessException('conflict', $locked->status === 'received' ? 'PO ini sudah diterima.' : 'PO harus disetujui sebelum barang diterima.');
            }
            $lines = DB::table('purchase_order_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')
                ->where('l.purchase_order_id', $po->id)->orderBy('l.item_id')
                ->get(['l.id', 'l.item_id', 'l.quantity', 'l.unit_cost', 'i.name', 'i.unit']);
            $received = [];
            foreach ($lines as $line) {
                $value = $input[$line->item_id] ?? null;
                $milli = $value === null || $value === '' ? Qty::fromDb($line->quantity) : Qty::parseCount($value, $line->unit);
                $movementId = null;
                if ($milli > 0) {
                    $posted = InventoryLedger::post([
                        'itemId' => $line->item_id, 'type' => 'purchase', 'quantity' => $milli, 'idempotencyKey' => "po:{$po->id}:{$line->item_id}",
                        'actorId' => $actor->id, 'unitCost' => $line->unit_cost, 'reason' => "Penerimaan {$po->number}",
                        'documentRef' => $po->number, 'referenceType' => 'purchase_order', 'referenceId' => $po->id, 'allowInactive' => true,
                    ]);
                    $movementId = $posted['movementId'];
                    if ((float) $line->unit_cost > 0) {
                        DB::table('inventory_items')->where('id', $line->item_id)->update(['purchase_price' => $line->unit_cost]);
                    }
                    $received[] = ['item' => $line->name, 'quantity' => Qty::toDb($milli), 'unit' => $line->unit, 'stockAfter' => Qty::toDb($posted['after'])];
                }
                DB::table('purchase_order_items')->where('id', $line->id)->update(['received_quantity' => Qty::toDb($milli), 'movement_id' => $movementId, 'updated_at' => DB::raw('now()')]);
            }
            DB::table('purchase_orders')->where('id', $po->id)->update(['status' => 'received', 'received_at' => DB::raw('clock_timestamp()'), 'received_by' => $actor->id, 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                'company_id' => $po->company_id, 'brand_id' => $po->brand_id, 'outlet_id' => $po->outlet_id, 'actor_id' => $actor->id,
                'action' => 'purchase_order.received', 'entity_type' => 'purchase_order', 'entity_id' => $po->id,
                'after' => ['number' => $po->number, 'items' => $received],
            ], $context);

            return ['number' => $po->number, 'lines' => count($received)];
        });
    }

    private function load(User $actor, mixed $poId): object
    {
        return DB::table('purchase_orders')->where('id', CatalogInput::uuid($poId))->where('company_id', $actor->company_id)->first()
            ?? throw AccessException::notFound();
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
