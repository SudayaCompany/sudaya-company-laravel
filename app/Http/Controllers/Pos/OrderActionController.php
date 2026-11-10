<?php

namespace App\Http\Controllers\Pos;

use App\Services\Audit\AuditContext;
use App\Services\Orders\OrderWorkflow;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Aksi kasir dan manager pada order: bayar tunai, batal, putuskan pembatalan,
 * refund, dan status produksi. Izin dan aturan diperiksa di OrderWorkflow;
 * AccessException tampil sebagai pesan error tanpa mengubah data.
 */
class OrderActionController
{
    public function __construct(private OrderWorkflow $workflow) {}

    public function pay(Request $request, string $order): RedirectResponse
    {
        $paid = $this->workflow->confirmCash($request->user(), ['orderId' => $order, 'tenderedAmount' => $request->input('tenderedAmount')], AuditContext::fromRequest($request));
        Inertia::flash('success', $paid['alreadyPaid']
            ? "Sudah lunas sebelumnya ({$paid['orderNumber']})."
            : "Pembayaran dikonfirmasi. Nomor antrean {$paid['orderNumber']}, kembalian ".Money::rupiah($paid['change']).'.');

        return back();
    }

    /** QRIS statis outlet: kasir menekan setelah dana terlihat masuk di aplikasi bank/QRIS. */
    public function confirmQris(Request $request, string $order): RedirectResponse
    {
        $paid = $this->workflow->confirmQris($request->user(), ['orderId' => $order], AuditContext::fromRequest($request));
        Inertia::flash('success', $paid['alreadyPaid'] ? "Sudah lunas sebelumnya ({$paid['orderNumber']})." : "Pembayaran QRIS dikonfirmasi. Nomor antrean {$paid['orderNumber']}.");

        return back();
    }

    public function cancel(Request $request, string $order): RedirectResponse
    {
        $result = $this->workflow->cancel($request->user(), ['orderId' => $order, 'reason' => $request->input('reason')], AuditContext::fromRequest($request));
        Inertia::flash('success', $result['result'] === 'cancelled' ? 'Pesanan dibatalkan.' : 'Pembatalan diajukan, menunggu persetujuan Manager.');

        return back();
    }

    public function decide(Request $request, string $approval): RedirectResponse
    {
        $result = $this->workflow->decideCancellation($request->user(), [
            'approvalId' => $approval,
            'approve' => $request->input('approve'),
            'note' => $request->input('note'),
        ], AuditContext::fromRequest($request));
        Inertia::flash('success', $result['approved'] ? 'Pembatalan disetujui.' : 'Pembatalan ditolak.');

        return back();
    }

    public function refund(Request $request, string $order): RedirectResponse
    {
        $result = $this->workflow->refundCash($request->user(), ['orderId' => $order], AuditContext::fromRequest($request));
        Inertia::flash('success', 'Refund tunai '.Money::rupiah($result['amount']).' tercatat.');

        return back();
    }

    public function status(Request $request, string $order): RedirectResponse
    {
        $this->workflow->updateStatus($request->user(), ['orderId' => $order, 'from' => $request->input('from'), 'to' => $request->input('to')], AuditContext::fromRequest($request));

        return back();
    }
}
