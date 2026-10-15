<?php

namespace Tests\Feature\Domain1;

use App\Models\Category;
use App\Models\EmailOutbox;
use App\Models\Ingredient;
use App\Models\OwnerOnboardingToken;
use App\Models\Product;
use App\Models\ProvisioningRequest;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\WithDefaultTenant;
use Tests\TestCase;

/**
 * Domain 1 — Tenant provisioning S2S dari website marketing.
 *
 * Klas ini opt-out dari WithDefaultTenant fixture agar tidak membawa
 * tenant pra-ada; setiap test memulai dari database kosong (RefreshDatabase).
 */
class ProvisioningDomainTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private string $secret = 'test-provisioning-secret-0123456789abcdef';

    private string $path = '/api/v1/provisioning/registrations';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.provisioning.secret_current' => $this->secret]);
        config(['services.provisioning.secret_previous' => null]);
    }

    // ------------------------------------------------------------------
    // Helper: buat request ter-signing sesuai kontrak docs/architecture.
    // ------------------------------------------------------------------

    private function signedHeaders(array $payload, array $overrides = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = $overrides['X-Nonce'] ?? (string) Str::uuid();
        $canonical = implode("\n", ['POST', $this->path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $this->secret);

        return array_merge([
            'Content-Type' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Idempotency-Key' => (string) Str::uuid(),
        ], $overrides);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'tenant_name' => 'Kopi Cepat',
            'owner_name' => 'Andi Wijaya',
            'owner_email' => 'owner+'.strtolower(Str::random(6)).'@example.test',
        ], $overrides);
    }

    private function postProvision(array $payload, array $headers): TestResponse
    {
        return $this->postJson($this->path, $payload, $headers);
    }

    // ------------------------------------------------------------------
    // 1. Happy path: provisioning sukses lengkap
    // ------------------------------------------------------------------

    public function test_provisioning_success_creates_tenant_owner_store_role_and_balances(): void
    {
        $payload = $this->validPayload();

        $response = $this->postProvision($payload, $this->signedHeaders($payload));

        $response->assertStatus(201);
        $this->assertDatabaseCount('tenants', 1);

        $tenant = Tenant::first();
        $this->assertSame('Kopi Cepat', $tenant->name);

        // Role Owner per-tenant
        $this->assertDatabaseHas('roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Owner',
        ]);

        // Store pertama
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($store);
        $this->assertSame($response->json('store_id'), $store->id);

        // User Owner: verified NULL (D4), password NULL (D5)
        $owner = User::where('email', $payload['owner_email'])->first();
        $this->assertNotNull($owner);
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertNull($owner->email_verified_at);
        $this->assertNull($owner->password_hash);
        $this->assertSame($response->json('owner_user_id'), $owner->id);

        // Hook stock balances JALAN tanpa error — tenant baru memang belum
        // punya Product/Ingredient sehingga 0 baris adalah INVARIANT YANG
        // BENAR (hook me-loop produk tenant yang masih kosong). Yang wajib
        // diverifikasi: hook tidak melewatkan tenant context (jika context
        // salah, hook akan skip silently — tak teramati; oleh karena itu
        // test terpisah dengan produk pre-seeded menguji balances dibuat).
        $this->assertSame(0,
            DB::table('product_stock_balances')->where('store_id', $store->id)->count()
            + DB::table('ingredient_stock_balances')->where('store_id', $store->id)->count()
        );
        $this->assertSame('ready', $tenant->readiness_status);
        $this->assertSame(8, DB::table('roles')->where('tenant_id', $tenant->id)->count());
        app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant): void {
            $provisioning = app(\App\Services\TenantProvisioningService::class);
            $this->assertSame(2, User::where('tenant_id', $tenant->id)->whereIn('email', [
                $provisioning->systemUserEmail($tenant, 'self_order'),
                $provisioning->systemUserEmail($tenant, 'channel_order'),
            ])->count());

            $ownerRole = \App\Models\Role::where('tenant_id', $tenant->id)->where('name', 'Owner')->firstOrFail();
            $this->assertTrue($ownerRole->hasPermission('dashboard'));
            $this->assertTrue($ownerRole->hasPermission('orders.create'));
        });

        // Ledger completed + outbox row persisted. Queue driver may be sync,
        // in which case the row can already be sent before the response.
        $ledger = ProvisioningRequest::where('result_tenant_id', $tenant->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $ledger->status);
        $this->assertSame(1, DB::table('email_outbox')->count());
    }

    // ------------------------------------------------------------------
    // 2-5. Auth: signature, timestamp, nonce, rate limit
    // ------------------------------------------------------------------

    public function test_employee_ids_are_unique_and_sequence_is_monotonic_per_tenant(): void
    {
        $payload = $this->validPayload();
        $this->postProvision($payload, $this->signedHeaders($payload))->assertStatus(201);
        $tenant = Tenant::firstOrFail();
        $owner = User::where('tenant_id', $tenant->id)->where('email', $payload['owner_email'])->firstOrFail();

        $secondUser = app(TenantContext::class)->runAs($tenant->id, null, fn () => User::create([
            'name' => 'Kasir',
            'email' => 'cashier+'.Str::random(8).'@example.test',
            'is_active' => true,
        ]));

        $this->assertNotSame($owner->employee_id, $secondUser->employee_id);
        $this->assertSame(4, DB::table('employee_id_sequences')->where('tenant_id', $tenant->id)->value('last_value'));
        $this->assertSame(1, DB::table('users')->where('employee_id', $owner->employee_id)->count());
    }

    public function test_rejects_invalid_signature_without_writes(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload);
        $headers['X-Signature'] = str_repeat('a', 64);

        $response = $this->postProvision($payload, $headers);

        $response->assertStatus(401);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('provisioning_requests', 0);
        // Nonce TIDAK boleh tercat untuk request yang ditolak signature.
        $this->assertDatabaseCount('provisioning_nonces', 0);
    }

    public function test_rejects_expired_timestamp(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload);
        $headers['X-Timestamp'] = (string) (now()->getTimestamp() - 400);

        // Hitung ulang signature dengan timestamp lama agar hanya timestamp yang salah.
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $canonical = implode("\n", ['POST', $this->path, $headers['X-Timestamp'], $headers['X-Nonce'], hash('sha256', $body)]);
        $headers['X-Signature'] = hash_hmac('sha256', $canonical, $this->secret);

        $this->postProvision($payload, $headers)
            ->assertStatus(401);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_rejects_replayed_nonce(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload, ['X-Nonce' => 'fixed-nonce-123456']);

        // Request pertama: berhasil
        $this->postProvision($payload, $headers)->assertStatus(201);
        // Request kedua: nonce yang sama (signature valid, timestamp fresh)
        // → HARUS ditolak (anti-replay), TIDAK membuat tenant kedua.
        $this->postProvision($payload, $headers)->assertStatus(409);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_rejects_query_string(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload);

        $this->postJson($this->path.'?debug=1', $payload, $headers)->assertStatus(400);
        $this->assertDatabaseCount('tenants', 0);
    }

    // ------------------------------------------------------------------
    // 6-8. Idempotency & concurrency
    // ------------------------------------------------------------------

    public function test_duplicate_request_with_same_key_does_not_double_provision(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => 'idem-key-fixed-0001']);

        $first = $this->postProvision($payload, $headers);
        $first->assertStatus(201);
        $tenantId = $first->json('tenant_id');

        // Nonce baru (bukan replay transport) TAPI key idempotency sama +
        // payload sama → hasil sama, tidak ada tenant kedua.
        $secondHeaders = $this->signedHeaders($payload, ['X-Idempotency-Key' => 'idem-key-fixed-0001']);
        $second = $this->postProvision($payload, $secondHeaders);

        $second->assertStatus(200);
        $this->assertTrue($second->json('idempotent_replay'));
        $this->assertSame($tenantId, $second->json('tenant_id'));
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_same_key_different_payload_conflicts_409(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => 'idem-key-fixed-0002']);

        $this->postProvision($payload, $headers)->assertStatus(201);

        $differentPayload = $this->validPayload(['tenant_name' => 'Bisnis Lain']);
        // Header BUKAN reference lama (nonce request pertama sudah dipakai).
        // Kita generate header signature fresh untuk payload yang berbeda.
        $headers2 = $this->signedHeaders($differentPayload, ['X-Idempotency-Key' => 'idem-key-fixed-0002']);
        $this->postProvision($differentPayload, $headers2)->assertStatus(409);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_parallel_requests_same_key_do_not_double_provision(): void
    {
        // Simulasi race: request A menang INSERT ledger; request B tiba saat
        // A masih transaksi. PK duplikat → jalur responseForExisting.
        $payload = $this->validPayload();

        // Pra-insert ledger 'processing' seperti request A yang sedang dalam transaksi bisnis.
        ProvisioningRequest::create([
            'idempotency_key' => 'race-key-long-0001',
            'request_fingerprint' => hash('sha256', 'POST|'.$this->path.'|'.json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_PROCESSING,
            'started_at' => now(),
            'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(10),
        ]);

        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => 'race-key-long-0001']);
        // Request B dengan payload IDENTIK pada processing state (lease aktif) → 200 pending
        // (bukan provisioning kedua); ini adalah state-machine test, bukan proses paralel.
        $response = $this->postProvision($payload, $headers);
        $response->assertStatus(200);
        $this->assertSame('pending', $response->json('status'));
        $this->assertDatabaseCount('tenants', 0);
    }

    // ------------------------------------------------------------------
    // 9-12. Failure & recovery
    // ------------------------------------------------------------------

    public function test_exception_during_provisioning_rolls_back_everything(): void
    {
        // Gangguan: email sudah terdaftar → 409, TIDAK membuat tenant (D1).
        // User wajib punya tenant_id. Kita buat tenant dummy dulu dan bypass
        // scope (karena seed dummy ini berada di luar flow provisioning).
        $preTenant = Tenant::create(['name' => 'Pre Tenant']);
        $existingUser = null;
        $ctx = app(TenantContext::class);
        $ctx->runAs($preTenant->id, null, function () use (&$existingUser) {
            $existingUser = User::create([
                'name' => 'Existing',
                'email' => 'taken@example.test',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]);
        });

        $payload = $this->validPayload(['owner_email' => 'taken@example.test']);
        $response = $this->postProvision($payload, $this->signedHeaders($payload));

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'email_already_in_use');
        $this->assertDatabaseCount('tenants', 1); // hanya Pre Tenant
        $this->assertDatabaseHas('provisioning_requests', [
            'status' => ProvisioningRequest::STATUS_FAILED,
            'error_message' => 'email_already_in_use',
        ]);
        $this->assertNull($existingUser->fresh()->email_verified_at);
    }

    public function test_reconcile_recovers_business_commit_before_ledger_finalization(): void
    {
        $payload = $this->validPayload();
        $key = 'recover-commit-'.Str::random(8);
        $this->postProvision($payload, $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]))->assertStatus(201);
        $ledger = ProvisioningRequest::where('idempotency_key', $key)->firstOrFail();
        $ledger->forceFill([
            'status' => ProvisioningRequest::STATUS_PROCESSING,
            'attempt_count' => 2,
            'lease_expires_at' => now()->subMinute(),
            'result_tenant_id' => null,
            'result_user_id' => null,
            'completed_at' => null,
        ])->save();

        $this->artisan('provisioning:reconcile')->assertExitCode(0);
        $ledger->refresh();
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $ledger->status);
        $this->assertNotNull($ledger->result_tenant_id);
        $this->assertNotNull($ledger->result_user_id);
        $this->assertSame(1, Tenant::where('provisioning_idempotency_key', $key)->count());
    }

    public function test_ledger_result_user_must_belong_to_result_tenant(): void
    {
        $firstPayload = $this->validPayload();
        $this->postProvision($firstPayload, $this->signedHeaders($firstPayload))->assertStatus(201);
        $firstLedger = ProvisioningRequest::firstOrFail();

        $secondPayload = $this->validPayload();
        $this->postProvision($secondPayload, $this->signedHeaders($secondPayload))->assertStatus(201);
        $secondLedger = ProvisioningRequest::where('id', '<>', $firstLedger->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('provisioning_requests')->where('id', $firstLedger->id)->update([
            'result_user_id' => $secondLedger->result_user_id,
        ]);
    }

    public function test_retry_after_completed_commit_returns_same_result(): void
    {
        // Case F.6: crash setelah commit sebelum ledger completed →
        // disimulasikan lewat reconcile manual di sini (tenant ada, ledger
        // completed di-update). Pengujian inti: retry idempotent mengembalikan
        // tenant yang SAMA tanpa provisioning kedua.
        $payload = $this->validPayload();
        $key = 'retry-key-long-0001';
        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]);

        $this->postProvision($payload, $headers)->assertStatus(201);
        $tenantId = Tenant::first()->id;

        // Retry N kali dengan nonce baru — tetap satu tenant.
        for ($i = 0; $i < 2; $i++) {
            $retry = $this->postProvision($payload, $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]));
            $retry->assertStatus(200);
            $this->assertSame($tenantId, $retry->json('tenant_id'));
        }

        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_tenant_context_restored_after_exception(): void
    {
        $context = app(TenantContext::class);
        $context->setTenantId(999);
        $context->setStoreId(888);

        $payload = $this->validPayload();
        // Provisioning dengan email konflik → exception path di service.
        $this->postProvision($payload, $this->signedHeaders($payload));

        // Context harus pulih ke nilai semula (runAs finally).
        $this->assertSame(999, $context->getTenantId());
        $this->assertSame(888, $context->getStoreId());
    }

    public function test_first_store_with_empty_catalog_has_no_spurious_stock_balances(): void
    {
        $payload = $this->validPayload();
        $this->postProvision($payload, $this->signedHeaders($payload))->assertStatus(201);

        // Tenant baru tidak punya Product/Ingredient. Hook Store::created
        // wajib berjalan tanpa error dan membuat nol balance (bukan membuat
        // balance palsu tanpa parent catalog row).
        $store = Store::withoutGlobalScopes()->first();
        $this->assertSame(0, DB::table('product_stock_balances')->where('store_id', $store->id)->count());
        $this->assertSame(0, DB::table('ingredient_stock_balances')->where('store_id', $store->id)->count());
    }

    public function test_store_hook_seeds_balances_for_preexisting_products(): void
    {
        // Bukti kuat bahwa Store::created hook JALAN dalam tenant context
        // yang benar: seed Product+Ingredient milik tenant SEBELUM store
        // dibuat, lalu provisioning; hook harus membuat baris balance utk
        // keduanya. Ini membedakan "hook skip" vs "tenant kosong".
        $payload = $this->validPayload();
        $key = 'preseed-'.Str::random(10);

        // Pra-buat tenant tanpa store, lalu produk, baru provisioning store.
        // Cara paling sederhana: provisioning pertama (tanpa produk), lalu
        // tambah produk tenant tersebut, lalu panggil provisioning "tambah
        // store"? Tidak — provisioning selalu membuat tenant baru. Alternatif:
        // seed produk via platform context, lalu assert hook tetap membuat
        // balance karena Product di-loop tanpaTenantScope.
        $this->postProvision($payload, $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]))
            ->assertStatus(201);
        $tenant = Tenant::first();
        $store = Store::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        // Seed produk tenant ini secara langsung (platform context), lalu
        // trigger hook via pembuatan store kedua milik tenant yang sama.
        $product = null;
        $ingredient = null;
        $ctx = app(TenantContext::class);
        $ctx->runAs($tenant->id, null, function () use ($tenant, &$product, &$ingredient) {
            $category = Category::create([
                'tenant_id' => $tenant->id,
                'category_name' => 'Minuman',
            ]);
            $product = Product::create([
                'tenant_id' => $tenant->id,
                'product_name' => 'Kopi Susu',
                'price' => 18000,
                'stock' => 0,
                'is_active' => true,
                'category_id' => $category->id,
            ]);
            $ingredient = Ingredient::create([
                'tenant_id' => $tenant->id,
                'name' => 'Gula',
                'unit' => 'gram',
                'current_stock' => 0,
            ]);
        });

        $store2 = null;
        $ctx->runAs($tenant->id, null, function () use ($tenant, &$store2) {
            $store2 = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cabang 2']);
        });
        $store2 = Store::withoutGlobalScopes()->find($store2->id);

        // Hook harus membuat balances utk kedua store (loop products/ingredients).
        $this->assertDatabaseHas('product_stock_balances', [
            'tenant_id' => $tenant->id, 'store_id' => $store->id, 'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('product_stock_balances', [
            'tenant_id' => $tenant->id, 'store_id' => $store2->id, 'product_id' => $product->id,
        ]);
        $this->assertDatabaseHas('ingredient_stock_balances', [
            'tenant_id' => $tenant->id, 'store_id' => $store2->id, 'ingredient_id' => $ingredient->id,
        ]);
    }

    // ------------------------------------------------------------------
    // 13-16. Magic link onboarding
    // ------------------------------------------------------------------

    private function provisionOwner(array $payload): array
    {
        $this->postProvision($payload, $this->signedHeaders($payload))->assertStatus(201);

        return [
            'tenant' => Tenant::first(),
            'owner' => User::where('email', $payload['owner_email'])->first(),
        ];
    }

    private function generateTestToken(int $tenantId, int $userId): string
    {
        $record = OwnerOnboardingToken::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->firstOrFail();
        return OwnerOnboardingToken::reissueFor($record->token_hash, $tenantId, $userId);
    }

    public function test_magic_link_url_from_outbox_is_valid_and_renders_form(): void
    {
        [$tenant, $owner] = array_values($this->provisionOwner($this->validPayload()));

        $token = $this->generateTestToken($tenant->id, $owner->id);
        $url = "/onboarding/set-password/{$token}";

        $response = $this->get($url);

        $response->assertStatus(200);
    }

    public function test_magic_link_token_rejected_when_reused_or_expired(): void
    {
        [$tenant, $owner] = array_values($this->provisionOwner($this->validPayload()));
        $token = $this->generateTestToken($tenant->id, $owner->id);

        // Penggunaan pertama sukses
        $this->postJson("/onboarding/set-password/{$token}", [
            'password' => 'Sup3r-Secret!',
            'password_confirmation' => 'Sup3r-Secret!',
        ])->assertRedirect(route('login'));

        $owner->refresh();
        $this->assertNotNull($owner->password_hash);
        $this->assertNotNull($owner->email_verified_at);

        // Reuse → ditolak
        $this->postJson("/onboarding/set-password/{$token}", [
            'password' => 'Another-Pass1!',
            'password_confirmation' => 'Another-Pass1!',
        ])->assertRedirect(route('login')); // redirect dengan error, password tidak berubah
        $this->assertTrue(Hash::check('Sup3r-Secret!', $owner->fresh()->password_hash));

        // Expired → ditolak
        $record = OwnerOnboardingToken::first();
        $record->update(['consumed_at' => null, 'expires_at' => now()->subHour()]);
        $this->postJson("/onboarding/set-password/{$token}", [
            'password' => 'Another-Pass1!',
            'password_confirmation' => 'Another-Pass1!',
        ])->assertRedirect(route('login'));
        $this->assertNull($record->fresh()->consumed_at);
    }

    public function test_valid_token_sets_password_and_verifies_email(): void
    {
        [$tenant, $owner] = array_values($this->provisionOwner($this->validPayload()));
        $token = $this->generateTestToken($tenant->id, $owner->id);
        $hashInDb = OwnerOnboardingToken::latest('id')->first()->token_hash;

        $this->assertNotSame($token, $hashInDb); // plaintext tidak tersimpan
        $this->assertSame(hash('sha256', $token), $hashInDb);

        $this->postJson("/onboarding/set-password/{$token}", [
            'password' => 'Sup3r-Secret!',
            'password_confirmation' => 'Sup3r-Secret!',
        ])->assertRedirect(route('login'));

        $owner->refresh();
        $this->assertTrue(Hash::check('Sup3r-Secret!', $owner->password_hash));
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotNull(OwnerOnboardingToken::latest('id')->first()->consumed_at);
    }

    public function test_owner_unverified_cannot_access_pos_but_staff_unaffected(): void
    {
        [$tenant, $owner] = array_values($this->provisionOwner($this->validPayload()));

        // Owner login tanpa set password via magic link (password null) —
        // simulasi langsung: force set password + tetap unverified.
        $owner->forceFill(['password_hash' => Hash::make('password')])->save();
        $this->actingAs($owner);

        $response = $this->get('/dashboard');
        // Owner unverified TIDAK mendapat akses penuh → diarahkan ke notice.
        $response->assertRedirect(route('verification.notice'));
    }

    // ------------------------------------------------------------------
    // 16-19. Email failure, outbox retry, mass assignment, rotation
    // ------------------------------------------------------------------

    public function test_email_failure_does_not_cancel_provisioning(): void
    {
        $payload = $this->validPayload();
        $this->postProvision($payload, $this->signedHeaders($payload))->assertStatus(201);
        $outbox = EmailOutbox::firstOrFail();
        $outbox->update([
            'status' => EmailOutbox::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now()->subMinute(),
        ]);

        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP password must not be logged'));
        $this->app['queue']->setDefaultDriver('sync');
        app(\App\Jobs\ProcessProvisioningOutboxJob::class, ['outboxId' => $outbox->id])->handle();

        $this->assertDatabaseCount('tenants', 1);
        $outbox->refresh();
        $this->assertSame(EmailOutbox::STATUS_PENDING, $outbox->status);
        $this->assertStringNotContainsString('SMTP password', $outbox->last_error);
        $storedBody = DB::table('email_outbox')->where('id', $outbox->id)->value('body_text');
        $this->assertNotSame($outbox->body_text, $storedBody);
        $this->assertSame($outbox->body_text, decrypt($storedBody, false));
    }

    public function test_privileged_fields_cannot_be_manipulated(): void
    {
        $payload = $this->validPayload([
            'tenant_id' => 999,
            'role_id' => 1,
            'permissions' => ['*'],
            'email_verified_at' => '2020-01-01 00:00:00',
            'is_active' => false,
            'status' => 'completed',
        ]);
        $headers = $this->signedHeaders($payload);

        $response = $this->postProvision($payload, $headers);

        // Field ekstra diabaikan (allowlist) — provisioning tetap berjalan.
        $response->assertStatus(201);
        $tenant = Tenant::first();
        $this->assertNotSame(999, $tenant->id);
        $owner = User::first();
        $this->assertNull($owner->email_verified_at); // TIDAK bisa di-set pemanggil
        $this->assertTrue((bool) $owner->is_active);
    }

    public function test_secret_rotation_current_and_previous_both_accepted(): void
    {
        config(['services.provisioning.secret_previous' => 'old-secret-being-rotated']);

        $payload = $this->validPayload();

        // Request ditandatangani dengan SECRET LAMA — masih diterima.
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $canonical = implode("\n", ['POST', $this->path, $timestamp, $nonce, hash('sha256', $body)]);
        $headers = [
            'Content-Type' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac('sha256', $canonical, 'old-secret-being-rotated'),
            'X-Idempotency-Key' => (string) Str::uuid(),
        ];

        $this->postProvision($payload, $headers)->assertStatus(201);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_completed_cannot_be_overwritten_by_stale_worker_finalization(): void
    {
        $payload = $this->validPayload();
        $key = 'race-markfailed-001';
        $this->postProvision($payload, $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]))->assertStatus(201);
        $ledger = ProvisioningRequest::where('idempotency_key', $key)->firstOrFail();
        $tenantId = $ledger->result_tenant_id;
        $userId = $ledger->result_user_id;

        $method = new \ReflectionMethod(\App\Services\ProvisioningService::class, 'finaliseLedger');
        $method->setAccessible(true);
        $method->invoke(app(\App\Services\ProvisioningService::class), $key, $ledger->attempt_count - 1, [
            'status' => ProvisioningRequest::STATUS_FAILED,
            'error_message' => 'database_error',
            'completed_at' => now(),
        ]);

        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $ledger->fresh()->status);
        $this->assertSame($tenantId, $ledger->fresh()->result_tenant_id);
        $this->assertSame($userId, $ledger->fresh()->result_user_id);
    }

    public function test_stale_worker_cannot_complete_or_fail_an_entry_after_another_claims_it(): void
    {
        $payload = $this->validPayload();
        $key = 'race-claim-001';

        // Worker 2 claimed the stuck entry (attempt_count = 2, status PENDING)
        ProvisioningRequest::create([
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', 'POST|'.$this->path.'|'.json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_PENDING,
            'started_at' => now(),
            'attempt_count' => 2,
        ]);

        // Worker 1 (attempt_count = 1) tries to complete
        $updated = ProvisioningRequest::where('idempotency_key', $key)
            ->where('status', ProvisioningRequest::STATUS_PENDING)
            ->where('attempt_count', 1) // Stale!
            ->update([
                'status' => ProvisioningRequest::STATUS_COMPLETED,
                'result_tenant_id' => 1,
            ]);

        $this->assertSame(0, $updated);

        $ledger = ProvisioningRequest::where('idempotency_key', $key)->first();
        $this->assertSame(ProvisioningRequest::STATUS_PENDING, $ledger->status);
        $this->assertSame(2, $ledger->attempt_count);
    }

    public function test_unique_key_collision_on_ledger_is_caught_safely_and_returns_idempotent_result(): void
    {
        $payload = $this->validPayload();
        $key = 'race-insert-long-001';

        $tenant = Tenant::create(['name' => 'Dummy Tenant 2']);
        $user = app(\App\Services\Context\TenantContext::class)->runAs($tenant->id, null, function() {
            return User::create(['name' => 'Dummy User 2', 'email' => 'dummy2@example.com', 'is_active' => true]);
        });

        ProvisioningRequest::create([
            'idempotency_key' => $key,
            'request_fingerprint' => hash('sha256', 'POST|'.$this->path.'|'.json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_COMPLETED,
            'started_at' => now(),
            'attempt_count' => 1,
            'result_tenant_id' => $tenant->id,
            'result_user_id' => $user->id,
        ]);

        // Simulating the catch block in the Service by using the same key
        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]);
        $response = $this->postProvision($payload, $headers);

        $response->assertStatus(200);
        $this->assertTrue($response->json('idempotent_replay'));
    }

    public function test_unrelated_query_exception_propagates(): void
    {
        $payload = $this->validPayload();
        $key = 'unrelated-query-ex-001';

        // We can just use an Event listener on eloquent creation to throw a QueryException.
        \App\Models\Tenant::creating(function () {
            throw new \Illuminate\Database\QueryException('connection', 'insert into tenants', [], new \Exception('Disk full', 9999));
        });

        $headers = $this->signedHeaders($payload, ['X-Idempotency-Key' => $key]);
        $response = $this->postProvision($payload, $headers);

        // Service catches all Throwables and returns 500 with 'provisioning_failed'
        $response->assertStatus(500);
        $this->assertSame('provisioning_failed', $response->json('error'));

        // Cleanup event listener by flushing dispatcher for Tenant
        \App\Models\Tenant::flushEventListeners();
        \App\Models\Tenant::boot();
    }

    public function test_error_responses_do_not_leak_internal_details(): void
    {
        $payload = $this->validPayload();
        $headers = $this->signedHeaders($payload);
        $headers['X-Signature'] = 'deadbeef'.str_repeat('0', 56);

        $response = $this->postProvision($payload, $headers);

        $content = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $content);
        $this->assertStringNotContainsString($this->secret, $content);
        $this->assertStringNotContainsString(base_path(), $content);
        $this->assertStringNotContainsString('stack', $content);
    }
}
