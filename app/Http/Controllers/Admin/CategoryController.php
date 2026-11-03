<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Support\Access\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Isi data divalidasi ulang di CatalogAdmin; actor selalu dari sesi. */
class CategoryController
{
    public function __construct(private CatalogAdmin $catalog) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $scope = $this->catalog->scope($actor);
        $company = AccessScope::company();

        return Inertia::render('admin/categories', [
            'brand' => $scope['brand'],
            'categories' => $this->catalog->listCategories($actor, $scope['brand']['id']),
            'can' => [
                'create' => $actor->can('product.create', $company),
                'edit' => $actor->can('product.edit', $company),
                'delete' => $actor->can('product.delete', $company),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->catalog->createCategory($request->user(), (string) $request->input('brandId'), (array) $request->input('values'), AuditContext::fromRequest($request));

        return $this->done('Kategori ditambahkan.');
    }

    public function update(Request $request, string $category): RedirectResponse
    {
        $this->catalog->updateCategory($request->user(), $category, (array) $request->input('values'), AuditContext::fromRequest($request));

        return $this->done('Kategori disimpan.');
    }

    public function destroy(Request $request, string $category): RedirectResponse
    {
        $this->catalog->deleteCategory($request->user(), $category, AuditContext::fromRequest($request));

        return $this->done('Kategori dihapus.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back();
    }
}
