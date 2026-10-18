<?php

namespace Tests\Feature\Domain1;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProvisioningNonce;
use App\Models\Role;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\CartService;
use App\Services\Context\TenantContext;
use App\Services\SystemUserResolver;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Tests\TestCase;

class Domain1AuditRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    /**
     * DB-017 / OR-001 / SEC-024: System user email and resolution consistency
     */
    public function test_system_user_emails_and_resolver_are_fully_unified_across_tenants(): void
    {
        // 1. Tenant 1 config-based emails
        $this->assertSame(
            config('pos.self_order_system_email', 'selforder@system.local'),
            SystemUserResolver::selfOrderEmail(1)
        );
        $this->assertSame(
            config('pos.channel_order_system_email', 'channel@system.local'),
            SystemUserResolver::channelEmail(1)
        );

        // 2. Tenant > 1 deterministic tenant-scoped emails
        $this->assertSame('selforder.t2@system.local', SystemUserResolver::selfOrderEmail(2));
        $this->assertSame('channel.t2@system.local', SystemUserResolver::channelEmail(2));

        // 3. Provisioning baseline creates exact emails expected by SystemUserResolver
        $tenant1 = Tenant::create(['name' => 'Kedai Kopi Uji 1', 'readiness_status' => 'pending']);
        $context = app(TenantContext::class);
        $ownerRole1 = null;
        $owner1 = null;
        $context->runAs($tenant1->id, null, function () use ($tenant1, &$ownerRole1, &$owner1) {
            $ownerRole1 = Role::create([
                'tenant_id' => $tenant1->id,
                'name' => 'Owner',
                'role_code' => 'ROL-OWNER-1',
                'permissions' => ['*'],
                'is_active' => true,
            ]);
            $owner1 = User::create([
                'tenant_id' => $tenant1->id,
                'name' => 'Pemilik Uji 1',
                'email' => 'owner.uji1@example.com',
                'role_id' => $ownerRole1->id,
                'is_active' => true,
            ]);
            Store::create(['tenant_id' => $tenant1->id, 'name' => 'Store 1']);
        });

        $service = app(TenantProvisioningService::class);
        $service->provisionBaseline($tenant1, $owner1);

        // Verify resolver finds both system users for tenant 1
        $selfOrderUser1 = SystemUserResolver::selfOrder((int) $tenant1->id);
        $channelUser1 = SystemUserResolver::channel((int) $tenant1->id);

        $this->assertInstanceOf(User::class, $selfOrderUser1);
        $this->assertInstanceOf(User::class, $channelUser1);
        $this->assertSame(SystemUserResolver::selfOrderEmail((int) $tenant1->id), $selfOrderUser1->email);
        $this->assertSame(SystemUserResolver::channelEmail((int) $tenant1->id), $channelUser1->email);

        // Also test for a second tenant (tenant > 1)
        $tenant2 = Tenant::create(['name' => 'Kedai Kopi Uji 2', 'readiness_status' => 'pending']);
        $ownerRole2 = null;
        $owner2 = null;
        $context->runAs($tenant2->id, null, function () use ($tenant2, &$ownerRole2, &$owner2) {
            $ownerRole2 = Role::create([
                'tenant_id' => $tenant2->id,
                'name' => 'Owner',
                'role_code' => 'ROL-OWNER-2',
                'permissions' => ['*'],
                'is_active' => true,
            ]);
            $owner2 = User::create([
                'tenant_id' => $tenant2->id,
                'name' => 'Pemilik Uji 2',
                'email' => 'owner.uji2@example.com',
                'role_id' => $ownerRole2->id,
                'is_active' => true,
            ]);
            Store::create(['tenant_id' => $tenant2->id, 'name' => 'Store 2']);
        });

        $service->provisionBaseline($tenant2, $owner2);

        $selfOrderUser2 = SystemUserResolver::selfOrder((int) $tenant2->id);
        $channelUser2 = SystemUserResolver::channel((int) $tenant2->id);

        $this->assertSame("selforder.t{$tenant2->id}@system.local", $selfOrderUser2->email);
        $this->assertSame("channel.t{$tenant2->id}@system.local", $channelUser2->email);

        // Verify readiness evaluation passes for both
        $this->assertTrue($service->evaluateReadiness($tenant1)['ready']);
        $this->assertTrue($service->evaluateReadiness($tenant2)['ready']);
    }

    /**
     * DB-016 / OR-003: Password reset writes both password and password_hash
     */
    public function test_password_reset_updates_password_hash_and_allows_login_with_new_password(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant Reset Test']);
        $context = app(TenantContext::class);

        $initialPlain = 'OldSecretPass123!';
        $initialHash = Hash::make($initialPlain);
        $user = null;

        $context->runAs($tenant->id, null, function () use ($tenant, $initialHash, &$user) {
            $role = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Owner',
                'role_code' => 'ROL-OWNER-RESET',
                'permissions' => ['*'],
                'is_active' => true,
            ]);

            // Activated owner has password_hash populated
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Reset User',
                'email' => 'reset.user@example.com',
                'password' => $initialHash,
                'password_hash' => $initialHash,
                'role_id' => $role->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        });

        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => $initialPlain]));
        Auth::logout();

        // Perform password reset using NewPasswordController
        $brokerToken = Password::broker()->createToken($user);
        $newPlain = 'NewSecretPass456!';

        $response = $this->post(route('password.store'), [
            'token' => $brokerToken,
            'email' => $user->email,
            'password' => $newPlain,
            'password_confirmation' => $newPlain,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('login'));

        $user->refresh();

        // Both columns must reflect the new password
        $this->assertTrue(Hash::check($newPlain, $user->password_hash));
        $this->assertTrue(Hash::check($newPlain, $user->password));

        // User can now log in with new password and cannot log in with old password
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => $initialPlain]));
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => $newPlain]));
    }

    /**
     * OR-008: Table secure token is random UUID and collision-free across tenants
     */
    public function test_tables_with_identical_names_across_tenants_generate_distinct_uuid_tokens(): void
    {
        $tenant1 = Tenant::create(['name' => 'Cafe 1']);
        $tenant2 = Tenant::create(['name' => 'Cafe 2']);
        $store1 = Store::create(['tenant_id' => $tenant1->id, 'name' => 'Store Cafe 1']);
        $store2 = Store::create(['tenant_id' => $tenant2->id, 'name' => 'Store Cafe 2']);

        $context = app(TenantContext::class);

        $table1 = $context->runAs($tenant1->id, $store1->id, function () {
            return Table::create(['table_name' => 'Meja 1', 'capacity' => 4]);
        });

        $table2 = $context->runAs($tenant2->id, $store2->id, function () {
            return Table::create(['table_name' => 'Meja 1', 'capacity' => 4]);
        });

        $this->assertNotNull($table1->secure_token);
        $this->assertNotNull($table2->secure_token);
        $this->assertNotSame($table1->secure_token, $table2->secure_token);

        // Tokens must be valid UUIDs, not predictable MD5 hashes
        $this->assertTrue(Str::isUuid($table1->secure_token));
        $this->assertTrue(Str::isUuid($table2->secure_token));
        $this->assertNotSame(md5('apeiron-pos-qr-meja 1'), $table1->secure_token);
    }

    /**
     * SEC-016 / OR-012: Mailer log leak in production failover guard
     */
    public function test_production_mailer_guard_forbids_log_in_failover_mailers(): void
    {
        $app = $this->app;

        // In local/testing environment, log or failover does not throw
        $provider = new AppServiceProvider($app);
        $provider->boot();

        // In production environment, failover containing log must throw RuntimeException
        $origEnv = $app['env'];
        $app['env'] = 'production';

        Config::set('services.midtrans.server_key', 'mock-prod-key');
        Config::set('mail.default', 'failover');
        Config::set('mail.mailers.failover', [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
        ]);

        $thrown = false;
        try {
            $provider->boot();
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertStringContainsString('Mail driver "log" dilarang di production', $e->getMessage());
        } finally {
            $app['env'] = $origEnv;
        }

        $this->assertTrue($thrown, 'Expected RuntimeException when failover includes log in production');
    }

    /**
     * DB-021: ProvisioningNonce model keys and operations
     */
    public function test_provisioning_nonce_model_handles_string_primary_key_operations(): void
    {
        $nonceValue = 'test-nonce-string-key-'.Str::random(32);
        $nonce = ProvisioningNonce::create([
            'nonce' => $nonceValue,
            'expires_at_epoch' => time() + 600,
        ]);

        $this->assertSame($nonceValue, $nonce->getKey());

        // Instance lookup by string PK
        $found = ProvisioningNonce::find($nonceValue);
        $this->assertNotNull($found);
        $this->assertSame($nonceValue, $found->nonce);

        // Instance deletion by string PK
        $deleted = $found->delete();
        $this->assertTrue($deleted);
        $this->assertNull(ProvisioningNonce::find($nonceValue));
    }

    /**
     * DB-014: VerifyDatabase command runs all 10 SQL verification scripts without skip
     */
    public function test_verify_database_command_executes_all_scripts_successfully(): void
    {
        $exitCode = Artisan::call('pos:verify-database');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, 'Expected pos:verify-database to exit with code 0. Output: '.$output);
        $this->assertStringContainsString('Database integrity is verified', $output);
        $this->assertStringNotContainsString('SKIPPED: File is empty', $output);
    }

    /**
     * DB-014: VerifyDatabase fails with non-zero exit code when database discrepancy is detected
     */
    public function test_verify_database_command_fails_when_discrepancy_detected(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        try {
            // Inject an orphan user pointing to non-existent tenant to trigger 01_fk_orphans.sql
            DB::table('users')->insert([
                'tenant_id' => 999999,
                'name' => 'Orphan User Test',
                'email' => 'orphan.test@example.com',
                'email_normalized' => 'orphan.test@example.com',
                'employee_id' => 'ORPH-001',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $exitCode = Artisan::call('pos:verify-database');
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Verification Suite completed with ERRORS', $output);
            $this->assertStringContainsString('FAILED: Found 1 discrepancies', $output);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }
    }

    /**
     * DB-014: VerifyDatabase fails when a script has SQL syntax exception
     */
    public function test_verify_database_command_fails_on_sql_exception(): void
    {
        $tempFile = base_path('database/sql/verify/99_temp_error.sql');
        file_put_contents($tempFile, 'SELECT * FROM non_existent_table_xyz_123;');

        try {
            $exitCode = Artisan::call('pos:verify-database');
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('ERROR Executing Script', $output);
            $this->assertStringContainsString('Verification Suite completed with ERRORS', $output);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * DB-014: VerifyDatabase fails on empty script unless --allow-skipped is provided
     */
    public function test_verify_database_command_fails_on_empty_script_unless_allowed(): void
    {
        $tempFile = base_path('database/sql/verify/99_temp_empty.sql');
        file_put_contents($tempFile, '   ');

        try {
            // Default: empty script triggers fail-closed (exit code 1)
            $exitCode = Artisan::call('pos:verify-database');
            $output = Artisan::output();

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('SKIPPED: File is empty', $output);
            $this->assertStringContainsString('Database integrity cannot be verified when checks are skipped', $output);

            // With --allow-skipped: passes
            $exitCodeAllowed = Artisan::call('pos:verify-database', ['--allow-skipped' => true]);
            $outputAllowed = Artisan::output();

            $this->assertSame(0, $exitCodeAllowed);
            $this->assertStringContainsString('SKIPPED: File is empty', $outputAllowed);
            $this->assertStringContainsString('Database integrity is verified', $outputAllowed);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * QR-01: Legacy QR token access logs structured security telemetry
     */
    public function test_legacy_qr_token_access_triggers_structured_telemetry_log(): void
    {
        $logger = Log::spy();

        $tenant = Tenant::create(['name' => 'Kedai Telemetri']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Telemetri']);
        $legacyToken = md5('apeiron-pos-qr-meja 99');

        $context = app(TenantContext::class);
        $table = $context->runAs($tenant->id, $store->id, function () use ($legacyToken) {
            $t = new Table([
                'table_name' => '99',
                'capacity' => 2,
                'is_active' => true,
            ]);
            $t->secure_token = $legacyToken;
            $t->save();

            return $t;
        });

        $response = $this->get(route('customer.menu.index', ['token' => $legacyToken]));
        $response->assertOk();

        $logger->shouldHaveReceived('info')->withArgs(function ($message, $context) use ($table, $tenant, $store) {
            return $message === '[SECURITY] Legacy QR token access detected'
                && ($context['event'] ?? null) === 'legacy_qr_token_accessed'
                && ($context['table_id'] ?? null) === $table->id
                && ($context['tenant_id'] ?? null) === $tenant->id
                && ($context['store_id'] ?? null) === $store->id
                && ($context['is_legacy_token'] ?? null) === true;
        })->once();
    }

    /**
     * QR-01: High-entropy UUID QR token does NOT trigger legacy telemetry
     */
    public function test_uuid_qr_token_access_does_not_trigger_legacy_telemetry_log(): void
    {
        $logger = Log::spy();

        $tenant = Tenant::create(['name' => 'Kedai UUID']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang UUID']);

        $context = app(TenantContext::class);
        $table = $context->runAs($tenant->id, $store->id, function () {
            return Table::create([
                'table_name' => '101',
                'capacity' => 4,
                'is_active' => true,
            ]);
        });

        $this->assertTrue(Str::isUuid($table->secure_token));

        $response = $this->get(route('customer.menu.index', ['token' => $table->secure_token]));
        $response->assertOk();

        $logger->shouldNotHaveReceived('info', function ($message) {
            return $message === '[SECURITY] Legacy QR token access detected';
        });
    }

    /**
     * QR-02: Backoffice owner can regenerate table token on-demand to fresh UUID
     */
    public function test_backoffice_owner_can_regenerate_table_token_on_demand(): void
    {
        $tenant = Tenant::create([
            'name' => 'Kedai Regen',
            'onboarding_status' => Tenant::ONBOARDING_COMPLETED,
        ]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang Regen']);
        $context = app(TenantContext::class);

        $ownerRole = null;
        $owner = null;
        $table = null;
        $oldToken = md5('old-table-token-123');

        $context->runAs($tenant->id, $store->id, function () use ($tenant, $store, $oldToken, &$ownerRole, &$owner, &$table) {
            $ownerRole = Role::create([
                'tenant_id' => $tenant->id,
                'name' => 'Owner',
                'role_code' => 'ROL-OWNER-REGEN',
                'permissions' => ['*'],
                'is_active' => true,
            ]);
            $owner = User::forceCreate([
                'tenant_id' => $tenant->id,
                'name' => 'Pemilik Regen',
                'email' => 'owner.regen@example.com',
                'role_id' => $ownerRole->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $owner->stores()->attach($store->id, ['tenant_id' => $tenant->id]);

            $t = new Table([
                'table_name' => 'VIP-1',
                'capacity' => 6,
                'is_active' => true,
            ]);
            $t->secure_token = $oldToken;
            $t->save();
            $table = $t;
        });

        $response = $this->actingAs($owner)->post(route('tables.regenerate-token', $table->secure_token));
        $response->assertRedirect(route('tables.index'));
        $response->assertSessionHas('success');

        $table->refresh();
        $this->assertNotSame($oldToken, $table->secure_token);
        $this->assertTrue(Str::isUuid($table->secure_token));
    }

    /**
     * QR-02: Cross-tenant table token regeneration is denied (404 / scoped boundary)
     */
    public function test_backoffice_cross_tenant_table_token_regeneration_denied(): void
    {
        $tenant1 = Tenant::create(['name' => 'Tenant 1']);
        $tenant2 = Tenant::create(['name' => 'Tenant 2']);
        $store1 = Store::create(['tenant_id' => $tenant1->id, 'name' => 'Store 1']);
        $store2 = Store::create(['tenant_id' => $tenant2->id, 'name' => 'Store 2']);
        $context = app(TenantContext::class);

        $owner1 = null;
        $table2 = null;

        $context->runAs($tenant1->id, $store1->id, function () use ($tenant1, $store1, &$owner1) {
            $role1 = Role::create([
                'tenant_id' => $tenant1->id,
                'name' => 'Owner',
                'role_code' => 'ROL-OWN-1',
                'permissions' => ['*'],
                'is_active' => true,
            ]);
            $owner1 = User::forceCreate([
                'tenant_id' => $tenant1->id,
                'name' => 'Owner 1',
                'email' => 'owner1.cross@example.com',
                'role_id' => $role1->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $owner1->stores()->attach($store1->id, ['tenant_id' => $tenant1->id]);
        });

        $context->runAs($tenant2->id, $store2->id, function () use (&$table2) {
            $table2 = Table::create([
                'table_name' => 'Meja T2',
                'capacity' => 4,
                'is_active' => true,
            ]);
        });

        // Owner 1 attempting to regenerate Table 2 (Tenant 2) must return 404
        $response = $this->actingAs($owner1)->post(route('tables.regenerate-token', $table2->secure_token));
        $response->assertNotFound();
    }

    /**
     * Helper to setup self-order test environment for Domain 1 acceptance criteria.
     *
     * @return array{tenant: Tenant, store: Store, role: Role, system_user: User, category: Category, product: Product}
     */
    protected function setupSelfOrderEnvironment(?Tenant $tenant = null, ?Store $store = null): array
    {
        $tenant = $tenant ?? Tenant::create(['name' => 'Kedai Kopi Domain 1', 'readiness_status' => 'active']);
        $store = $store ?? Store::create(['tenant_id' => $tenant->id, 'name' => 'Store Domain 1']);
        $context = app(TenantContext::class);
        $context->setTenantId($tenant->id);
        $context->setStoreId($store->id);

        $role = Role::firstOrCreate(
            ['name' => 'Admin', 'tenant_id' => $tenant->id],
            [
                'role_code' => 'ROL-ADM-'.$tenant->id,
                'permissions' => ['*'],
                'is_active' => true,
            ]
        );

        $systemUser = User::firstOrCreate(
            ['email' => SystemUserResolver::selfOrderEmail($tenant->id)],
            [
                'name' => 'System Order '.$tenant->id,
                'password' => Hash::make('password'),
                'role_id' => $role->id,
                'is_active' => true,
                'tenant_id' => $tenant->id,
            ]
        );

        $category = Category::create([
            'tenant_id' => $tenant->id,
            'category_name' => 'Minuman',
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'product_name' => 'Kopi Tubruk',
            'product_price' => 15000,
            'stock' => 50,
            'is_active' => true,
        ]);

        return [
            'tenant' => $tenant,
            'store' => $store,
            'role' => $role,
            'system_user' => $systemUser,
            'category' => $category,
            'product' => $product,
        ];
    }

    /**
     * DOMAIN 1 AC-1: self-order checkout allowed when table is available.
     * Table status transitions from available to occupied. Stock deducted.
     */
    public function test_self_order_checkout_allowed_when_table_available(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-01',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-avail-1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 2, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(2);
            $mock->shouldReceive('getSubtotal')->andReturn(30000);
            $mock->shouldReceive('snapshot')->once();
            $mock->shouldReceive('clear')->once();
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-avail-1',
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'table_id' => $table->id,
            'tenant_id' => $env['tenant']->id,
            'order_status' => 'pending',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $env['product']->id,
            'tenant_id' => $env['tenant']->id,
            'type' => 'sale_deduction',
        ]);

        $table->refresh();
        $this->assertSame('occupied', $table->operational_status);
    }

    /**
     * DOMAIN 1 AC-2: self-order checkout rejected when table is cleaning.
     * Zero orders, zero items, zero payments, zero stock movements.
     */
    public function test_self_order_checkout_rejected_when_table_cleaning(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-02',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'cleaning',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-clean-1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $initialStock = $env['product']->stock;

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-clean-1',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('pembersihan', Session::get('error'));

        // Assert zero orders, items, payments, and stock movements
        $this->assertSame(0, Order::where('table_id', $table->id)->count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertSame($initialStock, $env['product']->fresh()->stock);
    }

    /**
     * DOMAIN 1 AC-3: self-order checkout allowed when table is occupied for repeat/add-on orders.
     */
    public function test_self_order_checkout_allowed_when_table_occupied_for_repeat_orders(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-03',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'occupied',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-occ-1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
            $mock->shouldReceive('getSubtotal')->andReturn(15000);
            $mock->shouldReceive('snapshot')->once();
            $mock->shouldReceive('clear')->once();
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-occ-1',
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'table_id' => $table->id,
            'tenant_id' => $env['tenant']->id,
        ]);
        $table->refresh();
        $this->assertSame('occupied', $table->operational_status);
    }

    /**
     * DOMAIN 1 AC-4: self-order checkout rejected when table is reserved without staff activation.
     */
    public function test_self_order_checkout_rejected_when_table_reserved_without_staff_activation(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-04',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'reserved',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-res-1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-res-1',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('direservasi', Session::get('error'));

        $this->assertSame(0, Order::where('table_id', $table->id)->count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * DOMAIN 1 AC-5: self-order checkout allowed after authorized staff activates/confirms reserved table.
     */
    public function test_self_order_checkout_allowed_after_staff_activates_reserved_table(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-05',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'reserved',
            'secure_token' => Str::uuid()->toString(),
        ]);

        // 1. Initial attempt while reserved is rejected
        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-res-attempt1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
            $mock->shouldReceive('getSubtotal')->andReturn(15000);
            $mock->shouldReceive('snapshot')->byDefault();
            $mock->shouldReceive('clear')->byDefault();
        });

        $res1 = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-res-attempt1',
            'payment_method' => 'cash',
        ]);
        $res1->assertSessionHas('error');
        $this->assertSame(0, Order::where('table_id', $table->id)->count());

        // 2. Staff activates table by confirming guest arrival (updates operational_status to 'available')
        app(TenantContext::class)->runAs($env['tenant']->id, $env['store']->id, function () use ($table) {
            $table->update(['operational_status' => 'available']);
        });

        // 3. Second checkout attempt succeeds
        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-res-attempt2');

        $res2 = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-res-attempt2',
            'payment_method' => 'cash',
        ]);
        $res2->assertRedirect();
        $this->assertDatabaseHas('orders', [
            'table_id' => $table->id,
            'tenant_id' => $env['tenant']->id,
        ]);
    }

    /**
     * DOMAIN 1 AC-6: race condition protection - status change between QR scan and checkout is enforced.
     */
    public function test_self_order_checkout_status_change_between_scan_and_checkout_race_protection(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-06',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        // Customer scanned QR when table was available
        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-race-1');

        // Race condition: staff updates table to 'cleaning' before customer submits checkout
        $table->update(['operational_status' => 'cleaning']);

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-race-1',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('pembersihan', Session::get('error'));
        $this->assertSame(0, Order::where('table_id', $table->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    /**
     * DOMAIN 1 AC-7: deterministic table quota lock concurrency protection.
     */
    public function test_self_order_checkout_table_quota_lock_concurrency_protection(): void
    {
        $env = $this->setupSelfOrderEnvironment();
        $table = Table::create([
            'tenant_id' => $env['tenant']->id,
            'store_id' => $env['store']->id,
            'table_name' => 'T-07',
            'capacity' => 4,
            'is_active' => true,
            'operational_status' => 'available',
            'secure_token' => Str::uuid()->toString(),
        ]);

        Session::put('current_table_id', $table->id);
        Session::put('current_tenant_id', $env['tenant']->id);
        Session::put('current_store_id', $env['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-quota-1');

        $this->mock(CartService::class, function ($mock) use ($env) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
        });

        // Hold quota lock simulating concurrent in-flight checkout on the same table
        $quotaLock = Cache::lock('table_quota_'.$table->id, 15);
        $quotaLock->get();

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-quota-1',
            'payment_method' => 'cash',
        ]);

        $response->assertSessionHas('error', 'Pesanan Anda sedang diproses. Mohon tunggu.');
        $this->assertSame(0, Order::where('table_id', $table->id)->count());

        $quotaLock->release();
    }

    /**
     * DOMAIN 1 AC-8: cross-tenant table isolation at checkout.
     */
    public function test_self_order_checkout_cross_tenant_table_denied(): void
    {
        $env1 = $this->setupSelfOrderEnvironment();

        $tenant2 = Tenant::create(['name' => 'Kedai Kopi Tenant 2']);
        $store2 = null;
        app(TenantContext::class)->runAs($tenant2->id, null, function () use ($tenant2, &$store2) {
            $store2 = Store::create(['tenant_id' => $tenant2->id, 'name' => 'Store 2']);
        });
        $store2 = $store2->withoutGlobalScopes()->findOrFail($store2->id);
        $tableTenant2 = null;
        app(TenantContext::class)->runAs($tenant2->id, $store2->id, function () use ($tenant2, $store2, &$tableTenant2) {
            $tableTenant2 = Table::create([
                'tenant_id' => $tenant2->id,
                'store_id' => $store2->id,
                'table_name' => 'T-Tenant2',
                'capacity' => 4,
                'is_active' => true,
                'operational_status' => 'available',
                'secure_token' => Str::uuid()->toString(),
            ]);
        });

        // Customer in Tenant 1 context attempts checkout with a table belonging to Tenant 2
        Session::put('current_table_id', $tableTenant2->id);
        Session::put('current_tenant_id', $env1['tenant']->id);
        Session::put('current_store_id', $env1['store']->id);
        Session::put('checkout_idempotency_key', 'idemp-cross-tbl');

        $this->mock(CartService::class, function ($mock) use ($env1) {
            $mock->shouldReceive('getItems')->andReturn([
                ['product_id' => $env1['product']->id, 'qty' => 1, 'unit_price' => 15000, 'options' => []],
            ]);
            $mock->shouldReceive('getTotalQty')->andReturn(1);
            $mock->shouldReceive('getSubtotal')->andReturn(15000);
            $mock->shouldReceive('snapshot')->byDefault();
            $mock->shouldReceive('clear')->byDefault();
        });

        $response = $this->post(route('customer.checkout.store'), [
            '_idempotency_key' => 'idemp-cross-tbl',
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Order::where('table_id', $tableTenant2->id)->count());
    }
}
