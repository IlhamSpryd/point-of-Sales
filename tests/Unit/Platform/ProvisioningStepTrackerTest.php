<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Modules\Subscription\Services\ProvisioningStepTracker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProvisioningStepTrackerTest extends TestCase
{
    private ProvisioningStepTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new ProvisioningStepTracker;
    }

    public function test_executes_step_and_records_completed_state(): void
    {
        $executed = 0;
        $result = $this->tracker->executeStep('req_001', ProvisioningStepTracker::STEP_TENANT_CREATE, function () use (&$executed) {
            $executed++;

            return ['tenant_id' => 99];
        });

        $this->assertSame(1, $executed);
        $this->assertSame(['tenant_id' => 99], $result);
        $this->assertTrue($this->tracker->isStepCompleted('req_001', ProvisioningStepTracker::STEP_TENANT_CREATE));
        $this->assertSame(ProvisioningStepTracker::STATUS_COMPLETED, $this->tracker->getStepStatus('req_001', ProvisioningStepTracker::STEP_TENANT_CREATE));

        // Replaying the step does not re-execute the action
        $replayedResult = $this->tracker->executeStep('req_001', ProvisioningStepTracker::STEP_TENANT_CREATE, function () use (&$executed) {
            $executed++;

            return ['tenant_id' => 9999];
        });

        $this->assertSame(1, $executed, 'Completed step must not be executed again.');
        $this->assertSame(['tenant_id' => 99], $replayedResult);
    }

    public function test_records_failure_state_when_step_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB connection lost');

        try {
            $this->tracker->executeStep('req_002', ProvisioningStepTracker::STEP_STORE_CREATE, function () {
                throw new RuntimeException('DB connection lost');
            });
        } finally {
            $this->assertSame(ProvisioningStepTracker::STATUS_FAILED, $this->tracker->getStepStatus('req_002', ProvisioningStepTracker::STEP_STORE_CREATE));
            $this->assertFalse($this->tracker->isStepCompleted('req_002', ProvisioningStepTracker::STEP_STORE_CREATE));
        }
    }

    public function test_all_steps_completed_verification(): void
    {
        $steps = [
            ProvisioningStepTracker::STEP_VALIDATE,
            ProvisioningStepTracker::STEP_TENANT_CREATE,
            ProvisioningStepTracker::STEP_OWNER_CREATE,
        ];

        $this->assertFalse($this->tracker->allStepsCompleted('req_003', $steps));

        $this->tracker->executeStep('req_003', ProvisioningStepTracker::STEP_VALIDATE, fn () => true);
        $this->tracker->executeStep('req_003', ProvisioningStepTracker::STEP_TENANT_CREATE, fn () => 10);
        $this->assertFalse($this->tracker->allStepsCompleted('req_003', $steps));

        $this->tracker->executeStep('req_003', ProvisioningStepTracker::STEP_OWNER_CREATE, fn () => 20);
        $this->assertTrue($this->tracker->allStepsCompleted('req_003', $steps));
        $this->assertCount(3, $this->tracker->getCompletedSteps('req_003'));
    }
}
