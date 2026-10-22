<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\DocumentSequence;
use App\Models\Store;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\Purchasing\DocumentSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSequenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA1;

    private Store $storeA2;

    private Store $storeB1;

    private DocumentSequenceService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenantA = Tenant::create(['name' => 'Tenant Alpha']);
        $this->tenantB = Tenant::create(['name' => 'Tenant Beta']);

        $this->storeA1 = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'Store Alpha Central']);
        $this->storeA2 = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'Store Alpha South']);
        $this->storeB1 = Store::create(['tenant_id' => $this->tenantB->id, 'name' => 'Store Beta Main']);

        app(TenantContext::class)->setTenantId($this->tenantA->id);
        app(TenantContext::class)->setStoreId($this->storeA1->id);

        $this->service = app(DocumentSequenceService::class);
    }

    public function test_supplier_code_sequence_increments_globally_per_tenant(): void
    {
        $code1 = $this->service->nextSupplierCode($this->tenantA->id);
        $code2 = $this->service->nextSupplierCode($this->tenantA->id);
        $code3 = $this->service->nextSupplierCode($this->tenantA->id);

        $this->assertSame('SUP-0001', $code1);
        $this->assertSame('SUP-0002', $code2);
        $this->assertSame('SUP-0003', $code3);

        // Tenant B harus terisolasi dan mulai dari 0001
        $codeB1 = $this->service->nextSupplierCode($this->tenantB->id);
        $this->assertSame('SUP-0001', $codeB1);
    }

    public function test_purchase_request_sequence_is_scoped_by_store_and_period(): void
    {
        $prA1_1 = $this->service->nextPurchaseRequestNumber($this->tenantA->id, $this->storeA1->id, '202610');
        $prA1_2 = $this->service->nextPurchaseRequestNumber($this->tenantA->id, $this->storeA1->id, '202610');

        $this->assertSame("PR-{$this->storeA1->id}-202610-0001", $prA1_1);
        $this->assertSame("PR-{$this->storeA1->id}-202610-0002", $prA1_2);

        // Store A2 pada periode yang sama harus mulai dari 0001
        $prA2_1 = $this->service->nextPurchaseRequestNumber($this->tenantA->id, $this->storeA2->id, '202610');
        $this->assertSame("PR-{$this->storeA2->id}-202610-0001", $prA2_1);

        // Periode baru (202611) pada Store A1 harus mereset urutan ke 0001
        $prA1_nov = $this->service->nextPurchaseRequestNumber($this->tenantA->id, $this->storeA1->id, '202611');
        $this->assertSame("PR-{$this->storeA1->id}-202611-0001", $prA1_nov);

        // Store B1 pada tenant B juga harus mulai dari 0001
        $prB1 = $this->service->nextPurchaseRequestNumber($this->tenantB->id, $this->storeB1->id, '202610');
        $this->assertSame("PR-{$this->storeB1->id}-202610-0001", $prB1);
    }

    public function test_all_m5_document_types_have_distinct_isolated_sequences(): void
    {
        $tenantId = $this->tenantA->id;
        $storeId = $this->storeA1->id;
        $period = '202610';

        $po = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $grn = $this->service->nextGrnNumber($tenantId, $storeId, $period);
        $pinv = $this->service->nextSupplierInvoiceNumber($tenantId, $storeId, $period);
        $pret = $this->service->nextPurchaseReturnNumber($tenantId, $storeId, $period);
        $dn = $this->service->nextDebitNoteNumber($tenantId, $storeId, $period);

        $this->assertSame("PO-{$storeId}-202610-0001", $po);
        $this->assertSame("GRN-{$storeId}-202610-0001", $grn);
        $this->assertSame("PINV-{$storeId}-202610-0001", $pinv);
        $this->assertSame("PRET-{$storeId}-202610-0001", $pret);
        $this->assertSame("DN-{$storeId}-202610-0001", $dn);

        // Panggilan kedua pada masing-masing dokumen harus menghasilkan sequence 0002
        $po2 = $this->service->nextPurchaseOrderNumber($tenantId, $storeId, $period);
        $grn2 = $this->service->nextGrnNumber($tenantId, $storeId, $period);
        $this->assertSame("PO-{$storeId}-202610-0002", $po2);
        $this->assertSame("GRN-{$storeId}-202610-0002", $grn2);
    }

    public function test_tenant_level_documents_without_store_id(): void
    {
        $tenantId = $this->tenantA->id;
        $period = '202610';

        $pinvGlobal = $this->service->nextSupplierInvoiceNumber($tenantId, null, $period);
        $dnGlobal = $this->service->nextDebitNoteNumber($tenantId, null, $period);

        $this->assertSame('PINV-202610-0001', $pinvGlobal);
        $this->assertSame('DN-202610-0001', $dnGlobal);
    }

    public function test_document_sequence_model_attributes_and_relationships(): void
    {
        $this->service->nextPurchaseOrderNumber($this->tenantA->id, $this->storeA1->id, '202610');

        $record = DocumentSequence::withoutTenantScope()
            ->where('tenant_id', $this->tenantA->id)
            ->where('doc_type', 'PO')
            ->first();

        $this->assertNotNull($record);
        $this->assertSame($this->tenantA->id, $record->tenant_id);
        $this->assertSame($this->storeA1->id, $record->store_id);
        $this->assertSame("store:{$this->storeA1->id}", $record->scope_key);
        $this->assertSame('202610', $record->period_key);
        $this->assertSame('PO', $record->prefix);
        $this->assertSame(1, $record->last_value);

        $this->assertTrue($record->tenant->is($this->tenantA));
        $this->assertTrue($record->store->is($this->storeA1));
    }
}
