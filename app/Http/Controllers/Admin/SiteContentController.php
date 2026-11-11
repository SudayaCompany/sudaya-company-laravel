<?php

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditContext;
use App\Services\Site\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin → Konten situs: isi halaman publik (tentang, program, pelatihan, sedekah, kontak). */
class SiteContentController
{
    public function __construct(private SiteContent $site) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('admin/site', $this->site->forAdmin($request->user()));
    }

    public function update(Request $request): RedirectResponse
    {
        $this->site->update($request->user(), $request->only(['about', 'programs', 'donationUrl', 'whatsapp', 'instagram', 'email', 'trainings']), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Konten situs disimpan dan langsung tampil di halaman depan.');

        return back();
    }
}
