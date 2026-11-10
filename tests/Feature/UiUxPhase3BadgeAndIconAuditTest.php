<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UiUxPhase3BadgeAndIconAuditTest extends TestCase
{
    public function test_products_index_converts_type_to_plain_text_and_status_to_subtle_dot(): void
    {
        $path = resource_path('views/products/index.blade.php');
        $this->assertFileExists($path);
        $content = File::get($path);

        // 1. Product type is plain text (not wrapped in badge pill)
        $this->assertStringContainsString('class="text-xs text-muted-foreground font-medium"', $content);
        $this->assertStringNotContainsString('<x-ui.badge :variant="$typeBadges[$prodType][\'type\']', $content);

        // 2. Product status uses subtle dot indicator
        $this->assertMatchesRegularExpression('/<span[^>]*w-1\.5\s+h-1\.5\s+rounded-full[^>]*><\/span>\s*\{\{\s*\$statusBadges\[\$prodStatus\]\[\'label\'\]/', $content);
        $this->assertStringNotContainsString('<x-ui.badge :variant="$statusBadges[$prodStatus][\'type\']', $content);
    }

    public function test_order_history_converts_payment_method_and_status_to_subtle_indicators(): void
    {
        $path = resource_path('views/livewire/kasir/order-history.blade.php');
        $this->assertFileExists($path);
        $content = File::get($path);

        // 1. Payment method is plain text without gray pill box
        $this->assertStringContainsString('class="text-xs text-foreground font-medium"', $content);
        $this->assertStringNotContainsString('bg-gray-100 border border-border rounded-lg px-2 py-0.5', $content);

        // 2. Order status uses subtle dot indicators
        $this->assertMatchesRegularExpression('/<span[^>]*w-1\.5\s+h-1\.5\s+rounded-full[^>]*><\/span>\s*\{\{\s*\$order->order_status->label\(\)\s*\}\}/', $content);

        // 3. Modal titles do not have decorative icons
        preg_match('/<h3 id="return-modal-title"[^>]*>([\s\S]*?)<\/h3>/', $content, $returnMatch);
        $this->assertNotEmpty($returnMatch);
        $this->assertStringNotContainsString('material-symbols', $returnMatch[1]);

        preg_match('/<h3 id="reprint-modal-title"[^>]*>([\s\S]*?)<\/h3>/', $content, $reprintMatch);
        $this->assertNotEmpty($reprintMatch);
        $this->assertStringNotContainsString('material-symbols', $reprintMatch[1]);

        preg_match('/<h3 id="void-modal-title"[^>]*>([\s\S]*?)<\/h3>/', $content, $voidMatch);
        $this->assertNotEmpty($voidMatch);
        $this->assertStringNotContainsString('material-symbols', $voidMatch[1]);
        $this->assertStringNotContainsString('<span class="material-symbols-rounded text-[24px]">cancel</span>', $content);
    }

    public function test_employee_manager_converts_employment_type_to_plain_text_and_status_to_dot(): void
    {
        $path = resource_path('views/livewire/hr/employee-manager.blade.php');
        $this->assertFileExists($path);
        $content = File::get($path);

        // 1. Employment type is plain text (not secondary badge)
        $this->assertMatchesRegularExpression('/<span class="text-xs text-muted-foreground font-medium">\s*\{\{\s*\$emp->employment_type->label\(\)\s*\}\}\s*<\/span>/', $content);
        $this->assertStringNotContainsString('<x-ui.badge variant="secondary" size="sm">', $content);

        // 2. Employment status uses subtle dot
        $this->assertStringContainsString('rounded-full bg-success', $content);
        $this->assertStringContainsString('rounded-full bg-destructive', $content);
    }

    public function test_product_grid_uses_integrated_text_counters_for_categories(): void
    {
        $path = resource_path('views/livewire/pos/partials/product-grid.blade.php');
        $this->assertFileExists($path);
        $content = File::get($path);

        // 1. Category counters are integrated text counters e.g. (24)
        $this->assertStringContainsString('({{ $this->categories->sum(fn($cat) => $cat->products->count()) }})', $content);
        $this->assertStringContainsString('({{ $category->products->count() }})', $content);

        // 2. Floating badge pills are eliminated from category buttons
        $this->assertStringNotContainsString('px-1.5 py-0.5 rounded text-xs font-medium tabular-nums transition-colors', $content);
    }

    public function test_module_sub_nav_renders_text_only_tabs(): void
    {
        $path = resource_path('views/components/erp/module-sub-nav.blade.php');
        $this->assertFileExists($path);
        $content = File::get($path);

        // Icons are completely removed from horizontal sub-nav tabs
        $this->assertStringNotContainsString('$tab[\'icon\']', $content);
        $this->assertStringNotContainsString('material-symbols', $content);
    }

    public function test_heading_icon_rationalization_across_views(): void
    {
        $viewsToCheck = [
            'views/livewire/channel-mapping-manager.blade.php',
            'views/livewire/shift-manager.blade.php',
            'views/livewire/subscription/subscription-portal.blade.php',
            'views/livewire/retail/retail-exchange-manager.blade.php',
            'views/livewire/retail/barcode-manager.blade.php',
            'views/livewire/retail/shelf-label-manager.blade.php',
            'views/livewire/finance/bank-reconciliation-workbench.blade.php',
            'views/livewire/sales/accounts-receivable-manager.blade.php',
            'views/livewire/pricing/promotion-campaign-manager.blade.php',
            'views/livewire/pricing/voucher-manager.blade.php',
            'views/livewire/pos/partials/kiosk-attendance-modal.blade.php',
            'views/livewire/kds/board.blade.php',
            'views/livewire/manufacturing/manufacturing-order-manager.blade.php',
            'views/components/erp/hr/payslip.blade.php',
            'views/livewire/pos/partials/parked-list-modal.blade.php',
            'views/livewire/pos/partials/park-modal.blade.php',
            'views/livewire/pos/partials/checkout-modal.blade.php',
            'views/livewire/pos/partials/manual-discount-modal.blade.php',
            'views/livewire/pos/partials/mobile-cart-drawer.blade.php',
            'views/livewire/inventory/stock-opname-manager.blade.php',
            'views/livewire/finance/journal-entry-manager.blade.php',
            'views/livewire/finance/accounting-period-manager.blade.php',
        ];

        foreach ($viewsToCheck as $relPath) {
            $path = resource_path($relPath);
            $this->assertFileExists($path);
            $content = File::get($path);

            preg_match_all('/<h[1-6][^>]*>[\s\S]*?<\/h[1-6]>/i', $content, $matches);
            foreach ($matches[0] as $heading) {
                $this->assertStringNotContainsString(
                    'material-symbols',
                    $heading,
                    "Found decorative icon in heading in {$relPath}: {$heading}"
                );
            }
        }
    }

    public function test_icon_sizes_standardized_to_canonical_scale(): void
    {
        $viewsPath = resource_path('views');
        $files = File::allFiles($viewsPath);

        $violations = [];
        $adHocSizes = ['13px', '15px', '17px', '18px', '19px', '21px', '22px', '23px', '25px', '26px', '27px'];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'blade.php') {
                continue;
            }

            $content = $file->getContents();
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                if (str_contains($line, 'material-symbols')) {
                    foreach ($adHocSizes as $sz) {
                        if (str_contains($line, "text-[{$sz}]")) {
                            $violations[] = "{$file->getRelativePathname()}:" . ($lineNum + 1) . " has text-[{$sz}]";
                        }
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Non-canonical icon sizes found:\n" . implode("\n", array_slice($violations, 0, 10)));
    }
}
