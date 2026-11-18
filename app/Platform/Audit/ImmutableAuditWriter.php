<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Enterprise Immutable Append-Only Audit Entry Writer with PII & Secret Redaction (EXT-04, ADR-110).
 */
class ImmutableAuditWriter
{
    /**
     * @var list<string>
     */
    protected const SENSITIVE_PATTERNS = [
        'password',
        'passwd',
        'pin',
        'secret',
        'token',
        'api_key',
        'apikey',
        'npwp',
        'bank_account_number',
        'account_number',
        'credit_card',
        'card_number',
        'cvv',
        'authorization',
    ];

    /**
     * Writes an immutable, sanitized audit entry to the activity log.
     *
     * @param  int  $tenantId  Tenant scoping boundary
     * @param  string  $action  Canonical structured action identifier (e.g. 'order.voided', 'approval.granted')
     * @param  int|null  $userId  Actor user ID
     * @param  int|null  $storeId  Branch/store operational location ID
     * @param  string|null  $subjectType  Subject aggregate type
     * @param  int|null  $subjectId  Subject aggregate ID
     * @param  string|null  $description  Human-readable description
     * @param  array<string, mixed>|null  $payload  Contextual payload data
     */
    public function write(
        int $tenantId,
        string $action,
        ?int $userId = null,
        ?int $storeId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        ?array $payload = null
    ): ?int {
        $sanitizedPayload = $payload !== null ? $this->redactSensitiveData($payload) : null;

        // Ensure store_id satisfies non-null schema constraint
        if ($storeId === null && class_exists(\App\Core\Context\TenantContext::class)) {
            $storeId = app(\App\Core\Context\TenantContext::class)->getStoreId();
        }

        if ($storeId === null && class_exists(DB::class)) {
            $storeId = (int) (DB::table('stores')
                ->where('tenant_id', $tenantId)
                ->where('is_active', 1)
                ->value('id')
                ?? DB::table('stores')->where('tenant_id', $tenantId)->value('id')
                ?? 1);
        }

        try {
            if (class_exists(DB::class)) {
                $id = DB::table('activity_logs')->insertGetId([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'user_id' => $userId,
                    'action' => $action,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'description' => $description,
                    'new_values' => $sanitizedPayload !== null ? json_encode($sanitizedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return (int) $id;
            }
        } catch (\Throwable $e) {
            Log::warning("[IMMUTABLE-AUDIT] Could not write audit log '{$action}' for tenant #{$tenantId}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Recursively redacts PII and sensitive credentials from the payload dictionary.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public function redactSensitiveData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->redactSensitiveData($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Checks if a key name represents a sensitive credential or PII field.
     */
    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-', ' ', '.'], '', $key));

        // Preserve non-sensitive operational timestamp / counter metadata
        if (
            str_ends_with($normalized, 'expiresat') ||
            str_ends_with($normalized, 'expiredat') ||
            str_ends_with($normalized, 'expiresin') ||
            str_ends_with($normalized, 'count') ||
            str_ends_with($normalized, 'length')
        ) {
            return false;
        }

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            $cleanPattern = str_replace(['_', '-'], '', $pattern);
            if (str_contains($normalized, $cleanPattern)) {
                return true;
            }
        }

        return false;
    }
}
