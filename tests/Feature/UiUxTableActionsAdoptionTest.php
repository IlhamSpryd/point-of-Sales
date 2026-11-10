<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UiUxTableActionsAdoptionTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function getTargetViews(): array
    {
        return [
            // Core resource index views
            'brands/index.blade.php',
            'categories/index.blade.php',
            'devices/index.blade.php',
            'price_lists/index.blade.php',
            'products/index.blade.php',
            'roles/index.blade.php',
            'stores/index.blade.php',
            'tables/index.blade.php',
            'tax_classes/index.blade.php',
            'units_of_measure/index.blade.php',
            'users/index.blade.php',
            'users/invitations.blade.php',

            // Livewire cashier & retail
            'livewire/kasir/order-history.blade.php',
            'livewire/retail/barcode-manager.blade.php',
            'livewire/retail/shelf-label-manager.blade.php',
            'livewire/channel-mapping-manager.blade.php',

            // Livewire purchasing
            'livewire/purchasing/goods-received-note-manager.blade.php',
            'livewire/purchasing/purchase-order-manager.blade.php',
            'livewire/purchasing/purchase-return-manager.blade.php',
            'livewire/purchasing/supplier-invoice-manager.blade.php',
            'livewire/purchasing/supplier-manager.blade.php',

            // Livewire inventory
            'livewire/inventory/goods-receipt-manager.blade.php',
            'livewire/inventory/ingredient-manager.blade.php',
            'livewire/inventory/modifier-manager.blade.php',
            'livewire/inventory/stock-adjustment-manager.blade.php',
            'livewire/inventory/stock-opname-manager.blade.php',
            'livewire/inventory/stock-transfer-manager.blade.php',
            'livewire/inventory/supplier-return-manager.blade.php',

            // Livewire sales
            'livewire/sales/accounts-receivable-manager.blade.php',
            'livewire/sales/quotation-manager.blade.php',
            'livewire/sales/return-credit-note-manager.blade.php',
            'livewire/sales/sales-order-manager.blade.php',

            // Livewire HR
            'livewire/hr/attendance-monitor.blade.php',
            'livewire/hr/employee-manager.blade.php',
            'livewire/hr/leave-manager.blade.php',
            'livewire/hr/payroll-batch-manager.blade.php',

            // Livewire manufacturing
            'livewire/manufacturing/manufacturing-order-manager.blade.php',

            // Livewire finance
            'livewire/finance/accounting-period-manager.blade.php',
            'livewire/finance/bank-reconciliation-workbench.blade.php',
            'livewire/finance/chart-of-account-manager.blade.php',
            'livewire/finance/journal-entry-manager.blade.php',

            // Livewire pricing & discounts
            'livewire/discount-manager.blade.php',
            'livewire/pricing/promotion-campaign-manager.blade.php',
            'livewire/pricing/voucher-manager.blade.php',

            // Livewire customers & CRM
            'livewire/customers/customer-credit-manager.blade.php',
            'livewire/customers/customer-group-manager.blade.php',
            'livewire/customers/customer-manager.blade.php',
            'livewire/customers/loyalty-and-value-instrument-manager.blade.php',
        ];
    }

    /**
     * Verify that all canonical index and manager views have adopted <x-ui.table-actions>.
     */
    public function test_all_target_views_adopt_canonical_table_actions(): void
    {
        $targetViews = $this->getTargetViews();
        $missingAdoption = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $this->assertFileExists($path, "Target view {$view} does not exist.");

            $content = File::get($path);
            if (! str_contains($content, '<x-ui.table-actions>') && ! str_contains($content, '<x-ui.table-actions')) {
                $missingAdoption[] = $view;
            }
        }

        $this->assertSame([], $missingAdoption, 'The following views are missing <x-ui.table-actions>: ' . implode(', ', $missingAdoption));
    }

    /**
     * Verify that all target views using table-actions use table-action-item for menu items.
     */
    public function test_target_views_use_table_action_items(): void
    {
        $targetViews = $this->getTargetViews();
        $missingItems = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);

            if (! str_contains($content, '<x-ui.table-action-item')) {
                $missingItems[] = $view;
            }
        }

        $this->assertSame([], $missingItems, 'The following views use table-actions but lack <x-ui.table-action-item>: ' . implode(', ', $missingItems));
    }

    /**
     * Verify that none of the target views have legacy inline action buttons (size="icon") in table cells.
     */
    public function test_target_views_have_zero_legacy_icon_buttons_in_table_cells(): void
    {
        $targetViews = $this->getTargetViews();
        $legacyViolations = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);

            // Extract all <td> cells and check for size="icon"
            preg_match_all('/<td[^>]*>(.*?)<\/td>/si', $content, $matches);
            foreach ($matches[1] as $td) {
                if (str_contains($td, 'size="icon"') || str_contains($td, "size='icon'")) {
                    $legacyViolations[] = $view;
                    break;
                }
            }
        }

        $this->assertSame([], $legacyViolations, 'Views with legacy size="icon" in <td>: ' . implode(', ', $legacyViolations));
    }

    /**
     * Verify that <x-ui.table-actions> renders accessible triggers, Alpine positioning, and teleportation.
     */
    public function test_table_actions_primitive_renders_accessible_markup(): void
    {
        $html = Blade::render(
            '<x-ui.table-actions label="Test Actions">' .
                '<x-ui.table-action-item href="/sample" icon="visibility">View</x-ui.table-action-item>' .
            '</x-ui.table-actions>'
        );

        $this->assertStringContainsString('aria-haspopup="true"', $html);
        $this->assertStringContainsString(':aria-expanded="dropdownOpen.toString()"', $html);
        $this->assertStringContainsString('aria-label="Test Actions"', $html);
        $this->assertStringContainsString('more_vert', $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('@keydown.escape.window="dropdownOpen = false"', $html);
        $this->assertStringContainsString('@click.away="dropdownOpen = false"', $html);
        $this->assertStringContainsString('href="/sample"', $html);
        $this->assertStringContainsString('visibility', $html);
        $this->assertStringContainsString('View', $html);
    }

    /**
     * Verify that <x-ui.table-action-item> renders link, button, and destructive variants correctly.
     */
    public function test_table_action_item_renders_link_and_button_variants(): void
    {
        // 1. Link variant
        $linkHtml = Blade::render(
            '<x-ui.table-action-item href="/resource/edit" icon="edit">Edit Record</x-ui.table-action-item>'
        );
        $this->assertStringContainsString('<a href="/resource/edit"', $linkHtml);
        $this->assertStringContainsString('edit', $linkHtml);
        $this->assertStringContainsString('Edit Record', $linkHtml);

        // 2. Button variant with wire:click
        $buttonHtml = Blade::render(
            '<x-ui.table-action-item icon="delete" variant="destructive" wire:click="delete(5)">Delete Record</x-ui.table-action-item>'
        );
        $this->assertStringContainsString('<button type="button"', $buttonHtml);
        $this->assertStringContainsString('text-destructive', $buttonHtml);
        $this->assertStringContainsString('wire:click="delete(5)"', $buttonHtml);
        $this->assertStringContainsString('delete', $buttonHtml);
        $this->assertStringContainsString('Delete Record', $buttonHtml);

        // 3. Custom type prop (e.g. submit)
        $submitHtml = Blade::render(
            '<x-ui.table-action-item icon="send" type="submit">Submit Form</x-ui.table-action-item>'
        );
        $this->assertStringContainsString('<button type="submit"', $submitHtml);
        $this->assertStringContainsString('Submit Form', $submitHtml);
    }
}

