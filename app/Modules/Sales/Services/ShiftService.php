<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\CashDrawerMovement;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ShiftService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected CashierPinService $cashierPinService
    ) {}

    /**
     * [D2-BE-008] Membuka shift baru secara atomik dengan penguncian baris (INV-01, INV-03, INV-10).
     * Mendukung signature penuh:
     *   openShift(int $tenantId, int $storeId, int $deviceId, int $cashierUserId, int|string $openingCash, string $cashierPin): Shift
     * dan signature legacy:
     *   openShift(int $userId, int $openingBalance): ?Shift
     */
    public function openShift(
        int $tenantIdOrUserId,
        int $storeIdOrOpeningBalance,
        ?int $deviceId = null,
        ?int $cashierUserId = null,
        int|string $openingCash = 0,
        ?string $cashierPin = null
    ): ?Shift {
        $isFullSignature = ($cashierUserId !== null);

        if ($isFullSignature) {
            $tenantId = $tenantIdOrUserId;
            $storeId = $storeIdOrOpeningBalance;
            $openingBalance = (int) $openingCash;
        } else {
            $cashierUserId = $tenantIdOrUserId;
            $openingBalance = (int) $storeIdOrOpeningBalance;
            $tenantContext = app(TenantContext::class);
            $tenantId = $tenantContext->getTenantId();
            $storeId = $tenantContext->getStoreId();
        }

        $user = User::withoutGlobalScopes()->find($cashierUserId);
        if (! $user) {
            throw new DomainException('Pengguna kasir tidak ditemukan.');
        }

        if ($tenantId === null) {
            $tenantId = (int) $user->tenant_id;
        }

        // Validasi PIN jika disertakan atau wajib
        if ($cashierPin !== null) {
            if (! $this->cashierPinService->verifyPin($user, $cashierPin)) {
                throw ValidationException::withMessages(['pin' => 'PIN Kasir salah.']);
            }
        } elseif (! $this->cashierPinService->hasPin($user)) {
            throw new DomainException('Kasir wajib menyetel PIN Kasir sebelum dapat membuka shift.');
        }

        // Resolusi dan validasi store_id
        if ($storeId === null) {
            $userStore = DB::table('user_stores')->where('user_id', $user->id)->value('store_id');
            $storeId = $userStore ? (int) $userStore : (int) ($user->store_id ?? 1);
        }

        // Validasi Device jika diberikan (INV-01, INV-03, AC-1.2)
        if ($deviceId !== null) {
            $device = Device::withoutGlobalScopes()->where('id', $deviceId)->first();
            if (! $device || (int) $device->tenant_id !== (int) $tenantId || (int) $device->store_id !== (int) $storeId) {
                throw new DomainException('Perangkat tidak terdaftar pada cabang atau tenant yang sesuai.');
            }

            if (! $device->is_active || $device->status === Device::STATUS_REVOKED) {
                throw new DomainException('Perangkat kasir tidak aktif atau telah dicabut.');
            }
        }

        return DB::transaction(function () use ($tenantId, $storeId, $deviceId, $cashierUserId, $openingBalance, $isFullSignature) {
            // Cek shift aktif pada device (INV-01, AC-1.1)
            if ($deviceId !== null) {
                $deviceShift = Shift::where('tenant_id', $tenantId)
                    ->where('store_id', $storeId)
                    ->where('device_id', $deviceId)
                    ->whereIn('status', ['open', 'handover_pending', 'closing'])
                    ->lockForUpdate()
                    ->first();

                if ($deviceShift) {
                    throw new DomainException('Perangkat kasir ini masih memiliki shift yang sedang aktif.');
                }
            }

            // Cek shift aktif pada kasir
            $userShift = Shift::where('tenant_id', $tenantId)
                ->where(function ($q) use ($cashierUserId) {
                    $q->where('active_cashier_id', $cashierUserId)
                        ->orWhere(function ($sub) use ($cashierUserId) {
                            $sub->whereNull('active_cashier_id')->where('user_id', $cashierUserId);
                        });
                })
                ->whereIn('status', ['open', 'handover_pending', 'closing'])
                ->lockForUpdate()
                ->first();

            if ($userShift) {
                if ($isFullSignature) {
                    throw new DomainException('Kasir sudah memiliki shift yang sedang aktif.');
                }

                return null;
            }

            try {
                $shift = Shift::create([
                    'tenant_id' => $tenantId,
                    'store_id' => $storeId,
                    'device_id' => $deviceId,
                    'user_id' => $cashierUserId,
                    'active_cashier_id' => $cashierUserId,
                    'opening_balance' => $openingBalance,
                    'status' => 'open',
                    'opened_at' => now(),
                ]);

                $this->auditTrail->log(
                    tenantId: $tenantId,
                    action: 'shift.opened',
                    userId: $cashierUserId,
                    storeId: $storeId,
                    subjectType: Shift::class,
                    subjectId: $shift->id,
                    description: "Shift #{$shift->id} dibuka oleh kasir #{$cashierUserId} dengan saldo awal Rp ".number_format($openingBalance, 0, ',', '.'),
                    metadata: [
                        'device_id' => $deviceId,
                        'opening_cash' => $openingBalance,
                    ]
                );

                return $shift;
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'shifts_one_open_per_device_unique')) {
                    throw new DomainException('Perangkat kasir ini masih memiliki shift yang sedang aktif.');
                }
                if (str_contains($e->getMessage(), 'shifts_one_open_per_user_unique')) {
                    if ($isFullSignature) {
                        throw new DomainException('Kasir sudah memiliki shift yang sedang aktif.');
                    }

                    return null;
                }
                throw $e;
            }
        });
    }

    /**
     * Menginisiasi serah terima shift (handover) dari kasir lama ke kasir baru (AC-1.3).
     */
    public function initiateHandover(
        int $shiftId,
        int $fromUserId,
        int $toUserId,
        int|string $countedCash,
        string $fromPin,
        ?string $notes = null
    ): ShiftHandover {
        return DB::transaction(function () use ($shiftId, $fromUserId, $toUserId, $countedCash, $fromPin, $notes) {
            $shift = Shift::where('id', $shiftId)->lockForUpdate()->first();
            if (! $shift) {
                throw new DomainException('Shift tidak ditemukan.');
            }
            if ($shift->status !== 'open') {
                throw new DomainException('Shift tidak dalam status terbuka untuk serah terima.');
            }

            $currentCashierId = $shift->active_cashier_id ?? $shift->user_id;
            if ((int) $currentCashierId !== (int) $fromUserId) {
                throw new DomainException('Hanya kasir aktif yang dapat menyerahkan shift.');
            }

            if ($fromUserId === $toUserId) {
                throw new DomainException('Kasir penerima tidak boleh sama dengan kasir penyerah.');
            }

            $fromUser = User::withoutGlobalScopes()->find($fromUserId);
            if (! $fromUser || ! $this->cashierPinService->verifyPin($fromUser, $fromPin, $shift)) {
                throw ValidationException::withMessages(['pin' => 'PIN Kasir penyerah shift tidak sah.']);
            }

            $toUser = User::withoutGlobalScopes()->find($toUserId);
            if (! $toUser || (int) $toUser->tenant_id !== (int) $shift->tenant_id) {
                throw new DomainException('Kasir penerima tidak ditemukan atau berbeda tenant.');
            }

            // Cek apakah kasir penerima sudah memiliki shift aktif
            $toUserOpenShift = Shift::where('tenant_id', $shift->tenant_id)
                ->where(function ($q) use ($toUserId) {
                    $q->where('active_cashier_id', $toUserId)
                        ->orWhere(function ($sub) use ($toUserId) {
                            $sub->whereNull('active_cashier_id')->where('user_id', $toUserId);
                        });
                })
                ->whereIn('status', ['open', 'handover_pending', 'closing'])
                ->exists();

            if ($toUserOpenShift) {
                throw new DomainException('Kasir penerima sedang memiliki shift aktif di terminal lain.');
            }

            $summary = $this->getCashSummary($shift);
            $expectedCash = $summary['expected_cash'];
            $counted = (int) $countedCash;
            $discrepancy = $counted - $expectedCash;

            $shift->update(['status' => 'handover_pending']);

            $handover = ShiftHandover::create([
                'tenant_id' => $shift->tenant_id,
                'store_id' => $shift->store_id,
                'shift_id' => $shift->id,
                'device_id' => $shift->device_id ?? 0,
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'expected_cash_at_handover' => $expectedCash,
                'counted_cash_at_handover' => $counted,
                'discrepancy_amount' => $discrepancy,
                'status' => 'pending',
                'notes' => $notes,
                'initiated_at' => now(),
            ]);

            $this->auditTrail->log(
                tenantId: (int) $shift->tenant_id,
                action: 'shift.handover_initiated',
                userId: $fromUserId,
                storeId: (int) $shift->store_id,
                subjectType: ShiftHandover::class,
                subjectId: $handover->id,
                description: "Handover shift #{$shift->id} diinisiasi dari kasir #{$fromUserId} ke kasir #{$toUserId}.",
                metadata: [
                    'shift_id' => $shift->id,
                    'expected_cash' => $expectedCash,
                    'counted_cash' => $counted,
                    'discrepancy' => $discrepancy,
                ]
            );

            return $handover;
        });
    }

    /**
     * Menerima serah terima shift (accept handover) dan mengalihkan active_cashier_id (AC-1.3).
     */
    public function acceptHandover(
        int $handoverId,
        int $toUserId,
        string $toPin,
        ?string $supervisorPin = null,
        ?int $supervisorUserId = null
    ): Shift {
        return DB::transaction(function () use ($handoverId, $toUserId, $toPin, $supervisorPin, $supervisorUserId) {
            $handover = ShiftHandover::where('id', $handoverId)->lockForUpdate()->first();
            if (! $handover || $handover->status !== 'pending') {
                throw new DomainException('Data serah terima tidak valid atau sudah diproses.');
            }

            if ((int) $handover->to_user_id !== (int) $toUserId) {
                throw new DomainException('Hanya kasir penerima yang berhak menerima serah terima ini.');
            }

            $shift = Shift::where('id', $handover->shift_id)->lockForUpdate()->first();
            if (! $shift || $shift->status !== 'handover_pending') {
                throw new DomainException('Status shift tidak sesuai untuk penerimaan serah terima.');
            }

            $toUser = User::withoutGlobalScopes()->find($toUserId);
            if (! $toUser || ! $this->cashierPinService->verifyPin($toUser, $toPin, $shift)) {
                throw ValidationException::withMessages(['pin' => 'PIN Kasir penerima tidak sah.']);
            }

            // Jika ada selisih kas, memerlukan otorisasi Supervisor (Dual PIN, INV-12)
            $approvedBy = null;
            if ((int) $handover->discrepancy_amount !== 0) {
                if (empty($supervisorPin) || empty($supervisorUserId)) {
                    throw new DomainException('Serah terima kasir memiliki selisih kas sehingga memerlukan persetujuan dan PIN Supervisor.');
                }

                $supervisor = User::withoutGlobalScopes()->find($supervisorUserId);
                if (! $supervisor || (int) $supervisor->tenant_id !== (int) $shift->tenant_id) {
                    throw new DomainException('Supervisor tidak valid untuk tenant ini.');
                }

                if (! $this->canAuthorizeSupervisor($supervisor, (int) $shift->store_id)) {
                    throw new DomainException('Pengguna tidak memiliki wewenang supervisor untuk menyetujui selisih kas.');
                }

                if (! $this->cashierPinService->verifyPin($supervisor, $supervisorPin, $shift)) {
                    throw ValidationException::withMessages(['supervisor_pin' => 'PIN Supervisor tidak sah.']);
                }

                $approvedBy = $supervisorUserId;
            }

            $shift->update([
                'active_cashier_id' => $toUserId,
                'status' => 'open',
            ]);

            $handover->update([
                'status' => 'accepted',
                'completed_at' => now(),
                'approved_by_user_id' => $approvedBy,
            ]);

            $this->auditTrail->log(
                tenantId: (int) $shift->tenant_id,
                action: 'shift.handover_completed',
                userId: $toUserId,
                storeId: (int) $shift->store_id,
                subjectType: ShiftHandover::class,
                subjectId: $handover->id,
                description: "Handover shift #{$shift->id} selesai. Kasir aktif kini adalah user #{$toUserId}.",
                metadata: [
                    'shift_id' => $shift->id,
                    'from_user_id' => $handover->from_user_id,
                    'to_user_id' => $toUserId,
                    'discrepancy' => $handover->discrepancy_amount,
                    'approved_by_user_id' => $approvedBy,
                ]
            );

            return $shift;
        });
    }

    /**
     * Membatalkan serah terima shift dan mengembalikan status shift ke open.
     */
    public function cancelHandover(int $handoverId, int $actorUserId): Shift
    {
        return DB::transaction(function () use ($handoverId, $actorUserId) {
            $handover = ShiftHandover::where('id', $handoverId)->lockForUpdate()->first();
            if (! $handover || $handover->status !== 'pending') {
                throw new DomainException('Data serah terima tidak valid atau sudah diproses.');
            }

            $shift = Shift::where('id', $handover->shift_id)->lockForUpdate()->first();
            if (! $shift) {
                throw new DomainException('Shift tidak ditemukan.');
            }

            $actor = User::withoutGlobalScopes()->find($actorUserId);
            $isSupervisor = $actor && $this->canAuthorizeSupervisor($actor, (int) $shift->store_id);
            if ((int) $handover->from_user_id !== (int) $actorUserId && ! $isSupervisor) {
                throw new DomainException('Hanya kasir penyerah atau supervisor yang dapat membatalkan serah terima.');
            }

            $handover->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);

            $shift->update(['status' => 'open']);

            $this->auditTrail->log(
                tenantId: (int) $shift->tenant_id,
                action: 'shift.handover_cancelled',
                userId: $actorUserId,
                storeId: (int) $shift->store_id,
                subjectType: ShiftHandover::class,
                subjectId: $handover->id,
                description: "Handover shift #{$shift->id} dibatalkan oleh user #{$actorUserId}."
            );

            return $shift;
        });
    }

    /**
     * [D2-BE-008] Mencatat CashDrawerMovement secara atomik di bawah penguncian baris (INV-10, AC-1.4).
     */
    public function recordCashMovement(
        int $arg1,
        int $arg2,
        $arg3,
        $arg4 = null,
        $arg5 = null,
        $arg6 = null,
        $arg7 = null,
        ?string $approverPin = null
    ): ?CashDrawerMovement {
        if (is_string($arg3)) {
            // Legacy signature: recordCashMovement($userId, $shiftId, $type, $amount, $category, $reason, $receiptReference)
            $userId = $arg1;
            $shiftId = $arg2;
            $type = $arg3;
            $amount = (int) $arg4;
            $category = (string) $arg5;
            $reason = (string) $arg6;
            $receiptReference = $arg7;
            $deviceId = null;
        } else {
            // New signature: recordCashMovement($shiftId, $deviceId, $actorUserId, $type, $category, $amount, $reason, $approverPin)
            $shiftId = $arg1;
            $deviceId = $arg2;
            $userId = (int) $arg3;
            $type = (string) $arg4;
            $category = (string) $arg5;
            $amount = (int) $arg6;
            $reason = (string) $arg7;
            $receiptReference = null;
        }

        if (! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new InvalidArgumentException("Tipe pergerakan kas tidak dikenal: {$type}");
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Nominal pergerakan kas harus positif.');
        }

        return DB::transaction(function () use ($userId, $shiftId, $deviceId, $type, $amount, $category, $reason, $receiptReference, $approverPin) {
            $shift = Shift::where('id', $shiftId)
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                return null;
            }

            if ($shift->status !== 'open') {
                throw new DomainException("Pergerakan kas hanya dapat dicatat pada shift berstatus open (status saat ini: {$shift->status}).");
            }

            $activeCashierId = $shift->active_cashier_id ?? $shift->user_id;
            $user = User::withoutGlobalScopes()->find($userId);
            $isSupervisor = $user && $this->canAuthorizeSupervisor($user, (int) $shift->store_id);

            if ((int) $activeCashierId !== (int) $userId && ! $isSupervisor) {
                return null;
            }

            if ($type === 'cash_out' && $approverPin !== null) {
                if (! $this->cashierPinService->verifyPin($user, $approverPin, $shift)) {
                    throw ValidationException::withMessages(['pin' => 'PIN otorisasi pengeluaran kas tidak sah.']);
                }
            }

            // Cek saldo kas tidak boleh negatif (INV-10)
            $summary = $this->getCashSummary($shift);
            if ($type === 'cash_out' && $summary['expected_cash'] < $amount) {
                throw new DomainException('Saldo kas laci tidak mencukupi untuk pengeluaran kas (saldo tidak boleh negatif).');
            }

            $resolvedDeviceId = $shift->device_id ?? $deviceId;

            $movement = CashDrawerMovement::create([
                'tenant_id' => $shift->tenant_id,
                'store_id' => $shift->store_id,
                'shift_id' => $shift->id,
                'device_id' => $resolvedDeviceId,
                'type' => $type,
                'category' => $category,
                'amount' => $amount,
                'reason' => $reason,
                'receipt_reference' => $receiptReference,
                'created_by' => $userId,
            ]);

            $this->auditTrail->log(
                tenantId: (int) $shift->tenant_id,
                action: 'cash_drawer.movement_created',
                userId: $userId,
                storeId: (int) $shift->store_id,
                subjectType: CashDrawerMovement::class,
                subjectId: $movement->id,
                description: "Pergerakan kas {$type} senilai Rp ".number_format($amount, 0, ',', '.')." dicatat pada shift #{$shift->id}.",
                metadata: [
                    'shift_id' => $shift->id,
                    'type' => $type,
                    'amount' => $amount,
                    'category' => $category,
                ]
            );

            return $movement;
        });
    }

    /**
     * Hitung ringkasan kas (penjualan tunai, cash-in, cash-out, dan kas yang diharapkan).
     *
     * @return array{cash_sales: int, cash_in: int, cash_out: int, expected_cash: int}
     */
    public function getCashSummary(Shift $shift): array
    {
        $cashSales = (int) Payment::leftJoin('orders', 'orders.id', '=', 'payments.order_id')
            ->where(function ($q) use ($shift) {
                $q->where('payments.shift_id', $shift->id)
                    ->orWhere('orders.shift_id', $shift->id);
            })
            ->where('payments.payment_method', 'cash')
            ->whereIn('payments.status', ['captured', 'voided'])
            ->sum(DB::raw("CASE WHEN payments.status = 'captured' THEN payments.amount ELSE -payments.amount END"));

        $cashIn = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_in')
            ->sum('amount');

        $cashOut = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_out')
            ->sum('amount');

        $expectedCash = (int) $shift->opening_balance + $cashSales + $cashIn - $cashOut;

        return [
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => $expectedCash,
        ];
    }

    /**
     * Menutup shift aktif secara atomik dan mengunci baris shift untuk update (AC-1.4).
     */
    public function closeShift(
        int $shiftIdOrUserId,
        $arg2 = null,
        $arg3 = null,
        $arg4 = null,
        $arg5 = null,
        bool $isForceClose = false,
        ?string $forceCloseReason = null
    ): ?Shift {
        // Deteksi legacy signature: closeShift(int $userId, int $closingBalance, ?string $notes = null)
        if (func_num_args() <= 3) {
            $userId = $shiftIdOrUserId;
            $closingBalance = (int) $arg2;
            $notes = $arg3;

            return DB::transaction(function () use ($userId, $closingBalance, $notes) {
                $shift = Shift::where(function ($q) use ($userId) {
                    $q->where('active_cashier_id', $userId)
                        ->orWhere(function ($sub) use ($userId) {
                            $sub->whereNull('active_cashier_id')->where('user_id', $userId);
                        });
                })
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();

                if (! $shift) {
                    return null;
                }

                $summary = $this->getCashSummary($shift);

                $shift->update([
                    'closing_balance' => $closingBalance,
                    'expected_cash' => $summary['expected_cash'],
                    'cash_difference' => $closingBalance - $summary['expected_cash'],
                    'notes' => $notes,
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by_user_id' => $userId,
                    'close_type' => 'normal',
                ]);

                return $shift;
            });
        }

        // New signature: closeShift(int $shiftId, int $deviceId, int $actorUserId, int|string $countedCash, string $pin, bool $isForceClose = false, ?string $forceCloseReason = null)
        $shiftId = $shiftIdOrUserId;
        $deviceId = (int) $arg2;
        $actorUserId = (int) $arg3;
        $countedCash = (int) $arg4;
        $pin = (string) $arg5;

        return DB::transaction(function () use ($shiftId, $actorUserId, $countedCash, $pin, $isForceClose, $forceCloseReason) {
            $shift = Shift::where('id', $shiftId)->lockForUpdate()->first();
            if (! $shift) {
                throw new DomainException('Shift tidak ditemukan.');
            }

            if ($shift->status === 'closed') {
                throw new DomainException('Shift sudah ditutup sebelumnya.');
            }

            $actor = User::withoutGlobalScopes()->find($actorUserId);
            if (! $actor) {
                throw new DomainException('Pengguna tidak ditemukan.');
            }

            if ($isForceClose) {
                if (! $this->canAuthorizeSupervisor($actor, (int) $shift->store_id)) {
                    throw new DomainException('Hanya Supervisor atau Store Manager yang berhak melakukan force close shift.');
                }
                if (empty($forceCloseReason)) {
                    throw new DomainException('Alasan force close wajib diisi.');
                }
                if (! $this->cashierPinService->verifyPin($actor, $pin, $shift)) {
                    throw ValidationException::withMessages(['pin' => 'PIN Supervisor untuk force close shift tidak sah.']);
                }
                $closeType = 'force_closed';
                $closeReason = $forceCloseReason;
            } else {
                $activeCashierId = $shift->active_cashier_id ?? $shift->user_id;
                if ((int) $activeCashierId !== (int) $actorUserId) {
                    throw new DomainException('Shift hanya dapat ditutup secara normal oleh kasir aktif yang bertugas.');
                }
                if (! $this->cashierPinService->verifyPin($actor, $pin, $shift)) {
                    throw ValidationException::withMessages(['pin' => 'PIN Kasir tidak sah.']);
                }
                $closeType = 'normal';
                $closeReason = null;
            }

            // Periksa apakah masih ada pembayaran in-flight
            $hasInFlight = Payment::where('shift_id', $shift->id)
                ->whereIn('status', ['initiated', 'processing'])
                ->exists();
            if ($hasInFlight) {
                throw new DomainException('Tidak dapat menutup shift karena masih ada pembayaran yang sedang diproses.');
            }

            $summary = $this->getCashSummary($shift);
            $expectedCash = $summary['expected_cash'];
            $cashDifference = $countedCash - $expectedCash;

            $shift->update([
                'closing_balance' => $countedCash,
                'expected_cash' => $expectedCash,
                'cash_difference' => $cashDifference,
                'notes' => $closeReason,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $actorUserId,
                'close_type' => $closeType,
                'close_reason' => $closeReason,
            ]);

            $this->auditTrail->log(
                tenantId: (int) $shift->tenant_id,
                action: $isForceClose ? 'shift.force_closed' : 'shift.closed',
                userId: $actorUserId,
                storeId: (int) $shift->store_id,
                subjectType: Shift::class,
                subjectId: $shift->id,
                description: "Shift #{$shift->id} ditutup ({$closeType}) oleh user #{$actorUserId}. Selisih kas: Rp ".number_format($cashDifference, 0, ',', '.'),
                metadata: [
                    'expected_cash' => $expectedCash,
                    'closing_balance' => $countedCash,
                    'discrepancy' => $cashDifference,
                    'close_type' => $closeType,
                ]
            );

            return $shift;
        });
    }

    /**
     * Melakukan penutupan paksa shift oleh Supervisor/Manager (INV-01, INV-03).
     */
    public function forceCloseShift(
        int $shiftId,
        int $supervisorUserId,
        int|string $countedCash,
        string $supervisorPin,
        string $reason
    ): Shift {
        return $this->closeShift(
            $shiftId,
            0,
            $supervisorUserId,
            $countedCash,
            $supervisorPin,
            true,
            $reason
        );
    }

    /**
     * Memeriksa otorisasi supervisor/manajer/owner untuk cabang terkait.
     */
    public function canAuthorizeSupervisor(User $user, int $storeId): bool
    {
        $roleName = $user->role?->name;
        if (! in_array($roleName, ['Supervisor', 'Manager', 'Owner', 'Admin'], true) && ! (bool) $user->role?->is_admin) {
            return false;
        }

        if (in_array($roleName, ['Owner', 'Admin'], true) || (bool) $user->role?->is_admin) {
            return true;
        }

        $hasAssignments = DB::table('user_stores')->where('user_id', $user->id)->exists();
        if (! $hasAssignments) {
            return true;
        }

        return DB::table('user_stores')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->exists();
    }
}
