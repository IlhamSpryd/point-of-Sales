<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use DevDojo\Components\Publisher;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DevDojoComponentSuiteTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    public function test_publisher_transforms_dynamic_components_with_ui_prefix(): void
    {
        $publisher = app(Publisher::class);

        $transformed = $publisher->transform('<x-components.button /><x-dynamic-component component="components.tiptap.icons.bold" />');

        $this->assertStringContainsString('<x-ui.button />', $transformed);
        $this->assertStringContainsString('component="ui.tiptap.icons.bold"', $transformed);
    }

    public function test_all_14_newly_published_devdojo_components_exist_in_ui_directory(): void
    {
        $expectedComponents = [
            'tabs',
            'drawer',
            'pagination',
            'popover',
            'date-picker',
            'number-input',
            'radio',
            'kbd',
            'separator',
            'avatar',
            'otp',
            'progress',
            'accordion',
            'command',
            'switch',
            'empty',
            'confirm-dialog',
        ];

        foreach ($expectedComponents as $component) {
            $this->assertDirectoryExists(
                resource_path("views/components/ui/{$component}"),
                "Missing published DevDojo component directory: resources/views/components/ui/{$component}"
            );
        }
    }

    public function test_hardened_ui_primitives_and_erp_composites_render_cleanly(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-erp.page-header
                title="Buku Besar & Neraca Saldo"
                description="Kelola jurnal akuntansi multi-cabang"
                badge="AKTIF"
                badgeVariant="success"
                :breadcrumbs="[['label' => 'Keuangan', 'href' => '/finance'], ['label' => 'Buku Besar']]"
            >
                <x-slot:actions>
                    <x-ui.button variant="primary" icon="add" hotkey="F2" id="btn-create-journal">
                        Jurnal Baru
                    </x-ui.button>
                </x-slot:actions>
            </x-erp.page-header>

            <x-erp.stat-card label="Total Debit" value="Rp 12.500.000" delta="+4.2%" deltaVariant="positive" icon="account_balance" />

            <x-erp.filter-bar searchModel="search" searchPlaceholder="Cari nomor jurnal...">
                <x-erp.status-badge status="posted" />
            </x-erp.filter-bar>

            <x-erp.data-table :isEmpty="false">
                <x-slot:head>
                    <x-ui.table.head>Kode Akun</x-ui.table.head>
                    <x-ui.table.head align="right" :mono="true">Saldo</x-ui.table.head>
                </x-slot:head>
                <x-ui.table.row>
                    <x-ui.table.cell :mono="true">1101</x-ui.table.cell>
                    <x-ui.table.cell align="right" :mono="true">Rp 12.500.000</x-ui.table.cell>
                </x-ui.table.row>
            </x-erp.data-table>

            <x-erp.money-input label="Nominal Jurnal" name="amount" />
            <x-ui.modal id="test-modal" maxWidth="2xl" title="Detail Jurnal" description="Rincian baris debit dan kredit">
                <p>Isi modal</p>
            </x-ui.modal>
            <x-ui.drawer id="test-drawer" maxWidth="lg" title="Filter Lanjutan">
                <p>Isi drawer</p>
            </x-ui.drawer>
            <x-ui.toast />
            <x-erp.confirm-dialog />
        BLADE);

        $this->assertStringContainsString('Buku Besar &amp; Neraca Saldo', $html);
        $this->assertStringContainsString('id="btn-create-journal"', $html);
        $this->assertStringContainsString('F2', $html);
        $this->assertStringContainsString('font-mono tabular-nums', $html);
        $this->assertStringContainsString('sm:max-w-2xl', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('@toast.window', $html);
        $this->assertStringContainsString('@pop-toast.window', $html);
    }
}
