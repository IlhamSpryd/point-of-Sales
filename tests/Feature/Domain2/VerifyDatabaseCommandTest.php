<?php

declare(strict_types=1);

namespace Tests\Feature\Domain2;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Domain 2: Database Verification Command & SQL Integrity Check Tests.
 *
 * Menguji bahwa `pos:verify-database` berhasil mengeksekusi kesepuluh skrip
 * SQL detektor anomali dan secara akurat mendeteksi anomali pada:
 * - 03_order_totals.sql
 * - 04_payment_totals.sql
 * - 05_inventory_balances.sql
 */
class VerifyDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private int $tenantId;

    private int $storeId;

    private int $userId;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $context = app(TenantContext::class);
        $context->clear();

        $tenant = Tenant::create(['name' => 'Verify DB Tenant '.Str::random(6), 'readiness_status' => 'active']);
        $this->tenantId = (int) $tenant->id;

        $store = Store::withoutTenantScope()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Verify DB Store '.Str::random(6),
        ]);
        $this->storeId = (int) $store->id;

        $context->setTenantId($this->tenantId);
        $context->setStoreId($this->storeId);

        $user = User::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Verify User '.Str::random(6),
            'email' => 'verify-'.uniqid().'@verify.test',
            'password_hash' => bcrypt('password123'),
        ]);
        $user->stores()->attach($this->storeId, ['tenant_id' => $this->tenantId]);
        $this->userId = (int) $user->id;
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /**
     * Test 1: Verifikasi database bersih menghasilkan PASS pada semua skrip.
     */
    public function test_verify_database_passes_on_clean_state(): void
    {
        $exitCode = Artisan::call('pos:verify-database');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, "Output:\n".$output);
        $this->assertStringNotContainsString('FAILED', $output);
        $this->assertStringContainsString('Verification Suite completed SUCCESSFULLY', $output);
    }

    /**
     * Test 2: Verifikasi deteksi ketidaksesuaian saldo stok (05_inventory_balances.sql).
     */
    public function test_verify_database_detects_inventory_balance_discrepancy(): void
    {
        $category = Category::create([
            'tenant_id' => $this->tenantId,
            'category_name' => 'Cat '.Str::random(6),
        ]);

        // Masukkan produk dengan stok 50 dan product_stock_balances 100 tanpa movements untuk memicu drift
        $productId = DB::table('products')->insertGetId([
            'tenant_id' => $this->tenantId,
            'category_id' => $category->id,
            'product_name' => 'Drift Stock Prod',
            'product_price' => 10000,
            'stock' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_stock_balances')->insert([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'product_id' => $productId,
            'quantity' => 100.0000,
            'baseline_quantity' => 0.0000,
            'baseline_movement_id' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('pos:verify-database');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('05_inventory_balances.sql', $output);
        $this->assertStringContainsString('FAILED: Found', $output);
    }

    /**
     * Test 3: Verifikasi deteksi ketidaksesuaian total order (03_order_totals.sql).
     */
    public function test_verify_database_detects_order_totals_discrepancy(): void
    {
        // Masukkan order dengan total tagihan yang tidak cocok dengan subtotal - diskon + pajak
        DB::table('orders')->insert([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'order_code' => 'ORD-DISC-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'order_status' => 'pending',
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 99999, // Rusak: harusnya 50000
            'order_change' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('pos:verify-database');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('03_order_totals.sql', $output);
        $this->assertStringContainsString('FAILED: Found', $output);
    }

    /**
     * Test 4: Verifikasi deteksi ketidaksesuaian total payment pada order lunas (04_payment_totals.sql).
     */
    public function test_verify_database_detects_payment_totals_discrepancy(): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'user_id' => $this->userId,
            'order_code' => 'ORD-PAY-'.uniqid(),
            'idempotency_key' => (string) Str::uuid(),
            'order_date' => now()->toDateString(),
            'order_type' => 'takeaway',
            'order_status' => 'paid', // Status paid
            'payment_method' => 'cash',
            'subtotal_amount' => 50000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_charge_amount' => 0,
            'order_amount' => 50000,
            'order_change' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Masukkan payment dengan nominal kurang dari tagihan (20000 < 50000)
        DB::table('payments')->insert([
            'tenant_id' => $this->tenantId,
            'store_id' => $this->storeId,
            'order_id' => $orderId,
            'payment_method' => 'cash',
            'amount' => 20000, // Discrepancy: paid order but payment < order_amount
            'status' => 'captured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('pos:verify-database');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('04_payment_totals.sql', $output);
        $this->assertStringContainsString('FAILED: Found', $output);
    }
}
