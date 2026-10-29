<?php

declare(strict_types=1);

namespace Tests\Feature\Domain4;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatusEnum;
use App\Enums\PreparationStatus;
use App\Livewire\Kasir\CreateOrder;
use App\Livewire\Kasir\OrderHistory;
use App\Models\Category;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class UiUxDesignSystemAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Store $store;

    protected Role $kasirRole;

    protected User $cashierUser;

    protected Product $product;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Domain 4 Tenant',
            'slug' => 'domain-4-tenant',
            'status' => 'active',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Domain 4 Store',
            'is_active' => true,
        ]);

        $context->setStoreId($this->store->id);

        $this->kasirRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Kasir',
            'permissions' => ['orders.create', 'orders.view', 'can_void_order'],
        ]);

        $this->cashierUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $this->kasirRole->id,
        ]);

        DB::table('user_stores')->insert([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->cashierUser->id,
            'store_id' => $this->store->id,
        ]);

        Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashierUser->id,
            'status' => 'open',
            'opening_balance' => 100000,
            'opened_at' => now(),
        ]);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Minuman',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Espresso Domain 4',
            'product_price' => 20000,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    public function test_cashier_can_increment_and_decrement_cart_line_quantity(): void
    {
        Livewire::actingAs($this->cashierUser)
            ->test(CreateOrder::class)
            ->call('addToCartDirectly', $this->product->id)
            ->assertCount('cart', 1)
            ->call('updateCartQty', 0, 1)
            ->assertSet('cart.0.qty', 2)
            ->call('updateCartQty', 0, -1)
            ->assertSet('cart.0.qty', 1)
            ->call('updateCartQty', 0, -1)
            ->assertCount('cart', 0);
    }

    public function test_modal_component_renders_accessible_dialog_roles(): void
    {
        $rendered = Blade::render(
            '<x-modal name="test-dialog" :show="true"><div>Modal Content</div></x-modal>'
        );

        $this->assertStringContainsString('role="dialog"', $rendered);
        $this->assertStringContainsString('aria-modal="true"', $rendered);
        $this->assertStringContainsString('triggerEl', $rendered);
        $this->assertStringContainsString('keydown.tab.prevent', $rendered);
    }

    public function test_button_and_form_input_primitives_render_consistent_focus_and_disabled_tokens(): void
    {
        // 1. Canonical Button Variants
        $btnPrimary = Blade::render('<x-button variant="primary">Simpan</x-button>');
        $this->assertStringContainsString('bg-apeiron-ink', $btnPrimary);
        $this->assertStringContainsString('focus-visible:ring-apeiron-ink', $btnPrimary);
        $this->assertStringContainsString('disabled:opacity-50', $btnPrimary);

        $btnSecondary = Blade::render('<x-button variant="secondary">Batal</x-button>');
        $this->assertStringContainsString('border-apeiron-border', $btnSecondary);
        $this->assertStringContainsString('focus-visible:ring-apeiron-ink', $btnSecondary);

        $btnDanger = Blade::render('<x-button variant="danger">Hapus</x-button>');
        $this->assertStringContainsString('bg-rose-600', $btnDanger);
        $this->assertStringContainsString('focus-visible:ring-rose-500', $btnDanger);

        // 2. Button Primitive Delegation Parity
        $primBtn = Blade::render('<x-primary-button>Aksi Utama</x-primary-button>');
        $this->assertStringContainsString('bg-apeiron-ink', $primBtn);
        $this->assertStringContainsString('focus-visible:ring-apeiron-ink', $primBtn);

        $secBtn = Blade::render('<x-secondary-button>Aksi Kedua</x-secondary-button>');
        $this->assertStringContainsString('border-apeiron-border', $secBtn);
        $this->assertStringContainsString('focus-visible:ring-apeiron-ink', $secBtn);

        $danBtn = Blade::render('<x-danger-button>Aksi Hapus</x-danger-button>');
        $this->assertStringContainsString('bg-rose-600', $danBtn);
        $this->assertStringContainsString('focus-visible:ring-rose-500', $danBtn);

        // 3. Form Input Primitives Parity
        $formInput = Blade::render('<x-form-input name="test_field" placeholder="Ketik disini" />');
        $this->assertStringContainsString('form-input', $formInput);
        $this->assertStringContainsString('focus:ring-apeiron-ink', $formInput);
        $this->assertStringContainsString('disabled:opacity-50', $formInput);

        // 4. Form Label Primitives Parity
        $formLabel = Blade::render('<x-form-label for="field">Nama Field</x-form-label>');
        $this->assertStringContainsString('text-apeiron-ink', $formLabel);
        $this->assertStringContainsString('for="field"', $formLabel);

        // 5. Redundant component removal verification (D4-001 & T-4.3 Pruning)
        $prunedComponents = [
            'discount-manager.blade.php',
            'application-logo.blade.php',
            'auth-session-status.blade.php',
            'dropdown.blade.php',
            'dropdown-link.blade.php',
            'nav-link.blade.php',
            'responsive-nav-link.blade.php',
            'zinc-button.blade.php',
            'text-input.blade.php',
            'input-label.blade.php',
        ];

        foreach ($prunedComponents as $componentFile) {
            $this->assertFalse(
                File::exists(resource_path("views/components/{$componentFile}")),
                "resources/views/components/{$componentFile} harus sudah dihapus."
            );
        }
    }

    public function test_public_webroot_and_vendor_pagination_hygiene(): void
    {
        // D4-002: spark-admin template and bootstrap pagination removed
        $this->assertFalse(
            File::exists(public_path('spark-admin-1.0.0')),
            'public/spark-admin-1.0.0 directory must not exist in public webroot.'
        );
        $this->assertFalse(
            File::exists(resource_path('views/vendor/livewire/bootstrap.blade.php')),
            'resources/views/vendor/livewire/bootstrap.blade.php must be removed.'
        );
        $this->assertFalse(
            File::exists(resource_path('views/vendor/livewire/simple-bootstrap.blade.php')),
            'resources/views/vendor/livewire/simple-bootstrap.blade.php must be removed.'
        );
        $this->assertTrue(
            File::exists(resource_path('views/vendor/livewire/tailwind.blade.php')),
            'resources/views/vendor/livewire/tailwind.blade.php must be preserved.'
        );
    }

    public function test_public_robots_txt_disallows_crawling(): void
    {
        // D4-005: robots.txt disallows crawler indexing
        $this->assertTrue(File::exists(public_path('robots.txt')));
        $content = File::get(public_path('robots.txt'));
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Disallow: /', $content);
        $this->assertStringNotContainsString("Disallow:\n", $content);
    }

    public function test_order_payment_preparation_and_order_type_status_badge_semantics(): void
    {
        // D4-004: Status badge mapping centralization across Enums
        // OrderStatus
        $this->assertSame('success', OrderStatus::Paid->badgeType());
        $this->assertSame('success', OrderStatus::Completed->badgeType());
        $this->assertSame('warning', OrderStatus::Pending->badgeType());
        $this->assertSame('danger', OrderStatus::Void->badgeType());
        $this->assertSame('danger', OrderStatus::Failed->badgeType());
        $this->assertSame('secondary', OrderStatus::Cancelled->badgeType());
        $this->assertSame('secondary', OrderStatus::Expired->badgeType());

        // PaymentStatusEnum
        $this->assertSame('success', PaymentStatusEnum::Captured->badgeType());
        $this->assertSame('warning', PaymentStatusEnum::Pending->badgeType());
        $this->assertSame('danger', PaymentStatusEnum::Failed->badgeType());
        $this->assertSame('secondary', PaymentStatusEnum::Refunded->badgeType());
        $this->assertSame('secondary', PaymentStatusEnum::Voided->badgeType());

        // PreparationStatus
        $this->assertSame('warning', PreparationStatus::Pending->badgeType());
        $this->assertSame('info', PreparationStatus::Brewing->badgeType());
        $this->assertSame('success', PreparationStatus::Ready->badgeType());

        // OrderType
        $this->assertSame('info', OrderType::DineIn->badgeType());
        $this->assertSame('secondary', OrderType::Takeaway->badgeType());

        // OrderHistory Livewire component parity with OrderStatus::badgeType
        $orderHistory = new OrderHistory;
        foreach (OrderStatus::cases() as $status) {
            $this->assertSame($status->badgeType(), $orderHistory->statusBadgeType($status));
        }

        // x-badge Blade component rendering with Enum instance
        $renderedPaid = Blade::render('<x-badge :status="\App\Enums\OrderStatus::Paid" />');
        $this->assertStringContainsString('bg-success-50', $renderedPaid);
        $this->assertStringContainsString('Lunas', $renderedPaid);

        $renderedPending = Blade::render('<x-badge :status="\App\Enums\OrderStatus::Pending" />');
        $this->assertStringContainsString('bg-warning-50', $renderedPending);
        $this->assertStringContainsString('Menunggu Pembayaran', $renderedPending);

        $renderedVoid = Blade::render('<x-badge :status="\App\Enums\OrderStatus::Void" />');
        $this->assertStringContainsString('bg-danger-50', $renderedVoid);
        $this->assertStringContainsString('Di-void', $renderedVoid);
    }

    public function test_modal_and_toast_and_alert_wcag_a11y_attributes(): void
    {
        // D4-003: WCAG a11y status messages & dialog focus management
        // Toast
        $toast = Blade::render('<x-toast />');
        $this->assertStringContainsString('role="status"', $toast);
        $this->assertStringContainsString('aria-live="polite"', $toast);
        $this->assertStringContainsString('aria-label="Tutup notifikasi"', $toast);

        // Alert
        $successAlert = Blade::render('<x-alert type="success">Data tersimpan</x-alert>');
        $this->assertStringContainsString('role="status"', $successAlert);
        $this->assertStringContainsString('aria-live="polite"', $successAlert);

        $errorAlert = Blade::render('<x-alert type="error">Terjadi kegagalan</x-alert>');
        $this->assertStringContainsString('role="alert"', $errorAlert);
        $this->assertStringContainsString('aria-live="assertive"', $errorAlert);

        // Input error
        $inputError = Blade::render('<x-input-error :messages="[\'Email wajib diisi\']" />');
        $this->assertStringContainsString('role="alert"', $inputError);
        $this->assertStringContainsString('aria-live="assertive"', $inputError);

        // SweetAlert confirm delete focus restoration
        $swalView = File::get(resource_path('views/partials/sweetalert-confirm-delete.blade.php'));
        $this->assertStringContainsString('returnFocus: true', $swalView);
        $this->assertStringContainsString('triggerEl.focus()', $swalView);
    }

    public function test_cashier_pos_out_of_stock_products_disabled_and_checkout_loading_bound(): void
    {
        $createOrderView = File::get(resource_path('views/livewire/kasir/create-order.blade.php'));

        // Checkout button bound with wire:loading.attr="disabled"
        $this->assertStringContainsString('wire:loading.attr="disabled"', $createOrderView);
        $this->assertStringContainsString('wire:target="submitOrder"', $createOrderView);

        // Out of stock product handling in catalog
        $this->assertStringContainsString('isOutOfStock', $createOrderView);
        $this->assertStringContainsString('Stok Habis', $createOrderView);

        // Customer variant modal focus trap & escape handling
        $variantModalView = File::get(resource_path('views/customer/menu/partials/variant-modal.blade.php'));
        $this->assertStringContainsString('keydown.tab.prevent', $variantModalView);
        $this->assertStringContainsString('keydown.escape.window="close()"', $variantModalView);
        $this->assertStringContainsString('triggerEl', $variantModalView);
    }

    public function test_blade_views_contain_wcag_a11y_attributes(): void
    {
        $createOrderView = file_get_contents(resource_path('views/livewire/kasir/create-order.blade.php'));
        $orderHistoryView = file_get_contents(resource_path('views/livewire/kasir/order-history.blade.php'));

        // Catalog cards have keyboard activation listeners
        $this->assertStringContainsString('@keydown.enter=', $createOrderView);
        $this->assertStringContainsString('@keydown.space.prevent=', $createOrderView);
        $this->assertStringContainsString('role="button"', $createOrderView);

        // Cart items have quantity update controls
        $this->assertStringContainsString('updateCartQty(', $createOrderView);

        // Modifier picker has dialog semantics and Escape key handling
        $this->assertStringContainsString('aria-labelledby="modifier-picker-title"', $createOrderView);
        $this->assertStringContainsString('@keydown.escape.window="$wire.closeModifierPicker()"', $createOrderView);

        // Order history has alertdialog for void and dialog for detail
        $this->assertStringContainsString('role="alertdialog"', $orderHistoryView);
        $this->assertStringContainsString('role="dialog"', $orderHistoryView);
        $this->assertStringContainsString('id="void-reason-input"', $orderHistoryView);
        $this->assertStringContainsString('@keydown.escape.stop.prevent=', $orderHistoryView);
    }
}
