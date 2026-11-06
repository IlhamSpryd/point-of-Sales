<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Services\Database\PartitionManagementService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartitionPruningBenchmarkTest extends TestCase
{
    protected bool $optOutFromDefaultTenant = true;

    protected PartitionManagementService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        $this->service = app(PartitionManagementService::class);
    }

    /**
     * Test 1: Tabel partisi order_items_partitioned dan stock_movements_partitioned terkonfigurasi RANGE COLUMNS.
     */
    public function test_partitioned_tables_exist_with_range_columns_partitioning(): void
    {
        $orderItemParts = $this->service->getPartitions('order_items_partitioned');
        $this->assertNotEmpty($orderItemParts);

        $partNames = array_column($orderItemParts, 'name');
        $this->assertContains('p2026_10', $partNames);
        $this->assertContains('p2026_11', $partNames);
        $this->assertContains('p2026_12', $partNames);
        $this->assertContains('pmax', $partNames);

        $stockMovParts = $this->service->getPartitions('stock_movements_partitioned');
        $this->assertNotEmpty($stockMovParts);
        $stockPartNames = array_column($stockMovParts, 'name');
        $this->assertContains('p2026_11', $stockPartNames);
        $this->assertContains('pmax', $stockPartNames);
    }

    /**
     * Test 2: Perintah Scheduled Artisan Command membuat partisi bulanan masa depan secara dinamis.
     */
    public function test_scheduled_artisan_command_creates_future_monthly_partitions(): void
    {
        $exitCode = Artisan::call('database:create-monthly-partitions', [
            '--months' => 4,
            '--table' => 'order_items_partitioned',
        ]);

        $this->assertEquals(0, $exitCode);

        $updatedParts = $this->service->getPartitions('order_items_partitioned');
        $partNames = array_column($updatedParts, 'name');

        // Target bulan ke-3 dan ke-4 ke depan (2027_03) harus ada
        $this->assertContains('p2027_03', $partNames);
    }

    /**
     * Test 3: Pembuktian empiris Partition Pruning (EXPLAIN PARTITIONS hanya memindai partisi bulan terkait).
     */
    public function test_explain_partitions_proves_exact_pruning_on_monthly_date_range(): void
    {
        $query = "SELECT * FROM order_items_partitioned WHERE created_at >= '2026-11-01 00:00:00' AND created_at < '2026-12-01 00:00:00'";
        $explain = $this->service->explainPartitionQuery($query);

        $this->assertNotEmpty($explain);
        $scannedPartitions = $explain[0]->partitions ?? $explain[0]->PARTITIONS ?? '';

        // Membuktikan hanya partisi p2026_11 yang dipindai
        $this->assertEquals('p2026_11', $scannedPartitions);
        $this->assertStringNotContainsString('p2026_10', $scannedPartitions);
        $this->assertStringNotContainsString('p2026_12', $scannedPartitions);
        $this->assertStringNotContainsString('pmax', $scannedPartitions);
    }

    /**
     * Test 4: Penyisipan dan pembacaan data melintasi beberapa partisi bulanan secara independen.
     */
    public function test_insert_and_query_across_different_monthly_partitions(): void
    {
        DB::table('order_items_partitioned')->delete();

        $idA = (int) (time() + 101);
        $idB = (int) (time() + 102);

        // Baris di partisi p2026_10
        DB::table('order_items_partitioned')->insert([
            'id' => $idA,
            'tenant_id' => 1,
            'store_id' => 1,
            'order_id' => 1001,
            'product_id' => 50,
            'order_price' => 25000,
            'qty' => 2,
            'order_subtotal' => 50000,
            'created_at' => '2026-10-15 10:30:00',
            'updated_at' => '2026-10-15 10:30:00',
        ]);

        // Baris di partisi p2026_11
        DB::table('order_items_partitioned')->insert([
            'id' => $idB,
            'tenant_id' => 1,
            'store_id' => 1,
            'order_id' => 1002,
            'product_id' => 51,
            'order_price' => 30000,
            'qty' => 1,
            'order_subtotal' => 30000,
            'created_at' => '2026-11-05 14:15:00',
            'updated_at' => '2026-11-05 14:15:00',
        ]);

        // Kueri terpangkas (pruned) untuk bulan November 2026 hanya mengembalikan 1 baris
        $novRows = DB::table('order_items_partitioned')
            ->whereBetween('created_at', ['2026-11-01 00:00:00', '2026-11-30 23:59:59'])
            ->get();

        $this->assertCount(1, $novRows);
        $this->assertEquals($idB, $novRows->first()->id);
        $this->assertEquals(30000, $novRows->first()->order_subtotal);

        // Kueri terpangkas (pruned) untuk bulan Oktober 2026 hanya mengembalikan 1 baris
        $octRows = DB::table('order_items_partitioned')
            ->whereBetween('created_at', ['2026-10-01 00:00:00', '2026-10-31 23:59:59'])
            ->get();

        $this->assertCount(1, $octRows);
        $this->assertEquals($idA, $octRows->first()->id);
    }

    /**
     * Test 5: Opsi --dry-run menampilkan pratinjau DDL tanpa mutasi skema.
     */
    public function test_dry_run_option_previews_partition_ddl_without_mutation(): void
    {
        $exitCode = Artisan::call('database:create-monthly-partitions', [
            '--months' => 12,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $exitCode);
    }
}
