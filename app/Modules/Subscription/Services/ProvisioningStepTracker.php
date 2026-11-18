<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use Throwable;

/**
 * Idempotent step execution and state tracker for multi-step tenant provisioning (EXT-03, ADR-104).
 * Ensures that if provisioning fails midway, completed steps are not duplicated on replay.
 */
class ProvisioningStepTracker
{
    public const STEP_VALIDATE = 'validate';

    public const STEP_TENANT_CREATE = 'tenant_create';

    public const STEP_ROLE_PROVISION = 'role_provision';

    public const STEP_STORE_CREATE = 'store_create';

    public const STEP_OWNER_CREATE = 'owner_create';

    public const STEP_AUDIT_LOG = 'audit_log';

    public const STEP_EVENT_EMIT = 'event_emit';

    public const STEP_CREATE_TENANT = 'create_tenant';

    public const STEP_CREATE_COMPANY_AND_BRANCH = 'create_company_and_branch';

    public const STEP_SEED_ROLES_AND_OWNER = 'seed_roles_and_owner';

    public const STEP_SEED_CHART_OF_ACCOUNTS = 'seed_chart_of_accounts';

    public const STEP_ACTIVATE_SUBSCRIPTION_PLAN = 'activate_subscription_plan';

    public const STEP_DISPATCH_WELCOME_NOTIFICATION = 'dispatch_welcome_notification';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    /**
     * In-memory ledger of step execution states.
     *
     * @var array<string, array<string, array{status: string, output: mixed, error: string|null, attempt: int}>>
     */
    protected array $stepLedger = [];

    /**
     * Executes an individual step idempotently. If previously completed, returns existing result.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public function executeStep(string $requestId, string $stepName, callable $action): mixed
    {
        if ($this->isStepCompleted($requestId, $stepName)) {
            /** @var T */
            return $this->getStepOutput($requestId, $stepName);
        }

        $attempt = ($this->stepLedger[$requestId][$stepName]['attempt'] ?? 0) + 1;
        $this->stepLedger[$requestId][$stepName] = [
            'status' => self::STATUS_RUNNING,
            'output' => null,
            'error' => null,
            'attempt' => $attempt,
        ];

        try {
            $result = $action();

            $this->stepLedger[$requestId][$stepName] = [
                'status' => self::STATUS_COMPLETED,
                'output' => $result,
                'error' => null,
                'attempt' => $attempt,
            ];

            return $result;
        } catch (Throwable $e) {
            $this->stepLedger[$requestId][$stepName] = [
                'status' => self::STATUS_FAILED,
                'output' => null,
                'error' => $e->getMessage(),
                'attempt' => $attempt,
            ];

            throw $e;
        }
    }

    public function isStepCompleted(string $requestId, string $stepName): bool
    {
        return ($this->stepLedger[$requestId][$stepName]['status'] ?? null) === self::STATUS_COMPLETED;
    }

    public function getStepStatus(string $requestId, string $stepName): string
    {
        return $this->stepLedger[$requestId][$stepName]['status'] ?? self::STATUS_PENDING;
    }

    public function getStepOutput(string $requestId, string $stepName): mixed
    {
        return $this->stepLedger[$requestId][$stepName]['output'] ?? null;
    }

    /**
     * @param  list<string>  $requiredSteps
     */
    public function allStepsCompleted(string $requestId, array $requiredSteps): bool
    {
        foreach ($requiredSteps as $step) {
            if (! $this->isStepCompleted($requestId, $step)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public function getCompletedSteps(string $requestId): array
    {
        $completed = [];
        foreach ($this->stepLedger[$requestId] ?? [] as $step => $info) {
            if ($info['status'] === self::STATUS_COMPLETED) {
                $completed[] = $step;
            }
        }

        return $completed;
    }

    public function clear(string $requestId): void
    {
        unset($this->stepLedger[$requestId]);
    }
}
