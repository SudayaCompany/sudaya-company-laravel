<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Inventory\InventoryAdmin;
use App\Services\Inventory\StockOpnames;
use App\Support\Access\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Stock opname: hitung fisik → selisih → alasan → persetujuan (PRD §23). */
class StockOpnameController
{
    public function __construct(private StockOpnames $opnames, private InventoryAdmin $inventory) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $outlets = $this->inventory->outlets($user, 'inventory.view');
        abort_if($outlets === [], 403);
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $request->query('outlet')))[0] ?? $outlets[0];

        return Inertia::render('admin/inventory/opname/index', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'opnames' => $this->opnames->list($user, $outlet),
            'canCreate' => $user->can('inventory.opname', AccessScope::outlet($outlet['id'])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $id = $this->opnames->create($request->user(), (string) $request->input('outletId'), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Stock opname dimulai. Isi hasil hitung fisik tiap item.');

        return redirect("/admin/inventory/opname/{$id}");
    }

    public function show(Request $request, string $opname): Response
    {
        try {
            $detail = $this->opnames->get($request->user(), $opname);
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }
        $outlet = collect($this->inventory->outlets($request->user(), 'inventory.view'))->firstWhere('id', $detail['outletId']) ?? abort(404);

        return Inertia::render('admin/inventory/opname/show', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'opname' => $detail,
        ]);
    }

    public function saveCounts(Request $request, string $opname): RedirectResponse
    {
        $saved = $this->opnames->saveCounts($request->user(), $opname, $request->input('lines'), AuditContext::fromRequest($request));
        Inertia::flash('success', "{$saved} hitungan tersimpan.");

        return back();
    }

    public function submit(Request $request, string $opname): RedirectResponse
    {
        $result = $this->opnames->submit($request->user(), $opname, AuditContext::fromRequest($request));
        Inertia::flash('success', $result['status'] === 'applied'
            ? 'Opname selesai: tidak ada selisih.'
            : 'Opname diajukan. Selisih diterapkan ke stok setelah disetujui Owner atau Manager.');

        return back();
    }

    public function cancel(Request $request, string $opname): RedirectResponse
    {
        $this->opnames->cancel($request->user(), $opname, AuditContext::fromRequest($request));
        Inertia::flash('success', 'Stock opname dibatalkan.');

        return redirect('/admin/inventory/opname');
    }
}
