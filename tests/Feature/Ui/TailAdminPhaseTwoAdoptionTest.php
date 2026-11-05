<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TailAdminPhaseTwoAdoptionTest extends TestCase
{
    public function test_segmented_control_renders_tailadmin_pills(): void
    {
        $html = Blade::render(
            '<x-ui.segmented-control
                :options="[
                    [\'value\' => \'all\', \'label\' => \'Semua\'],
                    [\'value\' => \'active\', \'label\' => \'Aktif\', \'badge\' => \'12\'],
                    [\'value\' => \'inactive\', \'label\' => \'Nonaktif\'],
                ]"
                value="active"
            />'
        );

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('role="tab"', $html);
        $this->assertStringContainsString('Semua', $html);
        $this->assertStringContainsString('Aktif', $html);
        $this->assertStringContainsString('Nonaktif', $html);
        $this->assertStringContainsString('12', $html);
        $this->assertStringContainsString('bg-muted', $html);
    }

    public function test_file_dropzone_renders_dashed_upload_area_and_gallery(): void
    {
        $html = Blade::render(
            '<x-erp.file-dropzone
                name="receipt"
                label="Unggah Nota Transaksi"
                hint="Mendukung JPG, PNG, PDF hingga 10MB"
                :max-size-mb="10"
            />'
        );

        $this->assertStringContainsString('Unggah Nota Transaksi', $html);
        $this->assertStringContainsString('Mendukung JPG, PNG, PDF hingga 10MB', $html);
        $this->assertStringContainsString('cloud_upload', $html);
        $this->assertStringContainsString('Pilih Berkas', $html);
        $this->assertStringContainsString('border-dashed', $html);
        $this->assertStringContainsString('formatFileSize', $html);
    }

    public function test_tailadmin_dropzone_input_alias_renders_successfully(): void
    {
        $html = Blade::render(
            '<x-main.forms.form-elements.dropzone.dropzone-input
                name="avatar"
                label="Drop Profile Picture"
            />'
        );

        $this->assertStringContainsString('Drop Profile Picture', $html);
        $this->assertStringContainsString('border-dashed', $html);
    }

    public function test_slide_over_renders_drawer_panel_and_footer(): void
    {
        $html = Blade::render(
            '<x-erp.slide-over title="Detail Pesanan #1042" description="Informasi tagihan kasir">
                <p>Isi Detail Tagihan</p>
                <x-slot:footer>
                    <button type="button">Tutup</button>
                    <button type="button">Cetak Struk</button>
                </x-slot:footer>
            </x-erp.slide-over>'
        );

        $this->assertStringContainsString('Detail Pesanan #1042', $html);
        $this->assertStringContainsString('Informasi tagihan kasir', $html);
        $this->assertStringContainsString('Isi Detail Tagihan', $html);
        $this->assertStringContainsString('Cetak Struk', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
    }

    public function test_badge_supports_tailadmin_color_and_dot_props(): void
    {
        $successBadge = Blade::render('<x-ui.badge color="success">Aktif</x-ui.badge>');
        $this->assertStringContainsString('Aktif', $successBadge);
        $this->assertStringContainsString('bg-success-muted', $successBadge);

        $errorBadge = Blade::render('<x-ui.badge color="error">Gagal</x-ui.badge>');
        $this->assertStringContainsString('Gagal', $errorBadge);
        $this->assertStringContainsString('bg-destructive-muted', $errorBadge);

        $dotBadge = Blade::render('<x-ui.badge variant="dot" color="success">Live</x-ui.badge>');
        $this->assertStringContainsString('Live', $dotBadge);
        $this->assertStringContainsString('rounded-full', $dotBadge);
    }
}
