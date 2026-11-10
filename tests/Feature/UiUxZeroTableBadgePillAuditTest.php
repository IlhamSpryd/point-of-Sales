<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class UiUxZeroTableBadgePillAuditTest extends TestCase
{
    /**
     * The 49 canonical table views targeted for badge pill elimination.
     *
     * @return array<int, string>
     */
    private function getTargetViews(): array
    {
        return [
            'brands/index.blade.php',
            'categories/index.blade.php',
            'units_of_measure/index.blade.php',
            'tax_classes/index.blade.php',
            'stores/index.blade.php',
            'devices/index.blade.php',
            'roles/index.blade.php',
            'tables/index.blade.php',
            'price_lists/index.blade.php',
            'users/index.blade.php',
            'users/invitations.blade.php',
            'activity-logs/index.blade.php',
            'assortments/index.blade.php',
            'livewire/sales/sales-order-manager.blade.php',
            'livewire/sales/quotation-manager.blade.php',
            'livewire/sales/accounts-receivable-manager.blade.php',
            'livewire/sales/return-credit-note-manager.blade.php',
            'livewire/purchasing/purchase-order-manager.blade.php',
            'livewire/purchasing/goods-received-note-manager.blade.php',
            'livewire/purchasing/supplier-invoice-manager.blade.php',
            'livewire/purchasing/purchase-return-manager.blade.php',
            'livewire/purchasing/supplier-manager.blade.php',
            'livewire/inventory/stock-adjustment-manager.blade.php',
            'livewire/inventory/stock-transfer-manager.blade.php',
            'livewire/inventory/stock-opname-manager.blade.php',
            'livewire/inventory/supplier-return-manager.blade.php',
            'livewire/inventory/ingredient-manager.blade.php',
            'livewire/inventory/ingredient-ledger.blade.php',
            'livewire/inventory/goods-receipt-manager.blade.php',
            'livewire/manufacturing/manufacturing-order-manager.blade.php',
            'livewire/finance/chart-of-account-manager.blade.php',
            'livewire/finance/accounting-period-manager.blade.php',
            'livewire/finance/journal-entry-manager.blade.php',
            'livewire/finance/bank-reconciliation-workbench.blade.php',
            'livewire/hr/attendance-monitor.blade.php',
            'livewire/hr/leave-manager.blade.php',
            'livewire/hr/payroll-batch-manager.blade.php',
            'livewire/customers/customer-manager.blade.php',
            'livewire/customers/customer-group-manager.blade.php',
            'livewire/customers/customer-credit-manager.blade.php',
            'livewire/customers/loyalty-and-value-instrument-manager.blade.php',
            'livewire/pricing/promotion-campaign-manager.blade.php',
            'livewire/pricing/voucher-manager.blade.php',
            'livewire/pricing/margin-simulation-modal.blade.php',
            'livewire/discount-manager.blade.php',
            'livewire/retail/shelf-label-manager.blade.php',
            'livewire/channel-mapping-manager.blade.php',
            'livewire/shift-manager.blade.php',
            'finance/index.blade.php',
        ];
    }

    /**
     * Check if a <td> cell content contains badge pill elements.
     *
     * @return array{hasBadgeComponent: bool, hasManualPill: bool}
     */
    private function inspectCellForBadgePill(string $tdContent): array
    {
        $hasBadgeComponent = str_contains($tdContent, '<x-ui.badge') || str_contains($tdContent, '<x-badge');

        $hasManualPill = false;
        // Check for manual badge pills: any element with padding (px- or py-), background (bg-), and rounding (rounded)
        if (preg_match('/\b(rounded[^\s\"<>]*)\b/', $tdContent)) {
            $hasBg = (bool) preg_match('/\bbg-[^\s\"<>]+\b/', $tdContent);
            $hasPadding = (bool) preg_match('/\b(p[xy]?-[^\s\"<>]+)\b/', $tdContent);
            $isAvatar = (bool) preg_match('/\bw-\d+[\.\d]*\s+h-\d+[\.\d]*\s+rounded-full\b/', $tdContent);
            $isStatusDot = (bool) preg_match('/\bw-1\.5\s+h-1\.5\s+rounded-full\b/', $tdContent);
            $isInteractiveFormOrSkeleton = str_contains($tdContent, '<input') ||
                str_contains($tdContent, '<button') ||
                str_contains($tdContent, '<select') ||
                str_contains($tdContent, '<textarea') ||
                str_contains($tdContent, '<x-ui.button') ||
                str_contains($tdContent, '<x-ui.input') ||
                str_contains($tdContent, '<x-ui.table-actions') ||
                str_contains($tdContent, '$skeletonRows');

            // Exclude QR code box in tables/index: bg-white p-2 rounded-xl
            $isQrCodeBox = str_contains($tdContent, 'QrCode::');

            if ($hasBg && $hasPadding && ! $isAvatar && ! $isStatusDot && ! $isInteractiveFormOrSkeleton && ! $isQrCodeBox) {
                $hasManualPill = true;
            }
        }

        return [
            'hasBadgeComponent' => $hasBadgeComponent,
            'hasManualPill' => $hasManualPill,
        ];
    }

    /**
     * Test that all 49 target table views have zero <x-ui.badge> or <x-badge> in <td> cells.
     */
    public function test_all_49_target_table_views_have_zero_badge_components_in_table_cells(): void
    {
        $targetViews = $this->getTargetViews();
        $violations = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $this->assertFileExists($path, "Target view {$view} does not exist.");

            $content = File::get($path);
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/si', $content, $matches);

            foreach ($matches[1] as $td) {
                $result = $this->inspectCellForBadgePill($td);
                if ($result['hasBadgeComponent']) {
                    $violations[] = "{$view}: contains badge component in <td>: " . trim(substr($td, 0, 100));
                }
            }
        }

        $this->assertSame([], $violations, 'Badge components found in <td> cells: ' . implode("\n", $violations));
    }

    /**
     * Test that all 49 target table views have zero manual badge pills in <td> cells.
     */
    public function test_all_49_target_table_views_have_zero_manual_badge_pills_in_table_cells(): void
    {
        $targetViews = $this->getTargetViews();
        $violations = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/si', $content, $matches);

            foreach ($matches[1] as $td) {
                $result = $this->inspectCellForBadgePill($td);
                if ($result['hasManualPill']) {
                    $violations[] = "{$view}: contains manual badge pill in <td>: " . trim(substr($td, 0, 100));
                }
            }
        }

        $this->assertSame([], $violations, 'Manual badge pills found in <td> cells: ' . implode("\n", $violations));
    }

    /**
     * Test that ZERO <td> cells in any table across the ENTIRE application contain badge pills.
     */
    public function test_entire_application_table_views_have_zero_badge_pills_in_table_cells(): void
    {
        $finder = new Finder();
        $finder->files()->in(resource_path('views'))->name('*.blade.php');

        $violations = [];

        foreach ($finder as $file) {
            $relPath = str_replace(resource_path('views') . '/', '', $file->getRealPath());
            $content = $file->getContents();

            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/si', $content, $matches);

            foreach ($matches[1] as $td) {
                $result = $this->inspectCellForBadgePill($td);
                if ($result['hasBadgeComponent'] || $result['hasManualPill']) {
                    $type = $result['hasBadgeComponent'] ? 'badge_component' : 'manual_pill';
                    $violations[] = "[{$type}] {$relPath}: " . trim(substr($td, 0, 100));
                }
            }
        }

        $this->assertSame([], $violations, 'Badge pills found in table <td> cells across application: ' . implode("\n", $violations));
    }

    /**
     * Test that target table views adopt canonical subtle dot indicators for operational statuses.
     */
    public function test_target_views_use_canonical_subtle_dot_indicators_for_operational_statuses(): void
    {
        $viewsToCheck = [
            'brands/index.blade.php',
            'categories/index.blade.php',
            'stores/index.blade.php',
            'devices/index.blade.php',
            'roles/index.blade.php',
            'tables/index.blade.php',
            'users/index.blade.php',
            'livewire/sales/sales-order-manager.blade.php',
            'livewire/sales/quotation-manager.blade.php',
            'livewire/purchasing/purchase-order-manager.blade.php',
            'livewire/inventory/goods-receipt-manager.blade.php',
            'livewire/hr/attendance-monitor.blade.php',
            'livewire/hr/payroll-batch-manager.blade.php',
        ];

        foreach ($viewsToCheck as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);

            $this->assertMatchesRegularExpression(
                '/w-1\.5\s+h-1\.5\s+rounded-full/',
                $content,
                "View {$view} should adopt canonical w-1.5 h-1.5 rounded-full status dot indicator."
            );
        }
    }

    /**
     * Test that all 49 target views have ZERO <x-ui.badge> or <x-badge> in the entire file.
     */
    public function test_all_49_target_views_have_zero_badge_components_in_entire_file(): void
    {
        $targetViews = $this->getTargetViews();
        $violations = [];

        foreach ($targetViews as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);

            if (str_contains($content, '<x-ui.badge') || str_contains($content, '<x-badge')) {
                $violations[] = "{$view} still contains badge component.";
            }
        }

        $this->assertSame([], $violations, 'Badge components found in target views: ' . implode("\n", $violations));
    }

    /**
     * Test that responsive mobile card lists (lg:hidden) have ZERO badge components or pills.
     */
    public function test_mobile_responsive_card_views_have_zero_badge_components_and_pills(): void
    {
        $viewsWithMobileList = [
            'users/index.blade.php',
            'roles/index.blade.php',
            'tables/index.blade.php',
            'categories/index.blade.php',
            'livewire/inventory/ingredient-manager.blade.php',
            'livewire/kasir/order-history.blade.php',
        ];

        $violations = [];

        foreach ($viewsWithMobileList as $view) {
            $path = resource_path('views/' . $view);
            $content = File::get($path);

            preg_match('/<div\s+class="[^"]*lg:hidden[^"]*"[^>]*>(.*?)<\/div>\s*@(?:endforelse|endif)/s', $content, $match);
            if (! empty($match[1])) {
                $section = $match[1];
                if (str_contains($section, '<x-ui.badge') || str_contains($section, '<x-badge')) {
                    $violations[] = "{$view}: mobile view contains <x-ui.badge>";
                }
            }
        }

        $this->assertSame([], $violations, 'Badge components found in mobile views: ' . implode("\n", $violations));
    }
}
