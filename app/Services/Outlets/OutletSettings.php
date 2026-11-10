<?php

namespace App\Services\Outlets;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Services\Catalog\MenuImageStorage;
use App\Services\Catalog\Storefront;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pengaturan outlet sederhana (nama, alamat, prefix antrean, batas waktu bayar, status)
 * dan daftar meja resmi. Butuh `settings.manage` di outlet tersebut. Kode outlet tidak
 * bisa diubah karena tercetak di QR.
 */
final class OutletSettings
{
    public function __construct(private AuditLogger $audit, private Storefront $storefront, private MenuImageStorage $images) {}

    /** Outlet company actor yang boleh diatur, termasuk yang nonaktif (agar bisa diaktifkan lagi). */
    public function list(User $actor): array
    {
        return DB::table('outlets')
            ->where('company_id', $actor->company_id)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_active'])
            ->filter(fn ($o) => $this->allowed($actor, $o->id))
            ->map(fn ($o) => [
                'id' => $o->id,
                'code' => $o->code,
                'name' => $o->name,
                'isActive' => (bool) $o->is_active,
                'tables' => DB::table('outlet_tables')->where('outlet_id', $o->id)->where('is_active', true)->count(),
            ])
            ->values()
            ->all();
    }

    public function get(User $actor, mixed $outletId): array
    {
        $outlet = $this->load($actor, $outletId);

        return [
            'outlet' => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'address' => $outlet->address,
                'orderNumberPrefix' => $outlet->order_number_prefix,
                'pendingPaymentTtlMinutes' => (int) $outlet->pending_payment_ttl_minutes,
                'isActive' => (bool) $outlet->is_active,
                'deliveryFee' => (int) $outlet->delivery_fee,
                'qrisImageUrl' => MenuImages::url($outlet->qris_image_path),
            ],
            'tables' => DB::table('outlet_tables')
                ->where('outlet_id', $outlet->id)
                ->orderByRaw('length(number), number')
                ->get(['id', 'number', 'is_active'])
                ->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'isActive' => (bool) $t->is_active])
                ->all(),
        ];
    }

    /** @return array{changed: list<string>} */
    public function update(User $actor, mixed $outletId, array $raw, AuditContext $context): array
    {
        $outlet = $this->load($actor, $outletId);
        $input = OutletInput::settings($raw);
        $before = [
            'name' => $outlet->name,
            'address' => $outlet->address,
            'orderNumberPrefix' => $outlet->order_number_prefix,
            'pendingPaymentTtlMinutes' => (int) $outlet->pending_payment_ttl_minutes,
            'isActive' => (bool) $outlet->is_active,
        ];
        $changed = array_keys(array_filter($input, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return ['changed' => []];
        }

        DB::transaction(function () use ($actor, $outlet, $input, $before, $changed, $context) {
            DB::table('outlets')->where('id', $outlet->id)->update([
                'name' => $input['name'],
                'address' => $input['address'],
                'order_number_prefix' => $input['orderNumberPrefix'],
                'pending_payment_ttl_minutes' => $input['pendingPaymentTtlMinutes'],
                'is_active' => $input['isActive'],
                'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->record([
                'company_id' => $outlet->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'actor_id' => $actor->id,
                'action' => 'outlet.updated',
                'entity_type' => 'outlet',
                'entity_id' => $outlet->id,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($input, array_flip($changed)),
            ], $context);
        });
        // Nama dan status outlet ada di cache menu customer.
        $this->storefront->forgetOutlet($outlet->code, $outlet->brand_id);

        return ['changed' => $changed];
    }

    /** @return array{created: list<string>, existing: list<string>} */
    public function addTables(User $actor, mixed $outletId, mixed $raw, AuditContext $context): array
    {
        $outlet = $this->load($actor, $outletId);
        $numbers = OutletInput::tableNumbers($raw);

        return DB::transaction(function () use ($actor, $outlet, $numbers, $context) {
            $created = [];
            foreach ($numbers as $number) {
                // Nomor yang sudah ada (aktif maupun nonaktif) tidak diduplikasi.
                $row = DB::selectOne(
                    'insert into outlet_tables (company_id, outlet_id, number) values (?, ?, ?) on conflict (outlet_id, number) do nothing returning number',
                    [$outlet->company_id, $outlet->id, $number],
                );
                if ($row !== null) {
                    $created[] = $row->number;
                }
            }
            if ($created !== []) {
                $this->audit->record([
                    'company_id' => $outlet->company_id,
                    'brand_id' => $outlet->brand_id,
                    'outlet_id' => $outlet->id,
                    'actor_id' => $actor->id,
                    'action' => 'outlet_table.created',
                    'entity_type' => 'outlet',
                    'entity_id' => $outlet->id,
                    'after' => ['numbers' => $created],
                ], $context);
            }

            return ['created' => $created, 'existing' => array_values(array_diff($numbers, $created))];
        });
    }

    public function setTableActive(User $actor, mixed $tableId, mixed $isActive, AuditContext $context): array
    {
        $id = CatalogInput::uuid($tableId);
        if (! is_bool($isActive)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $table = DB::table('outlet_tables')->where('id', $id)->where('company_id', $actor->company_id)->first()
            ?? throw new AccessException('not_found', 'Meja tidak ditemukan.');
        $outlet = $this->load($actor, $table->outlet_id);
        if ((bool) $table->is_active === $isActive) {
            return ['number' => $table->number, 'isActive' => $isActive];
        }

        DB::transaction(function () use ($actor, $outlet, $table, $isActive, $context) {
            DB::table('outlet_tables')->where('id', $table->id)->update(['is_active' => $isActive, 'updated_at' => DB::raw('now()')]);
            $this->audit->record([
                'company_id' => $outlet->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'actor_id' => $actor->id,
                'action' => 'outlet_table.status_changed',
                'entity_type' => 'outlet_table',
                'entity_id' => $table->id,
                'before' => ['number' => $table->number, 'isActive' => (bool) $table->is_active],
                'after' => ['number' => $table->number, 'isActive' => $isActive],
            ], $context);
        });

        return ['number' => $table->number, 'isActive' => $isActive];
    }

    /** Meja QR sah: terdaftar dan aktif di outlet itu. */
    public static function isActiveTable(string $outletId, string $number): bool
    {
        return DB::table('outlet_tables')
            ->where('outlet_id', $outletId)
            ->where('number', strtoupper($number))
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Pesanan meja kantor (permintaan owner 10 November 2026): biaya antar ke meja per pesanan
     * (Rp0–1.000.000; 0 = gratis) dan gambar QRIS statis milik outlet (publik, dipindai
     * pelanggan; pembayaran dikonfirmasi manual kasir). Gambar lama dihapus setelah diganti.
     *
     * @return array{deliveryFee: int, qris: bool}
     */
    public function updateOrdering(User $actor, mixed $outletId, array $raw, mixed $qris, AuditContext $context): array
    {
        $outlet = $this->load($actor, $outletId);
        $feeRaw = $raw['deliveryFee'] ?? '';
        $fee = $feeRaw === '' || $feeRaw === null ? 0 : Money::amount($feeRaw, 'Biaya antar', max: 1_000_000);
        $image = MenuImages::read($qris);
        $remove = CatalogInput::boolean($raw['removeQris'] ?? false);
        $newPath = $image === null ? null : MenuImages::path($actor->company_id, $outlet->brand_id, $image['ext']);
        if ($image !== null) {
            $this->images->upload($newPath, $image['bytes'], $image['contentType']);
        }
        $oldPath = $outlet->qris_image_path;
        $path = $newPath ?? ($remove ? null : $oldPath);
        try {
            DB::transaction(function () use ($actor, $outlet, $fee, $path, $oldPath, $context) {
                DB::table('outlets')->where('id', $outlet->id)->update(['delivery_fee' => $fee, 'qris_image_path' => $path, 'updated_at' => DB::raw('now()')]);
                $this->audit->record([
                    'company_id' => $outlet->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                    'action' => 'outlet.ordering_updated', 'entity_type' => 'outlet', 'entity_id' => $outlet->id,
                    'before' => ['deliveryFee' => (int) $outlet->delivery_fee, 'qris' => $oldPath !== null],
                    'after' => ['deliveryFee' => $fee, 'qris' => $path !== null, 'qrisReplaced' => $path !== $oldPath],
                ], $context);
            });
        } catch (Throwable $error) {
            if ($newPath !== null) {
                try {
                    $this->images->remove([$newPath]);
                } catch (Throwable) {
                    // Biarkan error asli yang naik.
                }
            }
            throw $error;
        }
        // Menu pelanggan di-cache: biaya antar & ketersediaan QRIS harus langsung terlihat.
        $this->storefront->forgetOutlet($outlet->code, $outlet->brand_id);
        if ($oldPath !== null && $oldPath !== $path) {
            try {
                $this->images->remove([$oldPath]);
            } catch (Throwable) {
                // Gambar yatim tidak mengganggu; pengaturan sudah tersimpan.
            }
        }

        return ['deliveryFee' => $fee, 'qris' => $path !== null];
    }

    private function load(User $actor, mixed $outletId): object
    {
        $id = CatalogInput::uuid($outletId);
        $outlet = DB::table('outlets')->where('id', $id)->where('company_id', $actor->company_id)->first()
            ?? throw new AccessException('not_found', 'Outlet tidak ditemukan.');
        if (! $this->allowed($actor, $outlet->id)) {
            throw AccessException::forbidden();
        }

        return $outlet;
    }

    private function allowed(User $actor, string $outletId): bool
    {
        return $actor->can('settings.manage', AccessScope::outlet($outletId));
    }
}
