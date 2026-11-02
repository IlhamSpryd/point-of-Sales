<?php

declare(strict_types=1);

namespace App\Modules\Subscription\Exceptions;

use DomainException;

class PlanLimitExceededException extends DomainException
{
    public static function forQuota(string $dimension, int $limit): self
    {
        return new self("Batas kuota {$dimension} ({$limit}) untuk paket langganan Anda telah tercapai. Silakan upgrade paket langganan Anda untuk menambah data baru.");
    }
}
