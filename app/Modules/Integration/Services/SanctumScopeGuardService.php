<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SanctumScopeGuardService
{
    /**
     * Memvalidasi bahwa token Sanctum pengguna memiliki scope yang dipersyaratkan
     * serta header isolasi tenant (X-Tenant-ID) tidak melanggar batas tenant.
     *
     * @throws HttpException
     */
    public function authorize(Request $request, string $requiredScope): void
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            throw new HttpException(401, 'Unauthenticated API request.');
        }

        // 1. Validasi Isolasi Multi-Tenant via Header X-Tenant-ID
        $requestedTenantId = $request->header('X-Tenant-ID');
        if ($requestedTenantId !== null && (int) $requestedTenantId !== (int) $user->tenant_id) {
            throw new HttpException(403, 'Cross-tenant token usage detected.');
        }

        // 2. Validasi Cakupan Scope Granular pada Token Sanctum
        if (! $user->tokenCan($requiredScope)) {
            throw new HttpException(403, "Forbidden: Token lacks required scope [{$requiredScope}].");
        }
    }
}
