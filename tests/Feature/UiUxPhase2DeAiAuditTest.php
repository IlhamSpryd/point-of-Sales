<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UiUxPhase2DeAiAuditTest extends TestCase
{
    public function test_dashboard_blade_eliminates_mockup_svgs_tooltips_and_heading_icons(): void
    {
        $dashboardPath = resource_path('views/dashboard.blade.php');
        $this->assertFileExists($dashboardPath);
        $content = File::get($dashboardPath);

        // 1. Remove fake hardcoded SVG Bezier curves and dummy marker dots
        $this->assertStringNotContainsString('logisticsGradient', $content);
        $this->assertStringNotContainsString('M 0,85 Q 50,40', $content);
        $this->assertStringNotContainsString('circle cx="200" cy="35"', $content);
        $this->assertStringNotContainsString('channelsGradient', $content);

        // 2. Contains real-data progress bars & tabular breakdown
        $this->assertStringContainsString('Stock Health Index', $content);
        $this->assertStringContainsString('Safe Stock', $content);
        $this->assertStringContainsString('Critical / Low', $content);
        $this->assertStringContainsString('$paymentBreakdown', $content);
        $this->assertStringContainsString('QRIS', $content);
        $this->assertStringContainsString('Cash', $content);
        $this->assertStringContainsString('E-Wallet', $content);
        $this->assertStringContainsString('Card', $content);

        // 3. Remove static speech bubble tooltip and unused dead Alpine properties
        $this->assertStringNotContainsString('tooltipLeftPercent', $content);
        $this->assertStringNotContainsString('border-t-[7px] border-t-border', $content);

        // 4. Section headings have zero decorative icons
        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*Recent Orders\s*<\/h2>/', $content);
        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*Restock Forecast/', $content);
        $this->assertDoesNotMatchRegularExpression('/<h2[^>]*>[\s\S]*?(?:receipt_long|warehouse)[\s\S]*?<\/h2>/', $content);
    }

    public function test_finance_blade_eliminates_scaffolding_raw_zinc_and_heading_icons(): void
    {
        $financePath = resource_path('views/finance/index.blade.php');
        $this->assertFileExists($financePath);
        $content = File::get($financePath);

        // 1. Remove "Modul 7" badge from header
        $this->assertStringNotContainsString('badge="Modul 7"', $content);
        $this->assertStringNotContainsString('Modul 7', $content);

        // 2. Remove technical developer scaffolding outbox card
        $this->assertStringNotContainsString('accounting_payable_outbox', $content);
        $this->assertStringNotContainsString('accounting_receivable_outbox', $content);
        $this->assertStringNotContainsString('Financial Accounting & Reconciliation Center', $content);

        // 3. Convert all raw zinc-* classes to canonical design tokens
        $this->assertDoesNotMatchRegularExpression('/(?:bg|text|border|ring|divide)-zinc-\d+/', $content);

        // 4. Remove decorative icons from section headings
        $this->assertDoesNotMatchRegularExpression('/<h3[^>]*>[\s\S]*?(?:receipt_long|payments)[\s\S]*?<\/h3>/', $content);
    }

    public function test_settings_blade_eliminates_raw_colors_and_heading_icons(): void
    {
        $settingsPath = resource_path('views/settings/index.blade.php');
        $this->assertFileExists($settingsPath);
        $content = File::get($settingsPath);

        // 1. Convert all raw zinc-* classes to canonical design tokens
        $this->assertDoesNotMatchRegularExpression('/(?:bg|text|border|ring|divide)-zinc-\d+/', $content);

        // 2. Remove decorative icons from section headings
        $this->assertDoesNotMatchRegularExpression('/<h2[^>]*>[\s\S]*?calculate[\s\S]*?<\/h2>/', $content);
        $this->assertMatchesRegularExpression('/<h2[^>]*>\s*Tax & Cashier Rounding Rules\s*<\/h2>/', $content);
    }
}
