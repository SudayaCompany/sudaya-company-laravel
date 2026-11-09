<?php

namespace App\Services\Attendance;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessLoader;
use App\Support\Access\AccessScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Data karyawan (PRD §7), port dari `lib/attendance/employees.ts`. Karyawan terpisah dari
 * akun login: tautkan ke akun staf agar bisa absen dari HP. Izin per outlet
 * (`employee.view|create|edit`); tidak ada hapus, hanya nonaktifkan.
 */
final class Employees
{
    public function __construct(private AccessLoader $access, private AuditLogger $audit) {}

    /** @return list<array{id: string, name: string}> */
    public function outlets(User $actor, string $permission): array
    {
        return array_values(array_map(
            fn (array $o) => ['id' => $o['id'], 'name' => $o['name']],
            array_filter($this->access->accessibleOutlets($actor), fn (array $o) => $actor->can($permission, AccessScope::outlet($o['id']))),
        ));
    }

    public function list(User $actor, array $params): array
    {
        $outlets = $this->outlets($actor, 'employee.view');
        if ($outlets === []) {
            throw AccessException::forbidden();
        }
        $ids = array_column($outlets, 'id');
        $outlet = in_array($params['outlet'] ?? null, $ids, true) ? $params['outlet'] : null;
        $status = in_array($params['status'] ?? null, ['active', 'inactive'], true) ? $params['status'] : 'active';
        $q = is_string($params['q'] ?? null) && trim($params['q']) !== '' ? mb_substr(trim($params['q']), 0, 60) : null;

        $rows = DB::table('employees')
            ->join('outlets', 'outlets.id', '=', 'employees.outlet_id')
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.company_id', $actor->company_id)
            ->whereIn('employees.outlet_id', $outlet === null ? $ids : [$outlet])
            ->where('employees.is_active', $status === 'active')
            ->when($q !== null, function ($query) use ($q) {
                $pattern = '%'.addcslashes($q, '\\%_').'%';
                $query->where(fn ($w) => $w->where('employees.full_name', 'ilike', $pattern)->orWhere('employees.employee_code', 'ilike', $pattern)->orWhere('employees.position', 'ilike', $pattern));
            })
            ->orderBy('employees.full_name')
            ->limit(500)
            ->get(['employees.id', 'employees.employee_code', 'employees.full_name', 'employees.position', 'employees.phone', 'employees.is_active',
                'employees.joined_at', 'employees.user_id', 'outlets.name as outlet_name', 'users.full_name as account_name']);

        return [
            'outlets' => $outlets,
            'params' => ['outlet' => $outlet, 'status' => $status, 'q' => $q],
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'code' => $r->employee_code,
                'fullName' => $r->full_name,
                'position' => $r->position,
                'phone' => $r->phone,
                'isActive' => (bool) $r->is_active,
                'joinedAt' => $r->joined_at,
                'outletName' => $r->outlet_name,
                'accountName' => $r->account_name,
                'linked' => $r->user_id !== null,
            ])->all(),
        ];
    }

    public function get(User $actor, string $employeeId): array
    {
        $row = DB::table('employees')->where('id', $employeeId)->where('company_id', $actor->company_id)->first()
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'employee.view', $row->outlet_id);

        return [
            'id' => $row->id,
            'code' => $row->employee_code,
            'fullName' => $row->full_name,
            'phone' => $row->phone,
            'position' => $row->position,
            'outletId' => $row->outlet_id,
            'joinedAt' => $row->joined_at,
            'userId' => $row->user_id,
            'isActive' => (bool) $row->is_active,
        ];
    }

    /**
     * Pilihan form: outlet yang boleh dipakai + akun staf aktif yang belum ditautkan.
     *
     * @return array{outlets: list<array{id: string, name: string}>, accounts: list<array{id: string, fullName: string, email: string|null}>}
     */
    public function formOptions(User $actor, string $permission, ?string $currentUserId = null): array
    {
        $outlets = $this->outlets($actor, $permission);
        if ($outlets === []) {
            throw AccessException::forbidden();
        }
        $accounts = DB::table('users')
            ->leftJoin('auth.users as auth_users', 'auth_users.id', '=', 'users.id')
            ->where('users.company_id', $actor->company_id)
            ->where('users.is_active', true)
            ->where(fn ($q) => $q->whereNotIn('users.id', DB::table('employees')->whereNotNull('user_id')->select('user_id'))
                ->when($currentUserId !== null, fn ($w) => $w->orWhere('users.id', $currentUserId)))
            ->orderBy('users.full_name')
            ->get(['users.id', 'users.full_name', 'auth_users.email']);

        return [
            'outlets' => $outlets,
            'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'fullName' => $a->full_name, 'email' => $a->email])->all(),
        ];
    }

    public function create(User $actor, array $raw, AuditContext $context): string
    {
        $input = AttendanceInput::employee($raw, true);
        $outlet = $this->outlet($actor, $input['outletId']);
        $this->assertAt($actor, 'employee.create', $outlet->id);
        $this->assertLinkable($actor, $input['userId']);

        return $this->unique(fn () => DB::transaction(function () use ($actor, $input, $outlet, $context) {
            $id = DB::table('employees')->insertGetId([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'user_id' => $input['userId'],
                'employee_code' => $input['employeeCode'],
                'full_name' => $input['fullName'],
                'phone' => $input['phone'],
                'position' => $input['position'],
                'joined_at' => $input['joinedAt'],
            ]);
            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'actor_id' => $actor->id,
                'action' => 'employee.created',
                'entity_type' => 'employee',
                'entity_id' => $id,
                'after' => ['employeeCode' => $input['employeeCode'], 'fullName' => $input['fullName'], 'position' => $input['position'], 'linked' => $input['userId'] !== null],
            ], $context);

            return $id;
        }));
    }

    public function update(User $actor, string $employeeId, array $raw, AuditContext $context): void
    {
        $input = AttendanceInput::employee($raw, false);
        $current = DB::table('employees')->where('id', $employeeId)->where('company_id', $actor->company_id)->first()
            ?? throw AccessException::notFound();
        $this->assertAt($actor, 'employee.edit', $current->outlet_id);
        $outlet = $this->outlet($actor, $input['outletId']);
        $this->assertAt($actor, 'employee.edit', $outlet->id);
        $this->assertLinkable($actor, $input['userId'], $current->id);
        if (! $input['isActive'] && $current->is_active && DB::table('attendances')->where('employee_id', $current->id)->whereNull('check_out_at')->exists()) {
            throw new AccessException('conflict', 'Karyawan masih tercatat masuk. Tutup sesi absennya dulu (absen pulang atau koreksi).');
        }
        $before = ['fullName' => $current->full_name, 'phone' => $current->phone, 'position' => $current->position, 'outletId' => $current->outlet_id,
            'joinedAt' => $current->joined_at, 'userId' => $current->user_id, 'isActive' => (bool) $current->is_active];
        $after = ['fullName' => $input['fullName'], 'phone' => $input['phone'], 'position' => $input['position'], 'outletId' => $outlet->id,
            'joinedAt' => $input['joinedAt'], 'userId' => $input['userId'], 'isActive' => $input['isActive']];
        $changed = array_keys(array_filter($after, fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            return;
        }

        $this->unique(fn () => DB::transaction(function () use ($actor, $current, $outlet, $input, $before, $after, $changed, $context) {
            DB::table('employees')->where('id', $current->id)->update([
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'user_id' => $input['userId'],
                'full_name' => $input['fullName'],
                'phone' => $input['phone'],
                'position' => $input['position'],
                'joined_at' => $input['joinedAt'],
                'is_active' => $input['isActive'],
                'updated_at' => DB::raw('now()'),
            ]);
            $this->audit->record([
                'company_id' => $actor->company_id,
                'brand_id' => $outlet->brand_id,
                'outlet_id' => $outlet->id,
                'actor_id' => $actor->id,
                'action' => in_array('isActive', $changed, true) && $changed === ['isActive'] ? 'employee.status_changed' : 'employee.updated',
                'entity_type' => 'employee',
                'entity_id' => $current->id,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ], $context);
        }));
    }

    private function outlet(User $actor, string $outletId): object
    {
        return DB::table('outlets')->where('id', $outletId)->where('company_id', $actor->company_id)->first(['id', 'brand_id'])
            ?? throw new AccessException('invalid', 'Outlet tidak ditemukan.');
    }

    private function assertLinkable(User $actor, ?string $userId, ?string $employeeId = null): void
    {
        if ($userId === null) {
            return;
        }
        if (! DB::table('users')->where('id', $userId)->where('company_id', $actor->company_id)->exists()) {
            throw new AccessException('invalid', 'Akun tidak ditemukan di company ini.');
        }
        $other = DB::table('employees')->where('user_id', $userId)->when($employeeId !== null, fn ($q) => $q->where('id', '<>', $employeeId))->exists();
        if ($other) {
            throw new AccessException('conflict', 'Akun ini sudah ditautkan ke karyawan lain.');
        }
    }

    /** Kode karyawan unik per company; akun hanya untuk satu karyawan. */
    private function unique(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                // Pesan driver menyebut kolom penyebab ("Key (user_id)=…"); pesan exception
                // memuat seluruh SQL insert (selalu berisi user_id), jadi tidak dipakai.
                $detail = (string) ($error->errorInfo[2] ?? '');
                throw new AccessException('conflict', str_contains($detail, '(user_id)')
                    ? 'Akun ini sudah ditautkan ke karyawan lain.'
                    : 'Kode karyawan sudah dipakai. Pakai kode lain.');
            }
            throw $error;
        }
    }

    private function assertAt(User $actor, string $permission, string $outletId): void
    {
        if (! $actor->can($permission, AccessScope::outlet($outletId))) {
            throw AccessException::forbidden();
        }
    }
}
