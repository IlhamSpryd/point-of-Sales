<?php

declare(strict_types=1);

namespace App\Support\Dataset\Generators;

use App\Support\Dataset\CatalogFixtures;
use App\Support\Dataset\DatasetConfig;
use App\Support\Dataset\DatasetWriter;
use App\Support\Dataset\SeededRandom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuat katalog tenant: kategori, bahan baku, produk, BOM, modifier,
 * meja, pelanggan, pemasok, diskon, loyalty tier, dan saldo stok awal.
 *
 * Semua baris ditulis dengan bulk insert (kecuali master yang butuh ID),
 * tetap menghormati FK dan CHECK constraint aktual.
 */
final class CatalogGenerator
{
    /** @var array<int,int> category_code => id */
    private array $categoryIds = [];

    /** @var array<string,int> ingredient_code => id */
    private array $ingredientIds = [];

    /** @var array<int, array<string,mixed>> */
    private array $products = [];

    public function __construct(
        private readonly DatasetConfig $config,
        private readonly DatasetWriter $writer,
    ) {}

    /**
     * @param  array<int,array{id:int,opened_at:CarbonImmutable}>  $stores
     * @return array<string,mixed> katalog siap pakai untuk generator transaksi
     */
    public function generate(int $tenantId, array $stores): array
    {
        $rng = (new SeededRandom($this->config->seed))->derive("catalog:{$tenantId}");

        $this->ensureCategories($tenantId);
        $this->ensureIngredients($tenantId);
        $this->ensureProducts($tenantId, $rng);
        $this->ensureModifiers($tenantId);
        $this->ensureTables($tenantId, $stores, $rng);
        $this->ensureDiscounts($tenantId, $stores);
        $this->ensureLoyaltyTiers($tenantId);
        $this->ensureSuppliers($tenantId);
        $customers = $this->ensureCustomers($tenantId, $rng);
        $this->writer->flushAll();

        return [
            'categories' => $this->categoryIds,
            'ingredients' => $this->ingredientIds,
            'products' => $this->products,
            'customers' => $customers,
            'suppliers' => DB::table('suppliers')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->get(['id', 'code', 'name'])->keyBy('code')->toArray(),
            'modifier_groups' => DB::table('modifier_groups')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->get(['id', 'name'])->keyBy('name')->toArray(),
            'discounts' => DB::table('discounts')->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')->get()->toArray(),
        ];
    }

    private function ensureCategories(int $tenantId): void
    {
        $limit = max(3, $this->config->volumeInt('categories', count(CatalogFixtures::categories())));
        $categories = array_slice(CatalogFixtures::categories(), 0, $limit);
        $ts = $this->config->startDate->subDays(40);

        foreach ($categories as $idx => $cat) {
            $code = strtoupper($cat['code']);
            $existing = DB::table('categories')
                ->where('tenant_id', $tenantId)
                ->where('category_code', $code)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->categoryIds[$code] = (int) $existing->id;

                continue;
            }

            $this->categoryIds[$code] = (int) DB::table('categories')->insertGetId([
                'tenant_id' => $tenantId,
                'category_code' => $code,
                'category_name' => $cat['name'],
                'description' => null,
                'sort_order' => $idx + 1,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }
    }

    private function ensureIngredients(int $tenantId): void
    {
        $limit = max(10, $this->config->volumeInt('ingredients', count(CatalogFixtures::ingredients())));
        $ts = $this->config->startDate->subDays(35);

        foreach (array_slice(CatalogFixtures::ingredients(), 0, $limit) as $ing) {
            $code = $ing['code'];
            $existing = DB::table('ingredients')
                ->where('tenant_id', $tenantId)
                ->where('ingredient_code', $code)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $this->ingredientIds[$code] = (int) $existing->id;

                continue;
            }

            $this->ingredientIds[$code] = (int) DB::table('ingredients')->insertGetId([
                'tenant_id' => $tenantId,
                'ingredient_code' => $code,
                'name' => $ing['name'],
                'unit' => $ing['unit'],
                'current_stock' => 0, // akan diisi lewat movement initial_sync
                'cost_per_unit' => $ing['cost'],
                'reorder_level' => $ing['reorder'],
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }
    }

    private function ensureProducts(int $tenantId, SeededRandom $rng): void
    {
        $limit = max(10, $this->config->volumeInt('products', count(CatalogFixtures::products())));
        // Pastikan retail disertakan saat limit memotong daftar: ambil BOM dulu,
        // lalu sisakan slot retail.
        $all = CatalogFixtures::products();
        $bom = array_values(array_filter($all, fn ($p) => ! $p['retail']));
        $retail = array_values(array_filter($all, fn ($p) => $p['retail']));
        $retailCount = min(count($retail), (int) ceil($limit * 0.12));
        $selected = array_merge(
            array_slice($bom, 0, max(1, $limit - $retailCount)),
            array_slice($retail, 0, $retailCount)
        );

        $ts = $this->config->startDate->subDays(30);
        $seq = 0;
        $productRows = [];
        $bomRows = [];
        $stockRows = [];

        foreach ($selected as $def) {
            $seq++;
            $code = sprintf('DS%d-%s', $tenantId, $def['code']);
            $existing = DB::table('products')
                ->where('tenant_id', $tenantId)
                ->where('product_code', $code)
                ->first();

            if ($existing) {
                $productId = (int) $existing->id;
            } else {
                // Margin deterministik 63–72% dari harga jual, dibulatkan ke 100.
                $costRatio = $rng->floatBetween(0.28, 0.37);
                $bomCost = 0.0;
                foreach ($def['bom'] as $ingCode => $qty) {
                    $ingDef = collect(CatalogFixtures::ingredients())->firstWhere('code', $ingCode);
                    if ($ingDef) {
                        $bomCost += (float) $ingDef['cost'] * (float) $qty;
                    }
                }
                $standardCost = $def['retail'] ? round($def['price'] * $costRatio, 2) : round($bomCost, 2);

                $productId = $this->writer->insertGetId('products', [
                    'tenant_id' => $tenantId,
                    'product_code' => $code,
                    'barcode' => $def['retail'] ? sprintf('899%09d', $rng->int(100000000, 999999999)) : null,
                    'product_type' => 'standard',
                    'status' => 'active',
                    'has_variants' => 0,
                    'track_inventory' => $def['retail'] ? 1 : 1,
                    'category_id' => $this->categoryIds[$def['category']] ?? null,
                    'product_name' => $def['name'],
                    'product_price' => (int) $def['price'],
                    'costing_method' => 'standard',
                    'standard_cost' => $standardCost,
                    'is_tax_inclusive' => 0,
                    'product_description' => null,
                    'stock' => 0,
                    'is_active' => 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }

            foreach ($def['bom'] as $ingCode => $qty) {
                if (! isset($this->ingredientIds[$ingCode])) {
                    continue;
                }
                $bomRows[] = [
                    'tenant_id' => $tenantId,
                    'product_id' => $productId,
                    'ingredient_id' => $this->ingredientIds[$ingCode],
                    'quantity_required' => $qty,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
            }

            $this->products[] = [
                'id' => $productId,
                'code' => $code,
                'name' => $def['name'],
                'category' => $def['category'],
                'price' => (int) $def['price'],
                'cost' => (float) ($standardCost ?? 0),
                'retail' => (bool) $def['retail'],
                'popularity' => (int) $def['popularity'],
                'bom' => $def['bom'],
            ];
        }

        // BOM: upsert per (product_id, ingredient_id) — unique tanpa tenant.
        if ($bomRows !== []) {
            $existingPairs = DB::table('product_ingredients')
                ->where('tenant_id', $tenantId)
                ->get(['product_id', 'ingredient_id'])
                ->map(fn ($r) => $r->product_id.':'.$r->ingredient_id)
                ->all();
            $existingSet = array_flip($existingPairs);
            $newBom = array_values(array_filter(
                $bomRows,
                fn ($r) => ! isset($existingSet[$r['product_id'].':'.$r['ingredient_id']])
            ));
            if ($newBom !== []) {
                foreach (array_chunk($newBom, $this->writer->batchSize()) as $chunk) {
                    DB::table('product_ingredients')->insert($chunk);
                    $this->writer->markWritten('product_ingredients', count($chunk));
                }
            }
        }

        $this->writer->flushAll();
    }

    private function ensureModifiers(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(30);
        $created = [];

        foreach (CatalogFixtures::modifierGroups() as $name => [$selection, $required, $options]) {
            $group = DB::table('modifier_groups')
                ->where('tenant_id', $tenantId)->where('name', $name)->whereNull('deleted_at')->first();

            if (! $group) {
                $groupId = (int) DB::table('modifier_groups')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'selection_type' => $selection,
                    'is_required' => $required ? 1 : 0,
                    'min_select' => $required ? 1 : 0,
                    'max_select' => $selection === 'single' ? 1 : count($options),
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            } else {
                $groupId = (int) $group->id;
            }

            foreach ($options as $idx => $opt) {
                [$modName, $extra] = [$opt[0], (int) $opt[1]];
                $exists = DB::table('modifiers')
                    ->where('tenant_id', $tenantId)
                    ->where('modifier_group_id', $groupId)
                    ->where('name', $modName)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }

                $modifierId = (int) DB::table('modifiers')->insertGetId([
                    'tenant_id' => $tenantId,
                    'modifier_group_id' => $groupId,
                    'name' => $modName,
                    'extra_price' => $extra,
                    'is_default' => $idx === 0 ? 1 : 0,
                    'is_active' => 1,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);

                // BOM modifier (jika menambah bahan baku).
                if (isset($opt[2], $opt[3]) && isset($this->ingredientIds[$opt[2]])) {
                    $linkExists = DB::table('modifier_ingredients')
                        ->where('tenant_id', $tenantId)
                        ->where('modifier_id', $modifierId)
                        ->where('ingredient_id', $this->ingredientIds[$opt[2]])
                        ->exists();
                    if (! $linkExists) {
                        DB::table('modifier_ingredients')->insert([
                            'tenant_id' => $tenantId,
                            'modifier_id' => $modifierId,
                            'ingredient_id' => $this->ingredientIds[$opt[2]],
                            'quantity_required' => $opt[3],
                            'created_at' => $ts,
                            'updated_at' => $ts,
                        ]);
                    }
                }
            }
            $created[$name] = $groupId;
        }

        // Hubungkan group minuman ke produk minuman (bukan retail/food).
        $beverageCats = ['ESP', 'SIG', 'MLK', 'TEA', 'FRP'];
        $drinkGroupIds = [
            $created['Sugar Level'] ?? null,
            $created['Ice Level'] ?? null,
            $created['Pilihan Susu'] ?? null,
            $created['Extra Topping'] ?? null,
        ];
        $drinkGroupIds = array_values(array_filter($drinkGroupIds));

        $rows = [];
        foreach ($this->products as $p) {
            if (! in_array($p['category'], $beverageCats, true)) {
                continue;
            }
            foreach ($drinkGroupIds as $gid) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'product_id' => $p['id'],
                    'modifier_group_id' => $gid,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ];
            }
        }

        if ($rows !== []) {
            $existing = DB::table('modifier_group_product')
                ->where('tenant_id', $tenantId)
                ->get(['product_id', 'modifier_group_id'])
                ->map(fn ($r) => $r->product_id.':'.$r->modifier_group_id)
                ->all();
            $set = array_flip($existing);
            $new = array_values(array_filter(
                $rows,
                fn ($r) => ! isset($set[$r['product_id'].':'.$r['modifier_group_id']])
            ));
            foreach (array_chunk($new, $this->writer->batchSize()) as $chunk) {
                DB::table('modifier_group_product')->insert($chunk);
                $this->writer->markWritten('modifier_group_product', count($chunk));
            }
        }
    }

    private function ensureTables(int $tenantId, array $stores, SeededRandom $rng): void
    {
        $areas = CatalogFixtures::tableAreas();

        foreach ($stores as $store) {
            $tableCount = $rng->int(10, 18);
            for ($i = 1; $i <= $tableCount; $i++) {
                $tableName = sprintf('T%02d', $i);
                $exists = DB::table('tables')
                    ->where('tenant_id', $tenantId)
                    ->where('store_id', $store['id'])
                    ->where('table_name', $tableName)
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }

                DB::table('tables')->insert([
                    'tenant_id' => $tenantId,
                    'store_id' => $store['id'],
                    'table_code' => sprintf('%s-%s', $store['code'], $tableName),
                    'table_name' => $tableName,
                    'capacity' => $rng->weighted([2 => 30, 4 => 50, 6 => 15, 8 => 5]),
                    'area' => $areas[($i - 1) % count($areas)],
                    'is_active' => 1,
                    'operational_status' => 'available',
                    'secure_token' => (string) Str::uuid(),
                    'created_at' => $store['opened_at'] ?? $this->config->startDate,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function ensureDiscounts(int $tenantId, array $stores): void
    {
        $ts = $this->config->startDate->subDays(20);

        $defs = [
            ['WEEKDAY10', 'Weekday 10% Off', 'percentage', 10, 25000, 50000, 'order', null],
            ['MEMBER15', 'Member 15% Off', 'percentage', 15, 40000, 100000, 'order', null],
            ['PAYDAY25', 'Payday Rp25.000 Off', 'fixed', 25000, 25000, 150000, 'order', null],
            ['COFFEE5K', 'Potongan Rp5.000 Coffee', 'fixed', 5000, 5000, 30000, 'order', null],
            ['GRANDOPEN', 'Grand Opening 20%', 'percentage', 20, 50000, 80000, 'order', null],
            ['FLASHSALE', 'Flash Sale Weekend', 'percentage', 12, 30000, 60000, 'order', null],
        ];

        foreach ($defs as $idx => [$code, $name, $type, $value, $max, $min, $scope, $_]) {
            $exists = DB::table('discounts')
                ->where('tenant_id', $tenantId)->where('code', $code)->whereNull('deleted_at')->exists();
            if ($exists) {
                continue;
            }

            // Sebagian promo hanya aktif pada rentang tertentu di tengah tahun.
            $validFrom = $idx >= 4 ? $this->config->startDate->addMonthsNoOverflow(4 + $idx) : $ts;
            $validUntil = $idx >= 4 ? $validFrom->addMonthsNoOverflow(2) : $this->config->endDate;

            DB::table('discounts')->insert([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'value' => $value,
                'max_discount_amount' => $max,
                'min_purchase_amount' => $min,
                'usage_limit' => null,
                'is_stackable' => 0,
                'scope' => $scope,
                'is_active' => 1,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'created_at' => $ts,
                'updated_at' => $ts,
                'store_id' => null,
            ]);
        }
    }

    private function ensureLoyaltyTiers(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(30);
        $tiers = [
            ['BRONZE', 'Bronze', 0, 1.00, ['discount' => 0]],
            ['SILVER', 'Silver', 500, 1.10, ['discount' => 5]],
            ['GOLD', 'Gold', 2000, 1.20, ['discount' => 10]],
            ['PLATINUM', 'Platinum', 5000, 1.35, ['discount' => 15]],
            ['DIAMOND', 'Diamond', 12000, 1.50, ['discount' => 20]],
        ];

        foreach ($tiers as [$code, $name, $minPoints, $mult, $benefits]) {
            $exists = DB::table('loyalty_tiers')
                ->where('tenant_id', $tenantId)->where('tier_code', $code)->exists();
            if ($exists) {
                continue;
            }
            DB::table('loyalty_tiers')->insert([
                'tenant_id' => $tenantId,
                'tier_code' => $code,
                'name' => $name,
                'min_points' => $minPoints,
                'points_multiplier' => $mult,
                'benefits' => json_encode($benefits),
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }
    }

    private function ensureSuppliers(int $tenantId): void
    {
        $ts = $this->config->startDate->subDays(45);
        $limit = max(1, $this->config->volumeInt('suppliers', count(CatalogFixtures::suppliers())));
        $defs = array_slice(CatalogFixtures::suppliers(), 0, $limit, true);

        foreach ($defs as $code => $sup) {
            $exists = DB::table('suppliers')
                ->where('tenant_id', $tenantId)->where('code', $code)->whereNull('deleted_at')->exists();
            if ($exists) {
                continue;
            }
            DB::table('suppliers')->insert([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $sup['name'],
                'normalized_name' => strtolower($sup['name']),
                'email' => strtolower($code).'@supplier.example.invalid',
                'phone' => '021-'.sprintf('%08d', crc32($code) % 100000000),
                'tax_id' => sprintf('01.%03d.%03d.%d-%03d.000', crc32($code) % 999, crc32($sup['name']) % 999, crc32($code.$sup['name']) % 9, crc32($code) % 999),
                'address' => 'Kawasan Industri '.$sup['name'].', Jakarta',
                'payment_terms_days' => $sup['terms'],
                'currency_code' => 'IDR',
                'status' => 'active',
                'created_by_user_id' => null,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }

        // supplier_products: hubungkan ingredient ke pemasoknya.
        $supplierIds = DB::table('suppliers')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->pluck('id', 'code')->all();

        $rows = [];
        foreach (array_slice(CatalogFixtures::ingredients(), 0, max(10, $this->config->volumeInt('ingredients', 60))) as $ing) {
            if (! isset($supplierIds[$ing['supplier']], $this->ingredientIds[$ing['code']])) {
                continue;
            }
            $rows[] = [
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierIds[$ing['supplier']],
                'purchasable_type' => 'ingredient',
                'purchasable_id' => $this->ingredientIds[$ing['code']],
                'supplier_sku' => 'SUP-'.$ing['code'],
                'purchase_uom' => $ing['unit'],
                'uom_conversion_factor' => 1,
                'standard_price' => $ing['cost'],
                'last_purchase_price' => $ing['cost'],
                'lead_time_days' => CatalogFixtures::suppliers()[$ing['supplier']]['lead'] ?? 2,
                'min_order_qty' => 1,
                'order_multiple_qty' => 1,
                'is_preferred' => 1,
                'is_active' => 1,
                'created_at' => $ts,
                'updated_at' => $ts,
            ];
        }

        if ($rows !== []) {
            $existing = DB::table('supplier_products')
                ->where('tenant_id', $tenantId)
                ->get(['supplier_id', 'purchasable_id'])
                ->map(fn ($r) => $r->supplier_id.':'.$r->purchasable_id)
                ->all();
            $set = array_flip($existing);
            $new = array_values(array_filter(
                $rows,
                fn ($r) => ! isset($set[$r['supplier_id'].':'.$r['purchasable_id']])
            ));
            foreach (array_chunk($new, $this->writer->batchSize()) as $chunk) {
                DB::table('supplier_products')->insert($chunk);
                $this->writer->markWritten('supplier_products', count($chunk));
            }
        }
    }

    /**
     * @return array<int, array{id:int,joined_at:CarbonImmutable}>
     */
    private function ensureCustomers(int $tenantId, SeededRandom $rng): array
    {
        $target = max(10, $this->config->volumeInt('customers', 900));
        $existing = DB::table('customers')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'join_date']);
        $customers = $existing->map(fn ($c) => [
            'id' => (int) $c->id,
            'joined_at' => $c->join_date ? CarbonImmutable::parse($c->join_date) : $this->config->startDate,
        ])->all();

        $need = $target - count($customers);
        if ($need <= 0) {
            return $customers;
        }

        $first = CatalogFixtures::firstNames();
        $last = CatalogFixtures::lastNames();
        $seqStart = count($customers) + 1;
        $rows = [];

        for ($i = 0; $i < $need; $i++) {
            $seq = $seqStart + $i;
            $fn = $first[$rng->int(0, count($first) - 1)];
            $ln = $last[$rng->int(0, count($last) - 1)];
            $joinedAt = $this->config->startDate
                ->addDays($rng->int(0, max(1, $this->config->totalDays() - 20)));
            $rows[] = [
                'tenant_id' => $tenantId,
                'customer_code' => sprintf('CUS%05d', $seq),
                'name' => "{$fn} {$ln}",
                'phone' => '08'.$rng->int(11, 99).sprintf('%08d', $seq * 7919 % 99999999),
                'email' => sprintf('cust%05d@example.invalid', $seq),
                'date_of_birth' => $this->config->startDate
                    ->subYears($rng->int(18, 55))->addDays($rng->int(0, 364))->toDateString(),
                'join_date' => $joinedAt->toDateString(),
                'is_active' => 1,
                'created_at' => $joinedAt,
                'updated_at' => $joinedAt,
            ];
        }

        foreach (array_chunk($rows, $this->writer->batchSize()) as $chunk) {
            DB::table('customers')->insert($chunk);
            $this->writer->markWritten('customers', count($chunk));
        }

        $fresh = DB::table('customers')
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'join_date']);

        return $fresh->map(fn ($c) => [
            'id' => (int) $c->id,
            'joined_at' => $c->join_date ? CarbonImmutable::parse($c->join_date) : $this->config->startDate,
        ])->all();
    }
}
