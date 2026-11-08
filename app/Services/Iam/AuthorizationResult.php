<?php

declare(strict_types=1);

namespace App\Services\Iam;

class AuthorizationResult
{
    public const CODE_ALLOWED = 'ALLOW_EXPLICIT_GRANT';
    public const CODE_EXPLICIT_DENY = 'DENY_EXPLICIT';
    public const CODE_TENANT_MISMATCH = 'DENY_TENANT_MISMATCH';
    public const CODE_SCOPE_MISMATCH = 'DENY_SCOPE_MISMATCH';
    public const CODE_SOD_VIOLATION = 'DENY_SOD_VIOLATION';
    public const CODE_TEMPORAL_EXPIRED = 'DENY_TEMPORAL_EXPIRED';
    public const CODE_APPROVAL_REQUIRED = 'GATE_APPROVAL_REQUIRED';
    public const CODE_ABAC_LIMIT_EXCEEDED = 'DENY_ABAC_LIMIT_EXCEEDED';
    public const CODE_DEFAULT_DENY = 'DENY_DEFAULT';

    public function __construct(
        public readonly bool $isAllowed,
        public readonly string $code,
        public readonly string $reason,
        public readonly bool $requiresApproval = false,
        public readonly ?float $maxAllowedValue = null,
        public readonly array $metadata = []
    ) {}

    public static function allow(string $code = self::CODE_ALLOWED, string $reason = 'Action authorized.'): self
    {
        return new self(true, $code, $reason);
    }

    public static function deny(string $code, string $reason, array $metadata = []): self
    {
        return new self(false, $code, $reason, false, null, $metadata);
    }

    public static function requiresApproval(string $reason, float $maxAllowedValue, array $metadata = []): self
    {
        return new self(false, self::CODE_APPROVAL_REQUIRED, $reason, true, $maxAllowedValue, $metadata);
    }
}
