<?php

declare(strict_types=1);

namespace App\Platform\Workflow\Exceptions;

use DomainException;

/**
 * Thrown when an approval attempt violates Segregation of Duties (SoD / Maker-Checker) (EXT-04).
 */
class SegregationOfDutiesViolationException extends DomainException
{
    public static function forUser(int $userId): self
    {
        return new self("Pelanggaran Pemisahan Tugas (SoD): Pengguna #{$userId} tidak diizinkan menyetujui pengajuannya sendiri.");
    }
}
