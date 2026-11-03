<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogAdmin;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Support\Access\AccessScope;
use App\Support\Catalog\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Semua input divalidasi ulang di CatalogAdmin; actor selalu dari sesi. */
class ProductController
{
    public function __construct(private CatalogAdmin $catalog) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        $params = CatalogInput::productListParams($request->query());
        $scope = $this->catalog->scope($actor, $this->brandParam($request->query('brand')));
        $outlet = array_values(array_filter($scope['outlets'], fn ($o) => $o['id'] === $params['outlet']))[0] ?? $scope['outlets'][0] ?? null;
        $company = AccessScope::company();

        return Inertia::render('admin/products/index', [
            'brand' => $scope['brand'],
            'outlets' => $scope['outlets'],
            'outlet' => $outlet,
            'params' => $params,
            'list' => $this->catalog->listProducts($actor, $scope['brand']['id'], $outlet['id'] ?? null, $params),
            'categories' => $this->catalog->listCategories($actor, $scope['brand']['id']),
            'can' => [
                'create' => $actor->can('product.create', $company),
                'edit' => $actor->can('product.edit', $company),
                'delete' => $actor->can('product.delete', $company),
                'toggleAvailability' => $outlet !== null && $actor->can('product.edit', AccessScope::outlet($outlet['id'])),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        // Data master menu berlaku untuk semua outlet, jadi wajib company-wide.
        abort_unless($request->user()->can('product.create', AccessScope::company()), 403);
        $scope = $this->catalog->scope($request->user());

        return Inertia::render('admin/products/form', [
            'brand' => $scope['brand'],
            'product' => null,
            ...$this->formOptions($request, $scope['brand']['id']),
        ]);
    }

    public function edit(Request $request, string $product): Response
    {
        abort_unless($request->user()->can('product.edit', AccessScope::company()), 403);
        try {
            $data = $this->catalog->productForEdit($request->user(), $product);
        } catch (AccessException) {
            // Produk company lain, terhapus, atau id tidak valid: 404 tanpa bocor info.
            abort(404);
        }

        return Inertia::render('admin/products/form', [
            'brand' => ['id' => $data['brandId']],
            'product' => $data,
            ...$this->formOptions($request, $data['brandId']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $brandId = $this->catalog->scope($actor, $this->brandParam($request->input('brandId')))['brand']['id'];
        $this->catalog->createProduct($actor, $brandId, $this->formValues($request), MenuImages::read($request->file('image')), AuditContext::fromRequest($request));

        return $this->saved();
    }

    public function update(Request $request, string $product): RedirectResponse
    {
        $this->catalog->updateProduct($request->user(), $product, $this->formValues($request), [
            'image' => MenuImages::read($request->file('image')),
            'remove' => $request->input('removeImage') === '1',
        ], AuditContext::fromRequest($request));

        return $this->saved();
    }

    public function setActive(Request $request, string $product): RedirectResponse
    {
        $this->catalog->setProductActive($request->user(), ['productId' => $product, 'isActive' => $request->input('isActive')], AuditContext::fromRequest($request));

        return $this->done('Status produk diperbarui.');
    }

    public function setAvailability(Request $request, string $product): RedirectResponse
    {
        $this->catalog->setProductAvailability($request->user(), [
            'productId' => $product,
            'outletId' => $request->input('outletId'),
            'isAvailable' => $request->input('isAvailable'),
        ], AuditContext::fromRequest($request));

        return $this->done('Ketersediaan diperbarui.');
    }

    public function destroy(Request $request, string $product): RedirectResponse
    {
        $this->catalog->deleteProduct($request->user(), $product, AuditContext::fromRequest($request));

        return $this->done('Produk dihapus.');
    }

    /** Field produk diambil satu per satu dari form; field lain diabaikan. */
    private function formValues(Request $request): array
    {
        $text = fn (string $key) => is_string($value = $request->input($key)) ? $value : null;
        $groupIds = $request->input('modifierGroupIds', []);

        return [
            'name' => $text('name'),
            'slug' => $text('slug'),
            'sku' => $text('sku'),
            'categoryId' => $text('categoryId'),
            'description' => $text('description'),
            'basePrice' => $text('basePrice'),
            'isActive' => in_array($request->input('isActive'), ['on', 'true', true], true),
            'modifierGroupIds' => is_array($groupIds) ? array_values(array_filter($groupIds, 'is_string')) : [],
        ];
    }

    /** Pilihan kategori dan grup modifier untuk form produk. */
    private function formOptions(Request $request, string $brandId): array
    {
        $actor = $request->user();

        return [
            'categories' => array_map(fn ($c) => [
                'id' => $c['id'],
                'name' => $c['isActive'] ? $c['name'] : "{$c['name']} (nonaktif)",
            ], $this->catalog->listCategories($actor, $brandId)),
            'modifierGroups' => array_map(function ($g) {
                $active = array_values(array_filter($g['options'], fn ($o) => $o['isActive']));
                ['min' => $min, 'max' => $max] = Pricing::selectionLimits([...$g, 'modifiers' => $active]);
                $rule = $g['isRequired'] || $min > 0 ? 'wajib, pilih '.($min === $max ? $min : "{$min}–{$max}") : "opsional, maks {$max}";

                return ['id' => $g['id'], 'name' => $g['name'], 'summary' => count($active)." pilihan · {$rule}"];
            }, $this->catalog->listModifierGroups($actor, $brandId)),
        ];
    }

    private function brandParam(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function saved(): RedirectResponse
    {
        Inertia::flash('success', 'Produk tersimpan.');

        return redirect('/admin/products');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return back();
    }
}
