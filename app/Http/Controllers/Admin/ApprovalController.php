<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Pos\PosOutlet;
use App\Services\Orders\OrderEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Kotak persetujuan: pengajuan pembatalan pesanan lunas yang menunggu keputusan. */
class ApprovalController
{
    public function __construct(private OrderEngine $orders) {}

    public function index(Request $request): Response
    {
        [$outlets, $outlet] = PosOutlet::resolve($this->orders, $request, 'approval.view');

        return Inertia::render('admin/approvals', [
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'approvals' => $this->orders->pendingApprovals($request->user(), $outlet),
            'topic' => OrderEngine::outletTopic($outlet['id']),
        ]);
    }
}
