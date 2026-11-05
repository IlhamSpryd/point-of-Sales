<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Store;
use App\Modules\HR\DataTransferObjects\AttendancePunchDto;
use App\Modules\HR\Enums\AttendanceSourceEnum;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Enums\EmploymentStatusEnum;
use App\Modules\HR\Enums\WorkScheduleStatusEnum;
use App\Modules\HR\Models\AttendanceBreak;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBranchAssignment;
use App\Modules\HR\Models\WorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttendanceService
{
    public function __construct(
        private readonly ShiftTemplateService $shiftTemplateService
    ) {}

    /**
     * Mencatat kehadiran masuk (Clock-In) karyawan.
     *
     * Invarian:
     * 1. Anti-Clock Manipulation: stempel waktu mutlak server-side timestamp.
     * 2. De-bouncing: menolak pukulan ganda dalam rentang < 5 menit (300 detik).
     * 3. Mencegah clock-in ganda jika sesi absensi sebelumnya belum di-clock-out.
     * 4. Memverifikasi hak penugasan cabang (branch assignment) aktif di store terkait.
     * 5. Rekonsiliasi otomatis dengan work_schedules dan shift_templates untuk mendeteksi keterlambatan.
     */
    public function clockIn(AttendancePunchDto $dto, ?CarbonInterface $serverNow = null): AttendanceRecord
    {
        $now = $serverNow ?? now();
        $dateStr = $now->toDateString();

        $employee = Employee::where('tenant_id', $dto->tenantId)->findOrFail($dto->employeeId);

        if (! in_array($employee->employment_status, [EmploymentStatusEnum::ACTIVE, EmploymentStatusEnum::ON_PROBATION], true)) {
            throw new InvalidArgumentException("Karyawan {$employee->employee_number} berstatus {$employee->employment_status->value} dan tidak berhak melakukan absensi.");
        }

        // Verifikasi store
        Store::where('tenant_id', $dto->tenantId)->findOrFail($dto->storeId);

        // Verifikasi penugasan cabang aktif
        $hasBranch = EmployeeBranchAssignment::where('tenant_id', $dto->tenantId)
            ->where('employee_id', $dto->employeeId)
            ->where('store_id', $dto->storeId)
            ->where('valid_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $dateStr);
            })
            ->exists();

        if (! $hasBranch) {
            throw new InvalidArgumentException("Karyawan {$employee->employee_number} tidak memiliki penugasan cabang aktif di outlet ID {$dto->storeId}.");
        }

        return DB::transaction(function () use ($dto, $now, $dateStr, $employee) {
            // Cek debounce (punch terakhir < 5 menit yang lalu)
            $lastPunch = AttendanceRecord::where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->latest('clock_in_at')
                ->first();

            if ($lastPunch !== null) {
                $lastTime = $lastPunch->clock_out_at ?? $lastPunch->clock_in_at;
                if (abs((int) $now->diffInSeconds($lastTime)) < 300) {
                    throw new InvalidArgumentException('Punch ditolak: debouncing pencegahan penekanan tombol ganda (< 5 menit).');
                }
            }

            // Cek apakah masih ada clock-in yang belum di-clock-out
            $openRecord = AttendanceRecord::where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->whereNull('clock_out_at')
                ->first();

            if ($openRecord !== null) {
                throw new InvalidArgumentException("Karyawan {$employee->employee_number} masih memiliki sesi absensi aktif yang belum clock-out (ID #{$openRecord->id}).");
            }

            // Cari jadwal kerja (work schedule) untuk tanggal ini
            $schedule = WorkSchedule::with('shiftTemplate')
                ->where('tenant_id', $dto->tenantId)
                ->where('employee_id', $dto->employeeId)
                ->where('schedule_date', $dateStr)
                ->where('status', '!=', WorkScheduleStatusEnum::CANCELLED)
                ->first();

            $status = AttendanceStatusEnum::PRESENT;
            $lateMinutes = 0;

            if ($schedule !== null && $schedule->shiftTemplate !== null) {
                $template = $schedule->shiftTemplate;
                if ($this->shiftTemplateService->isLate($template, $schedule->schedule_date, $now)) {
                    $status = AttendanceStatusEnum::LATE;
                    $lateMinutes = $this->shiftTemplateService->calculateLateMinutes($template, $schedule->schedule_date, $now);
                }
            }

            /** @var AttendanceRecord $record */
            $record = AttendanceRecord::create([
                'tenant_id' => $dto->tenantId,
                'store_id' => $dto->storeId,
                'employee_id' => $dto->employeeId,
                'work_schedule_id' => $schedule?->id,
                'clock_in_at' => $now,
                'source' => $dto->source,
                'device_id' => $dto->deviceId,
                'status' => $status,
                'late_minutes' => $lateMinutes,
                'snapshot_url' => $dto->snapshotUrl,
                'latitude' => $dto->latitude,
                'longitude' => $dto->longitude,
                'notes' => $dto->notes,
            ]);

            return $record;
        });
    }

    /**
     * Mencatat kehadiran pulang (Clock-Out) karyawan.
     */
    public function clockOut(AttendancePunchDto $dto, ?CarbonInterface $serverNow = null): AttendanceRecord
    {
        $now = $serverNow ?? now();

        $openRecord = AttendanceRecord::with(['workSchedule.shiftTemplate', 'breaks'])
            ->where('tenant_id', $dto->tenantId)
            ->where('employee_id', $dto->employeeId)
            ->whereNull('clock_out_at')
            ->first();

        if ($openRecord === null) {
            throw new InvalidArgumentException("Tidak ditemukan sesi absensi masuk aktif untuk karyawan ID {$dto->employeeId}.");
        }

        // Cek debounce terhadap clock_in_at
        if (abs((int) $now->diffInSeconds($openRecord->clock_in_at)) < 300) {
            throw new InvalidArgumentException('Punch clock-out ditolak: durasi sejak clock-in kurang dari 5 menit.');
        }

        return DB::transaction(function () use ($openRecord, $now, $dto) {
            // Tutup break yang masih terbuka jika ada
            /** @var AttendanceBreak|null $openBreak */
            $openBreak = AttendanceBreak::where('attendance_record_id', $openRecord->id)
                ->whereNull('break_end_at')
                ->first();

            if ($openBreak !== null) {
                $openBreak->update([
                    'break_end_at' => $now,
                    'duration_minutes' => (int) abs((int) $openBreak->break_start_at->diffInMinutes($now)),
                ]);
            }

            // Hitung total menit istirahat
            $totalBreakMinutes = (int) AttendanceBreak::where('attendance_record_id', $openRecord->id)
                ->sum('duration_minutes');

            // Hitung durasi kotor kerja
            $grossMinutes = (int) abs((int) $openRecord->clock_in_at->diffInMinutes($now));
            $netMinutes = max(0, $grossMinutes - $totalBreakMinutes);

            // Cek pulang lebih awal (early departure)
            $earlyDepartureMinutes = 0;
            if ($openRecord->workSchedule !== null && $openRecord->workSchedule->shiftTemplate !== null) {
                $template = $openRecord->workSchedule->shiftTemplate;
                $schedDate = $openRecord->workSchedule->schedule_date;

                if ($this->shiftTemplateService->isEarlyDeparture($template, $schedDate, $now)) {
                    $earlyDepartureMinutes = $this->shiftTemplateService->calculateEarlyDepartureMinutes($template, $schedDate, $now);
                    if ($openRecord->status === AttendanceStatusEnum::PRESENT && $earlyDepartureMinutes > 0) {
                        $openRecord->status = AttendanceStatusEnum::EARLY_LEAVE;
                    }
                }
            }

            $openRecord->update([
                'clock_out_at' => $now,
                'status' => $openRecord->status,
                'early_departure_minutes' => $earlyDepartureMinutes,
                'total_break_minutes' => $totalBreakMinutes,
                'net_work_minutes' => $netMinutes,
                'notes' => trim(($openRecord->notes ?? '').' '.($dto->notes ?? '')),
            ]);

            // Tandai jadwal kerja selesai jika ada
            if ($openRecord->workSchedule !== null) {
                $openRecord->workSchedule->update([
                    'status' => WorkScheduleStatusEnum::COMPLETED,
                ]);
            }

            return $openRecord->fresh() ?? $openRecord;
        });
    }

    /**
     * Memulai waktu istirahat (Break Start) staf.
     */
    public function startBreak(int $attendanceRecordId, ?string $notes = null, ?CarbonInterface $serverNow = null): AttendanceBreak
    {
        $now = $serverNow ?? now();
        $record = AttendanceRecord::findOrFail($attendanceRecordId);

        if ($record->clock_out_at !== null) {
            throw new InvalidArgumentException('Tidak dapat memulai istirahat pada sesi absensi yang sudah selesai.');
        }

        // Cek apakah ada istirahat yang belum selesai
        $openBreak = AttendanceBreak::where('attendance_record_id', $record->id)
            ->whereNull('break_end_at')
            ->first();

        if ($openBreak !== null) {
            throw new InvalidArgumentException('Sesi istirahat sebelumnya belum diakhiri.');
        }

        /** @var AttendanceBreak $break */
        $break = AttendanceBreak::create([
            'tenant_id' => $record->tenant_id,
            'attendance_record_id' => $record->id,
            'break_start_at' => $now,
            'notes' => $notes,
        ]);

        return $break;
    }

    /**
     * Mengakhiri waktu istirahat (Break End) staf.
     */
    public function endBreak(int $attendanceRecordId, ?CarbonInterface $serverNow = null): AttendanceBreak
    {
        $now = $serverNow ?? now();
        $record = AttendanceRecord::findOrFail($attendanceRecordId);

        /** @var AttendanceBreak|null $openBreak */
        $openBreak = AttendanceBreak::where('attendance_record_id', $record->id)
            ->whereNull('break_end_at')
            ->first();

        if ($openBreak === null) {
            throw new InvalidArgumentException('Tidak ditemukan sesi istirahat aktif untuk diakhiri.');
        }

        $duration = (int) abs((int) $openBreak->break_start_at->diffInMinutes($now));

        $openBreak->update([
            'break_end_at' => $now,
            'duration_minutes' => $duration,
        ]);

        // Perbarui total break minutes di parent record
        $totalBreaks = (int) AttendanceBreak::where('attendance_record_id', $record->id)->sum('duration_minutes');
        $record->update(['total_break_minutes' => $totalBreaks]);

        return $openBreak->fresh() ?? $openBreak;
    }

    /**
     * Rekonsiliasi absensi harian toko untuk menandai jadwal yang mangkir (ABSENT).
     */
    public function reconcileDailyAttendance(int $tenantId, int $storeId, CarbonInterface|string $date): int
    {
        $dateStr = is_string($date) ? $date : $date->toDateString();

        // Cari seluruh jadwal yang masih SCHEDULED pada tanggal lampau tanpa record absensi
        $unpunchedSchedules = WorkSchedule::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('schedule_date', $dateStr)
            ->where('status', WorkScheduleStatusEnum::SCHEDULED)
            ->doesntHave('attendanceRecord')
            ->get();

        $absentCount = 0;
        foreach ($unpunchedSchedules as $sched) {
            AttendanceRecord::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'employee_id' => $sched->employee_id,
                'work_schedule_id' => $sched->id,
                'clock_in_at' => Carbon::parse("{$dateStr} 23:59:59"),
                'clock_out_at' => Carbon::parse("{$dateStr} 23:59:59"),
                'source' => AttendanceSourceEnum::MANUAL_CORRECTION,
                'status' => AttendanceStatusEnum::ABSENT,
                'notes' => 'Otomatis direkonsiliasi: tidak melakukan absensi (Mangkir/Alpha)',
            ]);

            $sched->update(['status' => WorkScheduleStatusEnum::COMPLETED]);
            $absentCount++;
        }

        return $absentCount;
    }
}
