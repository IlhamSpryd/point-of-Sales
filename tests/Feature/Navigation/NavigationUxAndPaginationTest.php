<?php

declare(strict_types=1);

namespace Tests\Feature\Navigation;

use App\Livewire\Customers\CustomerCreditManager;
use App\Livewire\Customers\CustomerGroupManager;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Customers\LoyaltyAndValueInstrumentManager;
use App\Livewire\DiscountManager;
use App\Livewire\Inventory\GoodsReceiptManager;
use App\Livewire\Inventory\IngredientLedger;
use App\Livewire\Inventory\IngredientManager;
use App\Livewire\Inventory\StockAdjustmentManager;
use App\Livewire\Inventory\StockOpnameManager;
use App\Livewire\Inventory\StockTransferManager;
use App\Livewire\Inventory\SupplierReturnManager;
use App\Livewire\Purchasing\GoodsReceivedNoteManager;
use App\Livewire\Purchasing\PurchaseOrderManager;
use App\Livewire\Purchasing\PurchaseReturnManager;
use App\Livewire\Purchasing\SupplierInvoiceManager;
use App\Livewire\Purchasing\SupplierManager;
use App\Livewire\Sales\AccountsReceivableManager;
use App\Livewire\Sales\QuotationManager;
use App\Livewire\Sales\ReturnCreditNoteManager;
use App\Livewire\Sales\SalesOrderManager;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class NavigationUxAndPaginationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

    private Tenant $tenant;

    private Store $store;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create([
            'name' => 'Nav Test Tenant',
            'slug' => 'nav-test-tenant',
            'status' => 'active',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
            'access_status' => Tenant::ACCESS_ACTIVE,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nav Test Store',
            'is_active' => true,
        ]);

        $adminRole = Role::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Owner',
            'is_admin' => true,
            'permissions' => ['*'],
        ]);

        $this->adminUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'role_id' => $adminRole->id,
        ]);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);
        session(['active_store_id' => $this->store->id]);
    }

    public function test_sidebar_has_dedicated_scroll_container_and_contract_attributes(): void
    {
        $response = $this->actingAs($this->adminUser)->get(route('dashboard'));
        $response->assertOk();

        // 1. Sidebar dedicated scroll container exists
        $response->assertSee('id="sidebar-nav-container"', false);

        // 2. Main content has targetable id for independent scrolling
        $response->assertSee('id="main-content"', false);

        // 3. Navigation progress bar indicator exists
        $response->assertSee('navProgress', false);
        $response->assertSee('navigating', false);

        // 4. Mobile drawer background scroll lock is wired
        $response->assertSee('sidebarMobileOpen', false);
    }

    public function test_pagination_views_scroll_to_main_content_not_body(): void
    {
        $tailwindPagination = file_get_contents(resource_path('views/vendor/livewire/tailwind.blade.php'));
        $simpleTailwindPagination = file_get_contents(resource_path('views/vendor/livewire/simple-tailwind.blade.php'));

        $this->assertStringContainsString('$scrollTo = \'#main-content\'', $tailwindPagination);
        $this->assertStringContainsString('mainTarget.scrollTo({ top: 0, behavior: \'smooth\' })', $tailwindPagination);
        $this->assertStringNotContainsString("document.querySelector('body').scrollIntoView()", $tailwindPagination);

        $this->assertStringContainsString('$scrollTo = \'#main-content\'', $simpleTailwindPagination);
        $this->assertStringContainsString('mainTarget.scrollTo({ top: 0, behavior: \'smooth\' })', $simpleTailwindPagination);
        $this->assertStringNotContainsString("(\$el.closest('body') || document.querySelector('body')).scrollIntoView()", $simpleTailwindPagination);
    }

    public function test_sidebar_persistence_script_is_registered_in_app_js(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import { initSidebarPersistence } from './navigation/sidebar-persistence'", $appJs);
        $this->assertStringContainsString('initSidebarPersistence()', $appJs);

        $persistenceScript = file_get_contents(resource_path('js/navigation/sidebar-persistence.js'));
        $this->assertStringContainsString("STORAGE_KEY = 'apeiron_sidebar_scroll_top'", $persistenceScript);
        $this->assertStringContainsString('sidebar-nav-container', $persistenceScript);
    }

    public function test_all_paginated_components_have_url_attribute_for_search_or_filter_properties(): void
    {
        $componentsWithSearch = [
            QuotationManager::class => ['search', 'statusFilter'],
            SalesOrderManager::class => ['search', 'statusFilter', 'fulfillmentFilter'],
            AccountsReceivableManager::class => ['activeTab', 'search', 'statusFilter'],
            ReturnCreditNoteManager::class => ['activeTab', 'search', 'statusFilter'],
            PurchaseOrderManager::class => ['search', 'statusFilter', 'supplierFilter'],
            GoodsReceivedNoteManager::class => ['search', 'statusFilter'],
            SupplierInvoiceManager::class => ['search', 'statusFilter', 'supplierFilter'],
            PurchaseReturnManager::class => ['search', 'statusFilter', 'supplierFilter'],
            SupplierManager::class => ['search', 'statusFilter'],
            CustomerCreditManager::class => ['search', 'statusFilter'],
            CustomerGroupManager::class => ['search'],
            CustomerManager::class => ['search', 'typeFilter', 'statusFilter', 'groupFilter'],
            LoyaltyAndValueInstrumentManager::class => ['activeTab', 'search'],
            DiscountManager::class => ['search'],
            StockAdjustmentManager::class => ['search', 'statusFilter', 'reasonFilter'],
            StockTransferManager::class => ['search', 'statusFilter', 'directionFilter'],
            GoodsReceiptManager::class => ['search', 'statusFilter'],
            SupplierReturnManager::class => ['search', 'statusFilter'],
            StockOpnameManager::class => ['search', 'statusFilter'],
            IngredientManager::class => ['search'],
            IngredientLedger::class => ['ingredient_id', 'type'],
        ];

        foreach ($componentsWithSearch as $componentClass => $properties) {
            $reflector = new ReflectionClass($componentClass);

            foreach ($properties as $property) {
                $this->assertTrue(
                    $reflector->hasProperty($property),
                    "Component {$componentClass} is missing property {$property}"
                );

                $prop = $reflector->getProperty($property);
                $urlAttributes = $prop->getAttributes(Url::class);

                $this->assertNotEmpty(
                    $urlAttributes,
                    "Property {$property} in {$componentClass} must have #[Url] attribute"
                );
            }
        }
    }

    public function test_search_and_filter_updates_reset_page_in_sales_order_manager(): void
    {
        $this->actingAs($this->adminUser);

        $component = app(SalesOrderManager::class);
        $component->setPage(2);
        $this->assertSame(2, $component->getPage());

        $component->updatedSearch();
        $this->assertSame(1, $component->getPage());

        $component->setPage(3);
        $component->updatedStatusFilter();
        $this->assertSame(1, $component->getPage());

        $component->setPage(4);
        $component->updatedFulfillmentFilter();
        $this->assertSame(1, $component->getPage());
    }

    public function test_search_and_filter_updates_reset_page_in_quotation_manager(): void
    {
        $this->actingAs($this->adminUser);

        $component = app(QuotationManager::class);
        $component->setPage(2);
        $this->assertSame(2, $component->getPage());

        $component->updatedSearch();
        $this->assertSame(1, $component->getPage());

        $component->setPage(3);
        $component->updatedStatusFilter();
        $this->assertSame(1, $component->getPage());
    }

    public function test_search_and_filter_updates_reset_page_in_accounts_receivable_manager(): void
    {
        $this->actingAs($this->adminUser);

        $component = app(AccountsReceivableManager::class);
        $component->setPage(2);
        $this->assertSame(2, $component->getPage());

        $component->updatedSearch();
        $this->assertSame(1, $component->getPage());

        $component->setPage(3);
        $component->updatedStatusFilter();
        $this->assertSame(1, $component->getPage());

        $component->setPage(4);
        $component->updatedActiveTab();
        $this->assertSame(1, $component->getPage());
    }
}
