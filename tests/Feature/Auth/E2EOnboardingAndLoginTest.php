<?php

namespace Tests\Feature\Auth;

use App\Models\OwnerOnboardingToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class E2EOnboardingAndLoginTest extends TestCase
{
    use RefreshDatabase;

    protected bool $optOutFromDefaultTenant = true;

    private string $secret = 'test-provisioning-secret-apeiron-e2e';
    private string $path = '/api/v1/provisioning/registrations';

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();
        config(['services.provisioning.secret_current' => $this->secret]);
        config(['services.provisioning.secret_previous' => null]);
    }

    private function signedHeaders(array $payload, array $overrides = []): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->getTimestamp();
        $nonce = $overrides['X-Nonce'] ?? (string) Str::uuid();
        $canonical = implode("\n", ['POST', $this->path, $timestamp, $nonce, hash('sha256', $body)]);
        $signature = hash_hmac('sha256', $canonical, $this->secret);

        return array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Idempotency-Key' => (string) Str::uuid(),
        ], $overrides);
    }

    /**
     * E2E Scenario: S2S Provisioning -> Activation/Password Set -> POS Login -> Context Binding -> Logout
     */
    public function test_full_e2e_registration_provisioning_password_setup_and_login(): void
    {
        $email = 'artisan.owner@apeiron-e2e.test';
        $payload = [
            'tenant_name' => 'Artisan Coffee Roasters',
            'owner_name' => 'Budi Santoso',
            'owner_email' => $email,
            'owner_phone' => '+6281234567890',
        ];

        // 1. Marketing S2S call to POS
        $headers = $this->signedHeaders($payload);
        $response = $this->postJson($this->path, $payload, $headers);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'onboarding' => [
                    'email_sent_to' => $email,
                ],
            ]);

        $tenantId = $response->json('tenant_id');
        $ownerUserId = $response->json('owner_user_id');
        $onboardingToken = $response->json('onboarding.onboarding_token');

        $this->assertNotEmpty($tenantId);
        $this->assertNotEmpty($ownerUserId);
        $this->assertNotEmpty($onboardingToken);

        // Verify user in DB: is_active = true, but password_hash is null
        $user = User::withoutGlobalScopes()->find($ownerUserId);
        $this->assertNotNull($user);
        $this->assertEquals($tenantId, $user->tenant_id);
        $this->assertTrue((bool) $user->is_active);
        $this->assertNull($user->password_hash);
        $this->assertNull($user->email_verified_at);

        // 2. Attempt login before setting password -> must fail
        $loginAttemptBefore = $this->post('/login', [
            'email' => $email,
            'password' => 'RandomPassword123!',
        ]);
        $loginAttemptBefore->assertSessionHasErrors('email');
        $this->assertGuest();

        // 3. Owner accesses magic link page
        $activationPage = $this->get("/onboarding/set-password/{$onboardingToken}");
        $activationPage->assertStatus(200);

        // 4. Owner sets password
        $setPasswordResponse = $this->post("/onboarding/set-password/{$onboardingToken}", [
            'password' => 'ApeironSecurePassword2026!',
            'password_confirmation' => 'ApeironSecurePassword2026!',
        ]);

        $setPasswordResponse->assertRedirect(route('login'));
        $setPasswordResponse->assertSessionHas('success');

        // Verify user password hash and email verification updated
        $user->refresh();
        $this->assertNotNull($user->password_hash);
        $this->assertTrue(Hash::check('ApeironSecurePassword2026!', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);

        // Verify token is consumed
        $tokenRecord = OwnerOnboardingToken::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $ownerUserId)
            ->first();
        $this->assertNotNull($tokenRecord);
        $this->assertTrue($tokenRecord->isConsumed());

        // 5. Reusing the token must be rejected
        $reuseResponse = $this->post("/onboarding/set-password/{$onboardingToken}", [
            'password' => 'AnotherPassword2026!',
            'password_confirmation' => 'AnotherPassword2026!',
        ]);
        $reuseResponse->assertRedirect(route('login'));
        $reuseResponse->assertSessionHas('error');

        // 6. Owner logs into POS
        $loginResponse = $this->post('/login', [
            'email' => $email,
            'password' => 'ApeironSecurePassword2026!',
        ]);

        $loginResponse->assertRedirect(route('home', absolute: false));
        $this->assertAuthenticatedAs($user);

        // Verify authenticated route resolves TenantContext via SetTenantContext middleware
        $homeResponse = $this->get(route('home'));
        $homeResponse->assertRedirect(route('dashboard'));
        $dashboardResponse = $this->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $tenantContext = app(TenantContext::class);
        $this->assertEquals($tenantId, $tenantContext->getTenantId());
        $this->assertNotEmpty($tenantContext->getAllowedStoreIds());

        // 7. Owner logs out
        $logoutResponse = $this->post('/logout');
        $logoutResponse->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_duplicate_email_registration_rejected_with_409(): void
    {
        $email = 'duplicate.owner@apeiron-e2e.test';
        $payload1 = [
            'tenant_name' => 'First Brand',
            'owner_name' => 'Owner Satu',
            'owner_email' => $email,
        ];

        $response1 = $this->postJson($this->path, $payload1, $this->signedHeaders($payload1));
        $response1->assertStatus(201);

        $payload2 = [
            'tenant_name' => 'Second Brand',
            'owner_name' => 'Owner Dua',
            'owner_email' => $email,
        ];

        $response2 = $this->postJson($this->path, $payload2, $this->signedHeaders($payload2));
        $response2->assertStatus(409)
            ->assertJson([
                'success' => false,
                'error' => 'email_already_in_use',
            ]);
    }

    public function test_invalid_hmac_rejected_with_401(): void
    {
        $payload = [
            'tenant_name' => 'Hacker Outlet',
            'owner_name' => 'Hacker Name',
            'owner_email' => 'hacker@attacker.test',
        ];

        $headers = $this->signedHeaders($payload, [
            'X-Signature' => str_repeat('a', 64),
        ]);

        $response = $this->postJson($this->path, $payload, $headers);
        $response->assertStatus(401);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $email = 'inactive.owner@apeiron-e2e.test';
        $payload = [
            'tenant_name' => 'Deactivated Brand',
            'owner_name' => 'Inactive Owner',
            'owner_email' => $email,
        ];

        $res = $this->postJson($this->path, $payload, $this->signedHeaders($payload));
        $token = $res->json('onboarding.onboarding_token');

        // Set password
        $this->post("/onboarding/set-password/{$token}", [
            'password' => 'ValidPassword123!',
            'password_confirmation' => 'ValidPassword123!',
        ]);

        // Deactivate user
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        $user->update(['is_active' => false]);

        // Attempt login
        $loginRes = $this->post('/login', [
            'email' => $email,
            'password' => 'ValidPassword123!',
        ]);

        $loginRes->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }
}
