<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Purchasing\Purchasing;
use App\Support\Access\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier dan purchase order (PRD §26–27): ajukan → setujui → terima barang → stok bertambah. */
class PurchasingController
{
    public function __construct(private Purchasing $purchasing) {}

    public function suppliers(Request $request): Response
    {
        return Inertia::render('admin/purchasing/suppliers', [
            'suppliers' => $this->purchasing->suppliers($request->user()),
            'canManage' => $this->purchasing->canManage($request->user()),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $this->purchasing->saveSupplier($request->user(), null, $request->all(), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Supplier ditambahkan.');

        return back();
    }

    public function updateSupplier(Request $request, string $supplier): RedirectResponse
    {
        $this->purchasing->saveSupplier($request->user(), $supplier, $request->all(), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Supplier diperbarui.');

        return back();
    }

    public function orders(Request $request): Response
    {
        [$outlets, $outlet] = $this->outlet($request);
        $status = is_string($request->query('status')) ? $request->query('status') : null;

        return Inertia::render('admin/purchasing/orders', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'status' => $status,
            'statuses' => Purchasing::STATUS_LABELS,
            'orders' => $this->purchasing->orders($request->user(), $outlet, $status),
            'canCreate' => $request->user()->can('inventory.receive', AccessScope::outlet($outlet['id'])),
        ]);
    }

    public function create(Request $request): Response
    {
        [, $outlet] = $this->outlet($request);

        return Inertia::render('admin/purchasing/order-form', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name']],
            ...$this->purchasing->formOptions($request->user(), $outlet),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $po = $this->purchasing->create($request->user(), $request->only(['outletId', 'supplierId', 'expectedDate', 'notes', 'lines']), AuditContext::fromRequest($request));
        Inertia::flash('success', "{$po['number']} diajukan dan menunggu persetujuan Owner atau Manager.");

        return redirect("/admin/purchase-orders/{$po['id']}");
    }

    public function show(Request $request, string $order): Response
    {
        $po = $this->purchasing->get($request->user(), $order);
        $outlet = collect($this->purchasing->outlets($request->user()))->firstWhere('id', $po['outletId']);

        return Inertia::render('admin/purchasing/order-show', [
            'order' => $po,
            'outlet' => ['id' => $po['outletId'], 'name' => $outlet['name'] ?? '', 'timezone' => $outlet['timezone'] ?? 'Asia/Jakarta'],
        ]);
    }

    public function receive(Request $request, string $order): RedirectResponse
    {
        $result = $this->purchasing->receive($request->user(), $order, $request->only(['lines']), AuditContext::fromRequest($request));
        Inertia::flash('success', "Barang {$result['number']} diterima; {$result['lines']} item masuk ke stok.");

        return back();
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $this->purchasing->cancel($request->user(), $order, AuditContext::fromRequest($request));
        Inertia::flash('success', 'Purchase order dibatalkan.');

        return back();
    }

    /** @return array{0: list<array<string, mixed>>, 1: array<string, mixed>} */
    private function outlet(Request $request): array
    {
        $outlets = $this->purchasing->outlets($request->user());
        abort_if($outlets === [], 403);

        return [$outlets, array_values(array_filter($outlets, fn ($o) => $o['id'] === $request->query('outlet')))[0] ?? $outlets[0]];
    }
}
