<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Models\Approval;
use App\Modules\HR\Enums\AttendanceStatusEnum;
use App\Modules\HR\Models\AttendanceRecord;
use App\Modules\HR\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AttendanceCorrectionService
{
    public function __construct(
        private readonly ShiftTemplateService $shiftTemplateService
    ) {}

    /**
     * Mengajukan permintaan koreksi absensi (menghasilkan baris persetujuan Approval).
     *
     * Invarian:
     * 1. Record absensi yang sudah terkunci (is_locked = true) tidak dapat dikoreksi.
     * 2. Window pengajuan default maksimal 3 hari kerja sejak tanggal absensi.
     */
    public function requestCorrection(
        AttendanceRecord $record,
        CarbonInterface|string|null $newClockIn,
        CarbonInterface|string|null $newClockOut,
        string $reason,
        int $requesterUserId,
        ?int $approverUserId = null,
        int $maxWindowDays = 3
    ): Approval {
        if ($record->is_locked) {
            throw new InvalidArgumentException("Record absensi ID #{$record->id} telah terkunci oleh finalisasi payroll dan dilarang dikoreksi.");
        }

        $now = now();
        $recordDate = $record->clock_in_at->copy()->startOfDay();
        $daysDiff = $recordDate->diffInDays($now->startOfDay());

        if ($daysDiff > $maxWindowDays) {
            throw new InvalidArgumentException("Batas waktu pengajuan koreksi absensi telah kadaluwarsa (maksimal {$maxWindowDays} hari kerja).");
        }

        $inStr = $newClockIn !== null ? (is_string($newClockIn) ? $newClockIn : $newClockIn->toDateTimeString()) : $record->clock_in_at->toDateTimeString();
        $outStr = $newClockOut !== null ? (is_string($newClockOut) ? $newClockOut : $newClockOut->toDateTimeString()) : ($record->clock_out_at?->toDateTimeString() ?? '');

        // Simpan payload koreksi ke format ringkas (approvals.reason varchar(255))
        $shortReason = substr($reason, 0, 150);
        $payloadStr = "IN:{$inStr}|OUT:{$outStr}|R:{$shortReason}";

        $manager = $record->employee?->manager;
        $managerUserId = $manager !== null ? $manager->user_id : null;
        $targetApproverId = $approverUserId ?? ($managerUserId ?? $requesterUserId);

        /** @var Approval $approval */
        $approval = Approval::create([
            'tenant_id' => $record->tenant_id,
            'store_id' => $record->store_id,
            'requester_user_id' => $requesterUserId,
            'approver_user_id' => $targetApproverId,
            'action_code' => 'ATTENDANCE_CORRECTION',
            'target_entity_type' => 'attendance_records',
            'target_entity_id' => $record->id,
            'reason' => $payloadStr,
            'status' => 'PENDING',
        ]);

        return $approval;
    }

    /**
     * Menyetujui koreksi absensi dan mengaplikasikan perubahan jam secara atomik.
     *
     * Invarian: Anti-Self Approval (penyetuju tidak boleh sama dengan pemohon).
     */
    public function approveCorrection(
        Approval $approval,
        int $approverUserId,
        ?string $approverNotes = null
    ): AttendanceRecord {
        if ($approval->action_code !== 'ATTENDANCE_CORRECTION') {
            throw new InvalidArgumentException("Kode aksi approval '{$approval->action_code}' bukan ATTENDANCE_CORRECTION.");
        }

        if ($approval->status !== 'PENDING') {
            throw new InvalidArgumentException("Status persetujuan '{$approval->status}' sudah tidak dapat diproses.");
        }

        // Anti-Self Approval
        if ($approval->requester_user_id === $approverUserId) {
            throw new InvalidArgumentException('Anti-Self Approval: Pengguna dilarang menyetujui pengajuan koreksi absensi miliknya sendiri.');
        }

        /** @var AttendanceRecord $record */
        $record = AttendanceRecord::where('tenant_id', $approval->tenant_id)
            ->with(['workSchedule.shiftTemplate', 'breaks'])
            ->findOrFail($approval->target_entity_id);

        if ($record->is_locked) {
            throw new InvalidArgumentException("Record absensi ID #{$record->id} telah terkunci oleh finalisasi payroll.");
        }

        $raw = $approval->reason ?? '';
        $newClockIn = null;
        $newClockOut = null;
        $userReason = 'Koreksi absensi disetujui';

        if (str_starts_with($raw, 'IN:')) {
            $parts = explode('|', $raw, 3);
            $inPart = substr($parts[0], 3);
            $outPart = isset($parts[1]) ? substr($parts[1], 4) : '';
            $userReason = isset($parts[2]) ? substr($parts[2], 2) : $userReason;

            $newClockIn = Carbon::parse($inPart);
            $newClockOut = ! empty($outPart) ? Carbon::parse($outPart) : null;
        } else {
            $payload = json_decode($raw, true) ?? [];
            $newClockIn = isset($payload['new_clock_in']) ? Carbon::parse($payload['new_clock_in']) : $record->clock_in_at;
            $newClockOut = isset($payload['new_clock_out']) && ! empty($payload['new_clock_out']) ? Carbon::parse($payload['new_clock_out']) : null;
            $userReason = $payload['user_reason'] ?? $userReason;
        }

        return DB::transaction(function () use ($approval, $approverUserId, $record, $newClockIn, $newClockOut, $userReason) {
            $lateMinutes = 0;
            $earlyDepartureMinutes = 0;

            if ($record->workSchedule !== null && $record->workSchedule->shiftTemplate !== null) {
                $template = $record->workSchedule->shiftTemplate;
                $schedDate = $record->workSchedule->schedule_date;

                if ($this->shiftTemplateService->isLate($template, $schedDate, $newClockIn)) {
                    $lateMinutes = $this->shiftTemplateService->calculateLateMinutes($template, $schedDate, $newClockIn);
                }

                if ($newClockOut !== null && $this->shiftTemplateService->isEarlyDeparture($template, $schedDate, $newClockOut)) {
                    $earlyDepartureMinutes = $this->shiftTemplateService->calculateEarlyDepartureMinutes($template, $schedDate, $newClockOut);
                }
            }

            $totalBreak = (int) $record->breaks()->sum('duration_minutes');
            $gross = $newClockOut !== null ? (int) abs((int) $newClockIn->diffInMinutes($newClockOut)) : 0;
            $net = max(0, $gross - $totalBreak);

            $record->update([
                'clock_in_at' => $newClockIn,
                'clock_out_at' => $newClockOut,
                'status' => AttendanceStatusEnum::CORRECTED,
                'late_minutes' => $lateMinutes,
                'early_departure_minutes' => $earlyDepartureMinutes,
                'total_break_minutes' => $totalBreak,
                'net_work_minutes' => $net,
                'correction_reason' => $userReason,
                'corrected_by_user_id' => $approverUserId,
                'approval_id' => $approval->id,
            ]);

            $approval->update([
                'approver_user_id' => $approverUserId,
                'status' => Approval::STATUS_APPROVED,
                'consumed_at' => now(),
            ]);

            return $record->fresh() ?? $record;
        });
    }

    /**
     * Menolak pengajuan koreksi absensi.
     */
    public function rejectCorrection(
        Approval $approval,
        int $approverUserId,
        string $rejectionReason
    ): Approval {
        if ($approval->action_code !== 'ATTENDANCE_CORRECTION') {
            throw new InvalidArgumentException("Kode aksi approval '{$approval->action_code}' bukan ATTENDANCE_CORRECTION.");
        }

        if ($approval->status !== 'PENDING') {
            throw new InvalidArgumentException("Status persetujuan '{$approval->status}' sudah tidak dapat diproses.");
        }

        if ($approval->requester_user_id === $approverUserId) {
            throw new InvalidArgumentException('Anti-Self Approval: Pengguna dilarang memproses pengajuan miliknya sendiri.');
        }

        $approval->update([
            'approver_user_id' => $approverUserId,
            'status' => Approval::STATUS_REJECTED,
            'reason' => trim(($approval->reason ?? '')." [Ditolak: {$rejectionReason}]"),
        ]);

        return $approval->fresh() ?? $approval;
    }

    /**
     * Menghasilkan rekapitulasi timesheet absensi karyawan dalam satu rentang tanggal (periode gaji).
     *
     * @return array{
     *     total_scheduled_shifts: int,
     *     total_present_days: int,
     *     total_late_days: int,
     *     total_late_minutes: int,
     *     total_early_departure_minutes: int,
     *     total_work_minutes: int,
     *     total_work_hours: float,
     *     total_break_minutes: int,
     *     total_absent_days: int
     * }
     */
    public function getTimesheetSummary(
        Employee $employee,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate
    ): array {
        $startStr = is_string($startDate) ? $startDate : $startDate->toDateString();
        $endStr = is_string($endDate) ? $endDate : $endDate->toDateString();

        $records = AttendanceRecord::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereDate('clock_in_at', '>=', $startStr)
            ->whereDate('clock_in_at', '<=', $endStr)
            ->get();

        $presentDays = 0;
        $lateDays = 0;
        $lateMinutes = 0;
        $earlyDepartureMinutes = 0;
        $workMinutes = 0;
        $breakMinutes = 0;
        $absentDays = 0;

        foreach ($records as $r) {
            if ($r->status === AttendanceStatusEnum::ABSENT) {
                $absentDays++;

                continue;
            }

            $presentDays++;
            if ($r->late_minutes > 0 || $r->status === AttendanceStatusEnum::LATE) {
                $lateDays++;
                $lateMinutes += $r->late_minutes;
            }

            $earlyDepartureMinutes += $r->early_departure_minutes;
            $workMinutes += $r->net_work_minutes;
            $breakMinutes += $r->total_break_minutes;
        }

        return [
            'total_scheduled_shifts' => $presentDays + $absentDays,
            'total_present_days' => $presentDays,
            'total_late_days' => $lateDays,
            'total_late_minutes' => $lateMinutes,
            'total_early_departure_minutes' => $earlyDepartureMinutes,
            'total_work_minutes' => $workMinutes,
            'total_work_hours' => round($workMinutes / 60, 2),
            'total_break_minutes' => $breakMinutes,
            'total_absent_days' => $absentDays,
        ];
    }
}
