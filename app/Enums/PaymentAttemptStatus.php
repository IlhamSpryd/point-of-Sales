<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case PendingProvider = 'pending_provider';
    case UnknownTimeout = 'unknown_timeout';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Expired, self::Cancelled => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Diinisiasi',
            self::PendingProvider => 'Menunggu Gateway',
            self::UnknownTimeout => 'Timeout Tidak Diketahui',
            self::Succeeded => 'Berhasil',
            self::Failed => 'Gagal',
            self::Expired => 'Kedaluwarsa',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
