<?php

namespace App\Console\Commands;

use App\Services\Auth\AdminBootstrap;
use App\Services\Auth\BootstrapRefused;
use App\Services\Seed\InitialData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Menyiapkan Super Admin pertama dari akun Supabase Auth yang sudah ada. Password
 * tidak ditangani di sini: buat akunnya di Supabase Dashboard lebih dulu.
 */
class AdminBootstrapCommand extends Command
{
    protected $signature = 'sudaya:admin-bootstrap {--email= : Email akun Supabase Auth} {--name= : Nama lengkap}';

    protected $description = 'Menjadikan akun Supabase Auth sebagai Super Admin pertama';

    public function handle(AdminBootstrap $bootstrap): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email akun Supabase Auth'))));
        $name = trim((string) ($this->option('name') ?: $this->ask('Nama lengkap')));
        $companyCode = InitialData::TARGET['company']['code'];

        $authUserId = DB::table('auth.users')->whereRaw('lower(email) = ?', [$email])->value('id');
        if ($authUserId === null) {
            $this->error('Akun tidak ditemukan. Buat dulu di Supabase Dashboard, Authentication, Add user.');

            return self::FAILURE;
        }

        try {
            $bootstrap->assertAllowed($companyCode, $authUserId);
            $result = $bootstrap->run($companyCode, $authUserId, $name);
        } catch (BootstrapRefused $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info($result['status'] === 'created'
            ? "Super Admin {$result['companyName']} dibuat."
            : 'Akun ini sudah menjadi Super Admin.');

        return self::SUCCESS;
    }
}
