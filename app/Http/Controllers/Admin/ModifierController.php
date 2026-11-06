<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Support\Access\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Isi grup divalidasi ulang di CatalogAdmin; actor selalu dari sesi. */
class ModifierController
{
    public function __construct(private CatalogAdmin $catalog) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $scope = $this->catalog->scope($actor);
        $company = AccessScope::company();

        return Inertia::render('admin/modifiers', [
            'brand' => $scope['brand'],
            'groups' => $this->catalog->listModifierGroups($actor, $scope['brand']['id']),
            'can' => [
                'create' => $actor->can('product.create', $company),
                'edit' => $actor->can('product.edit', $company),
                'delete' => $actor->can('product.delete', $company),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->catalog->createModifierGroup($request->user(), (string) $request->input('brandId'), (array) $request->input('values'), AuditContext::fromRequest($request));

        return $this->done('Grup pilihan dibuat.');
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $this->catalog->updateModifierGroup($request->user(), $group, (array) $request->input('values'), AuditContext::fromRequest($request));

        return $this->done('Grup pilihan disimpan.');
    }

    public function destroy(Request $request, string $group): RedirectResponse
    {
        $this->catalog->deleteModifierGroup($request->user(), $group, AuditContext::fromRequest($request));

        return $this->done('Grup pilihan dihapus.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back();
    }
}
