<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Application\Services;

use App\Core\Context\TenantContext;
use App\Modules\Subscription\Models\ProvisioningRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates response generation, error redaction, and committed fact lookups for provisioning (EXT-03, ADR-104).
 */
class ProvisioningResponseHelper
{
    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function responseForExisting(ProvisioningRequest $existing, string $fingerprint): ?array
    {
        if ($existing->request_fingerprint !== $fingerprint) {
            return [
                'status' => 409,
                'body' => [
                    'success' => false,
                    'error' => 'idempotency_key_conflict',
                    'message' => 'Idempotency key sudah digunakan dengan payload berbeda.',
                ],
            ];
        }

        if ($existing->status === ProvisioningRequest::STATUS_COMPLETED) {
            if (! $existing->result_tenant_id) {
                return $this->pendingResponse();
            }

            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning sudah selesai sebelumnya (idempotent).',
                    'tenant_id' => $existing->result_tenant_id,
                    'owner_user_id' => $existing->result_user_id,
                    'idempotent_replay' => true,
                ],
            ];
        }

        if ($existing->status === ProvisioningRequest::STATUS_FAILED) {
            if ($existing->isPermanentlyFailed()) {
                return $this->emailConflictResponse();
            }

            return null;
        }

        if ($existing->status === ProvisioningRequest::STATUS_PROCESSING
            && $existing->lease_expires_at !== null
            && $existing->lease_expires_at->isFuture()) {
            return $this->pendingResponse();
        }

        return null;
    }

    /**
     * @return array{tenant: \App\Modules\Organization\Models\Tenant, owner: \App\Modules\Organization\Models\User}|null
     */
    public function committedProvisioning(string $idempotencyKey, TenantContext $tenantContext): ?array
    {
        $tenantRow = DB::table('tenants')->where('provisioning_idempotency_key', $idempotencyKey)->first();
        if ($tenantRow === null) {
            return null;
        }

        $ownerId = DB::table('owner_onboarding_tokens')->where('tenant_id', $tenantRow->id)->value('user_id');
        if ($ownerId === null) {
            return null;
        }

        $ownerRow = DB::table('users')->where('tenant_id', $tenantRow->id)->where('id', $ownerId)->first();
        if ($ownerRow === null) {
            return null;
        }

        /** @var \App\Modules\Organization\Models\Tenant $tenant */
        $tenant = \App\Modules\Organization\Models\Tenant::find($tenantRow->id);
        /** @var \App\Modules\Organization\Models\User $owner */
        $owner = \App\Modules\Organization\Models\User::find($ownerRow->id);

        return ['tenant' => $tenant, 'owner' => $owner];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function pendingResponse(): array
    {
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => 'Provisioning sedang diproses.',
                'status' => 'pending',
                'retryable' => true,
            ],
        ];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function emailConflictResponse(): array
    {
        return [
            'status' => 409,
            'body' => [
                'success' => false,
                'error' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                'message' => 'Email tersebut sudah terdaftar sebagai akun aktif.',
            ],
        ];
    }

    public function safeErrorMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof QueryException => 'database_error',
            default => 'internal_error',
        };
    }

    public function onboardingEmailBody(string $ownerName, string $tenantName, string $tokenHash): string
    {
        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $relative = route('onboarding.set-password', ['token' => $tokenHash], false);
        $link = $baseUrl.$relative;

        return "Halo {$ownerName},\n\n"
            ."Akun Apeiron System untuk bisnis \"{$tenantName}\" telah dibuat.\n"
            ."Silakan atur password Anda melalui tautan berikut (berlaku 48 jam, sekali pakai):\n\n"
            ."{$link}\n\n"
            ."Jika Anda tidak merasa mendaftar, abaikan email ini.\n\n"
            .'— Tim Apeiron System';
    }
}
