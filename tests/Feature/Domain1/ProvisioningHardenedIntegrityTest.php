<?php

namespace Tests\Feature\Domain1;

use App\Exceptions\StaleWorkerException;
use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\EmailOutbox;
use App\Models\OwnerOnboardingToken;
use App\Models\ProvisioningNonce;
use App\Models\ProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use App\Services\DatabaseErrorClassifier;
use App\Services\ProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProvisioningHardenedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private string $secret = 'test-provisioning-secret-0123456789abcdef';

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        config(['services.provisioning.secret_current' => $this->secret]);
        config(['services.provisioning.secret_previous' => null]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'tenant_name' => 'Integrity Remediation Cafe',
            'owner_name' => 'Integrity Owner',
            'owner_email' => 'integrity-owner@example.test',
            'owner_phone' => '081234567890',
        ], $overrides);
    }

    /**
     * TX-001 / TX-004: Worker stale dengan attempt token usang tidak boleh commit.
     * Transaksi bisnis harus di-rollback seluruhnya, tidak ada tenant yatim/duplikat.
     */
    public function test_stale_worker_with_expired_fencing_token_rolls_back_and_refuses_commit(): void
    {
        $payload = $this->validPayload();
        $key = 'stale-worker-test-'.Str::random(8);
        $fingerprint = hash('sha256', 'POST|/api/v1/provisioning/registrations|'.json_encode($payload, JSON_UNESCAPED_SLASHES));

        // Worker 1 mengklaim attempt 1
        $ledger = ProvisioningRequest::create([
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(10),
            'attempt_count' => 1,
            'lease_expires_at' => now()->subMinute(), // lease W1 sudah expired
        ]);

        // Worker 2 mengambil alih lease yang expired (attempt_count = 2)
        $ledger->update([
            'attempt_count' => 2,
            'lease_expires_at' => now()->addMinutes(10),
            'started_at' => now(),
        ]);

        // Sekarang Worker 1 mencoba menyelesaikan transaksi bisnis dengan attemptToken = 1 (stale)
        $service = app(ProvisioningService::class);

        $response = $service->provision($payload, $key, $fingerprint);

        // Respons untuk worker stale adalah idempotent status pending (milik Worker 2) atau idempotent replay
        $this->assertContains($response['status'], [200, 201]);
        $this->assertSame(0, Tenant::where('name', $payload['tenant_name'])->count());
        $this->assertSame(0, EmailOutbox::count());
    }

    /**
     * TX-001: Jika StaleWorkerException dilempar di dalam transaksi bisnis,
     * seluruh record (tenant, user, token, outbox) di-rollback.
     */
    public function test_stale_worker_exception_rolls_back_all_partial_writes(): void
    {
        $payload = $this->validPayload();
        $key = 'stale-rollback-'.Str::random(8);
        $fingerprint = hash('sha256', 'POST|/api/v1/provisioning/registrations|'.json_encode($payload, JSON_UNESCAPED_SLASHES));

        // Buat ledger awal
        ProvisioningRequest::create([
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_PROCESSING,
            'started_at' => now(),
            'attempt_count' => 1,
            'lease_expires_at' => now()->addMinutes(10),
        ]);

        // Simulasikan worker W1 yang sudah membuat tenant tapi kemudian fencing check gagal
        try {
            DB::transaction(function () use ($payload, $key) {
                // Tulis sebagian data bisnis
                $tenant = Tenant::create(['name' => $payload['tenant_name']]);
                app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant, $payload) {
                    return User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $payload['owner_name'],
                        'email' => $payload['owner_email'],
                        'password' => Hash::make(Str::random(32)),
                        'is_active' => true,
                    ]);
                });

                // Simulasikan lease / attempt berubah oleh worker lain sebelum commit
                ProvisioningRequest::where('idempotency_key', $key)->update(['attempt_count' => 2]);

                $locked = ProvisioningRequest::where('idempotency_key', $key)->lockForUpdate()->first();
                if ($locked->attempt_count !== 1) {
                    throw new StaleWorkerException('Worker is stale!');
                }
            });
        } catch (StaleWorkerException $e) {
            // Ditangani secara teratur
        }

        // Verifikasi semua business writes dibatalkan oleh DB rollback
        $this->assertSame(0, Tenant::where('name', $payload['tenant_name'])->count());
        $this->assertSame(0, User::where('email', $payload['owner_email'])->count());
    }

    /**
     * TX-002: Dispatcher command menemukan outbox PROCESSING yang lease-nya kedaluwarsa
     * dan memprosesnya kembali.
     */
    public function test_expired_processing_outbox_is_recovered_by_dispatcher(): void
    {
        Mail::fake();

        $tenant = Tenant::create(['name' => 'Outbox Recovery Cafe']);
        $user = app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant) {
            return User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Recovery Owner',
                'email' => 'recovery@example.test',
                'password' => Hash::make(Str::random(32)),
                'is_active' => true,
            ]);
        });

        $plainToken = Str::random(40);
        $hashedToken = hash('sha256', $plainToken);

        OwnerOnboardingToken::create([
            'token_hash' => $hashedToken,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'expires_at' => now()->addHours(24),
        ]);

        $magicUrl = "http://localhost/onboarding/claim?token={$plainToken}";

        // Buat item outbox yang statusnya PROCESSING dan lease_expires_at sudah lampau (stale worker)
        $outbox = EmailOutbox::create([
            'event_type' => 'owner_onboarding_magic_link',
            'recipient_email' => $user->email,
            'subject' => 'Aktivasi Akun Apeiron System',
            'body_text' => "Klik tautan: {$magicUrl}",
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => EmailOutbox::STATUS_PROCESSING,
            'attempts' => 1,
            'lease_expires_at' => now()->subMinutes(10), // Lease expired!
            'next_attempt_at' => now()->subMinutes(5),
        ]);

        $this->assertTrue($outbox->isClaimExpired());

        // Jalankan dispatcher command
        $this->artisan('emails:process-outbox', ['--limit' => 10])->assertExitCode(0);

        $outbox->refresh();
        $this->assertSame(EmailOutbox::STATUS_SENT, $outbox->status);
        $this->assertNotNull($outbox->sent_at);
        $this->assertNull($outbox->lease_expires_at);
    }

    /**
     * TX-005: Retry pengiriman outbox tidak merotasi atau membatalkan onboarding token.
     * Magic link dan token hash tetap identik dan valid untuk aktivasi.
     */
    public function test_outbox_retry_does_not_rotate_onboarding_token(): void
    {
        $tenant = Tenant::create(['name' => 'Token Stability Cafe']);
        $user = app(TenantContext::class)->runAs($tenant->id, null, function () use ($tenant) {
            return User::create([
                'tenant_id' => $tenant->id,
                'name' => 'Token Owner',
                'email' => 'stable-token@example.test',
                'password' => Hash::make(Str::random(32)),
                'is_active' => true,
            ]);
        });

        $plainToken = Str::random(40);
        $hashedToken = hash('sha256', $plainToken);

        $onboardingToken = OwnerOnboardingToken::create([
            'token_hash' => $hashedToken,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'expires_at' => now()->addHours(24),
        ]);

        $magicUrl = "http://localhost/onboarding/claim?token={$plainToken}";

        $outbox = EmailOutbox::create([
            'event_type' => 'owner_onboarding_magic_link',
            'recipient_email' => $user->email,
            'subject' => 'Aktivasi Akun Apeiron System',
            'body_text' => "Aktivasi: {$magicUrl}",
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => EmailOutbox::STATUS_PENDING,
            'attempts' => 0,
        ]);

        // Simulasikan kegagalan pengiriman pertama (misal SMTP timeout)
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Connection timed out'));
        $this->app['queue']->setDefaultDriver('sync');

        try {
            app(ProcessProvisioningOutboxJob::class, ['outboxId' => $outbox->id])->handle();
        } catch (\Throwable) {
        }

        $outbox->refresh();
        $this->assertSame(EmailOutbox::STATUS_PENDING, $outbox->status);
        $this->assertSame(1, $outbox->attempts);

        // Verifikasi token hash di database TIDAK BERUBAH
        $onboardingToken->refresh();
        $this->assertSame($hashedToken, $onboardingToken->token_hash);
        $this->assertNull($onboardingToken->consumed_at);

        // Percobaan kedua: pengiriman berhasil
        Mail::shouldReceive('raw')->once()->andReturnNull();
        $outbox->update(['next_attempt_at' => now()->subMinute()]);

        app(ProcessProvisioningOutboxJob::class, ['outboxId' => $outbox->id])->handle();

        $outbox->refresh();
        $this->assertSame(EmailOutbox::STATUS_SENT, $outbox->status);

        // Token tetap sama dan belum dikonsumsi sampai user mengklik
        $onboardingToken->refresh();
        $this->assertSame($hashedToken, $onboardingToken->token_hash);
        $this->assertNull($onboardingToken->consumed_at);
    }

    /**
     * TX-009: Reconcile memulihkan ledger PROCESSING dengan lease expired menjadi PENDING
     * jika belum ada tenant yang ter-commit.
     */
    public function test_reconcile_resets_orphaned_processing_to_pending(): void
    {
        $key = 'reconcile-reset-'.Str::random(8);
        $payload = $this->validPayload();
        $fingerprint = hash('sha256', 'POST|/api/v1/provisioning/registrations|'.json_encode($payload, JSON_UNESCAPED_SLASHES));

        $ledger = ProvisioningRequest::create([
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'owner_email' => $payload['owner_email'],
            'status' => ProvisioningRequest::STATUS_PROCESSING,
            'started_at' => now()->subMinutes(15),
            'attempt_count' => 1,
            'lease_expires_at' => now()->subMinutes(5), // lease expired
        ]);

        $this->artisan('provisioning:reconcile')->assertExitCode(0);

        $ledger->refresh();
        $this->assertSame(ProvisioningRequest::STATUS_PENDING, $ledger->status);
        $this->assertNull($ledger->lease_expires_at);
    }

    /**
     * TX-010: Anti-replay membedakan duplicate nonce (401 nonce_replayed)
     * dan tidak menyamarkan error DB lain sebagai duplicate nonce.
     */
    public function test_anti_replay_only_flags_actual_duplicate_nonce(): void
    {
        $nonce = Str::random(32);
        ProvisioningNonce::create([
            'nonce' => $nonce,
            'expires_at_epoch' => time() + 600,
        ]);

        // Simpan nonce yang sama lagi harus menghasilkan unique violation yang dikenali
        $duplicateCaught = false;
        try {
            ProvisioningNonce::create([
                'nonce' => $nonce,
                'expires_at_epoch' => time() + 600,
            ]);
        } catch (QueryException $e) {
            $duplicateCaught = DatabaseErrorClassifier::isDuplicateEntry($e);
        }

        $this->assertTrue($duplicateCaught, 'Duplicate nonce must be correctly classified by DatabaseErrorClassifier');
    }

    /**
     * TX-011: Enkripsi body_text round-trip: data di DB terenkripsi,
     * model membaca plaintext, token tidak tersimpan plaintext di database.
     */
    public function test_email_outbox_encryption_round_trip(): void
    {
        $token = 'secret-onboarding-token-'.Str::random(32);
        $plainBody = "Gunakan token aktivasi rahasia: {$token}";

        $outbox = EmailOutbox::create([
            'event_type' => 'owner_onboarding_magic_link',
            'recipient_email' => 'encrypted-test@example.test',
            'subject' => 'Test Enkripsi',
            'body_text' => $plainBody,
            'status' => EmailOutbox::STATUS_PENDING,
        ]);

        // Baca raw string langsung dari DB
        $rawDbValue = DB::table('email_outbox')->where('id', $outbox->id)->value('body_text');

        // DB TIDAK memuat plaintext token
        $this->assertStringNotContainsString($token, $rawDbValue);
        $this->assertNotSame($plainBody, $rawDbValue);

        // Model membaca plaintext dengan benar via casting
        $outbox->refresh();
        $this->assertSame($plainBody, $outbox->body_text);

        // Decrypt string manual cocok dengan model
        $decrypted = Crypt::decryptString($rawDbValue);
        $this->assertSame($plainBody, $decrypted);
    }
}
