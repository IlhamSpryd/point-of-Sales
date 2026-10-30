<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Approval;
use App\Modules\HR\Enums\LeaveRequestStatusEnum;
use App\Modules\HR\Enums\LeaveTransactionTypeEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeavePolicy;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveTransaction;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /**
     * Tambah saldo hak cuti berkala/tahunan (ACCRUAL).
     */
    public function accrueLeave(
        int $tenantId,
        int $employeeId,
        int $policyId,
        int $year,
        float $days,
        int $actorUserId,
        string $reason = 'Hak Cuti Tahunan'
    ): LeaveBalance {
        if ($days <= 0) {
            throw ValidationException::withMessages([
                'days' => 'Jumlah hari cuti yang di-akrual harus lebih besar dari 0.',
            ]);
        }

        return DB::transaction(function () use ($tenantId, $employeeId, $policyId, $year, $days, $actorUserId, $reason) {
            /** @var LeaveBalance|null $balance */
            $balance = LeaveBalance::where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('leave_policy_id', $policyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                $balance = LeaveBalance::create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $employeeId,
                    'leave_policy_id' => $policyId,
                    'year' => $year,
                    'allocated_days' => 0.00,
                    'used_days' => 0.00,
                    'remaining_days' => 0.00,
                    'expired_days' => 0.00,
                ]);
            }

            LeaveTransaction::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_policy_id' => $policyId,
                'transaction_type' => LeaveTransactionTypeEnum::ACCRUAL,
                'days_delta' => $days,
                'leave_request_id' => null,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);

            $balance->allocated_days += $days;
            $balance->remaining_days += $days;
            $balance->save();

            return $balance;
        });
    }

    /**
     * Ajukan permohonan izin cuti dengan pengecekan saldo dan kunci baris pesimistik (Row Lock).
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function submitLeaveRequest(
        int $tenantId,
        int $employeeId,
        int $policyId,
        CarbonInterface $startDate,
        CarbonInterface $endDate,
        float $totalDays,
        string $reason,
        int $actorUserId,
        ?string $attachmentPath = null
    ): LeaveRequest {
        if ($endDate->lt($startDate)) {
            throw ValidationException::withMessages([
                'end_date' => 'Tanggal akhir cuti tidak boleh sebelum tanggal mulai.',
            ]);
        }

        if ($totalDays <= 0) {
            throw ValidationException::withMessages([
                'total_days' => 'Jumlah hari cuti harus lebih besar dari 0.',
            ]);
        }

        /** @var LeavePolicy $policy */
        $policy = LeavePolicy::where('id', $policyId)
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (! $policy->is_active) {
            throw new DomainException("Kebijakan cuti [{$policy->policy_name}] sedang tidak aktif.");
        }

        if ($policy->requires_attachment && empty($attachmentPath)) {
            throw ValidationException::withMessages([
                'attachment_path' => "Dokumen pendukung (surat dokter/keterangan) wajib diunggah untuk cuti [{$policy->policy_name}].",
            ]);
        }

        /** @var Employee $employee */
        $employee = Employee::where('id', $employeeId)->where('tenant_id', $tenantId)->firstOrFail();

        if ($policy->is_gender_specific && $policy->applicable_gender !== null) {
            if ($employee->gender->value !== $policy->applicable_gender) {
                throw ValidationException::withMessages([
                    'leave_policy_id' => "Kebijakan cuti [{$policy->policy_name}] hanya berlaku untuk karyawan bergender {$policy->applicable_gender}.",
                ]);
            }
        }

        return DB::transaction(function () use (
            $tenantId,
            $employeeId,
            $policyId,
            $policy,
            $startDate,
            $endDate,
            $totalDays,
            $reason,
            $attachmentPath
        ) {
            $year = (int) $startDate->year;

            if ($policy->is_paid) {
                /** @var LeaveBalance|null $balance */
                $balance = LeaveBalance::where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where('leave_policy_id', $policyId)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                $available = $balance !== null ? $balance->remaining_days : 0.00;

                if ($available < $totalDays) {
                    throw ValidationException::withMessages([
                        'total_days' => "Saldo cuti tidak mencukupi. Sisa saldo tersedia: {$available} hari, pengajuan: {$totalDays} hari.",
                    ]);
                }
            }

            return LeaveRequest::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_policy_id' => $policyId,
                'approval_id' => null,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'total_days' => $totalDays,
                'reason' => $reason,
                'attachment_path' => $attachmentPath,
                'status' => LeaveRequestStatusEnum::PENDING,
            ]);
        });
    }

    /**
     * Setujui pengajuan cuti berdasar dokumen SPKL/Approval (USAGE).
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function approveLeaveRequest(
        int $tenantId,
        int $requestId,
        int $approvalId,
        int $approverUserId
    ): LeaveRequest {
        /** @var Approval|null $approval */
        $approval = Approval::where('id', $approvalId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            throw new DomainException("Dokumen Approval [ID: {$approvalId}] tidak ditemukan.");
        }

        if ($approval->action_code !== 'LEAVE_REQUEST') {
            throw ValidationException::withMessages([
                'approval_id' => "Aksi persetujuan harus bertipe 'LEAVE_REQUEST', ditemukan: {$approval->action_code}.",
            ]);
        }

        if ($approval->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'approval_id' => "Persetujuan cuti belum berstatus APPROVED (status saat ini: {$approval->status}).",
            ]);
        }

        return DB::transaction(function () use ($tenantId, $requestId, $approvalId, $approverUserId) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::where('id', $requestId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== LeaveRequestStatusEnum::PENDING) {
                throw new DomainException("Pengajuan cuti tidak dalam status PENDING (status: {$request->status->value}).");
            }

            /** @var LeavePolicy $policy */
            $policy = LeavePolicy::where('id', $request->leave_policy_id)
                ->where('tenant_id', $tenantId)
                ->firstOrFail();

            $year = (int) $request->start_date->year;

            if ($policy->is_paid) {
                /** @var LeaveBalance|null $balance */
                $balance = LeaveBalance::where('tenant_id', $tenantId)
                    ->where('employee_id', $request->employee_id)
                    ->where('leave_policy_id', $request->leave_policy_id)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();

                if (! $balance || $balance->remaining_days < $request->total_days) {
                    $available = $balance !== null ? $balance->remaining_days : 0.00;
                    throw ValidationException::withMessages([
                        'leave_balance' => "Saldo cuti tidak mencukupi saat persetujuan. Sisa: {$available} hari, pengajuan: {$request->total_days} hari.",
                    ]);
                }

                $balance->used_days += $request->total_days;
                $balance->remaining_days -= $request->total_days;
                $balance->save();

                LeaveTransaction::create([
                    'tenant_id' => $tenantId,
                    'employee_id' => $request->employee_id,
                    'leave_policy_id' => $request->leave_policy_id,
                    'transaction_type' => LeaveTransactionTypeEnum::USAGE,
                    'days_delta' => -$request->total_days,
                    'leave_request_id' => $request->id,
                    'reason' => "Pemakaian cuti disetujui (Approval #{$approvalId})",
                    'actor_user_id' => $approverUserId,
                ]);
            }

            $request->approval_id = $approvalId;
            $request->status = LeaveRequestStatusEnum::APPROVED;
            $request->save();

            return $request;
        });
    }

    /**
     * Batalkan pengajuan cuti yang disetujui (REVERSAL).
     */
    public function cancelLeaveRequest(
        int $tenantId,
        int $requestId,
        int $actorUserId,
        string $reason
    ): LeaveRequest {
        return DB::transaction(function () use ($tenantId, $requestId, $actorUserId, $reason) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::where('id', $requestId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === LeaveRequestStatusEnum::CANCELLED) {
                throw new DomainException('Pengajuan cuti sudah dibatalkan sebelumnya.');
            }

            if ($request->status === LeaveRequestStatusEnum::APPROVED) {
                /** @var LeavePolicy $policy */
                $policy = LeavePolicy::where('id', $request->leave_policy_id)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                if ($policy->is_paid) {
                    $year = (int) $request->start_date->year;

                    /** @var LeaveBalance $balance */
                    $balance = LeaveBalance::where('tenant_id', $tenantId)
                        ->where('employee_id', $request->employee_id)
                        ->where('leave_policy_id', $request->leave_policy_id)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $balance->used_days -= $request->total_days;
                    $balance->remaining_days += $request->total_days;
                    $balance->save();

                    LeaveTransaction::create([
                        'tenant_id' => $tenantId,
                        'employee_id' => $request->employee_id,
                        'leave_policy_id' => $request->leave_policy_id,
                        'transaction_type' => LeaveTransactionTypeEnum::REVERSAL,
                        'days_delta' => $request->total_days,
                        'leave_request_id' => $request->id,
                        'reason' => "Pembatalan cuti: {$reason}",
                        'actor_user_id' => $actorUserId,
                    ]);
                }
            }

            $request->status = LeaveRequestStatusEnum::CANCELLED;
            $request->save();

            return $request;
        });
    }

    /**
     * Sesuaikan saldo cuti secara manual (ADJUSTMENT).
     */
    public function adjustBalance(
        int $tenantId,
        int $employeeId,
        int $policyId,
        int $year,
        float $daysDelta,
        string $reason,
        int $actorUserId
    ): LeaveBalance {
        return DB::transaction(function () use ($tenantId, $employeeId, $policyId, $year, $daysDelta, $reason, $actorUserId) {
            /** @var LeaveBalance $balance */
            $balance = LeaveBalance::where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)
                ->where('leave_policy_id', $policyId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();

            $balance->remaining_days += $daysDelta;
            if ($daysDelta > 0) {
                $balance->allocated_days += $daysDelta;
            } else {
                $balance->used_days += abs($daysDelta);
            }
            $balance->save();

            LeaveTransaction::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'leave_policy_id' => $policyId,
                'transaction_type' => LeaveTransactionTypeEnum::ADJUSTMENT,
                'days_delta' => $daysDelta,
                'leave_request_id' => null,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);

            return $balance;
        });
    }

    /**
     * Hitung ulang saldo aktual langsung dari transaksi buku besar (Current Balance = SUM(days_delta)).
     */
    public function calculateLedgerBalance(int $tenantId, int $employeeId, int $policyId): float
    {
        return (float) LeaveTransaction::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('leave_policy_id', $policyId)
            ->sum('days_delta');
    }

    /**
     * Tolak pengajuan cuti (REJECTED).
     */
    public function rejectLeaveRequest(
        int $tenantId,
        int $requestId,
        int $approverUserId,
        string $rejectionReason = 'Ditolak'
    ): LeaveRequest {
        return DB::transaction(function () use ($tenantId, $requestId, $rejectionReason) {
            /** @var LeaveRequest $request */
            $request = LeaveRequest::where('id', $requestId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== LeaveRequestStatusEnum::PENDING) {
                throw new DomainException("Hanya pengajuan cuti berstatus PENDING yang dapat ditolak (status saat ini: {$request->status->value}).");
            }

            $request->status = LeaveRequestStatusEnum::REJECTED;
            $request->reason = $request->reason ? $request->reason.' | Penolakan: '.$rejectionReason : $rejectionReason;
            $request->save();

            return $request;
        });
    }
}
