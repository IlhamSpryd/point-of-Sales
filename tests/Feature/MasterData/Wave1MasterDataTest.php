<?php

declare(strict_types=1);

namespace Tests\Feature\MasterData;

use App\Core\Context\TenantContext;
use App\Models\Product;
use App\Models\ProductUnitConversion;
use App\Models\Store;
use App\Models\TaxClass;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Modules\MasterData\Services\DocumentSequenceService;
use App\Modules\MasterData\Services\UnitConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Wave1MasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        app(TenantContext::class)->clear();
    }

    /**
     * T-MDM-01: Uji konkurensi 50 panggilan ke DocumentSequenceService
     * menghasilkan 50 nomor urut berurutan tanpa satu pun nomor duplikat.
     */
    public function test_t_mdm_01_concurrency_50_calls_generates_50_sequential_unique_numbers(): void
    {
        $context = app(TenantContext::class);
        $sequenceService = app(DocumentSequenceService::class);

        [$tenant, $store] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'MDM Sequence Tenant']);
            $store = Store::create([
                'tenant_id' => $tenant->id,
                'name' => 'Cabang Utama',
                'code' => 'JKT01',
            ]);

            return [$tenant, $store];
        });

        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        $period = '202611';
        $docType = 'INV';
        $totalCalls = 50;
        $generatedNumbers = [];

        // Lakukan 50 panggilan berurutan/simultan
        for ($i = 1; $i <= $totalCalls; $i++) {
            $generatedNumbers[] = $sequenceService->generateNextNumber(
                tenantId: $tenant->id,
                docType: $docType,
                storeId: $store->id,
                storeCode: $store->code,
                periodKey: $period,
                paddingLength: 4
            );
        }

        // 1. Tepat 50 nomor dihasilkan
        $this->assertCount($totalCalls, $generatedNumbers);

        // 2. Nol nomor duplikat (Zero duplicate numbers)
        $uniqueNumbers = array_unique($generatedNumbers);
        $this->assertCount($totalCalls, $uniqueNumbers, 'Terdapat nomor dokumen yang terduplikasi!');

        // 3. Pola dan urutan nomor berurutan monotonik (1 sampai 50)
        for ($i = 1; $i <= $totalCalls; $i++) {
            $padded = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $expected = "INV/JKT01/202611/{$padded}";
            $this->assertSame($expected, $generatedNumbers[$i - 1]);
        }
    }

    /**
     * T-MDM-02: Uji konversi UOM (1 Sak = 25 Kg, 1 Kg = 1.000 Gram)
     * akurat tanpa selisih pembulatan bolak-balik (round-trip).
     */
    public function test_t_mdm_02_uom_conversion_accurate_and_reversible_without_rounding_error(): void
    {
        $context = app(TenantContext::class);
        $conversionService = app(UnitConversionService::class);

        [$tenant, $product, $uomSak, $uomKg, $uomGram] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'MDM Bakery Tenant']);

            $uomSak = UnitOfMeasure::create([
                'tenant_id' => $tenant->id,
                'code' => 'SAK',
                'name' => 'Sak / Karung',
                'precision_scale' => 2,
            ]);

            $uomKg = UnitOfMeasure::create([
                'tenant_id' => $tenant->id,
                'code' => 'KG',
                'name' => 'Kilogram',
                'precision_scale' => 3,
            ]);

            $uomGram = UnitOfMeasure::create([
                'tenant_id' => $tenant->id,
                'code' => 'G',
                'name' => 'Gram',
                'precision_scale' => 0,
            ]);

            $category = \App\Models\Category::create([
                'tenant_id' => $tenant->id,
                'name' => 'Bahan Baku',
            ]);

            $product = Product::create([
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'name' => 'Tepung Terigu Premium',
                'base_uom_id' => $uomKg->id,
                'sku' => 'TPG-001',
                'price' => 15000,
            ]);

            // 1 Sak = 25 Kg
            ProductUnitConversion::create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'from_uom_id' => $uomSak->id,
                'to_uom_id' => $uomKg->id,
                'conversion_factor' => '25.0000',
            ]);

            // 1 Kg = 1000 Gram
            ProductUnitConversion::create([
                'tenant_id' => $tenant->id,
                'product_id' => $product->id,
                'from_uom_id' => $uomKg->id,
                'to_uom_id' => $uomGram->id,
                'conversion_factor' => '1000.0000',
            ]);

            return [$tenant, $product, $uomSak, $uomKg, $uomGram];
        });

        $context->setTenantId($tenant->id);

        // --- SKENARIO 1: Konversi 1 Sak ke Kg lalu balik ke Sak ---
        $kgFrom1Sak = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomSak->id,
            toUomId: $uomKg->id,
            quantity: '1.0000',
            productId: $product->id
        );
        $this->assertSame('25.0000', $kgFrom1Sak);

        $sakFrom25Kg = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomKg->id,
            toUomId: $uomSak->id,
            quantity: $kgFrom1Sak,
            productId: $product->id
        );
        $this->assertSame('1.0000', $sakFrom25Kg);

        // --- SKENARIO 2: Konversi 1 Kg ke Gram lalu balik ke Kg ---
        $gramFrom1Kg = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomKg->id,
            toUomId: $uomGram->id,
            quantity: '1.0000',
            productId: $product->id
        );
        $this->assertSame('1000.0000', $gramFrom1Kg);

        $kgFrom1000Gram = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomGram->id,
            toUomId: $uomKg->id,
            quantity: $gramFrom1Kg,
            productId: $product->id
        );
        $this->assertSame('1.0000', $kgFrom1000Gram);

        // --- SKENARIO 3: Konversi Bertingkat (Multi-Step) Sak -> Gram ---
        // 1 Sak = 25 Kg = 25.000 Gram
        $gramFrom1Sak = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomSak->id,
            toUomId: $uomGram->id,
            quantity: '1.0000',
            productId: $product->id
        );
        $this->assertSame('25000.0000', $gramFrom1Sak);

        // Konversi balik (Reverse Multi-Step): 25.000 Gram -> 1 Sak
        $sakFrom25000Gram = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomGram->id,
            toUomId: $uomSak->id,
            quantity: $gramFrom1Sak,
            productId: $product->id
        );
        $this->assertSame('1.0000', $sakFrom25000Gram);

        // --- SKENARIO 4: Kuantitas Pecahan Mikro (0.0300 Sak = 750 Gram) ---
        $gramFromMicro = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomSak->id,
            toUomId: $uomGram->id,
            quantity: '0.0300',
            productId: $product->id
        );
        $this->assertSame('750.0000', $gramFromMicro);

        $sakFrom750Gram = $conversionService->convertBetweenUoms(
            tenantId: $tenant->id,
            fromUomId: $uomGram->id,
            toUomId: $uomSak->id,
            quantity: $gramFromMicro,
            productId: $product->id
        );
        $this->assertSame('0.0300', $sakFrom750Gram);
    }

    /**
     * Pengujian master kelas pajak (tax_classes) tarif tunggal dan komposit.
     */
    public function test_tax_classes_supports_single_and_composite_rates(): void
    {
        $context = app(TenantContext::class);

        [$tenant, $singleTax, $compositeTax] = $context->runWithoutTenant(function () {
            $tenant = Tenant::create(['name' => 'Tax Master Tenant']);

            // Tarif tunggal: PPN 11%
            $singleTax = TaxClass::create([
                'tenant_id' => $tenant->id,
                'code' => 'PPN11',
                'name' => 'Pajak Pertambahan Nilai 11%',
                'rate' => '11.0000',
                'is_inclusive' => false,
            ]);

            // Tarif komposit: PPN 11% + Pajak Restoran (PB1) 10%
            $compositeTax = TaxClass::create([
                'tenant_id' => $tenant->id,
                'code' => 'PPN_PB1',
                'name' => 'PPN 11% + Resto PB1 10%',
                'rate' => '21.0000',
                'is_inclusive' => true,
                'composite_rates' => [
                    ['name' => 'PPN', 'rate' => '11.0000'],
                    ['name' => 'PB1', 'rate' => '10.0000'],
                ],
            ]);

            return [$tenant, $singleTax, $compositeTax];
        });

        $context->setTenantId($tenant->id);

        // Verifikasi tarif tunggal
        $this->assertFalse($singleTax->isComposite());
        $this->assertFalse($singleTax->is_inclusive);
        $this->assertSame('11.0000', $singleTax->effectiveRate());
        $this->assertSame('11.0000', $singleTax->rate_percent);

        // Verifikasi tarif komposit
        $this->assertTrue($compositeTax->isComposite());
        $this->assertTrue($compositeTax->is_inclusive);
        $this->assertSame('21.0000', $compositeTax->effectiveRate());
        $this->assertCount(2, $compositeTax->composite_rates);
    }
}
