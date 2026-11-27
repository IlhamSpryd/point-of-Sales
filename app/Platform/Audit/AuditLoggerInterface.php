<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * Cross-module contract for structured enterprise audit trail logging.
 */
interface AuditLoggerInterface
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>|null  $metadata
     */
    public function log(
        int $tenantId,
        string $action,
        int|string|null $userId = null,
        ?int $storeId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?int;
}
