<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\CashDrawerMovement;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Finance\Services\FinancialOutboxDispatcherService;
use App\Modules\Sales\Exceptions\ShiftClosedException;
use App\Services\AuditTrailService;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShiftManagementService
{
    public function __construct(
        protected AuditTrailService $auditTrail,
        protected CashierPinService $cashierPinService,
        protected TenantContext $tenantContext,
        protected FinancialOutboxDispatcherService $financialOutboxDispatcher
    ) {}

    /**
     * Membuka sesi shift baru secara atomik dengan validasi integritas tenant dan penguncian unik.
     *
     * @throws DomainException|ValidationException|QueryException
     */
    public function openShift(
        int $tenantId,
        int $storeId,
        int $userId,
        float|string|int $openingCash = 0,
        ?int $deviceId = null,
        ?string $cashierPin = null
    ): Shift {
        $user = User::withoutGlobalScopes()->find($userId);
        if (! $user) {
            throw new DomainException('Pengguna kasir tidak ditemukan.');
        }

        if ((int) $user->tenant_id !== $tenantId) {
            throw new DomainException('Pengguna tidak terdaftar pada tenant ini.');
        }

        // Validasi PIN jika disediakan atau jika pengguna memiliki PIN terdaftar
        if ($cashierPin !== null) {
            if (! $this->cashierPinService->verifyPin($user, $cashierPin)) {
                throw ValidationException::withMessages(['pin' => 'PIN Kasir tidak sah.']);
            }
        } elseif ($this->cashierPinService->hasPin($user)) {
            // Jika user punya PIN tapi tidak diinput, wajibkan PIN
            throw new DomainException('Kasir wajib memasukkan PIN Kasir untuk membuka shift.');
        }

        $openingBalance = (int) round((float) $openingCash);
        if ($openingBalance < 0) {
            throw new DomainException('Modal awal kasir tidak boleh bernilai negatif.');
        }

        return DB::transaction(function () use ($tenantId, $storeId, $user, $openingBalance, $deviceId) {
            // Pemeriksaan shift aktif saat ini untuk kasir pada cabang ini
            $existingOpenShift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('status', 'open')
                ->where(function ($q) use ($user) {
                    $q->where('active_cashier_id', $user->id)
                        ->orWhere(function ($sub) use ($user) {
                            $sub->whereNull('active_cashier_id')->where('user_id', $user->id);
                        });
                })
                ->lockForUpdate()
                ->first();

            if ($existingOpenShift) {
                throw new DomainException("Kasir {$user->name} sudah memiliki shift aktif (#{$existingOpenShift->id}) di cabang ini.");
            }

            // Jika device_id diberikan, pastikan valid dan milik tenant & store yang sama
            if ($deviceId !== null) {
                $device = Device::withoutGlobalScopes()->where('id', $deviceId)->first();
                if (! $device || (int) $device->tenant_id !== $tenantId || (int) $device->store_id !== $storeId) {
                    throw new DomainException('Perangkat kasir tidak valid atau tidak terdaftar di cabang ini.');
                }
            }

            // Pembuatan record Shift baru (akan menegakkan shifts_one_open_per_user_unique di tingkat DB)
            $shift = Shift::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'user_id' => $user->id,
                'active_cashier_id' => $user->id,
                'device_id' => $deviceId,
                'opening_balance' => $openingBalance,
                'expected_cash' => $openingBalance,
                'status' => 'open',
                'opened_at' => now(),
            ]);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'shift.opened',
                userId: $user->id,
                storeId: $storeId,
                subjectType: Shift::class,
                subjectId: $shift->id,
                description: "Shift #{$shift->id} berhasil dibuka oleh kasir {$user->name} dengan modal awal Rp ".number_format($openingBalance, 0, ',', '.'),
                metadata: [
                    'shift_id' => $shift->id,
                    'opening_balance' => $openingBalance,
                    'device_id' => $deviceId,
                ]
            );

            return $shift;
        });
    }

    /**
     * Mencatat mutasi kas masuk / kas keluar pada laci kasir (Cash Drawer Movement).
     *
     * @throws DomainException|ShiftClosedException
     */
    public function recordCashMovement(
        int $tenantId,
        int $storeId,
        int $shiftId,
        int $userId,
        string $type,
        float|string|int $amount,
        string $reason,
        ?string $supervisorPin = null,
        ?int $supervisorUserId = null,
        ?string $category = 'general'
    ): CashDrawerMovement {
        $cleanAmount = (int) round((float) $amount);
        if ($cleanAmount <= 0) {
            throw new DomainException('Nominal pergerakan kas harus bernilai lebih dari 0.');
        }

        $type = strtolower($type);
        if (! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw new DomainException('Tipe pergerakan kas tidak valid. Harus cash_in atau cash_out.');
        }

        return DB::transaction(function () use (
            $tenantId, $storeId, $shiftId, $userId, $type, $cleanAmount, $reason, $supervisorPin, $supervisorUserId, $category
        ) {
            $shift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $shiftId)
                ->lockForUpdate()
                ->first();

            if (! $shift || $shift->status !== 'open') {
                throw new ShiftClosedException('Sesi shift tidak aktif atau telah ditutup. Tidak dapat mencatat pergerakan kas.');
            }

            $approvedBy = $userId;
            if ($supervisorPin !== null) {
                $supervisor = $supervisorUserId ? User::withoutGlobalScopes()->find($supervisorUserId) : null;
                if ($supervisor && ! $this->cashierPinService->verifyPin($supervisor, $supervisorPin, $shift)) {
                    throw ValidationException::withMessages(['supervisor_pin' => 'PIN Supervisor tidak sah.']);
                }
                if ($supervisor) {
                    $approvedBy = $supervisor->id;
                }
            }

            $resolvedCategory = match ($category) {
                'restock_change', 'petty_expense', 'owner_withdrawal', 'bank_deposit', 'correction', 'refund' => $category,
                default => ($type === 'cash_in' ? 'restock_change' : 'petty_expense'),
            };

            $movement = CashDrawerMovement::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'shift_id' => $shift->id,
                'device_id' => $shift->device_id,
                'type' => $type,
                'category' => $resolvedCategory,
                'amount' => $cleanAmount,
                'reason' => $reason,
                'created_by' => $userId,
                'approved_by' => $approvedBy,
            ]);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: "shift.cash_{$type}",
                userId: $userId,
                storeId: $storeId,
                subjectType: CashDrawerMovement::class,
                subjectId: $movement->id,
                description: "Pencatatan {$type} sebesar Rp ".number_format($cleanAmount, 0, ',', '.')." pada shift #{$shift->id}. Alasan: {$reason}",
                metadata: [
                    'shift_id' => $shift->id,
                    'type' => $type,
                    'amount' => $cleanAmount,
                    'approved_by' => $approvedBy,
                ]
            );

            return $movement;
        });
    }

    /**
     * Menutup shift dengan mekanisme Blind Cash Count.
     * Kasir wajib menghitung uang fisik tanpa mengetahui estimasi sistem sebelumnya.
     * Jika selisih kas fisik melebihi toleransi, verifikasi PIN supervisor diperlukan.
     *
     * @return array{shift: Shift, expected_cash: float, counted_cash: float, cash_difference: float, is_within_tolerance: bool}
     *
     * @throws DomainException|ShiftClosedException|ValidationException
     */
    public function closeShiftWithBlindCount(
        int $tenantId,
        int $storeId,
        int $shiftId,
        int $userId,
        float|string|int $countedCash,
        ?string $notes = null,
        ?string $supervisorPin = null,
        float|string|int $tolerance = 10000.00
    ): array {
        $counted = (float) $countedCash;
        if ($counted < 0) {
            throw new DomainException('Hitungan kas fisik tidak boleh bernilai negatif.');
        }

        $toleranceAmount = (float) $tolerance;

        return DB::transaction(function () use (
            $tenantId, $storeId, $shiftId, $userId, $counted, $notes, $supervisorPin, $toleranceAmount
        ) {
            $shift = Shift::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $shiftId)
                ->lockForUpdate()
                ->first();

            if (! $shift) {
                throw new DomainException('Shift tidak ditemukan.');
            }

            if ($shift->status !== 'open') {
                throw new ShiftClosedException('Shift sudah ditutup atau tidak dalam status terbuka.');
            }

            // Hitung ringkasan kas sistem (Expected Cash)
            $summary = $this->getCashSummary($shift);
            $expectedCash = (float) $summary['expected_cash'];
            $discrepancy = $counted - $expectedCash;
            $absDiscrepancy = abs($discrepancy);
            $isWithinTolerance = $absDiscrepancy <= $toleranceAmount;

            // Validasi toleransi selisih kas fisik
            if (! $isWithinTolerance) {
                if (empty($supervisorPin)) {
                    throw new DomainException(
                        'Selisih kas fisik (Rp '.number_format($absDiscrepancy, 2, ',', '.').
                        ') melebihi batas toleransi (Rp '.number_format($toleranceAmount, 2, ',', '.').
                        '). Diperlukan otorisasi PIN Supervisor untuk menutup shift.'
                    );
                }

                // Verifikasi PIN supervisor
                $allSupervisors = User::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereHas('role', function ($q) {
                        $q->whereIn('name', ['Supervisor', 'Manager', 'Admin', 'Owner', 'Store Manager']);
                    })
                    ->get();

                $pinValid = false;
                foreach ($allSupervisors as $candidate) {
                    if ($this->cashierPinService->verifyPin($candidate, $supervisorPin, $shift)) {
                        $pinValid = true;
                        break;
                    }
                }

                if (! $pinValid) {
                    throw ValidationException::withMessages([
                        'supervisor_pin' => 'PIN Supervisor tidak sah untuk otorisasi selisih kas.',
                    ]);
                }
            }

            $shift->update([
                'closing_balance' => (int) round($counted),
                'expected_cash' => (int) round($expectedCash),
                'cash_difference' => (int) round($discrepancy),
                'notes' => $notes,
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by_user_id' => $userId,
                'close_type' => 'normal',
            ]);

            // Dispatch outbox event untuk keuangan & akuntansi
            try {
                $this->financialOutboxDispatcher->dispatchShiftClosed($shift);
            } catch (\Throwable $e) {
                Log::warning("[FINANCE OUTBOX] Gagal dispatch outbox shift #{$shift->id}: ".$e->getMessage());
            }

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'shift.closed',
                userId: $userId,
                storeId: $storeId,
                subjectType: Shift::class,
                subjectId: $shift->id,
                description: "Shift #{$shift->id} ditutup dengan hitungan fisik Rp ".number_format($counted, 0, ',', '.').
                    ' (Expected: Rp '.number_format($expectedCash, 0, ',', '.').', Selisih: Rp '.number_format($discrepancy, 0, ',', '.').')',
                metadata: [
                    'shift_id' => $shift->id,
                    'counted_cash' => $counted,
                    'expected_cash' => $expectedCash,
                    'cash_difference' => $discrepancy,
                    'is_within_tolerance' => $isWithinTolerance,
                ]
            );

            return [
                'shift' => $shift,
                'expected_cash' => $expectedCash,
                'counted_cash' => $counted,
                'cash_difference' => $discrepancy,
                'is_within_tolerance' => $isWithinTolerance,
            ];
        });
    }

    /**
     * Mengambil sesi shift aktif untuk kasir saat ini.
     */
    public function getActiveShift(int $tenantId, int $storeId, int $userId): ?Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', 'open')
            ->where(function ($q) use ($userId) {
                $q->where('active_cashier_id', $userId)
                    ->orWhere(function ($sub) use ($userId) {
                        $sub->whereNull('active_cashier_id')->where('user_id', $userId);
                    });
            })
            ->first();
    }

    /**
     * Memastikan sesi shift kasir aktif ada, atau melempar ShiftClosedException (T-POS-01).
     *
     * @throws ShiftClosedException
     */
    public function assertActiveShift(int $tenantId, int $storeId, int $userId): Shift
    {
        $shift = $this->getActiveShift($tenantId, $storeId, $userId);

        if (! $shift) {
            throw new ShiftClosedException('Kasir tidak dapat bertransaksi di luar sesi shift aktif. Silakan buka shift baru sebelum melanjutkan.');
        }

        return $shift;
    }

    /**
     * Menghitung total ringkasan kas laci secara akurat.
     *
     * @return array{opening_balance: int, cash_sales: int, cash_in: int, cash_out: int, expected_cash: int}
     */
    public function getCashSummary(Shift $shift): array
    {
        $openingBalance = (int) $shift->opening_balance;

        // Total penerimaan kas dari pembayaran pesanan
        $cashSales = (int) Payment::where('shift_id', $shift->id)
            ->where('payment_method', 'cash')
            ->whereIn('status', ['captured', 'completed'])
            ->sum('amount');

        // Total kas masuk eksternal laci kasir (di luar starting cash)
        $cashIn = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_in')
            ->where('category', '!=', 'starting_cash')
            ->sum('amount');

        // Total kas keluar laci kasir
        $cashOut = (int) CashDrawerMovement::where('shift_id', $shift->id)
            ->where('type', 'cash_out')
            ->sum('amount');

        $expectedCash = $openingBalance + $cashSales + $cashIn - $cashOut;

        return [
            'opening_balance' => $openingBalance,
            'cash_sales' => $cashSales,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'expected_cash' => max(0, $expectedCash),
        ];
    }
}
