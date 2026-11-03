<?php

namespace Tests\Live;

use App\Exceptions\AccessException;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AccessAdmin;
use App\Services\Auth\AuthAdmin;
use App\Services\Auth\SupabaseAuthAdmin;
use App\Support\Access\Rbac;
use Illuminate\Support\Facades\DB;

/** Padanan tests/integration/access-control.test.ts, ditambah reset password (GL-5). */
class AccessControlTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        $a = $ctx->createCompany('a');
        $b = $ctx->createCompany('b');
        $ctx->createUser($b, [['role' => 'super_admin']]);

        return [
            'A' => $a,
            'B' => $b,
            'superA' => $ctx->createUser($a, [['role' => 'super_admin']]),
            'managerA' => $ctx->createUser($a, [['role' => 'manager', 'outlet' => 'outlet']]),
            'baristaA' => $ctx->createUser($a, [['role' => 'barista', 'outlet' => 'outlet']]),
            'cashierA' => $ctx->createUser($a, [['role' => 'cashier', 'outlet' => 'outlet']]),
            'baristaB' => $ctx->createUser($b, [['role' => 'barista', 'outlet' => 'outlet']]),
        ];
    }

    private function admin(): AccessAdmin
    {
        return app(AccessAdmin::class);
    }

    private function audit(): AuditContext
    {
        return new AuditContext('127.0.0.1', 'phpunit');
    }

    private function actor(string $name)
    {
        return $this->ctx()->load(static::$fixture[$name]['id']);
    }

    private function id(string $name): string
    {
        return static::$fixture[$name]['id'];
    }

    private function fakeAuthAdmin(): AuthAdmin
    {
        return new class implements AuthAdmin
        {
            public array $banned = [];

            public function createInvite(string $email, string $fullName): array
            {
                throw new \LogicException('tidak dipakai');
            }

            public function createRecovery(string $email): string
            {
                return 'hash-'.md5($email);
            }

            public function setBanned(string $userId, bool $banned): void
            {
                $this->banned[] = [$userId, $banned];
            }
        };
    }

    private function expectAccess(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("diharapkan AccessException {$reason}");
        } catch (AccessException $error) {
            $this->assertSame($reason, $error->reason, $error->getMessage());
        }
    }

    // --- isolasi company ---------------------------------------------------------

    public function test_user_company_a_tidak_bisa_membaca_user_company_b(): void
    {
        $ids = array_column($this->admin()->listCompanyUsers($this->actor('superA')), 'id');
        $this->assertContains($this->id('baristaA'), $ids);
        $this->assertNotContains($this->id('baristaB'), $ids);
    }

    public function test_tidak_bisa_mengubah_user_company_b(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('not_found', fn () => $this->admin()->assignRole($this->actor('superA'), [
            'userId' => $this->id('baristaB'), 'roleId' => $A['roles']['cashier'], 'outletId' => null,
        ], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->admin()->setUserActive($this->actor('superA'), [
            'userId' => $this->id('baristaB'), 'isActive' => false,
        ], $this->audit(), $this->fakeAuthAdmin()));
    }

    public function test_tidak_bisa_memakai_role_atau_outlet_company_b(): void
    {
        $A = static::$fixture['A'];
        $B = static::$fixture['B'];
        $this->expectAccess('not_found', fn () => $this->admin()->assignRole($this->actor('superA'), [
            'userId' => $this->id('cashierA'), 'roleId' => $B['roles']['manager'], 'outletId' => null,
        ], $this->audit()));
        $this->expectAccess('not_found', fn () => $this->admin()->assignRole($this->actor('superA'), [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['barista'], 'outletId' => $B['outletId'],
        ], $this->audit()));
    }

    public function test_audit_log_company_b_tidak_terlihat_dari_company_a(): void
    {
        // Satu baris di tiap company, agar pemeriksaan tidak lolos hanya karena log kosong.
        $logger = app(AuditLogger::class);
        $logger->record(['company_id' => static::$fixture['A']['id'], 'actor_id' => null, 'action' => 'test.isolation', 'entity_type' => 'user', 'entity_id' => $this->id('baristaA')]);
        $logger->record(['company_id' => static::$fixture['B']['id'], 'actor_id' => null, 'action' => 'test.isolation', 'entity_type' => 'user', 'entity_id' => $this->id('baristaB')]);

        $entities = array_column($this->admin()->listAuditLogs($this->actor('superA')), 'entityId');
        $this->assertContains($this->id('baristaA'), $entities);
        $this->assertNotContains($this->id('baristaB'), $entities);
    }

    // --- permission & privilege escalation --------------------------------------

    public function test_barista_tidak_bisa_membaca_daftar_user(): void
    {
        $this->expectAccess('forbidden', fn () => $this->admin()->listCompanyUsers($this->actor('baristaA')));
    }

    public function test_barista_tidak_bisa_menaikkan_dirinya_menjadi_super_admin(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('forbidden', fn () => $this->admin()->assignRole($this->actor('baristaA'), [
            'userId' => $this->id('baristaA'), 'roleId' => $A['roles']['super_admin'], 'outletId' => null,
        ], $this->audit()));
        $this->assertSame(1, DB::table('user_roles')->where('user_id', $this->id('baristaA'))->count());
    }

    public function test_barista_tidak_bisa_menonaktifkan_user_lain(): void
    {
        $this->expectAccess('forbidden', fn () => $this->admin()->setUserActive($this->actor('baristaA'), [
            'userId' => $this->id('cashierA'), 'isActive' => false,
        ], $this->audit(), $this->fakeAuthAdmin()));
    }

    public function test_tidak_bisa_mengubah_akses_akun_sendiri(): void
    {
        $this->expectAccess('forbidden', fn () => $this->admin()->setUserActive($this->actor('superA'), [
            'userId' => $this->id('superA'), 'isActive' => false,
        ], $this->audit(), $this->fakeAuthAdmin()));
    }

    public function test_manager_outlet_dengan_user_manage_hanya_memberi_role_yang_ia_punya(): void
    {
        $A = static::$fixture['A'];
        $this->admin()->setRolePermission($this->actor('superA'), [
            'roleId' => $A['roles']['manager'], 'permissionCode' => 'user.manage', 'granted' => true,
        ], $this->audit());
        $manager = $this->actor('managerA');

        // Super Admin punya permission yang tidak dimiliki manager.
        $this->expectAccess('forbidden', fn () => $this->admin()->assignRole($manager, [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['super_admin'], 'outletId' => $A['outletId'],
        ], $this->audit()));
        // Scope company-wide padahal grant manager hanya outlet.
        $this->expectAccess('forbidden', fn () => $this->admin()->assignRole($manager, [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['barista'], 'outletId' => null,
        ], $this->audit()));
        // Barista di outlet sendiri: diizinkan.
        $this->admin()->assignRole($manager, [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['barista'], 'outletId' => $A['outletId'],
        ], $this->audit());
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'rbac.role_assigned', $this->id('cashierA')));

        // Role yang sama kedua kali: konflik, bukan duplikat.
        $this->expectAccess('conflict', fn () => $this->admin()->assignRole($manager, [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['barista'], 'outletId' => $A['outletId'],
        ], $this->audit()));
    }

    public function test_input_tidak_valid_ditolak_validasi(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('invalid', fn () => $this->admin()->assignRole($this->actor('superA'), [
            'userId' => 'bukan-uuid', 'roleId' => $A['roles']['barista'], 'outletId' => null,
        ], $this->audit()));
        // outletId wajib dikirim, null berarti semua outlet.
        $this->expectAccess('invalid', fn () => $this->admin()->assignRole($this->actor('superA'), [
            'userId' => $this->id('cashierA'), 'roleId' => $A['roles']['barista'],
        ], $this->audit()));
    }

    public function test_super_admin_terakhir_tidak_bisa_dicabut(): void
    {
        $A = static::$fixture['A'];
        // Role kustom dengan semua permission untuk actor non-super-admin.
        $customId = DB::table('roles')->insertGetId(['company_id' => $A['id'], 'code' => 'custom_all', 'name' => 'Custom All']);
        DB::table('role_permissions')->insert(array_map(fn ($code) => ['role_id' => $customId, 'permission_code' => $code], Rbac::codes()));
        $helper = $this->ctx()->createUser($A, []);
        DB::table('user_roles')->insert(['user_id' => $helper['id'], 'role_id' => $customId, 'outlet_id' => null]);

        $superGrant = DB::table('user_roles')->where('user_id', $this->id('superA'))->where('role_id', $A['roles']['super_admin'])->value('id');
        $this->expectAccess('conflict', fn () => $this->admin()->revokeRole($this->ctx()->load($helper['id']), [
            'userRoleId' => $superGrant,
        ], $this->audit()));

        // Role yang dipegang sendiri tidak bisa diubah permission-nya.
        $this->expectAccess('forbidden', fn () => $this->admin()->setRolePermission($this->ctx()->load($helper['id']), [
            'roleId' => $customId, 'permissionCode' => 'kds.view', 'granted' => false,
        ], $this->audit()));
    }

    // --- status & permission tercatat di audit log -------------------------------

    public function test_menonaktifkan_dan_mengaktifkan_user(): void
    {
        $A = static::$fixture['A'];
        $authAdmin = $this->fakeAuthAdmin();
        $this->admin()->setUserActive($this->actor('superA'), [
            'userId' => $this->id('baristaA'), 'isActive' => false, 'reason' => 'test',
        ], $this->audit(), $authAdmin);
        $this->assertFalse((bool) DB::table('users')->where('id', $this->id('baristaA'))->value('is_active'));
        $this->assertSame([[$this->id('baristaA'), true]], $authAdmin->banned);

        $this->admin()->setUserActive($this->actor('superA'), [
            'userId' => $this->id('baristaA'), 'isActive' => true,
        ], $this->audit(), $authAdmin);
        $this->assertSame(2, $this->ctx()->countAudit($A['id'], 'user.status_changed', $this->id('baristaA')));

        // Status yang sama tidak menulis audit ulang.
        $this->admin()->setUserActive($this->actor('superA'), [
            'userId' => $this->id('baristaA'), 'isActive' => true,
        ], $this->audit(), $authAdmin);
        $this->assertSame(2, $this->ctx()->countAudit($A['id'], 'user.status_changed', $this->id('baristaA')));
    }

    public function test_permission_super_admin_terkunci(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('forbidden', fn () => $this->admin()->setRolePermission($this->actor('superA'), [
            'roleId' => $A['roles']['super_admin'], 'permissionCode' => 'kds.view', 'granted' => false,
        ], $this->audit()));
    }

    public function test_grant_dan_revoke_permission_role(): void
    {
        $A = static::$fixture['A'];
        $input = ['roleId' => $A['roles']['cashier'], 'permissionCode' => 'kds.view'];
        $this->admin()->setRolePermission($this->actor('superA'), [...$input, 'granted' => true], $this->audit());
        $this->admin()->setRolePermission($this->actor('superA'), [...$input, 'granted' => false], $this->audit());
        $this->assertGreaterThanOrEqual(1, $this->ctx()->countAudit($A['id'], 'rbac.permission_granted'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'rbac.permission_revoked'));

        $this->expectAccess('invalid', fn () => $this->admin()->setRolePermission($this->actor('superA'), [
            ...$input, 'permissionCode' => 'DROP TABLE', 'granted' => true,
        ], $this->audit()));
        // Sama dengan Next.js: permission yang tidak dipegang actor ditolak sebelum dicek keberadaannya.
        $this->expectAccess('forbidden', fn () => $this->admin()->setRolePermission($this->actor('superA'), [
            ...$input, 'permissionCode' => 'tidak.ada', 'granted' => true,
        ], $this->audit()));
    }

    // --- undang staf (Supabase Auth Admin sungguhan) ----------------------------

    public function test_membuat_undangan_menghubungkan_role_dan_menolak_email_duplikat(): void
    {
        $A = static::$fixture['A'];
        $input = ['email' => $this->ctx()->newEmail(), 'fullName' => 'Staf Undangan', 'roleId' => $A['roles']['barista'], 'outletId' => $A['outletId']];

        $result = $this->admin()->inviteStaff($this->actor('superA'), $input, $this->audit(), app(SupabaseAuthAdmin::class));
        $this->assertSame('invited', $result['status']);
        $this->assertGreaterThan(10, strlen($result['hashedToken']));
        $this->assertSame($A['id'], DB::table('users')->where('id', $result['userId'])->value('company_id'));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'user.invited', $result['userId']));
        $this->assertStringNotContainsString($result['hashedToken'], (string) DB::table('audit_logs')->where('entity_id', $result['userId'])->value('after'));

        $this->expectAccess('conflict', fn () => $this->admin()->inviteStaff($this->actor('superA'), [
            ...$input, 'email' => strtoupper($input['email']),
        ], $this->audit(), app(SupabaseAuthAdmin::class)));
    }

    public function test_akun_auth_yang_sudah_ada_langsung_dihubungkan(): void
    {
        $A = static::$fixture['A'];
        $account = $this->ctx()->createAuthAccount(false);
        $result = $this->admin()->inviteStaff($this->actor('superA'), [
            'email' => $account['email'], 'fullName' => 'Staf Lama', 'roleId' => $A['roles']['cashier'], 'outletId' => null,
        ], $this->audit(), $this->fakeAuthAdmin());

        $this->assertSame(['status' => 'linked', 'userId' => $account['id']], $result);
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'user.linked', $account['id']));
    }

    public function test_barista_tidak_bisa_mengundang_staf(): void
    {
        $A = static::$fixture['A'];
        $this->expectAccess('forbidden', fn () => $this->admin()->inviteStaff($this->actor('baristaA'), [
            'email' => $this->ctx()->newEmail(), 'fullName' => 'Staf Baru', 'roleId' => $A['roles']['barista'], 'outletId' => $A['outletId'],
        ], $this->audit(), app(SupabaseAuthAdmin::class)));
    }

    public function test_ban_dan_unban_di_supabase_auth_berjalan(): void
    {
        $authAdmin = app(SupabaseAuthAdmin::class);
        $authAdmin->setBanned($this->id('cashierA'), true);
        $this->assertNotNull(DB::table('auth.users')->where('id', $this->id('cashierA'))->value('banned_until'));
        $authAdmin->setBanned($this->id('cashierA'), false);
        $this->assertNull(DB::table('auth.users')->where('id', $this->id('cashierA'))->value('banned_until'));
    }

    // --- reset password oleh admin (GL-5) ---------------------------------------

    public function test_link_reset_password_hanya_untuk_akun_yang_didominasi(): void
    {
        $A = static::$fixture['A'];
        $token = $this->admin()->createRecoveryLink($this->actor('superA'), ['userId' => $this->id('cashierA')], $this->audit(), app(SupabaseAuthAdmin::class));
        $this->assertGreaterThan(10, strlen($token));
        $this->assertSame(1, $this->ctx()->countAudit($A['id'], 'user.recovery_link_created', $this->id('cashierA')));

        $this->expectAccess('forbidden', fn () => $this->admin()->createRecoveryLink($this->actor('baristaA'), ['userId' => $this->id('cashierA')], $this->audit(), $this->fakeAuthAdmin()));
        $this->expectAccess('forbidden', fn () => $this->admin()->createRecoveryLink($this->actor('superA'), ['userId' => $this->id('superA')], $this->audit(), $this->fakeAuthAdmin()));
        $this->expectAccess('not_found', fn () => $this->admin()->createRecoveryLink($this->actor('superA'), ['userId' => $this->id('baristaB')], $this->audit(), $this->fakeAuthAdmin()));
    }
}
