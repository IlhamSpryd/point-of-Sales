<?php

declare(strict_types=1);

namespace App\Platform\Workflow\Services;

use App\Platform\Workflow\Exceptions\SelfApprovalForbiddenException;
use App\Platform\Workflow\MakerCheckerGuard;

class SegregationOfDutiesGuard extends MakerCheckerGuard
{
    /**
     * Memverifikasi pemisahan tugas (Segregation of Duties) antara pemohon dan penyetuju.
     * Mencegah self-approval secara mutlak.
     *
     * @throws SelfApprovalForbiddenException
     */
    public function assertCanApprove(int $requesterUserId, int $approverUserId, bool $allowSelfApprovalForMicroTenant = false): void
    {
        if ($requesterUserId === $approverUserId && ! $allowSelfApprovalForMicroTenant) {
            throw SelfApprovalForbiddenException::forUser($requesterUserId);
        }
    }
}
