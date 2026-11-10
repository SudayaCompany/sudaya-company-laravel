<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Outlets\OutletSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Pengaturan outlet dan daftar meja resmi. Izin dan validasi di OutletSettings. */
class OutletController
{
    public function __construct(private OutletSettings $outlets) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/outlets/index', ['outlets' => $this->outlets->list($request->user())]);
    }

    public function edit(Request $request, string $outlet): Response
    {
        return Inertia::render('admin/outlets/edit', $this->outlets->get($request->user(), $outlet));
    }

    /** Lembar QR meja siap cetak; hanya meja aktif (checkout QR menolak meja nonaktif). */
    public function qr(Request $request, string $outlet): Response
    {
        $data = $this->outlets->get($request->user(), $outlet);

        return Inertia::render('admin/outlets/qr', [
            'outlet' => ['id' => $data['outlet']['id'], 'code' => $data['outlet']['code'], 'name' => $data['outlet']['name']],
            'tables' => array_values(array_map(
                fn ($t) => ['id' => $t['id'], 'number' => $t['number']],
                array_filter($data['tables'], fn ($t) => $t['isActive']),
            )),
        ]);
    }

    public function update(Request $request, string $outlet): RedirectResponse
    {
        $result = $this->outlets->update($request->user(), $outlet, $request->only(['name', 'address', 'orderNumberPrefix', 'pendingPaymentTtlMinutes', 'isActive']), AuditContext::fromRequest($request));
        Inertia::flash('success', $result['changed'] === [] ? 'Tidak ada perubahan.' : 'Pengaturan outlet disimpan.');

        return back();
    }

    /** Biaya antar ke meja + QRIS statis outlet (multipart: foto QRIS opsional). */
    public function updateOrdering(Request $request, string $outlet): RedirectResponse
    {
        $result = $this->outlets->updateOrdering($request->user(), $outlet, [...$request->only(['deliveryFee']), 'removeQris' => $request->boolean('removeQris')], $request->file('qris'), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Pengaturan pesanan meja disimpan'.($result['qris'] ? '; QRIS aktif untuk pelanggan.' : '; QRIS belum diunggah.'));

        return back();
    }

    public function storeTables(Request $request, string $outlet): RedirectResponse
    {
        $result = $this->outlets->addTables($request->user(), $outlet, $request->input('numbers'), AuditContext::fromRequest($request));
        $message = $result['created'] === [] ? 'Semua nomor meja sudah terdaftar.' : count($result['created']).' meja ditambahkan.';
        if ($result['created'] !== [] && $result['existing'] !== []) {
            $message .= ' Sudah ada sebelumnya: '.implode(', ', $result['existing']).'.';
        }
        Inertia::flash('success', $message);

        return back();
    }

    public function updateTable(Request $request, string $table): RedirectResponse
    {
        $result = $this->outlets->setTableActive($request->user(), $table, $request->input('isActive'), AuditContext::fromRequest($request));
        Inertia::flash('success', "Meja {$result['number']} ".($result['isActive'] ? 'diaktifkan.' : 'dinonaktifkan.'));

        return back();
    }
}
