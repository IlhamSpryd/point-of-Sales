<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Exceptions\SelfApprovalForbiddenException;

class SegregationOfDutiesGuard
{
    /**
     * Memverifikasi pemisahan tugas (Segregation of Duties) antara pemohon dan penyetuju.
     * Mencegah self-approval secara mutlak.
     *
     * @throws SelfApprovalForbiddenException
     */
    public function assertCanApprove(int $requesterUserId, int $approverUserId): void
    {
        if ($requesterUserId === $approverUserId) {
            throw SelfApprovalForbiddenException::forUser($requesterUserId);
        }
    }
}
