<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Store;
use App\Modules\HR\Models\ShiftTemplate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class ShiftTemplateService
{
    /**
     * Membuat template shift kerja baru.
     *
     * @param  array{
     *     tenant_id: int,
     *     store_id: int,
     *     shift_code: string,
     *     name: string,
     *     start_time: string,
     *     end_time: string,
     *     cross_midnight?: bool,
     *     unpaid_break_minutes?: int,
     *     grace_period_minutes?: int,
     *     is_active?: bool
     * }  $data
     */
    public function createShiftTemplate(array $data): ShiftTemplate
    {
        // Validasi store
        Store::where('tenant_id', $data['tenant_id'])->findOrFail($data['store_id']);

        $startTime = substr($data['start_time'], 0, 5);
        $endTime = substr($data['end_time'], 0, 5);

        // Otomatis deteksi cross_midnight jika waktu selesai <= waktu mulai
        $crossMidnight = $data['cross_midnight'] ?? ($endTime <= $startTime);

        $unpaidBreak = $data['unpaid_break_minutes'] ?? 60;
        $gracePeriod = $data['grace_period_minutes'] ?? 15;

        // Hitung durasi kotor sementara untuk memvalidasi unpaid_break
        $baseDate = '2026-01-01';
        $start = Carbon::parse("{$baseDate} {$startTime}");
        $end = Carbon::parse("{$baseDate} {$endTime}");
        if ($crossMidnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
            $crossMidnight = true;
        }

        $grossMinutes = (int) $start->diffInMinutes($end);
        if ($unpaidBreak >= $grossMinutes) {
            throw new InvalidArgumentException("Durasi istirahat ($unpaidBreak menit) tidak boleh melebihi atau sama dengan total durasi shift ($grossMinutes menit).");
        }

        // Cek keunikan shift_code per store
        $exists = ShiftTemplate::where('tenant_id', $data['tenant_id'])
            ->where('store_id', $data['store_id'])
            ->where('shift_code', $data['shift_code'])
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("Kode shift {$data['shift_code']} sudah digunakan di cabang ini.");
        }

        /** @var ShiftTemplate $template */
        $template = ShiftTemplate::create([
            'tenant_id' => $data['tenant_id'],
            'store_id' => $data['store_id'],
            'shift_code' => strtoupper($data['shift_code']),
            'name' => $data['name'],
            'start_time' => $startTime,
            'end_time' => $endTime,
            'cross_midnight' => $crossMidnight,
            'unpaid_break_minutes' => $unpaidBreak,
            'grace_period_minutes' => $gracePeriod,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $template;
    }

    /**
     * Memperbarui template shift kerja.
     *
     * @param  array{
     *     name?: string,
     *     start_time?: string,
     *     end_time?: string,
     *     cross_midnight?: bool,
     *     unpaid_break_minutes?: int,
     *     grace_period_minutes?: int,
     *     is_active?: bool
     * }  $data
     */
    public function updateShiftTemplate(ShiftTemplate $template, array $data): ShiftTemplate
    {
        $startTime = isset($data['start_time']) ? substr($data['start_time'], 0, 5) : substr($template->start_time, 0, 5);
        $endTime = isset($data['end_time']) ? substr($data['end_time'], 0, 5) : substr($template->end_time, 0, 5);

        $crossMidnight = $data['cross_midnight'] ?? ($endTime <= $startTime);
        $unpaidBreak = $data['unpaid_break_minutes'] ?? $template->unpaid_break_minutes;

        $baseDate = '2026-01-01';
        $start = Carbon::parse("{$baseDate} {$startTime}");
        $end = Carbon::parse("{$baseDate} {$endTime}");
        if ($crossMidnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
            $crossMidnight = true;
        }

        $grossMinutes = (int) $start->diffInMinutes($end);
        if ($unpaidBreak >= $grossMinutes) {
            throw new InvalidArgumentException("Durasi istirahat ($unpaidBreak menit) tidak boleh melebihi atau sama dengan total durasi shift ($grossMinutes menit).");
        }

        $template->update([
            'name' => $data['name'] ?? $template->name,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'cross_midnight' => $crossMidnight,
            'unpaid_break_minutes' => $unpaidBreak,
            'grace_period_minutes' => $data['grace_period_minutes'] ?? $template->grace_period_minutes,
            'is_active' => $data['is_active'] ?? $template->is_active,
        ]);

        return $template;
    }

    /**
     * Mendapatkan rentang tanggal dan jam absolut yang dijadwalkan untuk template shift pada tanggal tertentu.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function getScheduledTimeRange(ShiftTemplate $template, CarbonInterface|string $scheduleDate): array
    {
        $dateStr = is_string($scheduleDate) ? $scheduleDate : $scheduleDate->toDateString();
        $startTime = substr($template->start_time, 0, 5);
        $endTime = substr($template->end_time, 0, 5);

        $start = Carbon::parse("{$dateStr} {$startTime}");
        $end = Carbon::parse("{$dateStr} {$endTime}");

        if ($template->cross_midnight || $end->lessThanOrEqualTo($start)) {
            $end->addDay();
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    /**
     * Mengecek apakah jam punch in karyawan melebihi toleransi keterlambatan (grace period).
     */
    public function isLate(
        ShiftTemplate $template,
        CarbonInterface|string $scheduleDate,
        CarbonInterface|string $actualClockIn
    ): bool {
        $range = $this->getScheduledTimeRange($template, $scheduleDate);
        $toleranceEnd = $range['start']->copy()->addMinutes($template->grace_period_minutes);

        $clockIn = $actualClockIn instanceof CarbonInterface ? $actualClockIn : Carbon::parse($actualClockIn);

        return $clockIn->greaterThan($toleranceEnd);
    }

    /**
     * Menghitung jumlah menit keterlambatan riil terhadap jam mulai shift.
     * Jika clock in masih dalam batas grace period atau tepat waktu, mengembalikan 0.
     */
    public function calculateLateMinutes(
        ShiftTemplate $template,
        CarbonInterface|string $scheduleDate,
        CarbonInterface|string $actualClockIn
    ): int {
        if (! $this->isLate($template, $scheduleDate, $actualClockIn)) {
            return 0;
        }

        $range = $this->getScheduledTimeRange($template, $scheduleDate);
        $clockIn = $actualClockIn instanceof CarbonInterface ? $actualClockIn : Carbon::parse($actualClockIn);

        return (int) $range['start']->diffInMinutes($clockIn);
    }

    /**
     * Mengecek apakah karyawan pulang lebih awal sebelum jam selesai shift.
     */
    public function isEarlyDeparture(
        ShiftTemplate $template,
        CarbonInterface|string $scheduleDate,
        CarbonInterface|string $actualClockOut
    ): bool {
        $range = $this->getScheduledTimeRange($template, $scheduleDate);
        $clockOut = $actualClockOut instanceof CarbonInterface ? $actualClockOut : Carbon::parse($actualClockOut);

        return $clockOut->lessThan($range['end']);
    }

    /**
     * Menghitung jumlah menit pulang lebih awal.
     */
    public function calculateEarlyDepartureMinutes(
        ShiftTemplate $template,
        CarbonInterface|string $scheduleDate,
        CarbonInterface|string $actualClockOut
    ): int {
        if (! $this->isEarlyDeparture($template, $scheduleDate, $actualClockOut)) {
            return 0;
        }

        $range = $this->getScheduledTimeRange($template, $scheduleDate);
        $clockOut = $actualClockOut instanceof CarbonInterface ? $actualClockOut : Carbon::parse($actualClockOut);

        return (int) $clockOut->diffInMinutes($range['end']);
    }
}
