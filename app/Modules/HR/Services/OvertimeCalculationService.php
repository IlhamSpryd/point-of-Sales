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
     * Mengonversi input numerik menjadi numeric-string aman bagi BCMath.
     *
     * @return numeric-string
     */
    private function toNumericString(string|int|float $value, int $scale = 4): string
    {
        $raw = is_string($value) && is_numeric($value) ? $value : (string) $value;
        /** @var numeric-string $safeRaw */
        $safeRaw = is_numeric($raw) ? $raw : '0';

        return bcadd($safeRaw, '0', $scale);
    }

    /**
     * Hitung faktor pengali jam lembur berbayar (payable hours) berdasarkan PP No. 35 Tahun 2021
     * menggunakan presisi desimal BCMath.
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
     *
     * @return numeric-string
     */
    public function calculatePayableHours(
        string|int|float $actualHours,
        OvertimeDayTypeEnum $dayType = OvertimeDayTypeEnum::WORKDAY,
        int $workDaysPerWeek = 6
    ): string {
        $hours = $this->toNumericString($actualHours, 4);

        if (bccomp($hours, '0.0000', 4) <= 0) {
            return '0.00';
        }

        if ($dayType === OvertimeDayTypeEnum::WORKDAY) {
            if (bccomp($hours, '1.0000', 4) <= 0) {
                return bcmul($hours, '1.5', 2);
            }

            $firstHour = bcmul('1.0000', '1.5', 4);
            $remainingHours = bcmul(bcsub($hours, '1.0000', 4), '2.0', 4);

            return bcadd($firstHour, $remainingHours, 2);
        }

        // Hari Istirahat Mingguan atau Hari Libur Resmi
        if ($workDaysPerWeek === 5) {
            // Sistem 5 hari kerja
            if (bccomp($hours, '8.0000', 4) <= 0) {
                return bcmul($hours, '2.0', 2);
            }

            if (bccomp($hours, '9.0000', 4) <= 0) {
                $tier1 = bcmul('8.0000', '2.0', 4);
                $tier2 = bcmul(bcsub($hours, '8.0000', 4), '3.0', 4);

                return bcadd($tier1, $tier2, 2);
            }

            $tier1 = bcmul('8.0000', '2.0', 4);
            $tier2 = bcmul('1.0000', '3.0', 4);
            $tier3 = bcmul(bcsub($hours, '9.0000', 4), '4.0', 4);

            return bcadd(bcadd($tier1, $tier2, 4), $tier3, 2);
        }

        // Sistem 6 hari kerja (default)
        if (bccomp($hours, '7.0000', 4) <= 0) {
            return bcmul($hours, '2.0', 2);
        }

        if (bccomp($hours, '8.0000', 4) <= 0) {
            $tier1 = bcmul('7.0000', '2.0', 4);
            $tier2 = bcmul(bcsub($hours, '7.0000', 4), '3.0', 4);

            return bcadd($tier1, $tier2, 2);
        }

        $tier1 = bcmul('7.0000', '2.0', 4);
        $tier2 = bcmul('1.0000', '3.0', 4);
        $tier3 = bcmul(bcsub($hours, '8.0000', 4), '4.0', 4);

        return bcadd(bcadd($tier1, $tier2, 4), $tier3, 2);
    }

    /**
     * Hitung upah sejam standar Indonesia: 1 / 173 x Upah Sebulan (Gaji Pokok + Tunjangan Tetap).
     *
     * @return numeric-string
     */
    public function calculateHourlyRateBasis(string|int|float $monthlyFixedWage): string
    {
        $wage = $this->toNumericString($monthlyFixedWage, 4);

        if (bccomp($wage, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcdiv($wage, '173', 2);
    }

    /**
     * Hitung total nominal uang lembur menggunakan BCMath.
     *
     * @return numeric-string
     */
    public function calculateOvertimeAmount(string|int|float $payableHours, string|int|float $hourlyRateBasis): string
    {
        $hours = $this->toNumericString($payableHours, 4);
        $rate = $this->toNumericString($hourlyRateBasis, 4);

        if (bccomp($hours, '0.0000', 4) <= 0 || bccomp($rate, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return bcmul($hours, $rate, 2);
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
        string|int|float $monthlyFixedWage,
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
        if ($durationMinutes <= 0) {
            throw ValidationException::withMessages([
                'end_time' => 'Waktu selesai lembur harus setelah waktu mulai.',
            ]);
        }

        $minutesStr = (string) $durationMinutes;
        $actualHours = bcdiv($minutesStr, '60', 2);

        // 3. Validasi PP 35/2021: Lembur hari kerja maksimal 4 jam / hari & 18 jam / minggu
        if ($dayType === OvertimeDayTypeEnum::WORKDAY) {
            if ($durationMinutes > 240) {
                throw ValidationException::withMessages([
                    'actual_duration_minutes' => "Waktu lembur hari kerja maksimal 4 jam (240 menit) per hari sesuai PP 35/2021. Ditemukan: {$actualHours} jam.",
                ]);
            }

            $weeklyMinutes = $this->getWeeklyWorkdayOvertimeMinutes($tenantId, $employeeId, $overtimeDate);
            if (($weeklyMinutes + $durationMinutes) > (18 * 60)) {
                $totalMinutesStr = (string) ($weeklyMinutes + $durationMinutes);
                $totalWeeklyHours = bcdiv($totalMinutesStr, '60', 2);
                throw ValidationException::withMessages([
                    'actual_duration_minutes' => "Akumulasi lembur hari kerja melebihi batas maksimal 18 jam per minggu sesuai PP 35/2021. Total minggu ini: {$totalWeeklyHours} jam.",
                ]);
            }
        }

        // 4. Hitung payable hours dan rate basis secara presisi BCMath
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
