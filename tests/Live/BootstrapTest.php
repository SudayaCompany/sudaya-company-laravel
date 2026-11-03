<?php

namespace Tests\Live;

use App\Services\Auth\AdminBootstrap;
use App\Services\Auth\BootstrapRefused;
use Illuminate\Support\Facades\DB;

/** Padanan tests/integration/bootstrap.test.ts. */
class BootstrapTest extends LiveTestCase
{
    protected function fixtures(LiveContext $ctx): array
    {
        return ['company' => $ctx->createCompany('boot')];
    }

    private function bootstrap(): AdminBootstrap
    {
        return app(AdminBootstrap::class);
    }

    public function test_membuat_super_admin_pertama_idempotent(): void
    {
        $company = static::$fixture['company'];
        $authUserId = $this->ctx()->createAuthAccount(false)['id'];

        $this->bootstrap()->assertAllowed($company['code'], $authUserId);
        $this->assertSame('created', $this->bootstrap()->run($company['code'], $authUserId, 'Admin Pertama')['status']);
        $this->assertSame('already_super_admin', $this->bootstrap()->run($company['code'], $authUserId, 'Admin Pertama')['status']);

        $this->assertSame($company['id'], DB::table('users')->where('id', $authUserId)->value('company_id'));
        $roles = DB::table('user_roles')->where('user_id', $authUserId)->get();
        $this->assertCount(1, $roles);
        $this->assertSame($company['roles']['super_admin'], $roles[0]->role_id);
        $this->assertNull($roles[0]->outlet_id);

        $audit = DB::table('audit_logs')->where('company_id', $company['id'])->where('action', 'auth.bootstrap_super_admin')->get();
        $this->assertCount(1, $audit);
        $this->assertNull($audit[0]->actor_id);
        $this->assertSame($authUserId, $audit[0]->entity_id);
    }

    public function test_menolak_akun_lain_bila_sudah_ada_super_admin(): void
    {
        $company = static::$fixture['company'];
        $other = $this->ctx()->createAuthAccount(false)['id'];

        $this->assertRefused(fn () => $this->bootstrap()->assertAllowed($company['code'], $other));
        $this->assertRefused(fn () => $this->bootstrap()->run($company['code'], $other, 'Penyusup'));
        $this->assertFalse(DB::table('users')->where('id', $other)->exists());
    }

    public function test_menolak_akun_yang_terdaftar_di_company_lain(): void
    {
        $otherCompany = $this->ctx()->createCompany('boot2');
        $staff = $this->ctx()->createUser(static::$fixture['company'], [['role' => 'barista', 'outlet' => 'outlet']]);

        $this->assertRefused(fn () => $this->bootstrap()->run($otherCompany['code'], $staff['id'], 'x'), 'company lain');
    }

    public function test_menolak_company_yang_belum_di_seed(): void
    {
        $authUserId = $this->ctx()->createAuthAccount(false)['id'];

        $this->assertRefused(fn () => $this->bootstrap()->run("missing-{$this->ctx()->runId}", $authUserId, 'x'), 'db:seed');
    }

    private function assertRefused(callable $call, ?string $message = null): void
    {
        try {
            $call();
            $this->fail('bootstrap tidak ditolak');
        } catch (BootstrapRefused $error) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
    }
}
