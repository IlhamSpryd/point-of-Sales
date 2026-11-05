<?php

declare(strict_types=1);

namespace Tests\Feature\Color;

use App\Enums\OrderStatus;
use App\Enums\PoInvoicingStatusEnum;
use App\Enums\PoReceivingStatusEnum;
use App\Enums\PurchaseOrderStatusEnum;
use App\Support\Color\StatusPalette;
use App\Support\Color\StatusVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locks the single status -> colour authority (docs/ui-ux-audit/color-system 06 + AC-S).
 */
final class StatusPaletteTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function canonicalStatuses(): array
    {
        return [
            ['paid', 'success'],
            ['completed', 'success'],
            ['approved', 'success'],
            ['posted', 'success'],
            ['received', 'success'],
            ['pending', 'warning'],
            ['pending_approval', 'warning'],
            ['partially_paid', 'warning'],
            ['unpaid', 'warning'],
            ['overdue', 'warning'],
            ['cancelled', 'destructive'],
            ['void', 'destructive'],
            ['rejected', 'destructive'],
            ['failed', 'destructive'],
            ['expired', 'destructive'],
            ['out_of_stock', 'destructive'],
            ['issued', 'info'],
            ['released', 'info'],
            ['sent', 'info'],
            ['draft', 'neutral'],
            ['closed', 'neutral'],
            ['refunded', 'neutral'],
            ['reversed', 'neutral'],
        ];
    }

    #[DataProvider('canonicalStatuses')]
    public function test_resolves_one_canonical_variant_per_status(string $status, string $expected): void
    {
        $this->assertSame($expected, StatusPalette::variant($status)->value);
    }

    public function test_approved_is_success_not_info(): void
    {
        // Regression guard: PurchaseOrderStatusEnum::badgeClass() painted Approved blue.
        $this->assertSame(StatusVariant::Success, StatusPalette::variant(PurchaseOrderStatusEnum::Approved));
        $this->assertSame('success', PurchaseOrderStatusEnum::Approved->badgeType());
    }

    public function test_accepts_enums_instances_and_case_variations(): void
    {
        $this->assertSame(StatusVariant::Success, StatusPalette::variant(OrderStatus::Paid));
        $this->assertSame(StatusVariant::Success, StatusPalette::variant('PAID'));
        $this->assertSame(StatusVariant::Warning, StatusPalette::variant('  partially-paid  '));
    }

    public function test_unknown_status_falls_back_to_neutral_instead_of_throwing(): void
    {
        $this->assertSame(StatusVariant::Neutral, StatusPalette::variant('some_new_status'));
        $this->assertSame(StatusVariant::Neutral, StatusPalette::variant(null));
        $this->assertSame(StatusVariant::Info, StatusPalette::variant(null, StatusVariant::Info));
    }

    public function test_emits_only_semantic_tokens_never_raw_palette(): void
    {
        $variants = ['success', 'warning', 'destructive', 'info', 'neutral'];

        $rawPalette = '/\b(?:bg|text|border)-(?:slate|gray|zinc|neutral-|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/';

        foreach ($variants as $variant) {
            $classes = StatusPalette::badgeClasses($variant);

            $this->assertDoesNotMatchRegularExpression($rawPalette, $classes, "badgeClasses({$variant}) leaked a raw palette colour");
            $this->assertStringNotContainsString('dark:', $classes);
            $this->assertStringContainsString('bg-', $classes);
            $this->assertStringContainsString('border-', $classes);
        }
    }

    public function test_text_and_dot_variants_are_token_based(): void
    {
        $this->assertSame('text-success', StatusPalette::textClasses('success'));
        $this->assertSame('bg-destructive', StatusPalette::dotClasses('destructive'));
        // Legacy aliases must keep working for existing call sites.
        $this->assertSame('bg-destructive-muted text-destructive border-destructive-border', StatusPalette::badgeClasses('danger'));
    }

    public function test_po_enums_no_longer_expose_badge_class(): void
    {
        foreach ([PurchaseOrderStatusEnum::class, PoReceivingStatusEnum::class, PoInvoicingStatusEnum::class] as $enum) {
            $this->assertFalse(method_exists($enum, 'badgeClass'), "{$enum} still exposes badgeClass()");
        }
    }
}
