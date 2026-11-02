<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomErrorPagesTest extends TestCase
{
    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        Route::get('/_test/error/403', function () {
            abort(403, 'Akses ke modul ini dibatasi khusus level Administrator.');
        });

        Route::get('/_test/error/500', function () {
            abort(500, 'Simulasi kegagalan server database.');
        });
    }

    public function test_custom_403_error_page_renders_with_apeiron_design_tokens(): void
    {
        $response = $this->get('/_test/error/403');

        $response->assertStatus(403);
        $response->assertSee('Apeiron Systems', false);
        $response->assertSee('Error 403', false);
        $response->assertSee('Otorisasi Tidak Memadai', false);
        $response->assertSee('Akses ke modul ini dibatasi khusus level Administrator.', false);
        $response->assertSee('Kembali ke Dashboard', false);
    }

    public function test_custom_404_error_page_renders_with_apeiron_design_tokens(): void
    {
        $response = $this->get('/non-existent-route-for-testing-404');

        $response->assertStatus(404);
        $response->assertSee('Apeiron Systems', false);
        $response->assertSee('Error 404', false);
        $response->assertSee('Halaman Tidak Ditemukan', false);
        $response->assertSee('Kembali ke Dashboard', false);
    }

    public function test_custom_500_error_page_renders_with_apeiron_design_tokens(): void
    {
        $response = $this->get('/_test/error/500');

        $response->assertStatus(500);
        $response->assertSee('Apeiron Systems', false);
        $response->assertSee('Error 500', false);
        $response->assertSee('Terjadi Kesalahan Sistem', false);
    }
}
