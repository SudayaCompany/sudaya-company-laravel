<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Finance\Expenses;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Pengeluaran outlet (PRD §59): catat + bukti, pantau status, rekap bulanan per kategori. */
class ExpenseController
{
    public function __construct(private Expenses $expenses) {}

    public function index(Request $request): Response
    {
        $outlets = $this->expenses->outlets($request->user());
        abort_if($outlets === [], 403);
        $outlet = array_values(array_filter($outlets, fn ($o) => $o['id'] === $request->query('outlet')))[0] ?? $outlets[0];
        $month = is_string($request->query('month')) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $request->query('month')) ? $request->query('month') : null;

        return Inertia::render('admin/expenses/index', [
            'outlets' => array_map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']], $outlets),
            'outlet' => ['id' => $outlet['id'], 'name' => $outlet['name'], 'timezone' => $outlet['timezone']],
            'today' => CarbonImmutable::now($outlet['timezone'])->toDateString(),
            'categories' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(Expenses::CATEGORIES), Expenses::CATEGORIES),
            'report' => $this->expenses->list($request->user(), $outlet, $month),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->expenses->create($request->user(), $request->only(['outletId', 'expenseDate', 'category', 'amount', 'description']), $request->file('photo'), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Pengeluaran tercatat dan menunggu persetujuan Manager atau Owner.');

        return back();
    }

    public function cancel(Request $request, string $expense): RedirectResponse
    {
        $this->expenses->cancel($request->user(), $expense, AuditContext::fromRequest($request));
        Inertia::flash('success', 'Pengeluaran dibatalkan.');

        return back();
    }

    public function evidence(Request $request, string $expense): SymfonyResponse
    {
        try {
            $photo = $this->expenses->evidence($request->user(), $expense);
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }

        return response($photo['bytes'], 200, [
            'Content-Type' => $photo['contentType'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
