<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Services;

use App\Models\Approval;
use App\Models\User;
use App\Modules\Users\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DiscountApprovalService
{
    public const SAFE_DISCOUNT_PERCENTAGE = 5.0;

    public const SAFE_DISCOUNT_AMOUNT = 10000.0;

    public function __construct(
        protected CashierPinService $cashierPinService,
        protected TenantContext $tenantContext,
    ) {}

    /**
     * Mengevaluasi apakah diskon manual memerlukan otorisasi Supervisor.
     * Mengembalikan false jika dalam batas aman PRD OD-13 (<= 5% DAN <= Rp10.000).
     * Mengembalikan true jika melebihi batas aman (wajib approval).
     */
    public function evaluateThreshold(float $discountPercent, float $discountAmount, float $orderSubtotal = 0.0): bool
    {
        if ($discountPercent <= self::SAFE_DISCOUNT_PERCENTAGE && $discountAmount <= self::SAFE_DISCOUNT_AMOUNT) {
            return false;
        }

        return true;
    }

    /**
     * Membuat baris persetujuan baru di tabel `approvals` setelah memvalidasi PIN Supervisor
     * dan menegakkan pencegahan persetujuan mandiri (anti self-approval).
     *
     * @throws ValidationException
     */
    public function createApproval(
        int $requesterId,
        string $pin,
        string $action,
        float $amount,
        string $reason,
        int $storeId,
        ?int $approverId = null
    ): Approval {
        $tenantId = $this->tenantContext->requireTenantId();

        $requester = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $requesterId)
            ->firstOrFail();

        $approver = null;

        if ($approverId !== null) {
            if ($requesterId === $approverId) {
                throw ValidationException::withMessages([
                    'pin' => 'Self-approval dilarang. Pengguna tidak boleh menyetujui diskon pada transaksinya sendiri.',
                ]);
            }

            $approver = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('id', $approverId)
                ->first();

            if (! $approver || ! $this->cashierPinService->hasPin($approver) || ! $this->cashierPinService->verifyPin($approver, $pin)) {
                throw ValidationException::withMessages([
                    'pin' => 'PIN Supervisor tidak sah atau otorisasi gagal.',
                ]);
            }
        } else {
            // Approver ID tidak ditentukan: Cari pengguna supervisor/manager/owner di tenant ini yang PIN-nya cocok
            $supervisors = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereHas('role', function ($query) {
                    $query->whereIn('name', ['Owner', 'Manager', 'Supervisor', 'Admin']);
                })
                ->whereNotNull('pin_hash')
                ->get();

            // Cek terlebih dahulu apakah requester mencoba self-approval dengan PIN-nya sendiri
            if ($requester->pin_hash && Hash::check($pin, (string) $requester->pin_hash)) {
                throw ValidationException::withMessages([
                    'pin' => 'Self-approval dilarang. Pengguna tidak boleh menyetujui diskon pada transaksinya sendiri.',
                ]);
            }

            foreach ($supervisors as $candidate) {
                if ($candidate->id === $requesterId) {
                    continue; // Skip self
                }

                if ($this->cashierPinService->verifyPin($candidate, $pin)) {
                    $approver = $candidate;
                    break;
                }
            }

            if (! $approver) {
                throw ValidationException::withMessages([
                    'pin' => 'PIN Supervisor tidak valid atau tidak memiliki wewenang persetujuan.',
                ]);
            }
        }

        return Approval::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'requester_user_id' => $requesterId,
            'approver_user_id' => $approver->id,
            'action_code' => $action,
            'amount' => $amount,
            'reason' => $reason,
            'status' => Approval::STATUS_APPROVED,
        ]);
    }

    /**
     * Mengonsumsi token persetujuan saat transaksi selesai (atomic consumption).
     *
     * @throws DomainException
     */
    public function consumeApproval(int $approvalId, int $orderId): void
    {
        DB::transaction(function () use ($approvalId, $orderId) {
            /** @var Approval|null $approval */
            $approval = Approval::lockForUpdate()->find($approvalId);

            if (! $approval) {
                throw new DomainException("Persetujuan dengan ID #{$approvalId} tidak ditemukan.");
            }

            if ($approval->status !== Approval::STATUS_APPROVED) {
                throw new DomainException("Token approval #{$approvalId} sudah pernah digunakan atau tidak berstatus APPROVED.");
            }

            $approval->update([
                'status' => Approval::STATUS_CONSUMED,
                'consumed_at' => now(),
                'consumed_by_order_id' => $orderId,
            ]);
        });
    }
}
