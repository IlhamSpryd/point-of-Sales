<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Modules\Subscription\Models\ProvisioningNonce;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\ProvisioningStep;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end contract test for Marketing Page -> POS S2S Provisioning integration (Domain 1, EXT-03, ADR-117).
 * Verifies exact header structure, HMAC-SHA256 signature verification, anti-replay nonce store,
 * step-tracked onboarding, and idempotent replay responses matching Cloudflare Pages Function.
 */
class MarketingS2SProvisioningContractTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    private string $secret = 'commercial-test-secret-hmac-64char-length-key-2026-production01';

    private string $endpoint = '/api/v1/provisioning/registrations';

    protected function afterSubclassSetUp(): void
    {
        Config::set('services.provisioning.secret_current', $this->secret);
        Config::set('services.provisioning.secret_previous', null);
        Config::set('services.provisioning.timestamp_window', 300);
        Config::set('services.provisioning.nonce_retention', 1800);
    }

    /**
     * Helper to compute exact HMAC-SHA256 signature matching VerifyProvisioningSignature and Cloudflare Pages register.ts.
     */
    private function computeSignature(string $method, string $path, string $timestamp, string $nonce, string $rawBody, string $secret): string
    {
        $bodyHash = hash('sha256', $rawBody);
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Helper to prepare valid headers for signed provisioning request.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function makeHeaders(array $payload, array $overrides = []): array
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $idempotencyKey = 'idem-'.Str::random(24);

        $signature = $this->computeSignature(
            'POST',
            $this->endpoint,
            $timestamp,
            $nonce,
            $rawBody,
            $this->secret
        );

        return array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Idempotency-Key' => $idempotencyKey,
            'X-Signature' => $signature,
        ], $overrides);
    }

    public function test_marketing_s2s_signed_registration_successfully_provisions_tenant_and_returns_contract(): void
    {
        $unique = strtolower(Str::random(6));
        $payload = [
            'tenant_name' => 'Kedai Kopi Harapan '.$unique,
            'owner_name' => 'Budi Santoso',
            'owner_email' => 'budi.santoso.'.$unique.'@harapan.test',
            'owner_phone' => '+6281234567890',
        ];

        $headers = $this->makeHeaders($payload);

        $response = $this->postJson($this->endpoint, $payload, $headers);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Provisioning berhasil. Email onboarding akan dikirim ke Owner.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'tenant_id',
                'owner_user_id',
                'store_id',
                'onboarding' => [
                    'email_sent_to',
                ],
            ]);

        $responseData = $response->json();
        $tenantId = $responseData['tenant_id'];
        $ownerId = $responseData['owner_user_id'];
        $storeId = $responseData['store_id'];

        // Assert database records created deterministically
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'name' => 'Kedai Kopi Harapan '.$unique,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $ownerId,
            'tenant_id' => $tenantId,
            'email' => 'budi.santoso.'.$unique.'@harapan.test',
        ]);

        $this->assertDatabaseHas('stores', [
            'id' => $storeId,
            'tenant_id' => $tenantId,
        ]);

        // Assert Step-Tracked Provisioning ledger and steps (EXT-03)
        $idempotencyKey = $headers['X-Idempotency-Key'];
        $provisioningReq = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
        $this->assertNotNull($provisioningReq);
        $this->assertSame(ProvisioningRequest::STATUS_COMPLETED, $provisioningReq->status);
        $this->assertSame($tenantId, (int) $provisioningReq->result_tenant_id);

        $steps = ProvisioningStep::where('provisioning_request_id', $provisioningReq->id)->get();
        $this->assertGreaterThanOrEqual(4, $steps->count());
        $completedStepKeys = $steps->where('status', ProvisioningStep::STATUS_COMPLETED)->pluck('step_key')->all();

        $this->assertContains(ProvisioningStep::STEP_CREATE_TENANT, $completedStepKeys);
        $this->assertContains(ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH, $completedStepKeys);
        $this->assertContains(ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, $completedStepKeys);
        $this->assertContains(ProvisioningStep::STEP_ACTIVATE_SUBSCRIPTION_PLAN, $completedStepKeys);

        // Assert Nonce recorded in anti-replay store
        $this->assertDatabaseHas('provisioning_nonces', [
            'nonce' => $headers['X-Nonce'],
        ]);
    }

    public function test_marketing_s2s_rejects_replayed_nonce_with_409_conflict(): void
    {
        $unique = strtolower(Str::random(6));
        $payload = [
            'tenant_name' => 'Warung Replay '.$unique,
            'owner_name' => 'Replay Tester',
            'owner_email' => 'replay.'.$unique.'@test.local',
        ];

        $replayedNonce = (string) Str::uuid();

        // Seed replayed nonce into anti-replay table
        ProvisioningNonce::create([
            'nonce' => $replayedNonce,
            'expires_at_epoch' => now()->getTimestamp() + 1800,
        ]);

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $signature = $this->computeSignature('POST', $this->endpoint, $timestamp, $replayedNonce, $rawBody, $this->secret);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $replayedNonce,
            'X-Idempotency-Key' => 'idem-'.Str::random(24),
            'X-Signature' => $signature,
        ];

        $response = $this->postJson($this->endpoint, $payload, $headers);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => 'unauthorized_request',
            ]);
    }

    public function test_marketing_s2s_rejects_tampered_signature_with_401(): void
    {
        $payload = [
            'tenant_name' => 'Tampered Shop',
            'owner_name' => 'Hacker Target',
            'owner_email' => 'hacker@tampered.test',
        ];

        $headers = $this->makeHeaders($payload);
        // Tamper with signature
        $headers['X-Signature'] = str_repeat('0', 64);

        $response = $this->postJson($this->endpoint, $payload, $headers);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => 'unauthorized_request',
            ]);
    }

    public function test_marketing_s2s_rejects_expired_timestamp(): void
    {
        $payload = [
            'tenant_name' => 'Expired Timestamp Shop',
            'owner_name' => 'Time Traveler',
            'owner_email' => 'timetraveler@expired.test',
        ];

        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        // 400 seconds ago (window is 300)
        $expiredTimestamp = (string) (now()->getTimestamp() - 400);
        $nonce = (string) Str::uuid();
        $signature = $this->computeSignature('POST', $this->endpoint, $expiredTimestamp, $nonce, $rawBody, $this->secret);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $expiredTimestamp,
            'X-Nonce' => $nonce,
            'X-Idempotency-Key' => 'idem-'.Str::random(24),
            'X-Signature' => $signature,
        ];

        $response = $this->postJson($this->endpoint, $payload, $headers);

        $response->assertStatus(401);
    }

    public function test_marketing_s2s_rejects_missing_or_short_idempotency_key_with_422(): void
    {
        $payload = [
            'tenant_name' => 'Missing Idempotency',
            'owner_name' => 'Idempotency Tester',
            'owner_email' => 'no.idemp@test.local',
        ];

        $headers = $this->makeHeaders($payload);
        $headers['X-Idempotency-Key'] = 'short'; // < 16 chars

        $response = $this->postJson($this->endpoint, $payload, $headers);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'invalid_idempotency_key',
            ]);
    }

    public function test_marketing_s2s_idempotent_replay_returns_200_ok(): void
    {
        $unique = strtolower(Str::random(6));
        $payload = [
            'tenant_name' => 'Idempotent Replay '.$unique,
            'owner_name' => 'Replay Owner',
            'owner_email' => 'replay.owner.'.$unique.'@test.local',
        ];

        $sharedIdempotencyKey = 'idem-replay-'.Str::random(20);

        // 1. First execution: succeeds (201)
        $headers1 = $this->makeHeaders($payload, ['X-Idempotency-Key' => $sharedIdempotencyKey]);
        $response1 = $this->postJson($this->endpoint, $payload, $headers1);
        $response1->assertStatus(201);
        $firstTenantId = $response1->json('tenant_id');

        // 2. Second execution with same idempotency key and same payload, but fresh timestamp & nonce (as S2S proxy retries do)
        $headers2 = $this->makeHeaders($payload, ['X-Idempotency-Key' => $sharedIdempotencyKey]);
        $response2 = $this->postJson($this->endpoint, $payload, $headers2);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tenant_id' => $firstTenantId,
                'idempotent_replay' => true,
            ]);
    }

    public function test_marketing_s2s_authenticates_with_provisioning_hmac_secret_env(): void
    {
        Config::set('services.provisioning.secret_current', null);
        putenv('PROVISIONING_HMAC_SECRET='.$this->secret);
        Config::set('services.provisioning.secret_current', env('PROVISIONING_HMAC_SECRET', env('PROVISIONING_SECRET')));

        $unique = strtolower(Str::random(6));
        $payload = [
            'tenant_name' => 'Env Secret Tenant '.$unique,
            'owner_name' => 'Env Owner',
            'owner_email' => 'env.owner.'.$unique.'@test.local',
        ];

        $headers = $this->makeHeaders($payload);
        $response = $this->postJson($this->endpoint, $payload, $headers);
        $response->assertStatus(201);

        putenv('PROVISIONING_HMAC_SECRET');
    }
}
