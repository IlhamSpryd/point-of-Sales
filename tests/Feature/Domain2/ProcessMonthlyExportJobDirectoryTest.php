<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Enums\OrderStatus;
use App\Jobs\ProcessMonthlyExportJob;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessMonthlyExportJobDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Store $store;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        app(TenantContext::class)->clear();

        $this->tenant = Tenant::create([
            'name' => 'Monthly Tenant',
            'slug' => 'monthly-tenant',
            'status' => 'active',
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Monthly Store',
            'is_active' => true,
        ]);

        $context = app(TenantContext::class);
        $context->setTenantId($this->tenant->id);
        $context->setStoreId($this->store->id);
    }

    public function test_monthly_export_creates_file_in_storage_local_disk(): void
    {
        Storage::fake('local');

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Order::forceCreate([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $user->id,
            'order_code' => 'MONTHLY-001',
            'order_date' => now()->toDateString(),
            'order_status' => OrderStatus::Paid,
            'payment_method' => 'cash',
            'subtotal_amount' => 10000,
            'tax_amount' => 1100,
            'order_amount' => 11100,
            'idempotency_key' => 'month-key-1',
            'created_at' => now(),
        ]);

        $job = new ProcessMonthlyExportJob(
            month: now()->format('m'),
            year: now()->format('Y'),
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
        );

        $job->handle();

        $files = Storage::disk('local')->files('exports');
        $this->assertNotEmpty($files, 'Monthly export file must be generated inside exports directory on local disk');
    }
}
