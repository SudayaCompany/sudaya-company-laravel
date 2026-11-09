<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Http\Controllers\Pos\PosOutlet;
use App\Services\Audit\AuditContext;
use App\Services\Orders\OrderEngine;
use App\Services\Orders\OrderWorkflow;
use App\Support\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pesanan untuk Owner/Manager di area admin: hanya-baca ditambah keputusan approval
 * pembatalan. Tidak ada aksi kasir (bayar, refund, batal) di sini, jadi Owner tanpa
 * `order.create` tetap bisa memantau dan menyetujui. Izin diperiksa ulang di service.
 */
class AdminOrderController
{
    private const VIEWS = ['all' => null, 'pending' => 'pending_payment', 'active' => 'active', 'paid' => 'paid'];

    public function __construct(private OrderEngine $orders) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'order.view');
        $user = $request->user();
        $view = array_key_exists((string) $request->query('view'), self::VIEWS) ? (string) $request->query('view') : 'all';
        $q = is_string($request->query('q')) && trim($request->query('q')) !== '' ? mb_substr(trim($request->query('q')), 0, 60) : null;
        $today = CarbonImmutable::now($outlet['timezone'])->toDateString();
        $date = $this->date($request->query('date'), $today);
        $canViewApprovals = $user->can('approval.view', AccessScope::outlet($outlet['id']));

        return Inertia::render('admin/orders/index', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'view' => $view,
            'q' => $q,
            'date' => $date,
            'today' => $today,
            'list' => $this->orders->list($user, $outlet, ['status' => self::VIEWS[$view], 'q' => $q, 'date' => $date, 'page' => $request->query('page', 1)]),
            'approvals' => $canViewApprovals ? $this->orders->pendingApprovals($user, $outlet) : null,
            'canDecide' => $user->can('approval.approve', AccessScope::outlet($outlet['id'])) || $user->can('approval.reject', AccessScope::outlet($outlet['id'])),
            'topic' => OrderEngine::outletTopic($outlet['id']),
        ]);
    }

    public function show(Request $request, string $order): Response
    {
        try {
            $detail = $this->orders->detail($request->user(), $order);
        } catch (AccessException) {
            abort(404);
        }
        $user = $request->user();
        $scope = AccessScope::outlet($detail['outletId']);

        return Inertia::render('admin/orders/show', [
            'order' => $detail,
            'canDecide' => ($user->can('approval.approve', $scope) || $user->can('approval.reject', $scope))
                && ($detail['pendingApproval']['requestedBy'] ?? null) !== $user->id,
            'topic' => OrderEngine::outletTopic($detail['outletId']),
        ]);
    }

    /** Sama dengan /pos/approvals/{id}/decide; aturan (bukan pemohon sendiri, sekali putus) di OrderWorkflow. */
    public function decide(Request $request, OrderWorkflow $workflow, string $approval): RedirectResponse
    {
        $result = $workflow->decideCancellation($request->user(), [
            'approvalId' => $approval,
            'approve' => $request->input('approve'),
            'note' => $request->input('note'),
        ], AuditContext::fromRequest($request));
        Inertia::flash('success', $result['approved'] ? 'Pembatalan disetujui.' : 'Pembatalan ditolak.');

        return back();
    }

    /** Tanggal Y-m-d yang valid dan tidak di masa depan; selain itu hari ini. */
    private function date(mixed $raw, string $today): string
    {
        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            return $today;
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === null || $parsed->format('Y-m-d') !== $raw || $raw > $today) {
            return $today;
        }

        return $raw;
    }
}
