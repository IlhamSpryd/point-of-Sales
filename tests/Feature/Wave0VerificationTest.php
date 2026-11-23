<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class Wave0VerificationTest extends TestCase
{
    public function test_alert_component_escapes_html_description_for_xss_prevention(): void
    {
        $payload = '<script>alert("xss-test")</script>';
        $html = Blade::render('<x-ui.alert :description="$payload" />', ['payload' => $payload]);

        $this->assertStringNotContainsString('<script>alert("xss-test")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss-test&quot;)&lt;/script&gt;', $html);
    }

    public function test_modal_component_uses_safe_clean_wire_model(): void
    {
        $html = Blade::render('<x-ui.modal wire:model="isOpen" id="test-modal"><div>Modal content</div></x-ui.modal>');

        $this->assertStringContainsString("entangle('isOpen', true)", $html);
        $this->assertStringNotContainsString('{!! $openExpression !!}', $html);
    }

    public function test_searchable_select_uses_safe_binding(): void
    {
        $options = [['value' => '1', 'label' => 'Item 1']];
        $html = Blade::render('<x-erp.searchable-select id="select-test" wire:model="selectedItem" :options="$options" />', [
            'options' => $options,
        ]);

        $this->assertStringContainsString("entangle('selectedItem')", $html);
        $this->assertStringNotContainsString('{!! $valueExpression !!}', $html);
    }

    public function test_slide_over_uses_safe_binding(): void
    {
        $html = Blade::render('<x-erp.slide-over id="slide-test" wire:model="showPanel"><div>Content</div></x-erp.slide-over>');

        $this->assertStringContainsString("entangle('showPanel')", $html);
        $this->assertStringNotContainsString('{!! $openExpr !!}', $html);
    }

    public function test_tokens_css_contains_wcag_aaa_contrast_ratios(): void
    {
        $css = File::get(resource_path('css/tokens.css'));

        // Light AAA tokens (Wave 5 calibrated)
        $this->assertStringContainsString('--muted-foreground: #4A4A52;', $css);
        $this->assertStringContainsString('--shell-foreground: #4A4A52;', $css);
        $this->assertStringContainsString('--border-input: #8E8E98;', $css);
        $this->assertStringContainsString('--ring: #0D0D0D;', $css);
        $this->assertStringContainsString('--success: #14532D;', $css);
        $this->assertStringContainsString('--warning: #78350F;', $css);
        $this->assertStringContainsString('--destructive: #991B1B;', $css);
        $this->assertStringContainsString('--info: #1E40AF;', $css);
        $this->assertStringContainsString('--on-positive: #FFFFFF;', $css);
        $this->assertStringContainsString('--on-warning: #FFFFFF;', $css);

        // Dark AAA tokens (Wave 5 calibrated)
        $this->assertStringContainsString('--destructive: #F89B9B;', $css);
        $this->assertStringContainsString('--border-input: rgba(255, 255, 255, 0.38);', $css);
        $this->assertStringContainsString('--on-positive: #052E16;', $css);
        $this->assertStringContainsString('--on-warning: #271200;', $css);

        // Wave 1 preparation tokens
        $this->assertStringContainsString('--surface-shell:', $css);
        $this->assertStringContainsString('--surface-canvas:', $css);
        $this->assertStringContainsString('--chart-accent: #0F766E;', $css);
    }

    public function test_button_link_variant_has_focus_visible_ring(): void
    {
        $html = Blade::render('<x-ui.button variant="link">Klik Saya</x-ui.button>');

        $this->assertStringContainsString('focus-visible:ring-2', $html);
        $this->assertStringContainsString('focus-visible:ring-ring', $html);
    }

    public function test_pos_offline_queue_enforces_strict_tenant_isolation(): void
    {
        $js = File::get(public_path('js/pos-offline-queue.js'));

        $this->assertStringContainsString('apeiron_pos_tenant_${tenantId}', $js);
        $this->assertStringContainsString('p.tenant_id && String(p.tenant_id) === String(tenantId)', $js);
        $this->assertStringNotContainsString('!p.tenant_id ||', $js);
        $this->assertStringContainsString('closeDatabase()', $js);
    }

    public function test_sw_precache_has_no_404_assets(): void
    {
        $sw = File::get(public_path('sw.js'));

        $this->assertStringNotContainsString("'/css/app.css'", $sw);
        $this->assertStringNotContainsString("'/js/app.js'", $sw);
        $this->assertStringContainsString("'/offline.html'", $sw);
    }

    public function test_manifest_json_is_valid(): void
    {
        $manifestPath = public_path('manifest.json');
        $this->assertFileExists($manifestPath);

        $json = json_decode(File::get($manifestPath), true);
        $this->assertIsArray($json);
        $this->assertSame('standalone', $json['display']);
        $this->assertSame('/transaction', $json['start_url']);
        $this->assertNotEmpty($json['icons']);
    }

    public function test_create_order_view_has_valid_syntax_and_guards_inputs(): void
    {
        $blade = File::get(resource_path('views/livewire/kasir/create-order.blade.php'));

        $this->assertStringNotContainsString('@keydown.window."+"', $blade);
        $this->assertStringNotContainsString('@keydown.window."="', $blade);
        $this->assertStringNotContainsString('@keydown.window."-"', $blade);
        $this->assertStringContainsString("['INPUT', 'TEXTAREA', 'SELECT'].includes", $blade);
        $this->assertStringContainsString('removeEventListener(\'keydown\', barcodeKeyHandler)', $blade);
    }

    public function test_wire_keys_exist_on_all_required_loops(): void
    {
        $files = [
            resource_path('views/livewire/finance/journal-entry-manager.blade.php') => 'journal-row-',
            resource_path('views/livewire/customers/customer-manager.blade.php') => 'customer-row-',
            resource_path('views/livewire/hr/employee-manager.blade.php') => 'employee-row-',
            resource_path('views/livewire/sales/sales-order-manager.blade.php') => 'so-row-',
            resource_path('views/livewire/purchasing/purchase-order-manager.blade.php') => 'po-row-',
            resource_path('views/livewire/kasir/order-history.blade.php') => 'order-',
        ];

        foreach ($files as $file => $expectedKeyPrefix) {
            $content = File::get($file);
            $this->assertStringContainsString('wire:key="'.$expectedKeyPrefix, $content, "Missing {$expectedKeyPrefix} in {$file}");
        }
    }

    public function test_rel_noopener_noreferrer_on_target_blank(): void
    {
        $orderShow = File::get(resource_path('views/orders/show.blade.php'));
        $shelfLabels = File::get(resource_path('views/livewire/retail/shelf-label-manager.blade.php'));

        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $orderShow);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $shelfLabels);
    }
}
