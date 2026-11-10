<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Pos\PosOutlet;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Audit\AuditContext;
use App\Services\Catalog\CatalogInput;
use App\Services\Inventory\StockOpnames;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Kotak persetujuan: pembatalan pesanan lunas, koreksi absen, dan stock opname. */
class ApprovalController
{
    public function __construct(private OrderEngine $orders, private OrderWorkflow $workflow, private AttendanceEngine $attendance, private StockOpnames $opnames) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'approval.view');

        return Inertia::render('admin/approvals', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'approvals' => $this->orders->pendingApprovals($request->user(), $outlet),
            'corrections' => $this->attendance->pendingCorrections($request->user(), $outlet),
            'opnames' => $this->opnames->pending($request->user(), $outlet),
            'topic' => OrderEngine::outletTopic($outlet['id']),
        ]);
    }

    /** Keputusan sesuai jenis pengajuan; izin, outlet, dan pemohon dicek di service. */
    public function decide(Request $request, string $approval): RedirectResponse
    {
        $type = CatalogInput::isUuid($approval)
            ? DB::table('approvals')->where('id', strtolower($approval))->where('company_id', $request->user()->company_id)->value('type')
            : null;
        $raw = ['approvalId' => $approval, 'approve' => $request->input('approve'), 'note' => $request->input('note')];
        $context = AuditContext::fromRequest($request);
        if ($type === 'stock_opname') {
            $result = $this->opnames->decide($request->user(), $raw, $context);
            Inertia::flash('success', $result['approved'] ? 'Stock opname disetujui, stok diperbarui.' : 'Stock opname ditolak.');
        } elseif ($type === 'attendance_correction') {
            $result = $this->attendance->decideCorrection($request->user(), $raw, $context);
            Inertia::flash('success', $result['approved'] ? 'Koreksi absen disetujui.' : 'Koreksi absen ditolak.');
        } else {
            // Pembatalan (atau id tak dikenal: service menolak dengan pesan yang sama seperti di kasir).
            $result = $this->workflow->decideCancellation($request->user(), $raw, $context);
            Inertia::flash('success', $result['approved'] ? 'Pembatalan disetujui.' : 'Pembatalan ditolak.');
        }

        return back();
    }
}
