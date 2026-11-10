<?php

namespace Tests\Live;

use App\Models\User;
use App\Services\Auth\AccessLoader;
use App\Services\Security\LoginLimits;
use App\Services\Security\RateLimits;
use App\Services\Seed\InitialData;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fixture untuk test yang memakai Supabase dan database sungguhan. Semua data
 * memakai prefix unik per run dan dihapus di cleanup(), hanya berdasarkan id
 * yang dibuat run ini. Password test dibuat acak di memori dan tidak pernah dicetak.
 * Prefix sama dengan tests/helpers/live.ts di Next.js, jadi skrip pembersih lama tetap berlaku.
 */
final class LiveContext
{
    public readonly string $runId;

    private readonly string $emailPrefix;

    /** @var list<string> */
    private array $companyIds = [];

    /** @var array<string, true> hash key rate limit yang dipakai test */
    private array $rateLimitKeys = [];

    /** @param string|null $runId run lama yang mau dibersihkan; null = run baru */
    public function __construct(?string $runId = null)
    {
        if ($runId !== null && preg_match('/^[0-9a-f]{8}$/', $runId) !== 1) {
            throw new RuntimeException('runId harus 8 karakter hex');
        }
        $this->runId = $runId ?? bin2hex(random_bytes(4));
        $this->emailPrefix = "sudaya-test-{$this->runId}-";
        if ($runId !== null) {
            $this->companyIds = DB::table('companies')->where('code', 'like', "test-{$runId}-%")->pluck('id')->all();
        }
    }

    public function newEmail(): string
    {
        return $this->emailPrefix.bin2hex(random_bytes(3)).'@example.com';
    }

    public static function newPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=').'aA1!';
    }

    /** @return array{id: string, code: string, name: string, brandId: string, outletId: string, outletCode: string, target: array, roles: array<string, string>} */
    public function createCompany(string $label): array
    {
        $code = "test-{$this->runId}-{$label}";
        $target = [
            'company' => ['code' => $code, 'name' => 'Test Company '.strtoupper($label)],
            'brand' => ['code' => "{$code}-brand", 'name' => 'Test Brand '.strtoupper($label)],
            'outlet' => ['code' => strtoupper("T{$this->runId}{$label}"), 'name' => 'Test Outlet '.strtoupper($label)],
        ];
        app(InitialData::class)->seed($target);
        $company = DB::table('companies')->where('code', $code)->first();
        $this->companyIds[] = $company->id;
        $outlet = DB::table('outlets')->where('company_id', $company->id)->first();

        return [
            'id' => $company->id,
            'code' => $code,
            'name' => $company->name,
            'brandId' => $outlet->brand_id,
            'outletId' => $outlet->id,
            'outletCode' => $outlet->code,
            'target' => $target,
            'roles' => DB::table('roles')->where('company_id', $company->id)->pluck('id', 'code')->all(),
        ];
    }

    /**
     * Membuat akun Supabase Auth + user aplikasi dengan role tertentu.
     *
     * @param  list<array{role: string, outlet?: 'company'|'outlet'}>  $assignments
     * @return array{id: string, email: string, password: ?string, fullName: string}
     */
    public function createUser(array $company, array $assignments, bool $withPassword = false, ?string $fullName = null): array
    {
        $email = $this->newEmail();
        $password = $withPassword ? self::newPassword() : null;
        $id = $this->createAuthUser($email, $password);
        $fullName ??= 'Test '.implode('+', array_column($assignments, 'role')).' '.bin2hex(random_bytes(2));

        DB::table('users')->insert(['id' => $id, 'company_id' => $company['id'], 'full_name' => $fullName]);
        foreach ($assignments as $assignment) {
            DB::table('user_roles')->insert([
                'user_id' => $id,
                'role_id' => $company['roles'][$assignment['role']],
                'outlet_id' => ($assignment['outlet'] ?? 'company') === 'outlet' ? $company['outletId'] : null,
            ]);
        }

        return ['id' => $id, 'email' => $email, 'password' => $password, 'fullName' => $fullName];
    }

    /** User dengan grants terbaru dari DB. */
    public function load(string $userId): User
    {
        return app(AccessLoader::class)->currentUser($userId) ?? throw new RuntimeException('user tidak ada');
    }

    /** Akun Supabase Auth saja, tanpa user aplikasi (bukan staf). */
    public function createAuthAccount(bool $withPassword = true): array
    {
        $email = $this->newEmail();
        $password = $withPassword ? self::newPassword() : null;

        return ['id' => $this->createAuthUser($email, $password), 'email' => $email, 'password' => $password];
    }

    public function countAudit(string $companyId, string $action, ?string $entityId = null): int
    {
        return DB::table('audit_logs')
            ->where('company_id', $companyId)
            ->where('action', $action)
            ->when($entityId !== null, fn ($query) => $query->where('entity_id', $entityId))
            ->count();
    }

    public function trackRateLimitKey(string $key): void
    {
        $this->rateLimitKeys[RateLimits::hash($key)] = true;
    }

    public function admin(): PendingRequest
    {
        $key = (string) config('services.supabase.service_role_key');

        return Http::baseUrl((string) config('services.supabase.url'))
            ->withHeaders(['apikey' => $key])
            ->withToken($key)
            ->acceptJson()
            ->timeout(20);
    }

    public function cleanup(): void
    {
        if ($this->companyIds !== []) {
            // Data transaksi merujuk users (created_by/confirmed_by), jadi dihapus sebelum
            // akun Auth. order_items dan order_item_modifiers ikut cascade. Urutan mengikuti FK:
            // pergerakan kas → refund → pembayaran → sesi kas → approval → order.
            DB::table('cash_movements')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('refunds')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('payments')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('cash_sessions')->whereIn('company_id', $this->companyIds)->delete();
            // Absensi (M7-lite): koreksi merujuk approval + sesi; sesi merujuk karyawan;
            // pengaturan merujuk users (updated_by).
            DB::table('attendance_corrections')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('attendances')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('outside_duty_requests')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('employees')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('attendance_settings')->whereIn('company_id', $this->companyIds)->delete();
            // Stok (M5-lite): opname → penyesuaian → ledger. Ledger append-only: DELETE hanya dengan
            // flag maintenance di transaksi yang sama, dibatasi ke company test run ini.
            DB::table('stock_opname_items')->whereIn('opname_id', DB::table('stock_opnames')->whereIn('company_id', $this->companyIds)->select('id'))->delete();
            DB::table('stock_opnames')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('inventory_adjustments')->whereIn('company_id', $this->companyIds)->delete();
            DB::transaction(function () {
                DB::select("select set_config('sudaya.ledger_maintenance', 'on', true)");
                DB::table('inventory_transactions')->whereIn('company_id', $this->companyIds)->delete();
            });
            DB::table('approvals')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('orders')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('order_number_counters')
                ->whereIn('outlet_id', DB::table('outlets')->whereIn('company_id', $this->companyIds)->select('id'))
                ->delete();
            DB::table('customers')->whereIn('company_id', $this->companyIds)->delete();
            // Setelah orders: pg_cron bisa menulis `order.expired` selama order pending masih ada.
            // Audit log append-only: DELETE hanya dengan flag maintenance di transaksi
            // yang sama, dan dibatasi ke company test run ini.
            DB::transaction(function () {
                DB::select("select set_config('sudaya.audit_maintenance', 'on', true)");
                DB::table('audit_logs')->whereIn('company_id', $this->companyIds)->delete();
            });
        }

        // ON DELETE CASCADE ke public.users lalu user_roles.
        $authUsers = DB::table('auth.users')->where('email', 'like', $this->emailPrefix.'%')->get(['id', 'email']);
        foreach ($authUsers as $user) {
            // Counter login akun test (tanpa IP tepercaya di lingkungan test), dari jalur login mana pun.
            foreach (LoginLimits::rules($user->email, null) as $rule) {
                $this->trackRateLimitKey($rule['key']);
            }
            $this->admin()->delete("/auth/v1/admin/users/{$user->id}")->throw();
        }

        if ($this->companyIds !== []) {
            // Foto menu test: <companyId>/<brandId>/<file>.
            foreach ($this->companyIds as $companyId) {
                $this->removeStorageFolder('menu-images', $companyId);
                $this->removeAttendancePhotos($companyId);
                $this->removeStorageFolder('inventory-evidence', $companyId);
                // Bukti tugas luar: outside-duty/<companyId>/<karyawan>/<file>.
                $this->removeStorageFolder('attendance-evidence', "outside-duty/{$companyId}");
            }
            // Katalog dulu (FK ke brand); cascade ke outlet_products, product_modifier_groups, modifiers.
            DB::table('products')->whereIn('company_id', $this->companyIds)->delete();
            // Item stok yang dibuat test inventory guard (tautan outlet_products sudah terhapus di atas).
            DB::table('inventory_items')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('modifier_groups')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('categories')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('users')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('roles')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('outlets')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('brands')->whereIn('company_id', $this->companyIds)->delete();
            DB::table('companies')->whereIn('id', $this->companyIds)->delete();
        }
        if ($this->rateLimitKeys !== []) {
            DB::table('rate_limit_counters')->whereIn('key', array_keys($this->rateLimitKeys))->delete();
        }
    }

    /** Hapus semua objek di bawah <prefix>/<subfolder>/ milik company test. */
    private function removeStorageFolder(string $bucket, string $prefix): void
    {
        $folders = $this->admin()->post("/storage/v1/object/list/{$bucket}", ['prefix' => $prefix, 'limit' => 1000])->json() ?? [];
        foreach ($folders as $folder) {
            $files = $this->admin()->post("/storage/v1/object/list/{$bucket}", ['prefix' => "{$prefix}/{$folder['name']}", 'limit' => 1000])->json() ?? [];
            $paths = array_map(fn ($file) => "{$prefix}/{$folder['name']}/{$file['name']}", $files);
            if ($paths !== []) {
                $this->admin()->delete("/storage/v1/object/{$bucket}", ['prefixes' => $paths])->throw();
            }
        }
    }

    /** Foto selfie test: selfie/<companyId>/<karyawan>/<YYYY-MM>/<file> di bucket privat. */
    private function removeAttendancePhotos(string $companyId): void
    {
        $list = fn (string $prefix) => $this->admin()->post('/storage/v1/object/list/attendance-evidence', ['prefix' => $prefix, 'limit' => 1000])->json() ?? [];
        foreach ($list("selfie/{$companyId}") as $employee) {
            foreach ($list("selfie/{$companyId}/{$employee['name']}") as $month) {
                $base = "selfie/{$companyId}/{$employee['name']}/{$month['name']}";
                $paths = array_map(fn ($file) => "{$base}/{$file['name']}", $list($base));
                if ($paths !== []) {
                    $this->admin()->delete('/storage/v1/object/attendance-evidence', ['prefixes' => $paths])->throw();
                }
            }
        }
    }

    private function createAuthUser(string $email, ?string $password): string
    {
        $body = ['email' => $email, 'email_confirm' => true];
        if ($password !== null) {
            $body['password'] = $password;
        }

        return $this->admin()->post('/auth/v1/admin/users', $body)->throw()->json('id');
    }
}
