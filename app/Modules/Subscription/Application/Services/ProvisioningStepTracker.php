<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Application\Services;

use App\Modules\Subscription\Models\ProvisioningStep;
use Throwable;

/**
 * Idempotent step-tracked execution and persistence service for multi-step tenant provisioning (EXT-03, R-19).
 * Ensures that if provisioning fails midway, completed steps are not duplicated on replay.
 */
class ProvisioningStepTracker
{
    /**
     * Executes an individual provisioning step idempotently and tracks its state in the database.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|mixed
     *
     * @throws Throwable
     */
    public function runStep(int $requestId, string $stepKey, ?int $tenantId, callable $callback): mixed
    {
        /** @var ProvisioningStep $step */
        $step = ProvisioningStep::firstOrCreate(
            [
                'provisioning_request_id' => $requestId,
                'step_key' => $stepKey,
            ],
            [
                'tenant_id' => $tenantId,
                'status' => ProvisioningStep::STATUS_PENDING,
                'attempts' => 0,
            ]
        );

        if ($step->status === ProvisioningStep::STATUS_COMPLETED) {
            return null;
        }

        if ($tenantId !== null && $step->tenant_id === null) {
            $step->tenant_id = $tenantId;
        }

        $step->increment('attempts');

        try {
            $result = $callback();

            $step->update([
                'status' => ProvisioningStep::STATUS_COMPLETED,
                'completed_at' => now(),
                'last_error' => null,
                'tenant_id' => $tenantId ?? $step->tenant_id,
            ]);

            return $result;
        } catch (Throwable $e) {
            $step->update([
                'status' => ProvisioningStep::STATUS_FAILED,
                'last_error' => $e->getMessage(),
                'tenant_id' => $tenantId ?? $step->tenant_id,
            ]);

            throw $e;
        }
    }

    public function isStepCompleted(int $requestId, string $stepKey): bool
    {
        return ProvisioningStep::where('provisioning_request_id', $requestId)
            ->where('step_key', $stepKey)
            ->where('status', ProvisioningStep::STATUS_COMPLETED)
            ->exists();
    }

    public function getStepStatus(int $requestId, string $stepKey): string
    {
        return ProvisioningStep::where('provisioning_request_id', $requestId)
            ->where('step_key', $stepKey)
            ->value('status') ?? ProvisioningStep::STATUS_PENDING;
    }

    /**
     * @param  list<string>  $requiredSteps
     */
    public function allStepsCompleted(int $requestId, array $requiredSteps): bool
    {
        $completedCount = ProvisioningStep::where('provisioning_request_id', $requestId)
            ->whereIn('step_key', $requiredSteps)
            ->where('status', ProvisioningStep::STATUS_COMPLETED)
            ->count();

        return $completedCount === count($requiredSteps);
    }

    /**
     * @return list<string>
     */
    public function getCompletedSteps(int $requestId): array
    {
        /** @var list<string> */
        return ProvisioningStep::where('provisioning_request_id', $requestId)
            ->where('status', ProvisioningStep::STATUS_COMPLETED)
            ->pluck('step_key')
            ->all();
    }
}
