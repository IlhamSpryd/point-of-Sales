<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

class DevDojoComponentsIntegrationTest extends TestCase
{
    public function test_can_render_devdojo_ui_button_component(): void
    {
        $html = Blade::render('<x-ui.button variant="primary">Simpan</x-ui.button>');

        $this->assertStringContainsString('Simpan', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('bg-linear-to-b', $html);
    }

    public function test_can_render_devdojo_ui_card_component(): void
    {
        $html = Blade::render('<x-ui.card>Isi Konten</x-ui.card>');

        $this->assertStringContainsString('Isi Konten', $html);
        $this->assertStringContainsString('bg-card', $html);
        $this->assertStringContainsString('border-border', $html);
    }

    public function test_can_render_devdojo_ui_badge_component(): void
    {
        $html = Blade::render('<x-ui.badge variant="success">Selesai</x-ui.badge>');

        $this->assertStringContainsString('Selesai', $html);
        $this->assertStringContainsString('bg-green-50', $html);
        $this->assertStringContainsString('text-green-600', $html);
    }

    public function test_can_render_devdojo_ui_input_with_label_component(): void
    {
        $html = Blade::render('<x-ui.input label="Nama Toko" placeholder="Masukkan nama..." />');

        $this->assertStringContainsString('Nama Toko', $html);
        $this->assertStringContainsString('placeholder="Masukkan nama..."', $html);
        $this->assertStringContainsString('border-input', $html);
    }

    public function test_can_render_devdojo_ui_alert_component(): void
    {
        $html = Blade::render('<x-ui.alert variant="destructive" title="Gagal" description="Terjadi kesalahan sistem." />');

        $this->assertStringContainsString('Gagal', $html);
        $this->assertStringContainsString('Terjadi kesalahan sistem.', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('bg-rose-50', $html);
    }

    public function test_can_render_devdojo_ui_modal_component(): void
    {
        $html = Blade::render('<x-ui.modal id="konfirmasi-modal"><x-slot:header>Hapus Item</x-slot:header></x-ui.modal>');

        $this->assertStringContainsString('Hapus Item', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
    }

    public function test_can_render_devdojo_ui_table_component(): void
    {
        $html = Blade::render('
            <x-ui.table>
                <x-ui.table.columns>
                    <x-ui.table.column>Nama</x-ui.table.column>
                </x-ui.table.columns>
                <x-ui.table.row>
                    <x-ui.table.cell>Apeiron POS</x-ui.table.cell>
                </x-ui.table.row>
            </x-ui.table>
        ');

        $this->assertStringContainsString('Nama', $html);
        $this->assertStringContainsString('Apeiron POS', $html);
        $this->assertStringContainsString('overflow-x-auto', $html);
    }

    public function test_can_render_devdojo_ui_skeleton_and_spinner(): void
    {
        $skeletonHtml = Blade::render('<x-ui.skeleton lines="2" />');
        $this->assertStringContainsString('animate-pulse', $skeletonHtml);

        $spinnerHtml = Blade::render('<x-ui.spinner size="sm" />');
        $this->assertStringContainsString('animate-spin', $spinnerHtml);
        $this->assertStringContainsString('role="status"', $spinnerHtml);
    }

    public function test_can_render_enhanced_ui_badge_with_pos_status_and_dot(): void
    {
        $html = Blade::render('<x-ui.badge type="danger" :dot="true">Batal</x-ui.badge>');
        $this->assertStringContainsString('Batal', $html);
        $this->assertStringContainsString('bg-rose-50', $html);
        $this->assertStringContainsString('bg-rose-500', $html); // dot color

        $paidHtml = Blade::render('<x-ui.badge status="paid" :dot="true">Lunas</x-ui.badge>');
        $this->assertStringContainsString('Lunas', $paidHtml);
        $this->assertStringContainsString('bg-green-50', $paidHtml);
        $this->assertStringContainsString('bg-emerald-500', $paidHtml); // dot color
    }

    public function test_can_render_ui_button_with_danger_variant_and_href(): void
    {
        $html = Blade::render('<x-ui.button variant="danger" href="/orders">Hapus Pesanan</x-ui.button>');

        $this->assertStringContainsString('Hapus Pesanan', $html);
        $this->assertStringContainsString('<a href="/orders"', $html);
        $this->assertStringContainsString('bg-destructive', $html);
    }

    public function test_can_render_ui_form_controls(): void
    {
        $selectHtml = Blade::render('
            <x-ui.select name="role_id" label="Peran Pengguna" placeholder="Pilih peran...">
                <option value="1">Kasir</option>
            </x-ui.select>
        ');
        $this->assertStringContainsString('Peran Pengguna', $selectHtml);
        $this->assertStringContainsString('Pilih peran...', $selectHtml);
        $this->assertStringContainsString('Kasir', $selectHtml);

        $textareaHtml = Blade::render('<x-ui.textarea name="notes" label="Catatan Transaksi" rows="3">Meja 05</x-ui.textarea>');
        $this->assertStringContainsString('Catatan Transaksi', $textareaHtml);
        $this->assertStringContainsString('Meja 05', $textareaHtml);

        $checkboxHtml = Blade::render('<x-ui.checkbox name="is_active" label="Aktifkan Modul" />');
        $this->assertStringContainsString('Aktifkan Modul', $checkboxHtml);
        $this->assertStringContainsString('type="checkbox"', $checkboxHtml);

        $toggleHtml = Blade::render('<x-ui.toggle name="notify" label="Notifikasi Suara" />');
        $this->assertStringContainsString('Notifikasi Suara', $toggleHtml);
        $this->assertStringContainsString('role="switch"', $toggleHtml);
    }

    public function test_can_render_ui_breadcrumbs_and_empty_state(): void
    {
        $breadcrumbHtml = Blade::render('
            <x-ui.breadcrumbs>
                <x-ui.breadcrumbs.item href="/dashboard">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item :current="true">Kategori</x-ui.breadcrumbs.item>
            </x-ui.breadcrumbs>
        ');
        $this->assertStringContainsString('Dashboard', $breadcrumbHtml);
        $this->assertStringContainsString('Kategori', $breadcrumbHtml);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $breadcrumbHtml);

        $emptyHtml = Blade::render('<x-ui.empty-state title="Belum ada data" description="Silakan tambahkan data baru." />');
        $this->assertStringContainsString('Belum ada data', $emptyHtml);
        $this->assertStringContainsString('Silakan tambahkan data baru.', $emptyHtml);
    }

    public function test_can_render_devdojo_preview_namespace(): void
    {
        $html = Blade::render('<x-components.button variant="secondary">Pratinjau</x-components.button>');

        $this->assertStringContainsString('Pratinjau', $html);
        $this->assertStringContainsString('bg-secondary', $html);
    }

    public function test_has_tw_merge_macro_on_component_attribute_bag(): void
    {
        $this->assertTrue(ComponentAttributeBag::hasMacro('twMerge'));
        $this->assertTrue(ComponentAttributeBag::hasMacro('twMergeFor'));

        $bag = new ComponentAttributeBag(['class' => 'p-4 text-red-500']);
        $merged = $bag->twMerge('p-6 text-blue-500');

        $this->assertStringContainsString('class=', (string) $merged);
    }

    public function test_can_render_ui_icon_primitive(): void
    {
        $html = Blade::render('<x-ui.icon name="dashboard" size="md" />');

        $this->assertStringContainsString('dashboard', $html);
        $this->assertStringContainsString('material-symbols-rounded', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('font-variation-settings', $html);

        $htmlWithLabel = Blade::render('<x-ui.icon name="lock" size="lg" fill label="Terkunci" />');
        $this->assertStringContainsString('lock', $htmlWithLabel);
        $this->assertStringContainsString('role="img"', $htmlWithLabel);
        $this->assertStringContainsString('aria-label="Terkunci"', $htmlWithLabel);
        $this->assertStringContainsString("'FILL' 1", $htmlWithLabel);
    }

    public function test_can_render_erp_form_field_with_label_required_and_error(): void
    {
        $html = Blade::render('
            <x-erp.form-field label="Pemasok" required hint="Pilih pemasok utama" error="Pemasok wajib dipilih.">
                <input type="text" name="supplier_id" id="supplier_id" />
            </x-erp.form-field>
        ');

        $this->assertStringContainsString('Pemasok', $html);
        $this->assertStringContainsString('*', $html);
        $this->assertStringContainsString('(wajib diisi)', $html);
        $this->assertStringContainsString('Pemasok wajib dipilih.', $html);
        $this->assertStringContainsString('role="alert"', $html);
    }

    public function test_can_render_erp_searchable_select(): void
    {
        $options = [
            ['value' => '1', 'label' => 'Biji Kopi Arabika'],
            ['value' => '2', 'label' => 'Susu Segar 1L'],
        ];

        $html = Blade::render('
            <x-erp.searchable-select
                id="item_selector"
                :options="$options"
                placeholder="Pilih Bahan Baku..."
                clearable
            />
        ', ['options' => $options]);

        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('Pilih Bahan Baku...', $html);
        $this->assertStringContainsString('Biji Kopi Arabika', $html);
        $this->assertStringContainsString('aria-label="Bersihkan pilihan"', $html);
    }

    public function test_can_render_erp_confirm_dialog_and_dirty_guard(): void
    {
        $confirmHtml = Blade::render('<x-erp.confirm-dialog />');
        $this->assertStringContainsString('confirmSubmit', $confirmHtml);
        $this->assertStringContainsString('window.confirmDelete', $confirmHtml);
        $this->assertStringContainsString('@erp-confirm.window', $confirmHtml);

        $guardHtml = Blade::render('<x-erp.dirty-guard watch="cart" />');
        $this->assertStringContainsString('livewireNavigateHandler', $guardHtml);
        $this->assertStringContainsString('beforeUnloadHandler', $guardHtml);
    }

    public function test_can_render_forwarded_legacy_form_input_and_label(): void
    {
        $labelHtml = Blade::render('<x-form-label for="user_email">Alamat Email</x-form-label>');
        $this->assertStringContainsString('Alamat Email', $labelHtml);
        $this->assertStringContainsString('for="user_email"', $labelHtml);

        $inputHtml = Blade::render('<x-form-input name="user_email" type="email" placeholder="user@apeiron.id" />');
        $this->assertStringContainsString('type="email"', $inputHtml);
        $this->assertStringContainsString('placeholder="user@apeiron.id"', $inputHtml);
    }

    public function test_can_render_erp_data_table_with_columns_and_sorting(): void
    {
        $columns = [
            ['key' => 'order_no', 'label' => 'No. Order', 'sortable' => true],
            ['key' => 'total', 'label' => 'Total', 'numeric' => true, 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'badge' => true],
        ];

        $html = Blade::render('
            <x-erp.data-table :columns="$columns" sort-field="order_no" sort-direction="asc">
                <tr>
                    <td>ORD-001</td>
                    <td class="text-right font-mono tabular-nums">Rp 150.000</td>
                    <td>Selesai</td>
                </tr>
            </x-erp.data-table>
        ', ['columns' => $columns]);

        $this->assertStringContainsString('No. Order', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('text-right', $html);
        $this->assertStringContainsString('font-mono tabular-nums', $html);
        $this->assertStringContainsString('arrow_upward', $html);
        $this->assertStringContainsString('ORD-001', $html);
    }

    public function test_can_render_erp_data_table_empty_state(): void
    {
        $columns = [
            ['key' => 'code', 'label' => 'Kode'],
            ['key' => 'name', 'label' => 'Nama'],
        ];

        $html = Blade::render('
            <x-erp.data-table :columns="$columns" :is-empty="true" empty-title="Tidak Ada Pesanan" />
        ', ['columns' => $columns]);

        $this->assertStringContainsString('Tidak Ada Pesanan', $html);
        $this->assertStringContainsString('colspan="2"', $html);
    }

    public function test_can_render_erp_data_table_with_head_and_rows(): void
    {
        $html = Blade::render('
            <x-erp.data-table>
                <x-slot:head>
                    <th>Kolom A</th>
                </x-slot:head>
                <tr><td>Nilai A</td></tr>
            </x-erp.data-table>
        ');

        $this->assertStringContainsString('Kolom A', $html);
        $this->assertStringContainsString('Nilai A', $html);
        $this->assertStringContainsString('data-table', $html);
    }

    public function test_livewire_concerns_with_sorting_and_bulk_actions(): void
    {
        $component = new class {
            use \App\Livewire\Concerns\WithSorting;
            use \App\Livewire\Concerns\WithBulkActions;

            public int $resetPageCalled = 0;

            public function resetPage(): void
            {
                $this->resetPageCalled++;
            }

            public function getSelectableIds(): array
            {
                return [101, 102, 103];
            }
        };

        // Test sorting
        $this->assertSame('created_at', $component->sortField);
        $this->assertSame('desc', $component->sortDirection);

        $component->sortBy('order_no');
        $this->assertSame('order_no', $component->sortField);
        $this->assertSame('asc', $component->sortDirection);
        $this->assertSame(1, $component->resetPageCalled);

        $component->sortBy('order_no');
        $this->assertSame('desc', $component->sortDirection);
        $this->assertSame(2, $component->resetPageCalled);

        // Test bulk actions
        $this->assertSame([], $component->selected);
        $this->assertSame(0, $component->getSelectedCountProperty());

        $component->updatedSelectAll(true);
        $this->assertSame(['101', '102', '103'], $component->selected);
        $this->assertSame(3, $component->getSelectedCountProperty());
        $this->assertTrue($component->isSelected(102));

        $component->clearSelected();
        $this->assertSame([], $component->selected);
        $this->assertSame(0, $component->getSelectedCountProperty());

        // Test plain component without resetPage or getSelectableIds
        $plainComponent = new class {
            use \App\Livewire\Concerns\WithSorting;
            use \App\Livewire\Concerns\WithBulkActions;
        };
        $plainComponent->sortBy('title');
        $this->assertSame('title', $plainComponent->sortField);
        $plainComponent->updatedSelectAll(true);
        $this->assertSame([], $plainComponent->selected);
    }
}

