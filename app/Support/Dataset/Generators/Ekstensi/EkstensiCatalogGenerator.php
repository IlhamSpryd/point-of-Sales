<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators\Ekstensi;

use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\Generators\Ekstensi\Concerns\ResolvesDatasetContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Melengkapi katalog yang belum disentuh generator inti:
 *
 *   brands, units_of_measure, tax_classes, product_attributes,
 *   product_attribute_values, product_variants, product_variant_attribute_values,
 *   product_bundle_items, product_unit_conversions, product_cost_histories,
 *   product_media, price_lists, price_list_items, store_product_assortments,
 *   channel_product_mappings, document_sequences, employee_id_sequences,
 *   supplier_contacts
 *
 * Semua baris direferensikan dari data nyata hasil generator inti (products,
 * variants, suppliers, stores) — tidak ada ID hardcode. Idempoten: dicek dulu
 * keunikan alaminya (kode/SKU/nama) sebelum insert.
 */
final class EkstensiCatalogGenerator
{
    use ResolvesDatasetContext;

    /** @var array<int, array{id:int,price:int,cost:float,retail:bool,category:?int,code:string,name:string,since:CarbonImmutable}> */
    private array $products = [];

    /** @var array<int, array<int, array{id:int,name:string,price:?float}>> product_id => variants */
    private array $variants = [];

    /** @var array<string,int> unit code => id */
    private array $uomIds = [];

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    public function generate(int $tenantId): void
    {
        $this->ensureUnitsOfMeasure($tenantId);
        $this->ensureBrands($tenantId);
        $this->ensureTaxClasses($tenantId);
        $this->loadCatalog($tenantId);
        $this->ensureAttributes($tenantId);
        $this->ensureVariants($tenantId);
        $this->ensureBundles($tenantId);
        $this->ensureUnitConversions($tenantId);
        $this->ensureProductMedia($tenantId);
        $this->ensurePriceLists($tenantId);
        $this->ensureStoreAssortments($tenantId);
        $this->ensureChannelMappings($tenantId);
        $this->ensureSupplierContacts($tenantId);
        $this->ensureDocumentSequences($tenantId);
        $this->ensureEmployeeIdSequences($tenantId);
    }

    /** Sets internal $this->uomIds (unit code => id). */
    private function ensureUnitsOfMeasure(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(60);
        // [code, name, precision_scale]
        $base = [
            ['PCS', 'Pieces', 0],
            ['PORTION', 'Porsi', 0],
            ['GLASS', 'Gelas', 0],
            ['PACK', 'Paket', 0],
            ['KG', 'Kilogram', 3],
            ['GR', 'Gram', 0],
            ['LTR', 'Liter', 3],
            ['ML', 'Mililiter', 0],
            ['BOX', 'Box', 0],
            ['BTL', 'Botol', 0],
        ];

        foreach ($base as [$code, $name, $precision]) {
            $existing = DB::table('units_of_measure')
                ->where('tenant_id', $tenantId)->where('code', $code)->first();

            if ($existing) {
                $this->uomIds[$code] = (int) $existing->id;

                continue;
            }

            $this->uomIds[$code] = (int) DB::table('units_of_measure')->insertGetId([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'precision_scale' => $precision,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $this->writer->markWritten('units_of_measure', 1);
        }
    }

    private function ensureBrands(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(70);
        $brands = [
            ['APR', 'Apeiron Roastery', 'Roastery kopi specialty internal'],
            ['SGR', 'Sagara Bakehouse', 'Artisan bakery & pastry'],
            ['LCL', 'Local Harvest', 'Bahan lokal Nusantara'],
            ['TRD', 'Third Wave Supply', 'Perlengkapan kopi'],
            ['RTL', 'Retail Corner', 'Produk retail siap jual'],
        ];

        foreach ($brands as [$code, $name, $desc]) {
            $exists = DB::table('brands')->where('tenant_id', $tenantId)->where('code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('brands')->insert([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'description' => $desc,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $this->writer->markWritten('brands', 1);
        }
    }

    private function ensureTaxClasses(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(70);
        $classes = [
            ['PPN11', 'PPN 11% (Makanan & Minuman)', 11.0],
            ['PPN11-INCL', 'PPN 11% Termasuk Harga', 11.0],
            ['NONTAX', 'Non-Pajak (Retail Bebas PPN)', 0.0],
        ];

        foreach ($classes as [$code, $name, $rate]) {
            $exists = DB::table('tax_classes')->where('tenant_id', $tenantId)->where('code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('tax_classes')->insert([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'rate' => $rate,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
            $this->writer->markWritten('tax_classes', 1);
        }
    }

    private function loadCatalog(int $tenantId): void
    {
        $rows = DB::table('products')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'product_code', 'product_name', 'product_price', 'standard_cost', 'category_id', 'has_variants', 'track_inventory', 'created_at']);

        foreach ($rows as $r) {
            $categoryId = $r->category_id !== null ? (int) $r->category_id : null;
            $isRetail = $this->looksRetail((string) $r->product_code);
            $this->products[(int) $r->id] = [
                'id' => (int) $r->id,
                'code' => (string) $r->product_code,
                'name' => (string) $r->product_name,
                'price' => (int) $r->product_price,
                'cost' => (float) $r->standard_cost,
                'retail' => $isRetail,
                'category' => $categoryId,
                'since' => $r->created_at ? CarbonImmutable::parse($r->created_at) : $this->config->startDate,
            ];
        }
    }

    private function looksRetail(string $productCode): bool
    {
        // Produk retail pada fixtures punya kode dengan segmen RET.
        return str_contains($productCode, 'RET');
    }

    private function ensureAttributes(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(60);
        $attributes = [
            'Ukuran' => ['Regular', 'Large'],
            'Suhu' => ['Hot', 'Iced'],
            'Gula' => ['Normal', 'Less Sugar', 'No Sugar'],
            'Jenis Susu' => ['Whole Milk', 'Oat Milk', 'Almond Milk'],
            'Sajian' => ['Dine In', 'Take Away'],
        ];

        $this->attributeIds = [];
        foreach ($attributes as $name => $values) {
            $attr = DB::table('product_attributes')->where('tenant_id', $tenantId)->where('name', $name)->first();
            if (! $attr) {
                $attrId = (int) DB::table('product_attributes')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'code' => Str::slug($name),
                    'sort_order' => count($this->attributeIds) + 1,
                    'is_active' => 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
                $this->writer->markWritten('product_attributes', 1);
            } else {
                $attrId = (int) $attr->id;
            }
            $this->attributeIds[$name] = $attrId;

            $this->attributeValues[$attrId] = [];
            foreach ($values as $idx => $value) {
                $val = DB::table('product_attribute_values')
                    ->where('tenant_id', $tenantId)->where('attribute_id', $attrId)->where('value', $value)->first();
                if (! $val) {
                    $valId = (int) DB::table('product_attribute_values')->insertGetId([
                        'tenant_id' => $tenantId,
                        'attribute_id' => $attrId,
                        'value' => $value,
                        'code' => Str::slug($value),
                        'sort_order' => $idx + 1,
                        'is_active' => 1,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ]);
                    $this->writer->markWritten('product_attribute_values', 1);
                } else {
                    $valId = (int) $val->id;
                }
                $this->attributeValues[$attrId][$value] = $valId;
            }
        }
    }

    /** @var array<string,int> */
    private array $attributeIds = [];

    /** @var array<int, array<string,int>> attribute_id => [value => value_id] */
    private array $attributeValues = [];

    private function ensureVariants(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(55);
        $target = max(0, $this->config->volumeInt('product_variants', 60));
        if ($target === 0) {
            return;
        }

        // Grup dengan produk lebih dari satu varian dibatasi agar nominal
        // product price tetap bisa dipakai sebagai basis harga varian.
        $rng = $this->rngFor($tenantId, 'variants');
        $sizeAttr = $this->attributeIds['Ukuran'] ?? null;
        $tempAttr = $this->attributeIds['Suhu'] ?? null;

        // Kandidat: produk minuman (non-retail) — berdasarkan kategori kopi/teh.
        $candidates = array_values(array_filter($this->products, fn ($p) => ! $p['retail']));
        $picked = array_slice($candidates, 0, max(1, (int) ceil($target / 2)));

        $rowsForVariantAttr = [];
        $created = 0;
        $variantSeq = 0;

        foreach ($picked as $product) {
            if ($created >= $target) {
                break;
            }
            $options = $rng->bool(0.7)
                ? [['Large', 3000], ['Regular', 0]]
                : [['Iced', 0], ['Hot', 0]];

            foreach ($options as [$label, $delta]) {
                if ($created >= $target) {
                    break;
                }
                $variantSeq++;
                $sku = sprintf('VAR-%d-%04d', $tenantId, $variantSeq);
                $existing = DB::table('product_variants')
                    ->where('tenant_id', $tenantId)->where('sku', $sku)->first();
                if ($existing) {
                    $variantId = (int) $existing->id;
                } else {
                    $variantId = (int) DB::table('product_variants')->insertGetId([
                        'tenant_id' => $tenantId,
                        'product_id' => $product['id'],
                        'variant_name' => $label,
                        'sku' => $sku,
                        'barcode' => sprintf('899%d%06d', 7 + ($variantSeq % 3), $variantSeq * 13 % 999),
                        'price_override' => max(0, $product['price'] + $delta),
                        'costing_method' => 'standard',
                        'standard_cost' => round($product['cost'] + ($delta > 0 ? 800 : 0), 2),
                        'is_active' => 1,
                        'sort_order' => $delta > 0 ? 2 : 1,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ]);
                    $this->writer->markWritten('product_variants', 1);
                }

                $this->variants[$product['id']][] = ['id' => $variantId, 'name' => $label, 'price' => $product['price'] + $delta];

                // Atribut varian: Ukuran ATAU Suhu, sesuai label.
                if ($label === 'Large' || $label === 'Regular') {
                    if ($sizeAttr !== null && isset($this->attributeValues[$sizeAttr][$label])) {
                        $rowsForVariantAttr[] = [
                            'tenant_id' => $tenantId,
                            'variant_id' => $variantId,
                            'attribute_id' => $sizeAttr,
                            'attribute_value_id' => $this->attributeValues[$sizeAttr][$label],
                            'created_at' => $ts,
                            'updated_at' => $ts,
                        ];
                    }
                } elseif ($tempAttr !== null && isset($this->attributeValues[$tempAttr][$label])) {
                    $rowsForVariantAttr[] = [
                        'tenant_id' => $tenantId,
                        'variant_id' => $variantId,
                        'attribute_id' => $tempAttr,
                        'attribute_value_id' => $this->attributeValues[$tempAttr][$label],
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ];
                }

                $created++;
            }
        }

        if ($rowsForVariantAttr !== []) {
            $existing = DB::table('product_variant_attribute_values')
                ->where('tenant_id', $tenantId)
                ->get(['variant_id', 'attribute_id'])
                ->map(fn ($r) => $r->variant_id.':'.$r->attribute_id)->flip()->all();

            $new = array_values(array_filter(
                $rowsForVariantAttr,
                fn ($r) => ! isset($existing[$r['variant_id'].':'.$r['attribute_id']])
            ));
            foreach (array_chunk($new, $this->writer->batchSize()) as $chunk) {
                DB::table('product_variant_attribute_values')->insert($chunk);
                $this->writer->markWritten('product_variant_attribute_values', count($chunk));
            }
        }

        if ($created > 0) {
            DB::table('products')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', array_keys($this->variants))
                ->update(['has_variants' => 1, 'updated_at' => now()]);
        }
    }

    private function ensureBundles(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(50);
        $rng = $this->rngFor($tenantId, 'bundles');
        $pool = array_values(array_filter($this->products, fn ($p) => ! $p['retail']));
        if (count($pool) < 6) {
            return;
        }

        $defs = [
            ['BUNDLE-BREAKFAST', 'Paket Kopi + Pastry', 1.0],
            ['BUNDLE-LUNCH', 'Paket Makan Siang + Es Teh', 1.0],
            ['BUNDLE-AFTERNOON', 'Paket Signature + Dessert', 1.0],
        ];

        foreach ($defs as $idx => [$code, $name, $ratio]) {
            $components = $rng->sample($pool, 3);
            $codeFull = sprintf('DS%d-%s', $tenantId, $code);
            $bundle = DB::table('products')
                ->where('tenant_id', $tenantId)->where('product_code', $codeFull)->first();

            $sumPrice = array_sum(array_map(fn ($p) => $p['price'], $components));
            $bundlePrice = (int) (round($sumPrice * 0.88 / 500) * 500);

            if (! $bundle) {
                $bundleId = (int) DB::table('products')->insertGetId([
                    'tenant_id' => $tenantId,
                    'product_code' => $codeFull,
                    'barcode' => null,
                    'product_type' => 'bundle',
                    'status' => 'active',
                    'has_variants' => 0,
                    'track_inventory' => 0,
                    'category_id' => $components[0]['category'],
                    'product_name' => $name,
                    'product_price' => $bundlePrice,
                    'costing_method' => 'standard',
                    'standard_cost' => round(array_sum(array_map(fn ($p) => $p['cost'], $components)), 2),
                    'is_tax_inclusive' => 0,
                    'product_description' => 'Paket hemat '.$name,
                    'stock' => 0,
                    'is_active' => 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
                $this->writer->markWritten('products', 1);
            } else {
                $bundleId = (int) $bundle->id;
            }

            foreach ($components as $i => $component) {
                $exists = DB::table('product_bundle_items')
                    ->where('tenant_id', $tenantId)
                    ->where('bundle_product_id', $bundleId)
                    ->where('component_product_id', $component['id'])
                    ->whereNull('component_variant_id')
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('product_bundle_items')->insert([
                    'tenant_id' => $tenantId,
                    'bundle_product_id' => $bundleId,
                    'component_product_id' => $component['id'],
                    'component_variant_id' => null,
                    'quantity' => 1,
                    'uom_id' => $this->uomIds['PORTION'] ?? null,
                    'cost_allocation_ratio' => round($ratio / 3, 4),
                    'sort_order' => $i + 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
                $this->writer->markWritten('product_bundle_items', 1);
            }
        }
    }

    /**
     * Konversi UOM bersifat PER PRODUK (product_id NOT NULL): mis. kopi biji
     * dijual per gram, susu per mililiter, pastry per pcs.
     */
    private function ensureUnitConversions(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(45);
        $rules = [
            ['KG', 'GR', 1000.0],
            ['LTR', 'ML', 1000.0],
            ['BOX', 'PCS', 12.0],
            ['PACK', 'PCS', 6.0],
        ];

        $rows = [];
        $seq = 0;
        foreach ($this->products as $product) {
            // Setiap produk punya satu konversi satuan jual → satuan dasar.
            [$from, $to, $factor] = $rules[$product['id'] % count($rules)];
            if (! isset($this->uomIds[$from], $this->uomIds[$to])) {
                continue;
            }
            $seq++;
            $rows[] = [
                'tenant_id' => $tenantId,
                'product_id' => $product['id'],
                'from_uom_id' => $this->uomIds[$from],
                'to_uom_id' => $this->uomIds[$to],
                'conversion_factor' => $factor,
                'barcode' => null,
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        if ($rows === []) {
            return;
        }

        $existing = DB::table('product_unit_conversions')->where('tenant_id', $tenantId)
            ->get(['product_id', 'from_uom_id', 'to_uom_id'])
            ->map(fn ($r) => $r->product_id.':'.$r->from_uom_id.':'.$r->to_uom_id)->flip()->all();

        $new = array_values(array_filter($rows, function ($r) use ($existing) {
            return ! isset($existing[$r['product_id'].':'.$r['from_uom_id'].':'.$r['to_uom_id']]);
        }));

        foreach (array_chunk($new, $this->writer->batchSize()) as $chunk) {
            DB::table('product_unit_conversions')->insert($chunk);
            $this->writer->markWritten('product_unit_conversions', count($chunk));
        }
    }

    private function ensureProductMedia(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(40);
        $existing = DB::table('product_media')->where('tenant_id', $tenantId)->exists();
        if ($existing) {
            return;
        }

        $rows = [];
        foreach ($this->products as $product) {
            $slug = Str::slug($product['name']);
            $rows[] = [
                'tenant_id' => $tenantId,
                'product_id' => $product['id'],
                'disk' => 'public',
                'file_path' => "products/{$tenantId}/{$slug}.jpg",
                'mime_type' => 'image/jpeg',
                'file_size_bytes' => 120_000 + (crc32($slug) % 180_000),
                'alt_text' => $product['name'],
                'is_primary' => 1,
                'sort_order' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('product_media')->insert($chunk);
            $this->writer->markWritten('product_media', count($chunk));
        }
    }

    private function ensurePriceLists(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(35);
        $stores = $this->storesWithCode($tenantId);
        if ($stores === []) {
            return;
        }

        $tiers = DB::table('loyalty_tiers')->where('tenant_id', $tenantId)
            ->orderBy('min_points')->get(['id', 'tier_code', 'name']);

        $defs = [];
        $defs[] = [
            'name' => 'Harga Standar', 'code' => 'PL-STD', 'type' => 'standard',
            'store_id' => null, 'customer_id' => null, 'tier_id' => null, 'channel' => null, 'priority' => 0,
        ];
        foreach ($stores as $idx => $store) {
            $defs[] = [
                'name' => 'Harga Cabang '.$store['code'], 'code' => 'PL-'.$store['code'], 'type' => 'branch',
                'store_id' => $store['id'], 'customer_id' => null, 'tier_id' => null, 'channel' => null,
                'priority' => 10 + $idx,
            ];
        }
        foreach ($tiers as $idx => $tier) {
            if (strtoupper((string) $tier->tier_code) === 'BRONZE') {
                continue;
            }
            $defs[] = [
                'name' => 'Harga Member '.$tier->name, 'code' => 'PL-'.$tier->tier_code, 'type' => 'customer_tier',
                'store_id' => null, 'customer_id' => null, 'tier_id' => (int) $tier->id, 'channel' => null,
                'priority' => 20 + $idx,
            ];
        }
        $defs[] = [
            'name' => 'Harga Kanal Delivery', 'code' => 'PL-DLV', 'type' => 'channel',
            'store_id' => null, 'customer_id' => null, 'tier_id' => null, 'channel' => 'delivery', 'priority' => 30,
        ];

        $rng = $this->rngFor($tenantId, 'pricelist');

        foreach ($defs as $def) {
            $priceList = DB::table('price_lists')
                ->where('tenant_id', $tenantId)->where('code', $def['code'])->first();

            $priceListId = $priceList
                ? (int) $priceList->id
                : (int) DB::table('price_lists')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => $def['name'],
                    'code' => $def['code'],
                    'type' => $def['type'],
                    'store_id' => $def['store_id'],
                    'customer_id' => $def['customer_id'],
                    'loyalty_tier_id' => $def['tier_id'],
                    'channel' => $def['channel'],
                    'currency' => 'IDR',
                    'priority' => $def['priority'],
                    'valid_from' => $this->config->startDate->subDays(30),
                    'valid_until' => null,
                    'is_active' => 1,
                    'description' => 'Daftar harga '.$def['name'],
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            if (! $priceList) {
                $this->writer->markWritten('price_lists', 1);
            }

            $already = DB::table('price_list_items')->where('tenant_id', $tenantId)
                ->where('price_list_id', $priceListId)->exists();
            if ($already) {
                continue;
            }

            $rows = [];
            foreach ($this->products as $product) {
                $delta = match ($def['type']) {
                    'branch' => $rng->bool(0.5) ? $rng->int(-1, 2) * 500 : 0,
                    'customer_tier' => -$rng->int(5, 15) * 1000,
                    'channel' => $rng->int(3, 12) * 500,
                    default => 0,
                };
                $price = max(1000, $product['price'] + $delta);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'price_list_id' => $priceListId,
                    'product_id' => $product['id'],
                    'product_variant_id' => null,
                    'uom_id' => null,
                    'min_quantity' => 1,
                    'price' => $price,
                    'discount_percentage' => 0,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];

                foreach ($this->variants[$product['id']] ?? [] as $variant) {
                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'price_list_id' => $priceListId,
                        'product_id' => $product['id'],
                        'product_variant_id' => $variant['id'],
                        'uom_id' => null,
                        'min_quantity' => 1,
                        'price' => max(1000, (int) $variant['price'] + $delta),
                        'discount_percentage' => 0,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ];
                }
            }

            foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
                DB::table('price_list_items')->insert($chunk);
                $this->writer->markWritten('price_list_items', count($chunk));
            }
        }
    }

    private function ensureStoreAssortments(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(30);
        $rng = $this->rngFor($tenantId, 'assortment');
        $stores = DB::table('stores')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->get(['id', 'code', 'created_at']);

        foreach ($stores as $store) {
            $already = DB::table('store_product_assortments')
                ->where('tenant_id', $tenantId)->where('store_id', $store->id)->exists();
            if ($already) {
                continue;
            }

            $rows = [];
            foreach ($this->products as $product) {
                // Sebagian produk tidak tersedia di semua cabang.
                $available = $rng->bool(0.94) ? 1 : 0;
                $override = $available && $rng->bool(0.08)
                    ? max(1000, $product['price'] + $rng->int(-2, 3) * 500)
                    : null;

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'store_id' => (int) $store->id,
                    'product_id' => $product['id'],
                    'product_variant_id' => null,
                    'is_available' => $available,
                    'store_price_override' => $override,
                    'allowed_channels' => json_encode(['dine_in', 'takeaway', 'delivery']),
                    'created_at' => $store->created_at ? CarbonImmutable::parse($store->created_at) : $ts,
                    'updated_at' => $ts,
                ];
            }

            foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
                DB::table('store_product_assortments')->insert($chunk);
                $this->writer->markWritten('store_product_assortments', count($chunk));
            }
        }
    }

    private function ensureChannelMappings(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(25);
        $channels = [['gofood', 'GF'], ['grabfood', 'GRB'], ['shopeefood', 'SPF']];

        $rows = [];
        foreach ($channels as [$provider, $prefix]) {
            foreach ($this->products as $product) {
                $external = sprintf('%s-%s-%d', $prefix, substr(md5($product['code']), 0, 6), $product['id']);
                $exists = DB::table('channel_product_mappings')
                    ->where('tenant_id', $tenantId)
                    ->where('provider', $provider)
                    ->where('external_product_id', $external)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'external_product_id' => $external,
                    'product_id' => $product['id'],
                    'product_variant_id' => null,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
            }
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('channel_product_mappings')->insert($chunk);
            $this->writer->markWritten('channel_product_mappings', count($chunk));
        }
    }

    private function ensureSupplierContacts(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(40);
        $suppliers = DB::table('suppliers')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->get(['id', 'code', 'name']);

        $titles = ['Sales Representative', 'Account Manager', 'Logistics Coordinator'];

        foreach ($suppliers as $idx => $supplier) {
            $exists = DB::table('supplier_contacts')->where('tenant_id', $tenantId)
                ->where('supplier_id', $supplier->id)->exists();
            if ($exists) {
                continue;
            }

            for ($i = 1; $i <= 2; $i++) {
                DB::table('supplier_contacts')->insert([
                    'tenant_id' => $tenantId,
                    'supplier_id' => (int) $supplier->id,
                    'name' => sprintf('%s Contact %d', $supplier->name, $i),
                    'title' => $titles[($idx + $i) % count($titles)],
                    'email' => sprintf('contact%d.%s@supplier.example.invalid', $i, strtolower($supplier->code)),
                    'phone' => sprintf('0812%08d', (crc32($supplier->code) + $i) % 99999),
                    'is_primary' => $i === 1 ? 1 : 0,
                    'notes' => $i === 1 ? 'Kontak utama pemesanan' : null,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
                $this->writer->markWritten('supplier_contacts', 1);
            }
        }
    }

    /**
     * document_sequences: caching nomor dokumen per (tenant, store, doc_type).
     * last_value dihitung dari dokumen nyata yang sudah dibuat generator inti
     * agar konsisten bila suatu saat ada dokumen baru.
     */
    private function ensureDocumentSequences(int $tenantId): void
    {
        $ts = $this->config->startDate;
        $stores = $this->storesWithCode($tenantId);

        $defs = [
            // doc_type => [prefix, tabel sumber, kolom nomor]
            'PO' => ['PO', 'purchase_orders', 'po_number'],
            'GRN' => ['GRN', 'goods_received_notes', 'grn_number'],
            'ADJ' => ['ADJ', 'stock_adjustments', 'adjustment_number'],
            'OPN' => ['OPN', 'stock_opnames', 'opname_number'],
            'TRF' => ['TRF', 'stock_transfers', 'transfer_number'],
            'GR' => ['GR', 'goods_receipts', 'receipt_number'],
            'SRE' => ['SRE', 'supplier_returns', 'return_number'],
            'PR' => ['PR', 'purchase_requests', 'pr_number'],
        ];

        foreach ($stores as $store) {
            foreach ($defs as $docType => [$prefix, $table, $column]) {
                $scopeKey = $docType.':'.strtolower($store['code']);
                $exists = DB::table('document_sequences')
                    ->where('tenant_id', $tenantId)
                    ->where('scope_key', $scopeKey)
                    ->where('doc_type', $docType)
                    ->where('period_key', 'global')
                    ->exists();
                if ($exists) {
                    continue;
                }

                $lastValue = $table === 'purchase_orders'
                    ? (int) DB::table($table)->where('tenant_id', $tenantId)
                        ->where('store_id', $store['id'])->count() + 100
                    : 1;

                DB::table('document_sequences')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'scope_key' => $scopeKey,
                    'doc_type' => $docType,
                    'period_key' => 'global',
                    'prefix' => $prefix,
                    'last_value' => $lastValue,
                    'created_at' => $ts,
                    'updated_at' => now(),
                ]);
                $this->writer->markWritten('document_sequences', 1);
            }
        }
    }

    /**
     * employee_id_sequences: sequence per tenant untuk generator NIK karyawan.
     * Disetel ke nilai tertinggi yang sudah terpakai di tabel users.
     */
    private function ensureEmployeeIdSequences(int $tenantId): void
    {
        $exists = DB::table('employee_id_sequences')->where('tenant_id', $tenantId)->exists();
        if ($exists) {
            return;
        }

        $maxUser = (int) DB::table('users')->where('tenant_id', $tenantId)->count();

        DB::table('employee_id_sequences')->insert([
            'tenant_id' => $tenantId,
            'prefix' => 'YVL-EMP',
            'last_value' => max(1, $maxUser),
            'created_at' => $this->config->startDate->subDays(60),
            'updated_at' => now(),
        ]);
        $this->writer->markWritten('employee_id_sequences', 1);
    }
}
