<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Modules\Subscription\Application\Services\ProvisioningStepTracker;
use App\Modules\Subscription\Domain\Events\TenantProvisionedV1;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Modules\Subscription\Models\ProvisioningStep;
use App\Modules\Subscription\Services\ProvisioningService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies step-tracked idempotent tenant provisioning ledger and execution (EXT-03, R-19).
 */
class IdempotentProvisioningStepsTest extends TestCase
{
    use DatabaseTransactions;

    private ProvisioningStepTracker $tracker;

    private ProvisioningRequest $request;

    protected function afterSubclassSetUp(): void
    {
        $this->tracker = new ProvisioningStepTracker;
        $this->request = ProvisioningRequest::create([
            'idempotency_key' => 'step-test-'.Str::random(12),
            'request_fingerprint' => hash('sha256', 'step-test'),
            'owner_email' => 'step.test.'.Str::random(6).'@example.com',
            'status' => ProvisioningRequest::STATUS_PENDING,
            'started_at' => now(),
            'attempt_count' => 1,
        ]);
    }

    public function test_provisioning_step_tracker_executes_step_and_records_completed_state(): void
    {
        $invocations = 0;
        $result = $this->tracker->runStep(
            $this->request->id,
            ProvisioningStep::STEP_CREATE_TENANT,
            null,
            function () use (&$invocations) {
                $invocations++;

                return ['tenant_id' => 101];
            }
        );

        $this->assertSame(1, $invocations);
        $this->assertSame(['tenant_id' => 101], $result);

        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $this->request->id,
            'step_key' => ProvisioningStep::STEP_CREATE_TENANT,
            'status' => ProvisioningStep::STATUS_COMPLETED,
            'attempts' => 1,
        ]);

        $this->assertTrue($this->tracker->isStepCompleted($this->request->id, ProvisioningStep::STEP_CREATE_TENANT));
        $this->assertSame(ProvisioningStep::STATUS_COMPLETED, $this->tracker->getStepStatus($this->request->id, ProvisioningStep::STEP_CREATE_TENANT));
    }

    public function test_completed_step_is_skipped_on_subsequent_replay(): void
    {
        $invocations = 0;
        $callback = function () use (&$invocations) {
            $invocations++;

            return 'executed';
        };

        $first = $this->tracker->runStep($this->request->id, ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, null, $callback);
        $this->assertSame('executed', $first);
        $this->assertSame(1, $invocations);

        // Replay step
        $second = $this->tracker->runStep($this->request->id, ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, null, $callback);
        $this->assertNull($second);
        $this->assertSame(1, $invocations, 'Completed step must not be executed again.');
    }

    public function test_failed_step_records_failed_status_and_last_error_then_can_be_retried(): void
    {
        $stepKey = ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH;

        try {
            $this->tracker->runStep($this->request->id, $stepKey, null, function () {
                throw new RuntimeException('Connection timed out');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection timed out', $e->getMessage());
        }

        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $this->request->id,
            'step_key' => $stepKey,
            'status' => ProvisioningStep::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Connection timed out',
        ]);
        $this->assertFalse($this->tracker->isStepCompleted($this->request->id, $stepKey));

        // Retry and succeed
        $retryResult = $this->tracker->runStep($this->request->id, $stepKey, null, function () {
            return 'recovered';
        });

        $this->assertSame('recovered', $retryResult);
        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $this->request->id,
            'step_key' => $stepKey,
            'status' => ProvisioningStep::STATUS_COMPLETED,
            'attempts' => 2,
            'last_error' => null,
        ]);
        $this->assertTrue($this->tracker->isStepCompleted($this->request->id, $stepKey));
    }

    public function test_all_steps_completed_verification(): void
    {
        $steps = [
            ProvisioningStep::STEP_CREATE_TENANT,
            ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH,
            ProvisioningStep::STEP_SEED_ROLES_AND_OWNER,
        ];

        $this->assertFalse($this->tracker->allStepsCompleted($this->request->id, $steps));

        $this->tracker->runStep($this->request->id, ProvisioningStep::STEP_CREATE_TENANT, null, fn () => true);
        $this->tracker->runStep($this->request->id, ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH, null, fn () => true);
        $this->assertFalse($this->tracker->allStepsCompleted($this->request->id, $steps));

        $this->tracker->runStep($this->request->id, ProvisioningStep::STEP_SEED_ROLES_AND_OWNER, null, fn () => true);
        $this->assertTrue($this->tracker->allStepsCompleted($this->request->id, $steps));
        $this->assertCount(3, $this->tracker->getCompletedSteps($this->request->id));
    }

    public function test_full_provisioning_emits_tenant_provisioned_v1_domain_event(): void
    {
        Event::fake([TenantProvisionedV1::class]);

        $service = app(ProvisioningService::class);
        $idempotencyKey = 'full-prov-'.Str::random(10);
        $ownerEmail = 'owner.steps.'.Str::random(6).'@example.com';
        $payload = [
            'tenant_name' => 'Automated Step Cafe',
            'owner_name' => 'Cafe Founder',
            'owner_email' => $ownerEmail,
            'owner_phone' => '081299990001',
        ];
        $fingerprint = hash('sha256', 'POST|prov|'.json_encode($payload));

        $result = $service->provision($payload, $idempotencyKey, $fingerprint);

        $this->assertSame(201, $result['status']);
        $this->assertTrue($result['body']['success']);
        $tenantId = $result['body']['tenant_id'];

        Event::assertDispatched(TenantProvisionedV1::class, function (TenantProvisionedV1 $event) use ($tenantId, $ownerEmail) {
            return $event->tenantId === $tenantId
                && $event->ownerEmail === $ownerEmail
                && $event->tenantName === 'Automated Step Cafe'
                && $event->eventName() === 'tenancy.tenant.provisioned.v1';
        });

        // Verify tracked steps
        $ledger = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->firstOrFail();
        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $ledger->id,
            'step_key' => ProvisioningStep::STEP_CREATE_TENANT,
            'status' => ProvisioningStep::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $ledger->id,
            'step_key' => ProvisioningStep::STEP_CREATE_COMPANY_AND_BRANCH,
            'status' => ProvisioningStep::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('provisioning_steps', [
            'provisioning_request_id' => $ledger->id,
            'step_key' => ProvisioningStep::STEP_SEED_ROLES_AND_OWNER,
            'status' => ProvisioningStep::STATUS_COMPLETED,
        ]);
    }

    public function test_step_executor_returns_valid_aggregates_on_idempotent_replay(): void
    {
        $executor = app(\App\Modules\Subscription\Application\Services\ProvisioningStepExecutor::class);
        $context = app(\App\Core\Context\TenantContext::class);

        $idempotencyKey = 'replay-executor-'.Str::random(10);
        $req = ProvisioningRequest::create([
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => hash('sha256', 'replay-executor'),
            'owner_email' => 'replay.'.Str::random(6).'@example.com',
            'status' => ProvisioningRequest::STATUS_PENDING,
            'started_at' => now(),
            'attempt_count' => 1,
        ]);

        $payload = [
            'tenant_name' => 'Replay Test Tenant',
            'owner_name' => 'Replay Owner',
            'owner_email' => $req->owner_email,
            'owner_phone' => '081234567890',
        ];

        // First execution
        $tenant1 = $executor->executeCreateTenant($req->id, $payload, $idempotencyKey);
        $result1 = $context->runAs($tenant1->id, null, function () use ($executor, $req, $tenant1, $payload) {
            $branch = $executor->executeCreateCompanyAndBranch($req->id, $tenant1, $payload);
            $owner = $executor->executeSeedRolesAndOwner($req->id, $tenant1, $payload);
            $outbox = $executor->executeDispatchWelcomeNotification($req->id, $tenant1, $owner['owner'], $owner['onboarding_token'], 'Welcome text');

            return ['branch' => $branch, 'owner' => $owner, 'outbox' => $outbox];
        });

        $this->assertInstanceOf(\App\Modules\Organization\Models\Tenant::class, $tenant1);
        $this->assertIsArray($result1['branch']);
        $this->assertIsArray($result1['owner']);
        $this->assertIsInt($result1['outbox']);

        // Idempotent replay: all steps already marked COMPLETED
        $tenant2 = $executor->executeCreateTenant($req->id, $payload, $idempotencyKey);
        $result2 = $context->runAs($tenant2->id, null, function () use ($executor, $req, $tenant2, $payload) {
            $branch = $executor->executeCreateCompanyAndBranch($req->id, $tenant2, $payload);
            $owner = $executor->executeSeedRolesAndOwner($req->id, $tenant2, $payload);
            $outbox = $executor->executeDispatchWelcomeNotification($req->id, $tenant2, $owner['owner'], $owner['onboarding_token'], 'Welcome text');

            return ['branch' => $branch, 'owner' => $owner, 'outbox' => $outbox];
        });

        $this->assertSame($tenant1->id, $tenant2->id);
        $this->assertSame($result1['branch']['store']->id, $result2['branch']['store']->id);
        $this->assertSame($result1['owner']['owner']->id, $result2['owner']['owner']->id);
        $this->assertSame($result1['outbox'], $result2['outbox']);
    }
}
