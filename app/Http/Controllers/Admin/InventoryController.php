<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Inventory\Qty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Stok (M5-lite): daftar & status, item master, stok masuk, stok keluar/waste, riwayat. */
class InventoryController
{
    private const ITEM_FIELDS = ['outletId', 'sku', 'name', 'kind', 'category', 'unit', 'minimum', 'purchasePrice', 'expiryDate', 'openingStock', 'isActive', 'productIds'];

    public function __construct(private InventoryAdmin $inventory) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'inventory.view');
        $user = $request->user();

        return Inertia::render('admin/inventory/index', [
            ...$this->shared($outlets, $outlet),
            ...$this->inventory->items($user, $outlet, $request->only(['q', 'kind', 'status'])),
            'can' => $this->inventory->can($user, $outlet['id']),
        ]);
    }

    public function create(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'inventory.adjust');

        return Inertia::render('admin/inventory/item-form', [
            ...$this->shared($outlets, $outlet),
            'item' => null,
            'products' => $this->inventory->productOptions($request->user(), $outlet['id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->inventory->createItem($request->user(), $request->only(self::ITEM_FIELDS), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Item stok ditambahkan.');

        return redirect('/admin/inventory?outlet='.$request->input('outletId'));
    }

    public function edit(Request $request, string $item): Response
    {
        $detail = $this->load(fn () => $this->inventory->item($request->user(), $item), $item);
        [$outlets] = $this->outlet($request, 'inventory.view');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $detail['outletId']))[0] ?? abort(404);

        return Inertia::render('admin/inventory/item-form', [
            ...$this->shared($outlets, $outlet),
            'item' => $detail,
            'products' => $this->inventory->productOptions($request->user(), $outlet['id']),
        ]);
    }

    public function update(Request $request, string $item): RedirectResponse
    {
        $detail = $this->load(fn () => $this->inventory->item($request->user(), $item), $item);
        $this->inventory->updateItem($request->user(), $detail['id'], $request->only(self::ITEM_FIELDS), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Item stok tersimpan.');

        return redirect('/admin/inventory?outlet='.$detail['outletId']);
    }

    public function stockInPage(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'inventory.receive');

        return Inertia::render('admin/inventory/stock-in', [
            ...$this->shared($outlets, $outlet),
            'items' => $this->inventory->itemOptions($request->user(), $outlet['id']),
            'types' => InventoryAdmin::IN_TYPES,
            'recent' => $this->inventory->movements($request->user(), $outlet, ['group' => 'in'])['rows'],
            'selected' => $request->query('item'),
        ]);
    }

    public function stockIn(Request $request): RedirectResponse
    {
        $result = $this->inventory->stockIn($request->user(), $request->only(['itemId', 'type', 'quantity', 'unit', 'unitCost', 'expiryDate', 'batchCode', 'documentRef', 'notes', 'idempotencyKey']), AuditContext::fromRequest($request));
        Inertia::flash('success', ($result['replayed'] ? 'Sudah tercatat sebelumnya. ' : 'Stok masuk tercatat. ')."Stok sekarang {$result['balance']}.");

        return back();
    }

    public function stockOutPage(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'inventory.issue');

        return Inertia::render('admin/inventory/stock-out', [
            ...$this->shared($outlets, $outlet),
            'items' => $this->inventory->itemOptions($request->user(), $outlet['id']),
            'types' => InventoryAdmin::OUT_TYPES,
            'recent' => $this->inventory->movements($request->user(), $outlet, ['group' => 'out'])['rows'],
            'selected' => $request->query('item'),
        ]);
    }

    public function stockOut(Request $request): RedirectResponse
    {
        $result = $this->inventory->stockOut($request->user(), $request->only(['itemId', 'type', 'quantity', 'unit', 'reason', 'idempotencyKey']), $request->file('photo'), AuditContext::fromRequest($request));
        Inertia::flash('success', ($result['replayed'] ? 'Sudah tercatat sebelumnya. ' : 'Stok keluar tercatat. ')."Stok sekarang {$result['balance']}.");

        return back();
    }

    public function movements(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request, 'inventory.view');

        return Inertia::render('admin/inventory/movements', [
            ...$this->shared($outlets, $outlet),
            ...$this->inventory->movements($request->user(), $outlet, $request->only(['item', 'group', 'page'])),
            'items' => $this->inventory->itemOptions($request->user(), $outlet['id']),
        ]);
    }

    /** Foto bukti stok keluar lewat server berizin, tanpa cache. */
    public function evidence(Request $request, string $movement): SymfonyResponse
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $movement)) {
            abort(404);
        }
        $photo = $this->load(fn () => $this->inventory->evidencePhoto($request->user(), strtolower($movement)), $movement) ?? abort(404);

        return response($photo['bytes'], 200, ['Content-Type' => $photo['contentType'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    private function outlet(Request $request, string $permission): array
    {
        $outlets = $this->inventory->outlets($request->user(), $permission);
        abort_if($outlets === [], 403);
        $requested = $request->query('outlet') ?? $request->input('outletId');
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $requested))[0] ?? $outlets[0];

        return [$outlets, $outlet];
    }

    private function shared(array $outlets, array $outlet): array
    {
        return [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'units' => array_map(fn ($u) => ['code' => $u, 'label' => Qty::UNITS[$u][0]], array_keys(Qty::UNITS)),
            'kinds' => InventoryAdmin::KINDS,
        ];
    }

    private function load(callable $read, string $id): mixed
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            abort(404);
        }
        try {
            return $read();
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }
    }
}
