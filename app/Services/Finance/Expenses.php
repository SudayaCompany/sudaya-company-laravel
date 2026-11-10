<?php

namespace App\Services\Finance;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Services\Catalog\CatalogInput;
use App\Services\Catalog\MenuImages;
use App\Services\Inventory\EvidenceStorage;
use App\Support\Access\AccessScope;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pengeluaran sederhana outlet (PRD §59, §69 "Expense: Barista → Manager"): parkir, transport,
 * pembelian kecil, perawatan. Dicatat staf yang memegang kas atau mencatat pemakaian outlet
 * (izin yang sudah ada: cash.view / inventory.issue), disetujui Owner/Manager (approval.approve,
 * bukan pemohon). Tidak mengubah saldo laci kas: uang dari laci tetap dicatat lewat Kas keluar.
 */
final class Expenses
{
    public const CATEGORIES = [
        'transport' => 'Transport',
        'parkir' => 'Parkir',
        'pembelian_kecil' => 'Pembelian kecil',
        'maintenance' => 'Perbaikan & perawatan',
        'kebersihan' => 'Kebersihan',
        'lainnya' => 'Lainnya',
    ];

    public const SUBMIT = ['cash.view', 'inventory.issue'];

    public const STATUS_LABELS = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan'];

    public function __construct(private AuditLogger $audit, private EvidenceStorage $evidence, private AccessLoader $access) {}

    public function canSubmit(User $actor, string $outletId): bool
    {
        $scope = AccessScope::outlet($outletId);

        return array_any(self::SUBMIT, fn (string $p) => $actor->can($p, $scope));
    }

    public function canReview(User $actor, string $outletId): bool
    {
        return $actor->can('approval.view', AccessScope::outlet($outletId));
    }

    /** Outlet yang boleh dibuka: pengaju atau peninjau. */
    public function outlets(User $actor): array
    {
        return array_values(array_filter(
            $this->access->accessibleOutlets($actor),
            fn (array $o) => $this->canSubmit($actor, $o['id']) || $this->canReview($actor, $o['id']),
        ));
    }

    /**
     * Daftar bulan berjalan (atau bulan dipilih). Peninjau melihat semua pengeluaran outlet,
     * pengaju hanya miliknya sendiri.
     *
     * @param  array{id: string, timezone: string}  $outlet
     */
    public function list(User $actor, array $outlet, ?string $month): array
    {
        $review = $this->canReview($actor, $outlet['id']);
        if (! $review && ! $this->canSubmit($actor, $outlet['id'])) {
            throw AccessException::forbidden();
        }
        $month ??= CarbonImmutable::now($outlet['timezone'])->format('Y-m');
        $from = "{$month}-01";
        $to = CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        $rows = DB::table('expenses')
            ->join('approvals', 'approvals.id', '=', 'expenses.approval_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'expenses.created_by')
            ->leftJoin('users as decider', 'decider.id', '=', 'approvals.decided_by')
            ->where('expenses.company_id', $actor->company_id)
            ->where('expenses.outlet_id', $outlet['id'])
            ->whereBetween('expenses.expense_date', [$from, $to])
            ->when(! $review, fn ($q) => $q->where('expenses.created_by', $actor->id))
            ->orderByDesc('expenses.expense_date')->orderByDesc('expenses.created_at')
            ->limit(300)
            ->get([
                'expenses.id', 'expenses.expense_date', 'expenses.category', 'expenses.amount', 'expenses.description', 'expenses.evidence_path',
                'expenses.created_by', 'approvals.id as approval_id', 'approvals.status', 'approvals.decision_note',
                'creator.full_name as creator_name', 'decider.full_name as decider_name',
            ]);
        $sum = fn (string $status) => (int) $rows->where('status', $status)->sum('amount');
        $byCategory = [];
        foreach ($rows->where('status', 'approved') as $r) {
            $byCategory[$r->category] = ($byCategory[$r->category] ?? 0) + (int) $r->amount;
        }
        arsort($byCategory);

        return [
            'month' => $month,
            'canReview' => $review,
            'canSubmit' => $this->canSubmit($actor, $outlet['id']),
            'totals' => ['approved' => $sum('approved'), 'pending' => $sum('pending'), 'count' => $rows->count()],
            'byCategory' => array_map(fn ($k, $v) => ['category' => $k, 'label' => self::CATEGORIES[$k] ?? $k, 'amount' => $v], array_keys($byCategory), $byCategory),
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'approvalId' => $r->approval_id,
                'date' => $r->expense_date,
                'category' => $r->category,
                'categoryLabel' => self::CATEGORIES[$r->category] ?? $r->category,
                'amount' => (int) $r->amount,
                'description' => $r->description,
                'evidenceUrl' => $r->evidence_path === null ? null : "/admin/expenses/{$r->id}/evidence",
                'status' => $r->status,
                'statusLabel' => self::STATUS_LABELS[$r->status] ?? $r->status,
                'createdBy' => $r->creator_name,
                'decidedBy' => $r->decider_name,
                'decisionNote' => $r->decision_note,
                'canCancel' => $r->status === 'pending' && $r->created_by === $actor->id,
            ])->all(),
        ];
    }

    /** @return array{expenseId: string, approvalId: string} */
    public function create(User $actor, array $raw, mixed $photo, AuditContext $context): array
    {
        $outletId = CatalogInput::uuid($raw['outletId'] ?? null);
        $outlet = DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id', 'timezone'])
            ?? throw AccessException::notFound();
        if (! $this->canSubmit($actor, $outlet->id)) {
            throw AccessException::forbidden();
        }
        $category = $raw['category'] ?? null;
        if (! is_string($category) || ! isset(self::CATEGORIES[$category])) {
            throw new AccessException('invalid', 'Pilih kategori pengeluaran.');
        }
        $amount = Money::amount($raw['amount'] ?? null, 'Nominal', 1, 50_000_000, 'Nominal wajib diisi.');
        $description = is_string($raw['description'] ?? null) ? trim($raw['description']) : '';
        if (mb_strlen($description) < 3 || mb_strlen($description) > 300) {
            throw new AccessException('invalid', 'Keterangan 3–300 karakter, mis. "Parkir belanja bahan".');
        }
        $today = CarbonImmutable::now($outlet->timezone)->toDateString();
        $date = is_string($raw['expenseDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw['expenseDate']) ? $raw['expenseDate'] : null;
        if ($date === null || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw new AccessException('invalid', 'Tanggal pengeluaran wajib diisi.');
        }
        if ($date > $today || $date < CarbonImmutable::parse($today)->subDays(60)->toDateString()) {
            throw new AccessException('invalid', 'Tanggal pengeluaran maksimal 60 hari ke belakang dan tidak boleh di masa depan.');
        }

        $image = MenuImages::read($photo);
        $path = $image === null ? null : "{$actor->company_id}/{$outlet->id}/expense-".Str::uuid().".{$image['ext']}";
        if ($path !== null) {
            $this->evidence->upload($path, $image['bytes'], $image['contentType']);
        }
        try {
            return DB::transaction(function () use ($actor, $outlet, $category, $amount, $description, $date, $path, $context) {
                $expenseId = DB::table('expenses')->insertGetId([
                    'company_id' => $actor->company_id,
                    'brand_id' => $outlet->brand_id,
                    'outlet_id' => $outlet->id,
                    'expense_date' => $date,
                    'category' => $category,
                    'amount' => $amount,
                    'description' => $description,
                    'evidence_path' => $path,
                    'created_by' => $actor->id,
                ]);
                $approvalId = DB::table('approvals')->insertGetId([
                    'company_id' => $actor->company_id,
                    'brand_id' => $outlet->brand_id,
                    'outlet_id' => $outlet->id,
                    'type' => 'expense',
                    'reference_id' => $expenseId,
                    'reason' => mb_substr(self::CATEGORIES[$category].' — '.$description, 0, 500),
                    'requested_by' => $actor->id,
                ]);
                DB::table('expenses')->where('id', $expenseId)->update(['approval_id' => $approvalId]);
                $this->audit->record([
                    'company_id' => $actor->company_id, 'brand_id' => $outlet->brand_id, 'outlet_id' => $outlet->id, 'actor_id' => $actor->id,
                    'action' => 'expense.requested', 'entity_type' => 'expense', 'entity_id' => $expenseId,
                    'after' => ['date' => $date, 'category' => $category, 'amount' => $amount, 'approvalId' => $approvalId, 'evidence' => $path !== null],
                    'reason' => $description,
                ], $context);

                return ['expenseId' => $expenseId, 'approvalId' => $approvalId];
            });
        } catch (Throwable $error) {
            if ($path !== null) {
                try {
                    $this->evidence->remove([$path]);
                } catch (Throwable) {
                    // Biarkan error asli yang naik.
                }
            }
            throw $error;
        }
    }

    public function cancel(User $actor, mixed $expenseId, AuditContext $context): void
    {
        $id = CatalogInput::uuid($expenseId);
        DB::transaction(function () use ($actor, $id, $context) {
            $expense = DB::table('expenses')->where('id', $id)->where('company_id', $actor->company_id)->where('created_by', $actor->id)->first()
                ?? throw AccessException::notFound();
            $cancelled = DB::selectOne(
                "update approvals set status = 'cancelled', decided_at = clock_timestamp(), updated_at = now() where id = ? and status = 'pending' returning id",
                [$expense->approval_id],
            ) ?? throw new AccessException('conflict', 'Pengeluaran sudah diputuskan dan tidak bisa dibatalkan.');
            $this->audit->record([
                'company_id' => $expense->company_id, 'brand_id' => $expense->brand_id, 'outlet_id' => $expense->outlet_id, 'actor_id' => $actor->id,
                'action' => 'expense.cancelled', 'entity_type' => 'expense', 'entity_id' => $expense->id, 'after' => ['approvalId' => $cancelled->id],
            ], $context);
        });
    }

    /** Pengeluaran yang menunggu keputusan di satu outlet (kotak Persetujuan). */
    public function pending(User $actor, array $outlet): array
    {
        $scope = AccessScope::outlet($outlet['id']);
        if (! $actor->can('approval.view', $scope)) {
            throw AccessException::forbidden();
        }
        $mayDecide = $actor->can('approval.approve', $scope) || $actor->can('approval.reject', $scope);

        return DB::table('approvals')
            ->join('expenses', 'expenses.id', '=', 'approvals.reference_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'expenses.created_by')
            ->where('approvals.company_id', $actor->company_id)
            ->where('approvals.outlet_id', $outlet['id'])
            ->where('approvals.type', 'expense')
            ->where('approvals.status', 'pending')
            ->orderBy('approvals.created_at')
            ->limit(100)
            ->get(['approvals.id', 'approvals.requested_by', 'approvals.created_at', 'expenses.id as expense_id', 'expenses.expense_date', 'expenses.category',
                'expenses.amount', 'expenses.description', 'expenses.evidence_path', 'creator.full_name as creator_name'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'expenseId' => $r->expense_id,
                'date' => $r->expense_date,
                'categoryLabel' => self::CATEGORIES[$r->category] ?? $r->category,
                'amount' => (int) $r->amount,
                'description' => $r->description,
                'requestedByName' => $r->creator_name,
                'evidenceUrl' => $r->evidence_path === null ? null : "/admin/expenses/{$r->expense_id}/evidence",
                'createdAt' => AttendanceEngine::iso($r->created_at),
                'canDecide' => $mayDecide && $r->requested_by !== $actor->id,
            ])->all();
    }

    public function decide(User $actor, array $raw, AuditContext $context): array
    {
        $approvalId = CatalogInput::uuid($raw['approvalId'] ?? null);
        $approve = $raw['approve'] ?? null;
        if (! is_bool($approve)) {
            throw new AccessException('invalid', 'Input tidak valid.');
        }
        $note = is_string($raw['note'] ?? null) && trim($raw['note']) !== '' ? mb_substr(trim($raw['note']), 0, 300) : null;
        $approval = DB::table('approvals')->where('id', $approvalId)->where('company_id', $actor->company_id)->where('type', 'expense')->first()
            ?? throw AccessException::notFound();
        if (! $actor->can($approve ? 'approval.approve' : 'approval.reject', AccessScope::outlet($approval->outlet_id))) {
            throw AccessException::forbidden();
        }
        if ($approval->requested_by === $actor->id) {
            throw AccessException::forbidden('Anda tidak bisa memutuskan pengajuan Anda sendiri.');
        }

        return DB::transaction(function () use ($actor, $approval, $approve, $note, $context) {
            $decided = DB::selectOne(
                "update approvals set status = ?::approval_status, decided_by = ?, decision_note = ?, decided_at = clock_timestamp(), updated_at = now()
                 where id = ? and status = 'pending' returning id",
                [$approve ? 'approved' : 'rejected', $actor->id, $note, $approval->id],
            ) ?? throw new AccessException('conflict', 'Pengajuan ini sudah diputuskan.');
            $this->audit->record([
                'company_id' => $approval->company_id, 'brand_id' => $approval->brand_id, 'outlet_id' => $approval->outlet_id, 'actor_id' => $actor->id,
                'action' => $approve ? 'expense.approved' : 'expense.rejected', 'entity_type' => 'expense', 'entity_id' => $approval->reference_id,
                'after' => ['approvalId' => $decided->id], 'reason' => $note,
            ], $context);

            return ['approved' => $approve];
        });
    }

    /** @return array{bytes: string, contentType: string} */
    public function evidence(User $actor, mixed $expenseId): array
    {
        $id = CatalogInput::uuid($expenseId);
        $row = DB::table('expenses')->where('id', $id)->where('company_id', $actor->company_id)->first(['outlet_id', 'evidence_path', 'created_by']);
        if ($row === null || $row->evidence_path === null || ($row->created_by !== $actor->id && ! $this->canReview($actor, $row->outlet_id))) {
            throw AccessException::notFound();
        }
        $bytes = $this->evidence->download($row->evidence_path) ?? throw AccessException::notFound();
        $ext = strtolower(pathinfo($row->evidence_path, PATHINFO_EXTENSION));

        return ['bytes' => $bytes, 'contentType' => match ($ext) {
            'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
        }];
    }

    /** Total pengeluaran disetujui dalam rentang tanggal (untuk laporan penjualan). */
    public static function approvedTotal(string $companyId, string $outletId, string $from, string $to): int
    {
        return (int) DB::table('expenses')
            ->join('approvals', 'approvals.id', '=', 'expenses.approval_id')
            ->where('expenses.company_id', $companyId)
            ->where('expenses.outlet_id', $outletId)
            ->whereBetween('expenses.expense_date', [$from, $to])
            ->where('approvals.status', 'approved')
            ->sum('expenses.amount');
    }
}
