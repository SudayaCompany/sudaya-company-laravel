<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_ok_tanpa_cache_dan_tanpa_detail(): void
    {
        DB::shouldReceive('select')->once()->with('select 1')->andReturn([]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeaderContains('Cache-Control', 'no-store');
    }

    public function test_database_gagal_tanpa_membocorkan_alasan(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('password rahasia di pesan error'));

        $response = $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'error']);

        $this->assertStringNotContainsString('rahasia', $response->getContent());
    }

    public function test_halaman_awal_memakai_inertia(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('home', shouldExist: true));
    }
}
