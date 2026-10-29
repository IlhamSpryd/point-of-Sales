<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\AccountingPeriod;
use App\Models\Category;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\GiftCard;
use App\Models\GoodsReceivedNote;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockOpname;
use App\Models\StoreCreditAccount;
use App\Models\Supplier;
use App\Policies\AccountingPeriodPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\ChartOfAccountPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\GiftCardPolicy;
use App\Policies\GrnPolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\ShiftPolicy;
use App\Policies\StockOpnamePolicy;
use App\Policies\StoreCreditPolicy;
use App\Policies\SupplierPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyDiscoveryTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    public function test_gate_resolves_identical_policies_for_app_models_and_module_models(): void
    {
        $pairs = [
            [Product::class, \App\Modules\Products\Models\Product::class, ProductPolicy::class],
            [Category::class, \App\Modules\Products\Models\Category::class, CategoryPolicy::class],
            [Order::class, \App\Modules\Sales\Models\Order::class, OrderPolicy::class],
            [Shift::class, \App\Modules\Sales\Models\Shift::class, ShiftPolicy::class],
            [StockOpname::class, \App\Modules\Inventory\Models\StockOpname::class, StockOpnamePolicy::class],
            [Supplier::class, \App\Modules\Purchasing\Models\Supplier::class, SupplierPolicy::class],
            [GoodsReceivedNote::class, \App\Modules\Purchasing\Models\GoodsReceivedNote::class, GrnPolicy::class],
            [Customer::class, \App\Modules\Customers\Models\Customer::class, CustomerPolicy::class],
            [CustomerGroup::class, \App\Modules\Customers\Models\CustomerGroup::class, CustomerPolicy::class],
            [GiftCard::class, \App\Modules\Customers\Models\GiftCard::class, GiftCardPolicy::class],
            [StoreCreditAccount::class, \App\Modules\Customers\Models\StoreCreditAccount::class, StoreCreditPolicy::class],
            [ChartOfAccount::class, \App\Modules\Finance\Models\ChartOfAccount::class, ChartOfAccountPolicy::class],
            [AccountingPeriod::class, \App\Modules\Finance\Models\AccountingPeriod::class, AccountingPeriodPolicy::class],
            [JournalEntry::class, \App\Modules\Finance\Models\JournalEntry::class, JournalEntryPolicy::class],
        ];

        foreach ($pairs as [$wrapperClass, $moduleClass, $expectedPolicyClass]) {
            $wrapperPolicy = Gate::getPolicyFor($wrapperClass);
            $modulePolicy = Gate::getPolicyFor($moduleClass);

            $this->assertNotNull($wrapperPolicy, "Policy missing for {$wrapperClass}");
            $this->assertNotNull($modulePolicy, "Policy missing for {$moduleClass}");
            $this->assertInstanceOf($expectedPolicyClass, $wrapperPolicy);
            $this->assertInstanceOf($expectedPolicyClass, $modulePolicy);
        }
    }
}
