<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Store;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\WorkScheduleStatusEnum;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBranchAssignment;
use App\Modules\HR\Models\ShiftTemplate;
use App\Modules\HR\Models\WorkSchedule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WorkforceSchedulingService
{
    /**
     * Menjadwalkan shift kerja untuk karyawan pada tanggal tertentu.
     *
     * Invarian:
     * 1. Karyawan wajib berstatus aktif (ACTIVE atau ON_PROBATION).
     * 2. Karyawan wajib memiliki penugasan cabang (PRIMARY / SECONDARY / TEMPORARY) yang aktif pada tanggal tersebut.
     * 3. Template shift wajib aktif dan milik cabang yang bersangkutan.
     * 4. Tidak boleh ada jadwal ganda pada tanggal yang sama untuk karyawan yang sama.
     */
    public function scheduleShift(
        Employee $employee,
        int $storeId,
        int $shiftTemplateId,
        CarbonInterface|string $scheduleDate,
        ?string $notes = null
    ): WorkSchedule {
        $dateStr = is_string($scheduleDate) ? $scheduleDate : $scheduleDate->toDateString();

        // 1. Validasi status karyawan
        if (! in_array($employee->employment_status, [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION], true)) {
            throw new InvalidArgumentException("Karyawan {$employee->employee_number} berstatus {$employee->employment_status->value} dan tidak dapat dijadwalkan shift.");
        }

        // 2. Validasi cabang toko milik tenant yang sama
        Store::where('tenant_id', $employee->tenant_id)->findOrFail($storeId);

        // 3. Validasi penugasan cabang aktif
        $hasBranchAssignment = EmployeeBranchAssignment::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('store_id', $storeId)
            ->where('valid_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $dateStr);
            })
            ->exists();

        if (! $hasBranchAssignment) {
            throw new InvalidArgumentException("Karyawan {$employee->employee_number} tidak memiliki penugasan cabang aktif di outlet ID {$storeId} pada tanggal {$dateStr}.");
        }

        // 4. Validasi template shift
        $template = ShiftTemplate::where('tenant_id', $employee->tenant_id)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->findOrFail($shiftTemplateId);

        // 5. Validasi tidak bentrok / jadwal ganda pada tanggal yang sama
        $existing = WorkSchedule::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('schedule_date', $dateStr)
            ->where('status', '!=', WorkScheduleStatusEnum::CANCELLED)
            ->first();

        if ($existing !== null) {
            throw new InvalidArgumentException("Karyawan {$employee->employee_number} sudah memiliki jadwal shift pada tanggal {$dateStr}.");
        }

        /** @var WorkSchedule $schedule */
        $schedule = WorkSchedule::create([
            'tenant_id' => $employee->tenant_id,
            'store_id' => $storeId,
            'employee_id' => $employee->id,
            'shift_template_id' => $template->id,
            'schedule_date' => $dateStr,
            'status' => WorkScheduleStatusEnum::SCHEDULED,
            'notes' => $notes,
        ]);

        return $schedule;
    }

    /**
     * Menjadwalkan sekelompok shift secara batch dalam satu transaksi atomik.
     *
     * @param  array<int, array{
     *     employee: Employee,
     *     store_id: int,
     *     shift_template_id: int,
     *     schedule_date: CarbonInterface|string,
     *     notes?: string|null
     * }>  $entries
     * @return Collection<int, WorkSchedule>
     */
    public function batchSchedule(array $entries): Collection
    {
        return DB::transaction(function () use ($entries) {
            /** @var Collection<int, WorkSchedule> $created */
            $created = new Collection;
            foreach ($entries as $entry) {
                $created->push($this->scheduleShift(
                    employee: $entry['employee'],
                    storeId: $entry['store_id'],
                    shiftTemplateId: $entry['shift_template_id'],
                    scheduleDate: $entry['schedule_date'],
                    notes: $entry['notes'] ?? null
                ));
            }

            return $created;
        });
    }

    /**
     * Memproses pertukaran shift (Shift Swap) antara dua jadwal kerja secara atomik.
     *
     * @return array{schedule_a: WorkSchedule, schedule_b: WorkSchedule}
     */
    public function requestShiftSwap(
        WorkSchedule $scheduleA,
        WorkSchedule $scheduleB,
        ?string $reason = null
    ): array {
        if ($scheduleA->tenant_id !== $scheduleB->tenant_id) {
            throw new InvalidArgumentException('Tidak dapat menukar shift antar-tenant yang berbeda.');
        }

        if ($scheduleA->status !== WorkScheduleStatusEnum::SCHEDULED || $scheduleB->status !== WorkScheduleStatusEnum::SCHEDULED) {
            throw new InvalidArgumentException('Pertukaran shift hanya dapat dilakukan untuk jadwal yang berstatus SCHEDULED.');
        }

        if ($scheduleA->employee_id === $scheduleB->employee_id) {
            throw new InvalidArgumentException('Tidak dapat menukar shift dengan karyawan yang sama.');
        }

        return DB::transaction(function () use ($scheduleA, $scheduleB, $reason) {
            $empAId = $scheduleA->employee_id;
            $empBId = $scheduleB->employee_id;

            // Catat swap link dan alasan
            $noteA = trim(($scheduleA->notes ?? '')." [Swapped with #{$scheduleB->id}: {$reason}]");
            $noteB = trim(($scheduleB->notes ?? '')." [Swapped with #{$scheduleA->id}: {$reason}]");

            // Karena ada unique constraint (tenant_id, employee_id, schedule_date),
            // jika kedua jadwal memiliki tanggal yang sama, pertukaran langsung bisa bentrok jika tidak memakai placeholder.
            // Gunakan update sementara untuk keamanan integritas:
            if ($scheduleA->schedule_date->toDateString() === $scheduleB->schedule_date->toDateString()) {
                // Tanggal sama: tukar template shift
                $tmpTemplateId = $scheduleA->shift_template_id;
                $scheduleA->update([
                    'shift_template_id' => $scheduleB->shift_template_id,
                    'status' => WorkScheduleStatusEnum::SWAPPED,
                    'swapped_with_schedule_id' => $scheduleB->id,
                    'notes' => $noteA,
                ]);
                $scheduleB->update([
                    'shift_template_id' => $tmpTemplateId,
                    'status' => WorkScheduleStatusEnum::SWAPPED,
                    'swapped_with_schedule_id' => $scheduleA->id,
                    'notes' => $noteB,
                ]);
            } else {
                // Tanggal berbeda: tukar employee_id
                $scheduleA->update([
                    'employee_id' => $empBId,
                    'status' => WorkScheduleStatusEnum::SWAPPED,
                    'swapped_with_schedule_id' => $scheduleB->id,
                    'notes' => $noteA,
                ]);
                $scheduleB->update([
                    'employee_id' => $empAId,
                    'status' => WorkScheduleStatusEnum::SWAPPED,
                    'swapped_with_schedule_id' => $scheduleA->id,
                    'notes' => $noteB,
                ]);
            }

            return [
                'schedule_a' => $scheduleA->fresh() ?? $scheduleA,
                'schedule_b' => $scheduleB->fresh() ?? $scheduleB,
            ];
        });
    }

    /**
     * Membatalkan jadwal shift.
     */
    public function cancelSchedule(WorkSchedule $schedule, ?string $reason = null): WorkSchedule
    {
        $notes = trim(($schedule->notes ?? '').($reason !== null ? " [Batal: {$reason}]" : ' [Dibatalkan]'));

        $schedule->update([
            'status' => WorkScheduleStatusEnum::CANCELLED,
            'notes' => $notes,
        ]);

        return $schedule;
    }

    /**
     * Mengambil seluruh roster jadwal kerja seorang karyawan pada rentang tanggal.
     *
     * @return Collection<int, WorkSchedule>
     */
    public function getEmployeeRoster(
        Employee $employee,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate
    ): Collection {
        return WorkSchedule::with(['shiftTemplate', 'store'])
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->betweenDates($startDate, $endDate)
            ->orderBy('schedule_date')
            ->get();
    }

    /**
     * Mengambil roster seluruh staf di suatu outlet pada tanggal tertentu.
     *
     * @return Collection<int, WorkSchedule>
     */
    public function getStoreDailyRoster(int $storeId, CarbonInterface|string $date): Collection
    {
        return WorkSchedule::with(['employee.jobTitle', 'shiftTemplate'])
            ->where('store_id', $storeId)
            ->forDate($date)
            ->where('status', '!=', WorkScheduleStatusEnum::CANCELLED)
            ->orderBy('shift_template_id')
            ->get();
    }
}
