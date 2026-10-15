<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Http\Requests\StoreTransactionRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Product;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreTransactionRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;
    protected Product $product;
    protected Customer $customer;
    protected Discount $discount;
    protected Table $table;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Req Tenant',
            'readiness_status' => 'active',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Req Store',
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);

        $category = Category::create([
            'tenant_id' => $this->tenant->id,
            'category_name' => 'Snacks',
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Chips',
            'product_price' => 15000,
            'stock' => 50,
        ]);

        $this->customer = Customer::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'John Doe',
            'phone' => '08123456789',
        ]);

        $this->discount = Discount::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Member Disc',
            'type' => 'percentage',
            'value' => 10,
            'min_purchase_amount' => 10000,
        ]);

        $this->table = Table::create([
            'tenant_id' => $this->tenant->id,
            'table_name' => 'Meja 5',
            'capacity' => 4,
        ]);
    }

    public function test_store_transaction_request_validates_optional_parameters_and_split_payments(): void
    {
        $payload = [
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                ],
            ],
            'payments' => [
                [
                    'method' => 'cash',
                    'amount' => 20000,
                ],
                [
                    'method' => 'qris',
                    'amount' => 13300,
                ],
            ],
            'customer_id' => $this->customer->id,
            'discount_id' => $this->discount->id,
            'table_id' => $this->table->id,
            'order_type' => 'dine_in',
            'idempotency_key' => (string) Str::uuid(),
        ];

        $request = new StoreTransactionRequest();
        $validator = Validator::make($payload, $request->rules());

        $this->assertTrue($validator->passes(), 'Validator must pass with optional checkout parameters and split payments');
        $validated = $validator->validated();

        $this->assertEquals($this->customer->id, $validated['customer_id']);
        $this->assertEquals($this->discount->id, $validated['discount_id']);
        $this->assertEquals($this->table->id, $validated['table_id']);
        $this->assertEquals('dine_in', $validated['order_type']);
        $this->assertCount(2, $validated['payments']);
    }
}
