<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccessException;
use App\Services\Attendance\Employees;
use App\Services\Audit\AuditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Data karyawan untuk absensi. Hapus tidak tersedia; karyawan dinonaktifkan. */
class EmployeeController
{
    private const FIELDS = ['employeeCode', 'fullName', 'phone', 'position', 'outletId', 'joinedAt', 'userId', 'isActive'];

    public function __construct(private Employees $employees) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/employees/index', [
            ...$this->employees->list($user, $request->only(['outlet', 'status', 'q'])),
            'canCreate' => $this->employees->outlets($user, 'employee.create') !== [],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/employees/form', [
            'employee' => null,
            ...$this->employees->formOptions($request->user(), 'employee.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->employees->create($request->user(), $request->only(self::FIELDS), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Karyawan ditambahkan.');

        return redirect('/admin/employees');
    }

    public function edit(Request $request, string $employee): Response
    {
        $detail = $this->load($request, $employee);

        return Inertia::render('admin/employees/form', [
            'employee' => $detail,
            ...$this->employees->formOptions($request->user(), 'employee.edit', $detail['userId']),
        ]);
    }

    public function update(Request $request, string $employee): RedirectResponse
    {
        $this->employees->update($request->user(), $this->load($request, $employee)['id'], $request->only(self::FIELDS), AuditContext::fromRequest($request));
        Inertia::flash('success', 'Data karyawan tersimpan.');

        return redirect('/admin/employees');
    }

    private function load(Request $request, string $employee): array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $employee)) {
            abort(404);
        }
        try {
            return $this->employees->get($request->user(), strtolower($employee));
        } catch (AccessException $error) {
            abort($error->reason === 'forbidden' ? 403 : 404);
        }
    }
}
