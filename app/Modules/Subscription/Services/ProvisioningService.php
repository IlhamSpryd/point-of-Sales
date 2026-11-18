<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Services;

use App\Core\Context\TenantContext;
use App\Exceptions\StaleWorkerException;
use App\Jobs\ProcessProvisioningOutboxJob;
use App\Modules\Organization\Services\TenantProvisioningService;
use App\Modules\Subscription\Application\Services\ProvisioningResponseHelper;
use App\Modules\Subscription\Application\Services\ProvisioningStepExecutor;
use App\Modules\Subscription\Domain\Events\TenantProvisionedV1;
use App\Modules\Subscription\Models\ProvisioningRequest;
use App\Platform\Database\DatabaseErrorClassifier;
use App\Support\EmailNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Domain 1 — Provisioning tenant baru dari website marketing.
 * Step-tracked, decomposed, and idempotent SaaS tenant onboarding (EXT-03, ADR-104, R-19).
 */
class ProvisioningService
{
    private ProvisioningStepExecutor $stepExecutor;

    private ProvisioningResponseHelper $responseHelper;

    public function __construct(
        private TenantContext $tenantContext,
        private TenantProvisioningService $tenantProvisioning,
        ?ProvisioningStepExecutor $stepExecutor = null,
        ?ProvisioningResponseHelper $responseHelper = null,
    ) {
        $this->stepExecutor = $stepExecutor ?? app(ProvisioningStepExecutor::class);
        $this->responseHelper = $responseHelper ?? app(ProvisioningResponseHelper::class);
    }

    /**
     * Entry point provisioning. Mengembalikan array response contract.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: int, body: array<string, mixed>}
     */
    public function provision(array $data, string $idempotencyKey, string $fingerprint): array
    {
        // 1. Idempotency pre-check & klaim
        $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $response = $this->responseHelper->responseForExisting($existing, $fingerprint);
            if ($response !== null) {
                return $response;
            }
        } else {
            try {
                ProvisioningRequest::create([
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'owner_email' => $data['owner_email'],
                    'status' => ProvisioningRequest::STATUS_PENDING,
                    'started_at' => now(),
                    'attempt_count' => 0,
                ]);
            } catch (QueryException $e) {
                if (! DatabaseErrorClassifier::isDuplicateEntry($e)) {
                    throw $e;
                }

                $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
                if ($existing === null) {
                    throw $e;
                }

                $response = $this->responseHelper->responseForExisting($existing, $fingerprint);
                if ($response !== null) {
                    return $response;
                }
            }
        }

        $claim = $this->claim($idempotencyKey, $fingerprint);
        if ($claim === null) {
            return $this->responseHelper->pendingResponse();
        }
        $fencingToken = $claim->attempt_count;

        $committed = $this->responseHelper->committedProvisioning($idempotencyKey, $this->tenantContext);
        if ($committed !== null) {
            $this->finaliseLedger($idempotencyKey, $fencingToken, [
                'status' => ProvisioningRequest::STATUS_COMPLETED,
                'result_tenant_id' => $committed['tenant']->id,
                'result_user_id' => $committed['owner']->id,
                'completed_at' => now(),
                'error_message' => null,
            ]);

            $this->tenantProvisioning->refreshReadiness($committed['tenant']);

            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning dipulihkan dari transaksi yang sudah commit.',
                    'tenant_id' => $committed['tenant']->id,
                    'owner_user_id' => $committed['owner']->id,
                    'idempotent_replay' => true,
                ],
            ];
        }

        // 2. Email duplication check -> 409
        $normalizedEmail = EmailNormalizer::normalize($data['owner_email']);
        $emailOwner = $this->tenantContext->runWithoutTenant(function () use ($normalizedEmail) {
            return DB::table('users')
                ->where('email_normalized', $normalizedEmail)
                ->whereNull('deleted_at')
                ->first();
        });
        if ($emailOwner !== null) {
            $this->finaliseLedger($idempotencyKey, $fencingToken, [
                'status' => ProvisioningRequest::STATUS_FAILED,
                'error_message' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                'completed_at' => now(),
            ]);

            return $this->responseHelper->emailConflictResponse();
        }

        // 3. Atomic business transaction
        $result = null;
        $attempts = 0;
        $maxBusinessAttempts = 2;

        while ($result === null && $attempts < $maxBusinessAttempts) {
            $attempts++;

            try {
                $result = $this->tenantContext->runWithoutTenant(function () use ($data, $idempotencyKey, $fencingToken) {
                    return DB::transaction(function () use ($data, $idempotencyKey, $fencingToken) {
                        return $this->provisionWithinTransaction($data, $idempotencyKey, $fencingToken);
                    });
                });
            } catch (StaleWorkerException $e) {
                Log::warning('Provisioning worker stale; transaction rolled back.', [
                    'idempotency_key' => $idempotencyKey,
                    'fencing_token' => $fencingToken,
                    'reason' => $e->getMessage(),
                ]);

                $currentLedger = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
                if ($currentLedger !== null) {
                    $existingResponse = $this->responseHelper->responseForExisting($currentLedger, $fingerprint);
                    if ($existingResponse !== null) {
                        return $existingResponse;
                    }
                }

                return $this->responseHelper->pendingResponse();
            } catch (\Throwable $e) {
                if ($e instanceof QueryException && DatabaseErrorClassifier::isDuplicateEntry($e)) {
                    $emailConflict = $this->tenantContext->runWithoutTenant(
                        fn () => DB::table('users')
                            ->where('email_normalized', $normalizedEmail)
                            ->whereNull('deleted_at')
                            ->first()
                    );

                    if ($emailConflict !== null) {
                        $this->finaliseLedger($idempotencyKey, $fencingToken, [
                            'status' => ProvisioningRequest::STATUS_FAILED,
                            'error_message' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                            'completed_at' => now(),
                        ]);

                        return $this->responseHelper->emailConflictResponse();
                    }
                }

                if ($e instanceof QueryException && DatabaseErrorClassifier::isLockConflict($e) && $attempts < $maxBusinessAttempts) {
                    usleep(random_int(20_000, 80_000));

                    continue;
                }

                $this->finaliseLedger($idempotencyKey, $fencingToken, [
                    'status' => ProvisioningRequest::STATUS_FAILED,
                    'error_message' => $this->responseHelper->safeErrorMessage($e),
                    'completed_at' => now(),
                ]);

                $rawBrief = $e instanceof QueryException
                    ? (string) ($e->errorInfo[2] ?? $e->getCode())
                    : $e->getMessage();
                $redactedBrief = (string) preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $rawBrief);

                Log::error('Provisioning failed, transaction rolled back.', [
                    'idempotency_key' => $idempotencyKey,
                    'attempt' => $attempts,
                    'exception_class' => $e::class,
                    'exception_brief' => $redactedBrief,
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'success' => false,
                        'error' => 'provisioning_failed',
                        'message' => 'Provisioning gagal, silakan coba lagi dengan idempotency key yang sama.',
                        'retryable' => true,
                    ],
                ];
            }
        }

        if ($result === null) {
            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'error' => 'provisioning_failed',
                    'message' => 'Provisioning gagal, silakan coba lagi dengan idempotency key yang sama.',
                    'retryable' => true,
                ],
            ];
        }

        // 4. Post-commit operations
        try {
            $this->tenantProvisioning->refreshReadiness($result['tenant']);
        } catch (\Throwable $e) {
            Log::error('Tenant readiness refresh failed after provisioning commit.', [
                'tenant_id' => $result['tenant']->id,
                'exception_class' => $e::class,
            ]);
        }

        try {
            ProcessProvisioningOutboxJob::dispatch($result['outbox_id'], $result['onboarding_token'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('Outbox dispatch failed; scheduled processor will pick it up.', [
                'outbox_id' => $result['outbox_id'],
            ]);
        }

        // Emit TenantProvisionedV1 event only when all provisioning steps succeed (Task 1.2)
        try {
            event(new TenantProvisionedV1(
                eventId: (string) Str::uuid(),
                tenantId: (int) $result['tenant']->id,
                companyId: $result['company']?->id,
                branchId: (int) $result['store']->id,
                tenantName: (string) $result['tenant']->name,
                planCode: (string) ($result['tenant']->subscription_plan ?? 'standard_trial'),
                ownerEmail: (string) $data['owner_email'],
                occurredAt: now()->toIso8601String(),
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch TenantProvisionedV1 event.', [
                'tenant_id' => $result['tenant']->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'status' => 201,
            'body' => [
                'success' => true,
                'message' => 'Provisioning berhasil. Email onboarding akan dikirim ke Owner.',
                'tenant_id' => $result['tenant']->id,
                'owner_user_id' => $result['owner']->id,
                'store_id' => $result['store']->id,
                'onboarding' => array_merge(
                    ['email_sent_to' => $data['owner_email']],
                    (app()->environment('local', 'testing') && ! empty($result['onboarding_token']))
                        ? [
                            'activation_url' => url('/onboarding/set-password/'.$result['onboarding_token']),
                            'onboarding_token' => $result['onboarding_token'],
                        ]
                        : []
                ),
            ],
        ];
    }

    private function claim(string $idempotencyKey, string $fingerprint): ?ProvisioningRequest
    {
        for ($i = 0; $i < 5; $i++) {
            $row = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
            if ($row === null) {
                return null;
            }

            if ($row->request_fingerprint !== $fingerprint) {
                return null;
            }

            if (! $this->isClaimable($row)) {
                return null;
            }

            $nextAttempt = $row->attempt_count + 1;
            $leaseMinutes = max(1, (int) config('pos.provisioning.ledger_lease_minutes', 10));

            $updated = ProvisioningRequest::whereKey($row->id)
                ->where('status', $row->status)
                ->where('attempt_count', $row->attempt_count)
                ->update([
                    'status' => ProvisioningRequest::STATUS_PROCESSING,
                    'attempt_count' => $nextAttempt,
                    'started_at' => now(),
                    'lease_expires_at' => now()->addMinutes($leaseMinutes),
                ]);

            if ($updated === 1) {
                $row->attempt_count = $nextAttempt;
                $row->status = ProvisioningRequest::STATUS_PROCESSING;
                $row->lease_expires_at = now()->addMinutes($leaseMinutes);

                return $row;
            }
        }

        return null;
    }

    private function isClaimable(ProvisioningRequest $row): bool
    {
        if ($row->status === ProvisioningRequest::STATUS_COMPLETED) {
            return false;
        }

        if ($row->status === ProvisioningRequest::STATUS_FAILED) {
            return ! $row->isPermanentlyFailed();
        }

        if ($row->status === ProvisioningRequest::STATUS_PENDING) {
            return $row->lease_expires_at === null || $row->lease_expires_at->isPast();
        }

        if ($row->status === ProvisioningRequest::STATUS_PROCESSING) {
            return $row->lease_expires_at !== null && $row->lease_expires_at->isPast();
        }

        return false;
    }

    /**
     * Penulisan terminal (TX-012).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function finaliseLedger(string $idempotencyKey, int $fencingToken, array $attributes): bool
    {
        if ($fencingToken < 1) {
            return false;
        }

        $affected = ProvisioningRequest::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', ProvisioningRequest::STATUS_PROCESSING)
            ->where('attempt_count', $fencingToken)
            ->update([
                'status' => $attributes['status'],
                'completed_at' => $attributes['completed_at'] ?? now(),
                'error_message' => $attributes['error_message'] ?? null,
                'result_tenant_id' => $attributes['result_tenant_id'] ?? null,
                'result_user_id' => $attributes['result_user_id'] ?? null,
                'lease_expires_at' => null,
            ]);

        return $affected === 1;
    }

    /**
     * Transaksi utama (TX-001): Step-tracked and modularized.
     *
     * @param  array<string, mixed>  $data
     * @return array{tenant: \App\Modules\Organization\Models\Tenant, owner: \App\Modules\Organization\Models\User, store: \App\Modules\Organization\Models\Store, company: \App\Modules\Organization\Models\Company|null, outbox_id: int, onboarding_token: string}
     */
    private function provisionWithinTransaction(array $data, string $idempotencyKey, int $fencingToken): array
    {
        $ledger = ProvisioningRequest::where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($ledger === null) {
            throw new StaleWorkerException("Baris ledger hilang untuk key {$idempotencyKey}", $idempotencyKey, $fencingToken);
        }

        if ($ledger->status !== ProvisioningRequest::STATUS_PROCESSING) {
            throw new StaleWorkerException("Status ledger bukan processing ({$ledger->status})", $idempotencyKey, $fencingToken);
        }

        if ((int) $ledger->attempt_count !== $fencingToken) {
            throw new StaleWorkerException("Fencing token mismatch (ledger: {$ledger->attempt_count}, worker: {$fencingToken})", $idempotencyKey, $fencingToken);
        }

        if ($ledger->lease_expires_at !== null && $ledger->lease_expires_at->isPast()) {
            throw new StaleWorkerException("Lease worker sudah kedaluwarsa ({$ledger->lease_expires_at})", $idempotencyKey, $fencingToken);
        }

        // Step 1: Create Tenant
        $tenant = $this->stepExecutor->executeCreateTenant($ledger->id, $data, $idempotencyKey);

        // Steps 2-6 within tenant context
        $result = $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $data, $ledger) {
            // Step 2: Company & Store
            $branchInfo = $this->stepExecutor->executeCreateCompanyAndBranch($ledger->id, $tenant, $data);
            $store = $branchInfo['store'];
            $company = $branchInfo['company'];

            // Step 3: Role & Owner
            $ownerInfo = $this->stepExecutor->executeSeedRolesAndOwner($ledger->id, $tenant, $data);
            $owner = $ownerInfo['owner'];
            $plaintextToken = $ownerInfo['onboarding_token'];

            // Step 4: Baseline & Chart of Accounts
            $this->stepExecutor->executeSeedChartOfAccounts($ledger->id, $tenant, $owner);

            // Step 5: Subscription Plan
            $this->stepExecutor->executeActivateSubscriptionPlan($ledger->id, $tenant, $ledger->plan_id);

            // Step 6: Welcome Notification
            $emailBody = $this->responseHelper->onboardingEmailBody($owner->name, $tenant->name, $plaintextToken);
            $outboxId = $this->stepExecutor->executeDispatchWelcomeNotification(
                $ledger->id,
                $tenant,
                $owner,
                $plaintextToken,
                $emailBody
            );

            // Audit
            $this->stepExecutor->recordAudit($tenant, $store, $owner);

            return [
                'owner' => $owner,
                'store' => $store,
                'company' => $company,
                'outbox_id' => $outboxId,
                'onboarding_token' => $plaintextToken,
            ];
        });

        $finalized = ProvisioningRequest::whereKey($ledger->id)
            ->where('status', ProvisioningRequest::STATUS_PROCESSING)
            ->where('attempt_count', $fencingToken)
            ->where(function ($q) {
                $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '>', now());
            })
            ->update([
                'status' => ProvisioningRequest::STATUS_COMPLETED,
                'result_tenant_id' => $tenant->id,
                'result_user_id' => $result['owner']->id,
                'completed_at' => now(),
                'error_message' => null,
                'lease_expires_at' => null,
            ]);

        if ($finalized !== 1) {
            throw new StaleWorkerException('Gagal memfinalisasi ledger di dalam transaksi; fencing atau lease hilang.', $idempotencyKey, $fencingToken);
        }

        return [
            'tenant' => $tenant,
            'owner' => $result['owner'],
            'store' => $result['store'],
            'company' => $result['company'],
            'outbox_id' => $result['outbox_id'],
            'onboarding_token' => $result['onboarding_token'],
        ];
    }

    public static function injectOnboardingToken(string $body, string $tokenHash, string $plaintextToken): string
    {
        return str_replace(
            '/onboarding/set-password/'.$tokenHash,
            '/onboarding/set-password/'.$plaintextToken,
            $body,
        );
    }
}
