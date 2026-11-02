<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Exceptions;

use DomainException;

class SelfApprovalForbiddenException extends DomainException
{
    public static function forUser(int $userId): self
    {
        return new self("Pemisahan Tugas (Segregation of Duties): Pemohon (ID: {$userId}) dilarang menyetujui pengajuannya sendiri.");
    }
}
