<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProvisioningRequest;
use App\Services\ProvisioningService;
use Illuminate\Http\JsonResponse;

/**
 * Domain 1 — endpoint provisioning S2S dari website marketing.
 *
 * Autentikasi: VerifyProvisioningSignature middleware (HMAC + nonce).
 * Tidak memakai session auth; tidak ada konteks user; tidak ada input
 * tenant_id dari pemanggil (allowlist di StoreProvisioningRequest).
 */
class ProvisioningController extends Controller
{
    public function __construct(
        private ProvisioningService $provisioningService,
    ) {}

    public function store(StoreProvisioningRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency key WAJIB dari header (bukan body — mencegah pemanggil
        // menggabungkan key dengan payload internal apapun).
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if (! is_string($idempotencyKey) || ! preg_match('/^[A-Za-z0-9\-_.]{16,64}$/', $idempotencyKey)) {
            return response()->json([
                'success' => false,
                'error' => 'invalid_idempotency_key',
                'message' => 'Header X-Idempotency-Key wajib (16-64 karakter alfanumerik).',
            ], 422);
        }

        // Fingerprint = hash(method | path | raw body) — disimpan di ledger;
        // payload berbeda dengan key sama → 409 (D3).
        $fingerprint = hash('sha256', implode('|', [
            strtoupper($request->getMethod()),
            '/'.$request->path(),
            $request->getContent(),
        ]));

        $result = $this->provisioningService->provision($validated, $idempotencyKey, $fingerprint);

        return response()->json($result['body'], $result['status']);
    }
}
