<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerGroup;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Pricing\Models\GiftCard;
use App\Modules\Pricing\Models\StoreCreditAccount;
use App\Modules\Purchasing\Models\GoodsReceivedNote;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\Shift;
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

    public function test_gate_resolves_policies_for_canonical_modular_models(): void
    {
        $models = [
            [Product::class, ProductPolicy::class],
            [Category::class, CategoryPolicy::class],
            [Order::class, OrderPolicy::class],
            [Shift::class, ShiftPolicy::class],
            [StockOpname::class, StockOpnamePolicy::class],
            [Supplier::class, SupplierPolicy::class],
            [GoodsReceivedNote::class, GrnPolicy::class],
            [Customer::class, CustomerPolicy::class],
            [CustomerGroup::class, CustomerPolicy::class],
            [GiftCard::class, GiftCardPolicy::class],
            [StoreCreditAccount::class, StoreCreditPolicy::class],
            [ChartOfAccount::class, ChartOfAccountPolicy::class],
            [AccountingPeriod::class, AccountingPeriodPolicy::class],
            [JournalEntry::class, JournalEntryPolicy::class],
        ];

        foreach ($models as [$modelClass, $expectedPolicyClass]) {
            $policy = Gate::getPolicyFor($modelClass);

            $this->assertNotNull($policy, "Policy missing for {$modelClass}");
            $this->assertInstanceOf($expectedPolicyClass, $policy);
        }
    }
}
