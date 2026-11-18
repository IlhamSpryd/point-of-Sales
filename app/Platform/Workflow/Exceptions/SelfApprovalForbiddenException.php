<?php

declare(strict_types=1);

namespace App\Platform\Workflow\Exceptions;

class SelfApprovalForbiddenException extends SegregationOfDutiesViolationException
{
    public static function forUser(int $userId): self
    {
        return new self("Pemisahan Tugas (Segregation of Duties): Pemohon (ID: {$userId}) dilarang menyetujui pengajuannya sendiri.");
    }
}
