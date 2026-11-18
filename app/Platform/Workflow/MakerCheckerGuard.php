<?php

declare(strict_types=1);

namespace App\Platform\Workflow;

use App\Platform\Workflow\Exceptions\SegregationOfDutiesViolationException;

/**
 * Enterprise Maker-Checker Guard for Segregation of Duties (SoD) and Payload Tamper Detection (EXT-04, ADR-110).
 */
class MakerCheckerGuard
{
    /**
     * Enforces Segregation of Duties (SoD) invariant between requester and approver.
     *
     * @param  int  $requesterId  User ID who submitted or created the approval request
     * @param  int  $approverId  User ID attempting to approve the request
     * @param  bool  $allowSelfApprovalForMicroTenant  Explicit exemption for 1-person micro-tenants
     *
     * @throws SegregationOfDutiesViolationException
     */
    public function assertCanApprove(
        int $requesterId,
        int $approverId,
        bool $allowSelfApprovalForMicroTenant = false
    ): void {
        if ($requesterId === $approverId && ! $allowSelfApprovalForMicroTenant) {
            throw SegregationOfDutiesViolationException::forUser($approverId);
        }
    }

    /**
     * Computes a deterministic SHA-256 hash of the approval subject payload snapshot.
     * Recursively sorts array keys so hashing is order-independent and deterministic.
     *
     * @param  array<string|int, mixed>  $payload
     */
    public function computePayloadHash(array $payload): string
    {
        $normalized = $this->normalizeArray($payload);

        return hash('sha256', (string) json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verifies that the current payload matches the expected snapshot hash.
     *
     * @param  string  $expectedHash  Pre-computed snapshot SHA-256 hash
     * @param  array<string|int, mixed>  $currentPayload  Current payload to verify
     */
    public function verifyPayloadIntegrity(string $expectedHash, array $currentPayload): bool
    {
        $currentHash = $this->computePayloadHash($currentPayload);

        return hash_equals($expectedHash, $currentHash);
    }

    /**
     * Recursively sorts array keys for deterministic serialization.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private function normalizeArray(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalizeArray($value);
            }
        }

        return $data;
    }
}
