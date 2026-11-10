<?php

namespace App\Services\Inventory;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Stock opname (PRD §23), port `lib/inventory/ops.ts`: pilih item → stok sistem (snapshot)
 * → hitung fisik → selisih → alasan → persetujuan. Tanpa selisih langsung selesai. Selisih
 * diterapkan ke saldo SAAT persetujuan, jadi penjualan setelah snapshot tidak hilang.
 */
final class StockOpnames
{
    public const STATUS = ['draft' => 'Sedang dihitung', 'submitted' => 'Menunggu persetujuan', 'applied' => 'Selesai', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];

    public function __construct(private AuditLogger $audit) {}

    /** @param array{id: string} $outlet */
    public function list(User $actor, array $outlet): array
    {
        $this->assertAt($actor, 'inventory.view', $outlet['id']);

        return DB::table('stock_opnames as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->where('o.company_id', $actor->company_id)
            ->where('o.outlet_id', $outlet['id'])
            ->orderByDesc('o.created_at')
            ->limit(30)
            ->get(['o.id', 'o.status', 'o.created_at', 'o.submitted_at', 'o.posted_at', 'u.full_name as created_by_name',
                DB::raw('(select count(*) from stock_opname_items i where i.opname_id = o.id) as items'),
                DB::raw('(select count(*) from stock_opname_items i where i.opname_id = o.id and i.actual_qty is not null) as counted'),
                DB::raw('(select count(*) from stock_opname_items i where i.opname_id = o.id and i.variance is not null and i.variance <> 0) as variances')])
            ->map(fn ($o) => [
                'id' => $o->id,
                'status' => $o->status,
                'statusLabel' => self::STATUS[$o->status] ?? $o->status,
                'createdAt' => CarbonImmutable::parse($o->created_at)->toIso8601String(),
                'createdByName' => $o->created_by_name,
                'items' => (int) $o->items,
                'counted' => (int) $o->counted,
                'variances' => (int) $o->variances,
            ])->all();
    }

    /** Snapshot stok sistem semua item aktif outlet. Satu opname terbuka per outlet. */
    public function create(User $actor, string $outletId, AuditContext $context): string
    {
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id'])
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'inventory.opname', $outlet->id);
        if (! DB::table('inventory_items')->where('outlet_id', $outlet->id)->where('is_active', true)->exists()) {
            throw new AccessException('invalid', 'Belum ada item stok aktif untuk dihitung.');
        }
        try {
            return DB::transaction(function () use ($actor, $outlet, $context) {
                $id = DB::table('stock_opnames')->insertGetId([
                    'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id,
                    'status' => 'draft', 'created_by' => $actor->id, 'snapshot_at' => DB::raw('clock_timestamp()'),
                ]);
                DB::statement(
                    'insert into stock_opname_items (opname_id, item_id, system_qty)
                     select ?, id, stock_qty from inventory_items where outlet_id = ? and is_active order by name',
                    [$id, $outlet->id],
                );
                $this->audit->record([
                    'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                    'action' => 'inventory.opname_created', 'entity_type' => 'stock_opname', 'entity_id' => $id,
                ], $context);

                return $id;
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw new AccessException('conflict', 'Masih ada stock opname yang belum selesai di outlet ini.');
            }
            throw $error;
        }
    }

    public function get(User $actor, string $opnameId): array
    {
        $opname = $this->load($actor, $opnameId);
        $this->assertAt($actor, 'inventory.view', $opname->outlet_id);
        $lines = DB::table('stock_opname_items as l')
            ->join('inventory_items as i', 'i.id', '=', 'l.item_id')
            ->where('l.opname_id', $opname->id)
            ->orderBy('i.name')
            ->get(['l.item_id', 'l.system_qty', 'l.actual_qty', 'l.variance', 'l.reason', 'i.name', 'i.sku', 'i.unit']);
        $approval = $opname->approval_id === null ? null : DB::table('approvals')->leftJoin('users', 'users.id', '=', 'approvals.decided_by')
            ->where('approvals.id', $opname->approval_id)->first(['approvals.status', 'approvals.decision_note', 'users.full_name as decided_by_name']);

        return [
            'id' => $opname->id,
            'outletId' => $opname->outlet_id,
            'status' => $opname->status,
            'statusLabel' => self::STATUS[$opname->status] ?? $opname->status,
            'snapshotAt' => CarbonImmutable::parse($opname->snapshot_at)->toIso8601String(),
            'decision' => $approval === null || $approval->status === 'pending' ? null : ['status' => $approval->status, 'note' => $approval->decision_note, 'by' => $approval->decided_by_name],
            'canCount' => $opname->status === 'draft' && $actor->can('inventory.opname', AccessScope::outlet($opname->outlet_id)),
            'lines' => $lines->map(fn ($l) => [
                'itemId' => $l->item_id,
                'name' => $l->name,
                'sku' => $l->sku,
                'unit' => $l->unit,
                'system' => Qty::format(Qty::fromDb($l->system_qty), $l->unit),
                // Angka polos (titik desimal) untuk pratinjau selisih di browser; nilai sah dihitung server.
                'systemValue' => rtrim(rtrim(Qty::toDb(Qty::fromDb($l->system_qty)), '0'), '.'),
                'actual' => $l->actual_qty === null ? null : rtrim(rtrim(Qty::toDb(Qty::fromDb($l->actual_qty)), '0'), '.'),
                'variance' => $l->variance === null ? null : Qty::format(Qty::fromDb($l->variance), $l->unit),
                'varianceMilli' => $l->variance === null ? null : Qty::fromDb($l->variance),
                'reason' => $l->reason,
            ])->all(),
        ];
    }

    /** Simpan hasil hitung (boleh sebagian). Kosong = belum dihitung. */
    public function saveCounts(User $actor, string $opnameId, mixed $lines, AuditContext $context): int
    {
        if (! is_array($lines) || $lines === []) {
            throw new AccessException('invalid', 'Tidak ada hitungan yang dikirim.');
        }

        return DB::transaction(function () use ($actor, $opnameId, $lines) {
            $opname = $this->lock($actor, $opnameId);
            $this->assertAt($actor, 'inventory.opname', $opname->outlet_id);
            if ($opname->status !== 'draft') {
                throw new AccessException('conflict', 'Opname ini sudah diajukan atau selesai.');
            }
            $current = DB::table('stock_opname_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')
                ->where('l.opname_id', $opname->id)->get(['l.item_id', 'l.system_qty', 'i.unit', 'i.name'])->keyBy('item_id');
            $saved = 0;
            foreach ($lines as $line) {
                $itemId = CatalogInput::uuid($line['itemId'] ?? null);
                $row = $current[$itemId] ?? throw new AccessException('invalid', 'Item tidak ada di opname ini.');
                $actualText = is_string($line['actual'] ?? null) ? trim($line['actual']) : (is_int($line['actual'] ?? null) ? (string) $line['actual'] : '');
                $actual = $actualText === '' ? null : Qty::parseCount($actualText, $row->unit);
                $reason = is_string($line['reason'] ?? null) && trim($line['reason']) !== '' ? mb_substr(trim($line['reason']), 0, 300) : null;
                DB::table('stock_opname_items')->where('opname_id', $opname->id)->where('item_id', $itemId)->update([
                    'actual_qty' => $actual === null ? null : Qty::toDb($actual),
                    'variance' => $actual === null ? null : Qty::toDb($actual - Qty::fromDb($row->system_qty)),
                    'reason' => $reason,
                ]);
                $saved++;
            }
            DB::table('stock_opnames')->where('id', $opname->id)->update(['updated_at' => DB::raw('now()')]);

            return $saved;
        });
    }

    /** @return array{status: string, approvalId: ?string} */
    public function submit(User $actor, string $opnameId, AuditContext $context): array
    {
        return DB::transaction(function () use ($actor, $opnameId, $context) {
            $opname = $this->lock($actor, $opnameId);
            $this->assertAt($actor, 'inventory.opname', $opname->outlet_id);
            if ($opname->status !== 'draft') {
                throw new AccessException('conflict', 'Opname ini sudah diajukan atau selesai.');
            }
            $rows = DB::table('stock_opname_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')
                ->where('l.opname_id', $opname->id)->whereNotNull('l.actual_qty')->get(['l.variance', 'l.reason', 'i.name']);
            if ($rows->isEmpty()) {
                throw new AccessException('invalid', 'Belum ada item yang dihitung.');
            }
            $withVariance = $rows->filter(fn ($r) => Qty::fromDb($r->variance) !== 0);
            $missing = $withVariance->first(fn ($r) => mb_strlen(trim((string) $r->reason)) < 3);
            if ($missing !== null) {
                throw new AccessException('invalid', "{$missing->name}: alasan selisih wajib diisi (minimal 3 karakter).");
            }
            $base = ['company_id' => $opname->company_id, 'brand_id' => $opname->brand_id, 'outlet_id' => $opname->outlet_id, 'actor_id' => $actor->id, 'entity_type' => 'stock_opname', 'entity_id' => $opname->id];
            if ($withVariance->isEmpty()) {
                DB::table('stock_opnames')->where('id', $opname->id)->update(['status' => 'applied', 'submitted_at' => DB::raw('clock_timestamp()'), 'posted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('now()')]);
                $this->audit->record([...$base, 'action' => 'inventory.opname_applied', 'after' => ['counted' => $rows->count(), 'variances' => 0]], $context);

                return ['status' => 'applied', 'approvalId' => null];
            }
            $approvalId = DB::table('approvals')->insertGetId([
                'company_id' => $opname->company_id, 'brand_id' => $opname->brand_id, 'outlet_id' => $opname->outlet_id,
                'type' => 'stock_opname', 'reference_id' => $opname->id,
                'reason' => "Stock opname: {$withVariance->count()} item selisih dari {$rows->count()} item dihitung",
                'requested_by' => $actor->id,
            ]);
            DB::table('stock_opnames')->where('id', $opname->id)->update(['status' => 'submitted', 'approval_id' => $approvalId, 'submitted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('now()')]);
            $this->audit->record([...$base, 'action' => 'inventory.opname_submitted', 'after' => ['counted' => $rows->count(), 'variances' => $withVariance->count(), 'approvalId' => $approvalId]], $context);

            return ['status' => 'submitted', 'approvalId' => $approvalId];
        });
    }

    public function cancel(User $actor, string $opnameId, AuditContext $context): void
    {
        DB::transaction(function () use ($actor, $opnameId, $context) {
            $opname = $this->lock($actor, $opnameId);
            $this->assertAt($actor, 'inventory.opname', $opname->outlet_id);
            if ($opname->status !== 'draft') {
                throw new AccessException('conflict', 'Hanya opname yang masih dihitung yang bisa dibatalkan.');
            }
            DB::table('stock_opnames')->where('id', $opname->id)->update(['status' => 'cancelled', 'updated_at' => DB::raw('now()')]);
            $this->audit->record(['company_id' => $opname->company_id, 'brand_id' => $opname->brand_id, 'outlet_id' => $opname->outlet_id, 'actor_id' => $actor->id,
                'action' => 'inventory.opname_cancelled', 'entity_type' => 'stock_opname', 'entity_id' => $opname->id], $context);
        });
    }

    /** Opname yang menunggu keputusan di satu outlet (kotak Persetujuan). */
    public function pending(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('approval.approve', $scope) || $actor->can('approval.reject', $scope);

        return DB::table('approvals as a')
            ->join('stock_opnames as o', 'o.id', '=', 'a.reference_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.requested_by')
            ->where('a.company_id', $actor->company_id)
            ->where('a.outlet_id', $outlet['id'])
            ->where('a.type', 'stock_opname')
            ->where('a.status', 'pending')
            ->orderBy('a.created_at')
            ->get(['a.id', 'a.reason', 'a.requested_by', 'a.created_at', 'o.id as opname_id', 'u.full_name as requested_by_name'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'opnameId' => $r->opname_id,
                'reason' => $r->reason,
                'requestedByName' => $r->requested_by_name,
                'createdAt' => CarbonImmutable::parse($r->created_at)->toIso8601String(),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
                'lines' => DB::table('stock_opname_items as l')->join('inventory_items as i', 'i.id', '=', 'l.item_id')
                    ->where('l.opname_id', $r->opname_id)->whereNotNull('l.variance')->where('l.variance', '<>', 0)->orderBy('i.name')
                    ->get(['i.name', 'i.unit', 'l.variance', 'l.reason'])
                    ->map(fn ($l) => ['name' => $l->name, 'variance' => Qty::format(Qty::fromDb($l->variance), $l->unit), 'reason' => $l->reason])->all(),
            ])->all();
    }

    /** Owner/Manager memutuskan. Disetujui → movement `opname` sebesar selisih tiap item. */
    public function decide(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = is_string($raw['note'] ?? null) && trim($raw['note']) !== '' ? mb_substr(trim($raw['note']), 0, 300) : null;
        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'stock_opname')->first()
            ?? throw AccessException::notFound();
        if (! $actor->can($approve ? 'approval.approve' : 'approval.reject', AccessScope::outlet($approval->outlet_id))) {
            throw AccessException::forbidden();
        }
        if ($approval->requested_by === $actor->id) {
            throw AccessException::forbidden('Anda tidak bisa memutuskan pengajuan Anda sendiri.');
        }

        return DB::transaction(function () use ($actor, $approval, $approve, $note, $context) {
            $decided = DB::selectOne(
                "update approvals set status = ?::approval_status, decided_by = ?, decision_note = ?, decided_at = clock_timestamp(), updated_at = now()
                 where id = ? and status = 'pending' returning id",
                [$approve ? 'approved' : 'rejected', $actor->id, $note, $approval->id],
            ) ?? throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            $opname = DB::selectOne('select * from stock_opnames where id = ? for update', [$approval->reference_id]);
            $base = ['company_id' => $opname->company_id, 'brand_id' => $opname->brand_id, 'outlet_id' => $opname->outlet_id, 'actor_id' => $actor->id, 'entity_type' => 'stock_opname', 'entity_id' => $opname->id, 'reason' => $note];
            if (! $approve) {
                DB::table('stock_opnames')->where('id', $opname->id)->update(['status' => 'rejected', 'updated_at' => DB::raw('now()')]);
                $this->audit->record([...$base, 'action' => 'inventory.opname_rejected', 'after' => ['approvalId' => $approval->id]], $context);

                return ['approved' => false, 'posted' => 0];
            }
            $lines = DB::table('stock_opname_items')->where('opname_id', $opname->id)->whereNotNull('variance')->where('variance', '<>', 0)
                ->orderBy('item_id')->get(['item_id', 'variance', 'reason']);
            foreach ($lines as $line) {
                InventoryLedger::post([
                    'itemId' => $line->item_id, 'type' => 'opname', 'quantity' => Qty::fromDb($line->variance),
                    'idempotencyKey' => "opname:{$opname->id}:{$line->item_id}", 'actorId' => $actor->id,
                    'reason' => $line->reason, 'referenceType' => 'stock_opname', 'referenceId' => $opname->id, 'allowInactive' => true,
                ]);
            }
            DB::table('stock_opnames')->where('id', $opname->id)->update(['status' => 'applied', 'posted_at' => DB::raw('clock_timestamp()'), 'updated_at' => DB::raw('now()')]);
            $this->audit->record([...$base, 'action' => 'inventory.opname_applied', 'after' => ['approvalId' => $approval->id, 'variances' => $lines->count()]], $context);

            return ['approved' => true, 'posted' => $lines->count()];
        });
    }

    private function load(User $actor, string $opnameId): object
    {
        if (! CatalogInput::isUuid($opnameId)) {
            throw AccessException::notFound();
        }

        return DB::table('stock_opnames')->where('id', strtolower($opnameId))->where('company_id', $actor->company_id)->first() ?? throw AccessException::notFound();
    }

    private function lock(User $actor, string $opnameId): object
    {
        $opname = $this->load($actor, $opnameId);

        return DB::selectOne('select * from stock_opnames where id = ? for update', [$opname->id]);
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
