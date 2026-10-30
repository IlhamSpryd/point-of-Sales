<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Store;
use App\Modules\HR\Enums\AssignmentTypeEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBranchAssignment;
use App\Modules\HR\Models\EmployeeEmploymentHistory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmployeeLifecycleService
{
    /**
     * Menugaskan karyawan ke suatu cabang toko (PRIMARY, SECONDARY, atau TEMPORARY).
     *
     * Invarian:
     * - Tepat satu penugasan PRIMARY aktif pada suatu rentang waktu.
     * - Jika tipe penugasan baru adalah PRIMARY, penugasan PRIMARY lama yang aktif
     *   otomatis ditutup valid_to-nya menjadi (valid_from - 1 hari).
     */
    public function assignBranch(
        Employee $employee,
        int $storeId,
        AssignmentTypeEnum $type,
        CarbonInterface|string $validFrom,
        CarbonInterface|string|null $validTo = null,
        ?string $notes = null
    ): EmployeeBranchAssignment {
        $fromStr = is_string($validFrom) ? $validFrom : $validFrom->toDateString();
        $toStr = $validTo !== null ? (is_string($validTo) ? $validTo : $validTo->toDateString()) : null;

        if ($toStr !== null && $toStr < $fromStr) {
            throw new InvalidArgumentException("Tanggal valid_to ($toStr) tidak boleh lebih awal dari valid_from ($fromStr).");
        }

        // Verifikasi cabang toko milik tenant yang sama
        $store = Store::where('tenant_id', $employee->tenant_id)->findOrFail($storeId);

        return DB::transaction(function () use ($employee, $store, $type, $fromStr, $toStr, $notes) {
            if ($type === AssignmentTypeEnum::PRIMARY) {
                $fromDate = Carbon::parse($fromStr);

                // Cari penugasan primer aktif sebelumnya
                $activePrimaries = EmployeeBranchAssignment::where('tenant_id', $employee->tenant_id)
                    ->where('employee_id', $employee->id)
                    ->where('assignment_type', AssignmentTypeEnum::PRIMARY)
                    ->where(function ($q) use ($fromStr) {
                        $q->whereNull('valid_to')
                            ->orWhere('valid_to', '>=', $fromStr);
                    })
                    ->get();

                foreach ($activePrimaries as $prevPrimary) {
                    if ($prevPrimary->valid_from->toDateString() >= $fromStr) {
                        // Jika valid_from penugasan sebelumnya sama atau lebih baru, batalkan / tutup di hari yg sama
                        $prevPrimary->update(['valid_to' => $prevPrimary->valid_from->toDateString()]);
                    } else {
                        $prevPrimary->update([
                            'valid_to' => $fromDate->copy()->subDay()->toDateString(),
                        ]);
                    }
                }
            }

            /** @var EmployeeBranchAssignment $assignment */
            $assignment = EmployeeBranchAssignment::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'store_id' => $store->id,
                'assignment_type' => $type,
                'valid_from' => $fromStr,
                'valid_to' => $toStr,
                'notes' => $notes,
            ]);

            // Jika karyawan terhubung dengan akun login User, sinkronkan ke user_stores untuk izin akses POS
            if ($employee->user_id !== null) {
                DB::table('user_stores')->insertOrIgnore([
                    'tenant_id' => $employee->tenant_id,
                    'user_id' => $employee->user_id,
                    'store_id' => $store->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $assignment;
        });
    }

    /**
     * Melakukan transisi status kepegawaian dan mencatat riwayat karir audit secara atomik.
     */
    public function transitionStatus(
        Employee $employee,
        EmploymentStatusEnum $toStatus,
        CarbonInterface|string $effectiveDate,
        ?string $reason = null,
        ?string $referenceNumber = null,
        ?int $actorUserId = null,
        ?int $newDepartmentId = null,
        ?int $newJobTitleId = null
    ): EmployeeEmploymentHistory {
        $effDateStr = is_string($effectiveDate) ? $effectiveDate : $effectiveDate->toDateString();
        $fromStatus = $employee->employment_status;

        // Validasi aturan siklus hidup
        $this->validateStatusTransition($fromStatus, $toStatus);

        return DB::transaction(function () use (
            $employee,
            $fromStatus,
            $toStatus,
            $effDateStr,
            $reason,
            $referenceNumber,
            $actorUserId,
            $newDepartmentId,
            $newJobTitleId
        ) {
            $fromDeptId = $employee->department_id;
            $fromJobId = $employee->job_title_id;

            $updatePayload = [
                'employment_status' => $toStatus,
            ];

            if ($newDepartmentId !== null && $newDepartmentId !== $fromDeptId) {
                $updatePayload['department_id'] = $newDepartmentId;
            }

            if ($newJobTitleId !== null && $newJobTitleId !== $fromJobId) {
                $updatePayload['job_title_id'] = $newJobTitleId;
            }

            if (in_array($toStatus, [EmploymentStatusEnum::RESIGNED, EmploymentStatusEnum::TERMINATED], true)) {
                $updatePayload['termination_date'] = $effDateStr;
                $updatePayload['termination_reason'] = $reason;
            }

            $employee->update($updatePayload);

            /** @var EmployeeEmploymentHistory $history */
            $history = EmployeeEmploymentHistory::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'from_department_id' => $fromDeptId,
                'to_department_id' => $newDepartmentId ?? $fromDeptId,
                'from_job_title_id' => $fromJobId,
                'to_job_title_id' => $newJobTitleId ?? $fromJobId,
                'effective_date' => $effDateStr,
                'reference_number' => $referenceNumber,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
            ]);

            return $history;
        });
    }

    /**
     * Mempromosikan atau memutasikan karyawan ke departemen / jabatan baru.
     */
    public function promoteOrTransfer(
        Employee $employee,
        ?int $newDepartmentId,
        ?int $newJobTitleId,
        CarbonInterface|string $effectiveDate,
        ?string $reason = null,
        ?string $referenceNumber = null,
        ?int $actorUserId = null
    ): EmployeeEmploymentHistory {
        $deptId = $newDepartmentId ?? $employee->department_id;
        $jobId = $newJobTitleId ?? $employee->job_title_id;

        return $this->transitionStatus(
            employee: $employee,
            toStatus: $employee->employment_status,
            effectiveDate: $effectiveDate,
            reason: $reason,
            referenceNumber: $referenceNumber,
            actorUserId: $actorUserId,
            newDepartmentId: $deptId,
            newJobTitleId: $jobId
        );
    }

    /**
     * Mendapatkan cabang utama (PRIMARY) yang aktif pada tanggal tertentu.
     */
    public function getEffectivePrimaryBranch(Employee $employee, CarbonInterface|string|null $date = null): ?EmployeeBranchAssignment
    {
        $targetDate = $date !== null ? (is_string($date) ? $date : $date->toDateString()) : now()->toDateString();

        return EmployeeBranchAssignment::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('assignment_type', AssignmentTypeEnum::PRIMARY)
            ->where('valid_from', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $targetDate);
            })
            ->latest('valid_from')
            ->first();
    }

    /**
     * Validasi transisi status kepegawaian.
     */
    private function validateStatusTransition(EmploymentStatusEnum $from, EmploymentStatusEnum $to): void
    {
        if ($from === $to) {
            return; // Mutasi departemen / jabatan tanpa ganti status diizinkan
        }

        // Status ARCHIVED bersifat terminal permanen (kecuali data rehire via entri karyawan baru)
        if ($from === EmploymentStatusEnum::ARCHIVED) {
            throw new InvalidArgumentException('Karyawan yang sudah berstatus ARCHIVED tidak dapat diubah statusnya.');
        }

        // Status DRAFT hanya boleh ke ACTIVE atau ON_PROBATION atau TERMINATED (batal kontrak)
        if ($from === EmploymentStatusEnum::DRAFT && ! in_array($to, [
            EmploymentStatusEnum::ACTIVE,
            EmploymentStatusEnum::ON_PROBATION,
            EmploymentStatusEnum::TERMINATED,
        ], true)) {
            throw new InvalidArgumentException("Transisi dari status DRAFT ke {$to->value} tidak valid.");
        }

        // Dari RESIGNED atau TERMINATED hanya boleh ke ARCHIVED
        if (in_array($from, [EmploymentStatusEnum::RESIGNED, EmploymentStatusEnum::TERMINATED], true)
            && $to !== EmploymentStatusEnum::ARCHIVED) {
            throw new InvalidArgumentException("Karyawan yang sudah {$from->value} hanya dapat ditransisikan ke status ARCHIVED.");
        }
    }
}
