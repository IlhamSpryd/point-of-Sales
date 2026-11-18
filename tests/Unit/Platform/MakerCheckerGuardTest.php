<?php

declare(strict_types=1);

namespace Tests\Unit\Platform;

use App\Platform\Workflow\Exceptions\SegregationOfDutiesViolationException;
use App\Platform\Workflow\MakerCheckerGuard;
use PHPUnit\Framework\TestCase;

class MakerCheckerGuardTest extends TestCase
{
    private MakerCheckerGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new MakerCheckerGuard;
    }

    public function test_can_approve_when_requester_and_approver_are_different(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->assertCanApprove(requesterId: 10, approverId: 20);
    }

    public function test_throws_sod_violation_when_requester_and_approver_are_identical(): void
    {
        $this->expectException(SegregationOfDutiesViolationException::class);
        $this->expectExceptionMessage('Pelanggaran Pemisahan Tugas (SoD)');

        $this->guard->assertCanApprove(requesterId: 10, approverId: 10);
    }

    public function test_allows_self_approval_when_micro_tenant_flag_is_active(): void
    {
        $this->expectNotToPerformAssertions();
        $this->guard->assertCanApprove(requesterId: 10, approverId: 10, allowSelfApprovalForMicroTenant: true);
    }

    public function test_compute_payload_hash_is_deterministic_regardless_of_key_ordering(): void
    {
        $payloadA = [
            'order_id' => 100,
            'amount' => '50000.00',
            'customer' => [
                'name' => 'Budi',
                'email' => 'budi@example.com',
            ],
        ];

        $payloadB = [
            'amount' => '50000.00',
            'customer' => [
                'email' => 'budi@example.com',
                'name' => 'Budi',
            ],
            'order_id' => 100,
        ];

        $hashA = $this->guard->computePayloadHash($payloadA);
        $hashB = $this->guard->computePayloadHash($payloadB);

        $this->assertSame($hashA, $hashB, 'Deterministic hashing must yield identical hashes despite key permutation.');
        $this->assertSame(64, strlen($hashA)); // SHA-256 length
    }

    public function test_verify_payload_integrity_detects_tampering(): void
    {
        $originalPayload = [
            'document_id' => 456,
            'amount' => '100000.00',
            'status' => 'PENDING',
        ];

        $expectedHash = $this->guard->computePayloadHash($originalPayload);

        // Verification passes for untampered payload
        $this->assertTrue($this->guard->verifyPayloadIntegrity($expectedHash, $originalPayload));

        // Verification fails if amount is modified
        $tamperedPayload = $originalPayload;
        $tamperedPayload['amount'] = '999999.00';

        $this->assertFalse($this->guard->verifyPayloadIntegrity($expectedHash, $tamperedPayload));

        // Verification fails if nested structure is modified
        $tamperedPayload2 = $originalPayload;
        $tamperedPayload2['status'] = 'APPROVED';

        $this->assertFalse($this->guard->verifyPayloadIntegrity($expectedHash, $tamperedPayload2));
    }
}
