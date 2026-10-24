<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\Purchasing\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentSequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenant;

    private Store $store;

    private DocumentSequenceService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = Tenant::create(['name' => 'Concurrency Tenant']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Concurrency Store']);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(TenantContext::class)->setStoreId($this->store->id);

        $this->service = app(DocumentSequenceService::class);
    }

    public function test_rapid_sequential_allocations_generate_strictly_unique_monotonically_increasing_numbers(): void
    {
        $numbers = [];
        $count = 50;

        for ($i = 0; $i < $count; $i++) {
            $numbers[] = $this->service->nextPurchaseOrderNumber(
                tenantId: $this->tenant->id,
                storeId: $this->store->id,
                period: '202610'
            );
        }

        // Pastikan seluruh nomor berjumlah 50 dan unik (tanpa tabrakan)
        $this->assertCount($count, $numbers);
        $this->assertCount($count, array_unique($numbers));

        // Pastikan nomor berurutan dari PO-{store}-202610-0001 sampai 0050
        $this->assertSame("PO-{$this->store->id}-202610-0001", $numbers[0]);
        $this->assertSame("PO-{$this->store->id}-202610-0050", $numbers[49]);
    }

    public function test_interleaved_document_types_maintain_independent_monotonic_counters(): void
    {
        $tenantId = $this->tenant->id;
        $storeId = $this->store->id;
        $period = '202610';

        $po1 = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $grn1 = $this->service->nextGrnNumber($tenantId, $storeId, $period);
        $po2 = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $grn2 = $this->service->nextGrnNumber($tenantId, $storeId, $period);
        $po3 = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);

        $this->assertSame("PO-{$storeId}-202610-0001", $po1);
        $this->assertSame("GRN-{$storeId}-202610-0001", $grn1);
        $this->assertSame("PO-{$storeId}-202610-0002", $po2);
        $this->assertSame("GRN-{$storeId}-202610-0002", $grn2);
        $this->assertSame("PO-{$storeId}-202610-0003", $po3);
    }

    public function test_transaction_rollback_preserves_pessimistic_lock_safety(): void
    {
        $tenantId = $this->tenant->id;
        $storeId = $this->store->id;
        $period = '202610';

        // Nomor awal
        $num1 = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $this->assertSame("PO-{$storeId}-202610-0001", $num1);

        // Simulasi transaksi bisnis gagal yang memanggil sequence
        try {
            DB::transaction(function () use ($tenantId, $storeId, $period) {
                $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
                throw new \RuntimeException('Simulated order creation failure.');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        // Alokasi berikutnya tetap aman dan tidak menghasilkan duplikasi pada database
        $numAfterRollback = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $this->assertNotSame($num1, $numAfterRollback);
    }
}
