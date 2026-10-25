<?php

namespace App\Modules\Auth\Services;

use App\Models\EmailVerificationOtp;

class OtpVerificationResult
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_INVALID_CODE = 'invalid_code';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_SUPERSEDED = 'superseded';

    public function __construct(
        public bool $successful,
        public string $status,
        public ?EmailVerificationOtp $otp = null,
        public int $attemptsRemaining = 0
    ) {}

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isInvalid(): bool
    {
        return $this->status === self::STATUS_INVALID_CODE;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRemainingAttempts(): int
    {
        return $this->attemptsRemaining;
    }
}
