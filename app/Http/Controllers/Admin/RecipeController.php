<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Inventory\Recipes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Resep/BOM per outlet: bahan per porsi menu, dipotong otomatis saat pesanan dibayar. */
class RecipeController
{
    public function __construct(private Recipes $recipes, private InventoryAdmin $inventory) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request);

        return Inertia::render('admin/inventory/recipes/index', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            'products' => $this->recipes->products($request->user(), $outlet),
        ]);
    }

    public function edit(Request $request, string $product): Response
    {
        [, $outlet] = $this->outlet($request);

        return Inertia::render('admin/inventory/recipes/edit', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            ...$this->recipes->get($request->user(), $outlet, $product),
        ]);
    }

    public function update(Request $request, string $product): RedirectResponse
    {
        $outletId = (string) $request->input('outletId');
        $count = $this->recipes->save($request->user(), ['outletId' => $outletId, 'productId' => $product, 'lines' => $request->input('lines', [])], AuditContext::fromRequest($request));
        Inertia::flash('success', $count === 0 ? 'Resep dihapus; menu ini tidak memotong bahan baku.' : "Resep tersimpan ({$count} bahan). Bahan dipotong otomatis saat pesanan dibayar.");

        return redirect('/admin/inventory/recipes?outlet='.urlencode($outletId));
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    private function outlet(Request $request): array
    {
        $outlets = $this->inventory->outlets($request->user(), 'inventory.view');
        abort_if($outlets === [], 403);
        $wanted = $request->query('outlet');

        return [$outlets, array_values(array_filter($outlets, fn ($o) => $o['id'] === $wanted))[0] ?? $outlets[0]];
    }
}
