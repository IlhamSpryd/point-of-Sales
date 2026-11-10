<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UiUxPhase4ModalAndTableAuditTest extends TestCase
{
    /**
     * TASK-UI-07: Ensure no custom-coded inline fixed modal overlays remain in Livewire views.
     */
    public function test_zero_inline_fixed_modal_overlays_in_livewire_views(): void
    {
        $livewirePath = resource_path('views/livewire');
        $this->assertDirectoryExists($livewirePath);

        $files = File::allFiles($livewirePath);
        $violations = [];

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            // Exclude mobile drawer trigger bars or non-modal floating overlays
            if ($file->getFilename() === 'mobile-cart-drawer.blade.php') {
                continue;
            }

            $content = File::get($file->getPathname());

            // Match fixed inset overlay patterns representing custom modals
            if (preg_match('/class=[\'"][^\'"]*fixed\s+inset-0[^\'"]*(?:items-center|items-end|justify-center)[^\'"]*[\'"]/', $content, $m)) {
                $violations[] = $file->getRelativePathname().': '.$m[0];
            }
        }

        $this->assertSame([], $violations, 'Ditemukan custom fixed modal overlay pada Livewire views: '.implode(', ', $violations));
    }

    /**
     * TASK-UI-07: Ensure POS partials use canonical <x-ui.modal> and zero legacy <x-modal>.
     */
    public function test_pos_modals_use_canonical_ui_modal_primitive(): void
    {
        $posPartialsPath = resource_path('views/livewire/pos/partials');
        $this->assertDirectoryExists($posPartialsPath);

        // 1. Modifier modal migrated
        $modifierModal = File::get($posPartialsPath.'/modifier-modal.blade.php');
        $this->assertStringContainsString('<x-ui.modal', $modifierModal);
        $this->assertStringNotContainsString('fixed inset-0', $modifierModal);
        $this->assertStringContainsString('id="modifier-picker"', $modifierModal);

        // 2. Checkout modal migrated from legacy <x-modal>
        $checkoutModal = File::get($posPartialsPath.'/checkout-modal.blade.php');
        $this->assertStringContainsString('<x-ui.modal name="checkout-payment"', $checkoutModal);
        $this->assertStringNotContainsString('<x-modal', $checkoutModal);

        // 3. Park, Parked List, Manual Discount, and Kiosk Attendance modals migrated
        $this->assertStringContainsString('<x-ui.modal wire:model="isParkModalOpen"', File::get($posPartialsPath.'/park-modal.blade.php'));
        $this->assertStringContainsString('<x-ui.modal wire:model="isParkedListOpen"', File::get($posPartialsPath.'/parked-list-modal.blade.php'));
        $this->assertStringContainsString('<x-ui.modal wire:model="isManualDiscountModalOpen"', File::get($posPartialsPath.'/manual-discount-modal.blade.php'));
        $this->assertStringContainsString('<x-ui.modal x-model="openKiosk"', File::get($posPartialsPath.'/kiosk-attendance-modal.blade.php'));
    }

    /**
     * TASK-UI-07: Standardize backdrop to bg-black/60 backdrop-blur-[2px], with keyboard ESC and WCAG focus trapping.
     */
    public function test_canonical_modal_primitive_enforces_backdrop_and_wcag(): void
    {
        $modalPrimitive = File::get(resource_path('views/components/ui/modal/index.blade.php'));

        $this->assertStringContainsString('bg-black/60 backdrop-blur-[2px]', $modalPrimitive);
        $this->assertStringContainsString('x-trap.inert.noscroll="modalOpen"', $modalPrimitive);
        $this->assertStringContainsString('@keydown.escape.window=', $modalPrimitive);
        $this->assertStringContainsString('@close=', $modalPrimitive);
        $this->assertStringContainsString('$cleanup', $modalPrimitive);
    }

    /**
     * TASK-UI-08: Standardize list toolbars onto <x-erp.filter-bar>.
     */
    public function test_core_index_views_standardized_on_erp_filter_bar(): void
    {
        $views = [
            'products/index.blade.php',
            'categories/index.blade.php',
            'brands/index.blade.php',
            'units_of_measure/index.blade.php',
            'tax_classes/index.blade.php',
            'stores/index.blade.php',
            'devices/index.blade.php',
            'price_lists/index.blade.php',
            'roles/index.blade.php',
            'tables/index.blade.php',
            'users/index.blade.php',
            'livewire/kasir/order-history.blade.php',
            'livewire/finance/chart-of-account-manager.blade.php',
            'livewire/finance/journal-entry-manager.blade.php',
            'livewire/pricing/promotion-campaign-manager.blade.php',
            'livewire/pricing/voucher-manager.blade.php',
            'livewire/retail/shelf-label-manager.blade.php',
            'livewire/manufacturing/manufacturing-order-manager.blade.php',
            'livewire/inventory/stock-transfer-manager.blade.php',
            'livewire/inventory/stock-adjustment-manager.blade.php',
            'livewire/inventory/goods-receipt-manager.blade.php',
            'livewire/inventory/stock-opname-manager.blade.php',
            'livewire/inventory/ingredient-manager.blade.php',
            'livewire/inventory/supplier-return-manager.blade.php',
        ];

        foreach ($views as $view) {
            $path = resource_path('views/'.$view);
            $this->assertFileExists($path, "File view tidak ditemukan: {$view}");
            $content = File::get($path);
            $this->assertStringContainsString('<x-erp.filter-bar', $content, "View {$view} belum menggunakan <x-erp.filter-bar>");
        }
    }

    /**
     * TASK-UI-08: Eliminate double-card nesting ("card-itis") around <x-erp.data-table>.
     */
    public function test_zero_card_itis_around_erp_data_table(): void
    {
        $bladeFiles = File::allFiles(resource_path('views'));
        $violations = [];

        foreach ($bladeFiles as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = File::get($file->getPathname());

            // Check if <x-erp.data-table> is directly wrapped in an outer element with card-surface
            if (preg_match('/class=[\x27\x22][^\x27\x22]*card-surface[^\x27\x22]*[\x27\x22][^>]*>\s*(?:<div[^>]*>\s*)*<x-erp\.data-table/s', $content)) {
                $violations[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $violations, 'Ditemukan card-itis (outer card-surface membungkus <x-erp.data-table>): '.implode(', ', $violations));
    }

    /**
     * TASK-UI-08: Core catalog & organization index views use <x-erp.data-table>.
     */
    public function test_core_index_views_use_erp_data_table(): void
    {
        $views = [
            'products/index.blade.php',
            'categories/index.blade.php',
            'brands/index.blade.php',
            'units_of_measure/index.blade.php',
            'tax_classes/index.blade.php',
            'stores/index.blade.php',
            'devices/index.blade.php',
            'price_lists/index.blade.php',
            'roles/index.blade.php',
            'tables/index.blade.php',
            'users/index.blade.php',
            'assortments/index.blade.php',
            'activity-logs/index.blade.php',
        ];

        foreach ($views as $view) {
            $path = resource_path('views/'.$view);
            $this->assertFileExists($path);
            $content = File::get($path);
            $this->assertStringContainsString('<x-erp.data-table', $content, "View {$view} belum menggunakan <x-erp.data-table>");
        }
    }
}

