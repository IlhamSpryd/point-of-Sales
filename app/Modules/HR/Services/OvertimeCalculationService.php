<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Approval;
use App\Modules\HR\Enums\OvertimeDayTypeEnum;
use App\Modules\HR\Enums\OvertimeStatusEnum;
use App\Modules\HR\Models\OvertimeRecord;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OvertimeCalculationService
{
    /**
     * Hitung faktor pengali jam lembur berbayar (payable hours) berdasarkan PP No. 35 Tahun 2021.
     *
     * 1. Hari Kerja Biasa (WORKDAY):
     *    - Jam ke-1: 1.5x upah sejam
     *    - Jam ke-2 dan seterusnya: 2.0x upah sejam
     *
     * 2. Hari Libur Resmi / Istirahat Mingguan (WEEKLY_REST / PUBLIC_HOLIDAY):
     *    - Sistem 6 hari kerja:
     *      - Jam 1 s/d 7: 2.0x
     *      - Jam 8: 3.0x
     *      - Jam 9 s/d 11+: 4.0x
     *    - Sistem 5 hari kerja:
     *      - Jam 1 s/d 8: 2.0x
     *      - Jam 9: 3.0x
     *      - Jam 10 s/d 12+: 4.0x
     */
    public function calculatePayableHours(
        float $actualHours,
        OvertimeDayTypeEnum $dayType = OvertimeDayTypeEnum::WORKDAY,
        int $workDaysPerWeek = 6
    ): float {
        if ($actualHours <= 0) {
            return 0.0;
        }

        if ($dayType === OvertimeDayTypeEnum::WORKDAY) {
            if ($actualHours <= 1.0) {
                return round($actualHours * 1.5, 2);
            }

            $firstHour = 1.0 * 1.5;
            $remainingHours = ($actualHours - 1.0) * 2.0;

            return round($firstHour + $remainingHours, 2);
        }

        // Hari Istirahat Mingguan atau Hari Libur Resmi
        if ($workDaysPerWeek === 5) {
            // Sistem 5 hari kerja
            if ($actualHours <= 8.0) {
                return round($actualHours * 2.0, 2);
            }

            if ($actualHours <= 9.0) {
                $tier1 = 8.0 * 2.0;
                $tier2 = ($actualHours - 8.0) * 3.0;

                return round($tier1 + $tier2, 2);
            }

            $tier1 = 8.0 * 2.0;
            $tier2 = 1.0 * 3.0;
            $tier3 = ($actualHours - 9.0) * 4.0;

            return round($tier1 + $tier2 + $tier3, 2);
        }

        // Sistem 6 hari kerja (default)
        if ($actualHours <= 7.0) {
            return round($actualHours * 2.0, 2);
        }

        if ($actualHours <= 8.0) {
            $tier1 = 7.0 * 2.0;
            $tier2 = ($actualHours - 7.0) * 3.0;

            return round($tier1 + $tier2, 2);
        }

        $tier1 = 7.0 * 2.0;
        $tier2 = 1.0 * 3.0;
        $tier3 = ($actualHours - 8.0) * 4.0;

        return round($tier1 + $tier2 + $tier3, 2);
    }

    /**
     * Hitung upah sejam standar Indonesia: 1 / 173 x Upah Sebulan (Gaji Pokok + Tunjangan Tetap).
     */
    public function calculateHourlyRateBasis(float $monthlyFixedWage): float
    {
        if ($monthlyFixedWage <= 0) {
            return 0.0;
        }

        return round($monthlyFixedWage / 173, 2);
    }

    /**
     * Hitung total nominal uang lembur.
     */
    public function calculateOvertimeAmount(float $payableHours, float $hourlyRateBasis): float
    {
        return round($payableHours * $hourlyRateBasis, 2);
    }

    /**
     * Validasi dan buat catatan lembur disetujui (OvertimeRecord).
     *
     * @throws ValidationException
     * @throws DomainException
     */
    public function createOvertimeRecord(
        int $tenantId,
        int $storeId,
        int $employeeId,
        int $attendanceRecordId,
        int $approvalId,
        CarbonInterface $overtimeDate,
        CarbonInterface $startTime,
        CarbonInterface $endTime,
        OvertimeDayTypeEnum $dayType,
        float $monthlyFixedWage,
        int $workDaysPerWeek = 6
    ): OvertimeRecord {
        // 1. Verifikasi persetujuan SPKL (approvals)
        /** @var Approval|null $approval */
        $approval = Approval::where('id', $approvalId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            throw new DomainException("Dokumen SPKL / Approval [ID: {$approvalId}] tidak ditemukan.");
        }

        if ($approval->action_code !== 'OVERTIME') {
            throw ValidationException::withMessages([
                'approval_id' => "Aksi persetujuan harus bertipe 'OVERTIME', ditemukan: {$approval->action_code}.",
            ]);
        }

        if ($approval->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'approval_id' => "Status SPKL belum disetujui (status: {$approval->status}).",
            ]);
        }

        // 2. Hitung durasi aktual
        $durationMinutes = abs((int) $endTime->diffInMinutes($startTime));
        $actualHours = round($durationMinutes / 60, 2);

        if ($durationMinutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => 'Waktu selesai lembur harus setelah waktu mulai.',
            ]);
        }

        // 3. Validasi PP 35/2021: Lembur hari kerja maksimal 4 jam / hari & 18 jam / minggu
        if ($dayType === OvertimeDayTypeEnum::WORKDAY) {
            if ($actualHours > 4.0) {
                throw ValidationException::withMessages([
                    'actual_duration_minutes' => "Waktu lembur hari kerja maksimal 4 jam (240 menit) per hari sesuai PP 35/2021. Ditemukan: {$actualHours} jam.",
                ]);
            }

            $weeklyMinutes = $this->getWeeklyWorkdayOvertimeMinutes($tenantId, $employeeId, $overtimeDate);
            if (($weeklyMinutes + $durationMinutes) > (18 * 60)) {
                $totalWeeklyHours = round(($weeklyMinutes + $durationMinutes) / 60, 2);
                throw ValidationException::withMessages([
                    'actual_duration_minutes' => "Akumulasi lembur hari kerja melebihi batas maksimal 18 jam per minggu sesuai PP 35/2021. Total minggu ini: {$totalWeeklyHours} jam.",
                ]);
            }
        }

        // 4. Hitung payable hours dan rate basis
        $payableHours = $this->calculatePayableHours($actualHours, $dayType, $workDaysPerWeek);
        $hourlyRateBasis = $this->calculateHourlyRateBasis($monthlyFixedWage);
        $calculatedAmount = $this->calculateOvertimeAmount($payableHours, $hourlyRateBasis);

        // 5. Simpan OvertimeRecord
        return OvertimeRecord::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'employee_id' => $employeeId,
            'attendance_record_id' => $attendanceRecordId,
            'approval_id' => $approvalId,
            'overtime_date' => $overtimeDate->toDateString(),
            'day_type' => $dayType,
            'start_time' => $startTime->toDateTimeString(),
            'end_time' => $endTime->toDateTimeString(),
            'actual_duration_minutes' => $durationMinutes,
            'payable_hours' => $payableHours,
            'hourly_rate_basis' => $hourlyRateBasis,
            'calculated_amount' => $calculatedAmount,
            'status' => OvertimeStatusEnum::APPROVED,
        ]);
    }

    /**
     * Hitung akumulasi menit lembur hari kerja karyawan dalam minggu kalender yang sama.
     */
    public function getWeeklyWorkdayOvertimeMinutes(int $tenantId, int $employeeId, CarbonInterface $date): int
    {
        $startOfWeek = Carbon::instance($date)->startOfWeek()->toDateString();
        $endOfWeek = Carbon::instance($date)->endOfWeek()->toDateString();

        return (int) OvertimeRecord::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('day_type', OvertimeDayTypeEnum::WORKDAY)
            ->whereIn('status', [OvertimeStatusEnum::APPROVED, OvertimeStatusEnum::PROCESSED_IN_PAYROLL, OvertimeStatusEnum::PENDING])
            ->whereBetween('overtime_date', [$startOfWeek, $endOfWeek])
            ->sum('actual_duration_minutes');
    }
}
