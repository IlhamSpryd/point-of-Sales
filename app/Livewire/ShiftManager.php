<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Device;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\User;
use App\Services\CashierPinService;
use App\Services\Context\TenantContext;
use App\Services\ShiftService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftManager extends Component
{
    use RequiresTenantContext;

    #[Locked]
    public $activeShift;

    #[Locked]
    public ?int $deviceId = null;

    public ?int $selectedDeviceId = null;

    public $opening_balance = 0;

    public $closing_balance = 0;

    public $notes = '';

    public string $cashierPin = '';

    public string $supervisorPin = '';

    // Cash Drawer Movement state
    public string $movementType = 'cash_in';

    public $movementAmount = 0;

    public string $movementCategory = 'restock_change';

    public string $movementReason = '';

    public string $movementSupervisorPin = '';

    public bool $isMovementModalOpen = false;

    // Shift Handover state
    public ?int $handoverToUserId = null;

    public ?int $toUserId = null;

    public ?int $handoverShiftId = null;

    public $handoverCountedCash = 0;

    public $countedCashAtHandover = null;

    public string $handoverNotes = '';

    public string $handoverCashierPin = '';

    public ?int $activeHandoverId = null;

    public bool $isHandoverModalOpen = false;

    // Force Close state
    public ?int $forceClosingShiftId = null;

    public string $forceCloseReason = '';

    public string $forceCloseSupervisorPin = '';

    public bool $isForceCloseModalOpen = false;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)) {
            abort(403, 'Anda tidak memiliki hak akses manajemen shift.');
        }
    }

    public function mount()
    {
        $this->loadActiveShift();
    }

    public function loadActiveShift()
    {
        $userId = auth()->id();
        $this->activeShift = Shift::where(function ($q) use ($userId) {
            $q->where('active_cashier_id', $userId)
                ->orWhere(function ($sub) use ($userId) {
                    $sub->whereNull('active_cashier_id')->where('user_id', $userId);
                });
        })
            ->whereIn('status', ['open', 'handover_pending', 'closing'])
            ->first();

        if ($this->activeShift && $this->activeShift->device_id) {
            $this->deviceId = $this->activeShift->device_id;
            $this->selectedDeviceId = $this->activeShift->device_id;
        }
    }

    public function openShift(?string $pin = null)
    {
        Gate::authorize('open', Shift::class);

        $user = auth()->user();
        $pinToVerify = $pin ?? $this->cashierPin;

        if ($user && ! app(CashierPinService::class)->hasPin($user)) {
            $this->dispatch('toast', message: 'Kasir wajib menyetel PIN Kasir sebelum dapat membuka shift.', type: 'error');
            $this->dispatch('require-pin-setup');

            return;
        }

        if (! empty($pinToVerify)) {
            if (! app(CashierPinService::class)->verifyPin($user, $pinToVerify)) {
                $this->addError('cashierPin', 'PIN Kasir salah.');
                $this->dispatch('toast', message: 'PIN Kasir salah.', type: 'error');

                return;
            }
        }

        $this->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeId = app(TenantContext::class)->getStoreId();
            $shiftService = app(ShiftService::class);
            $devId = $this->selectedDeviceId ?? $this->deviceId;

            if ($devId !== null && ! empty($pinToVerify)) {
                $opened = $shiftService->openShift(
                    $tenantId,
                    $storeId ?? 1,
                    $devId,
                    (int) $user->id,
                    (int) $this->opening_balance,
                    $pinToVerify
                );
            } else {
                $opened = $shiftService->openShift((int) $user->id, (int) $this->opening_balance);
            }
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (ValidationException $e) {
            $this->dispatch('toast', message: collect($e->errors())->flatten()->first(), type: 'error');

            return;
        }

        if ($opened === null) {
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Anda sudah memiliki shift yang sedang aktif.', type: 'info');

            return;
        }

        $this->cashierPin = '';
        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Shift berhasil dibuka.', type: 'success');
    }

    public function setCashierPin(string $pin): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(CashierPinService::class)->setPin($user, $pin);
            $this->dispatch('toast', message: 'PIN Kasir berhasil disetel.', type: 'success');
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function closeShift(?string $pin = null)
    {
        $this->loadActiveShift();

        if (! $this->activeShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk ditutup.', type: 'info');

            return;
        }

        Gate::authorize('close', $this->activeShift);

        $pinToVerify = $pin ?? $this->cashierPin;
        $user = auth()->user();

        if (! empty($pinToVerify)) {
            if (! app(CashierPinService::class)->verifyPin($user, $pinToVerify, $this->activeShift)) {
                $this->addError('cashierPin', 'PIN Kasir salah.');
                $this->dispatch('toast', message: 'PIN Kasir salah.', type: 'error');

                return;
            }
        }

        $this->validate([
            'closing_balance' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        $shiftService = app(ShiftService::class);

        try {
            if ($this->activeShift->device_id !== null && ! empty($pinToVerify)) {
                $closedShift = $shiftService->closeShift(
                    $this->activeShift->id,
                    $this->activeShift->device_id,
                    (int) $user->id,
                    (int) $this->closing_balance,
                    $pinToVerify
                );
            } else {
                $closedShift = $shiftService->closeShift((int) $user->id, (int) $this->closing_balance, $this->notes);
            }
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        }

        $this->loadActiveShift();

        if (! $closedShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk ditutup.', type: 'info');

            return;
        }

        $this->opening_balance = 0;
        $this->closing_balance = 0;
        $this->notes = '';
        $this->cashierPin = '';

        $this->dispatch('toast', message: 'Shift berhasil ditutup.', type: 'success');
    }

    public function initiateHandover(?string $pin = null)
    {
        $this->loadActiveShift();
        if ($this->handoverToUserId === null && $this->toUserId !== null) {
            $this->handoverToUserId = $this->toUserId;
        }
        if (! $this->activeShift && $this->handoverShiftId) {
            $this->activeShift = Shift::find($this->handoverShiftId);
        }
        if (! $this->activeShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk diserahterimakan.', type: 'error');

            return;
        }

        Gate::authorize('handover', $this->activeShift);

        if ($this->countedCashAtHandover !== null) {
            $this->handoverCountedCash = $this->countedCashAtHandover;
        }

        $this->validate([
            'handoverToUserId' => 'required|integer',
            'handoverCountedCash' => 'required|integer|min:0',
            'handoverNotes' => 'nullable|string|max:500',
        ]);

        $pinToVerify = $pin ?? ($this->handoverCashierPin ?: $this->cashierPin);
        $user = auth()->user();

        try {
            $handover = app(ShiftService::class)->initiateHandover(
                $this->activeShift->id,
                (int) $user->id,
                (int) $this->handoverToUserId,
                (int) $this->handoverCountedCash,
                $pinToVerify,
                $this->handoverNotes
            );

            $this->activeHandoverId = $handover->id;
            $this->loadActiveShift();
            $this->cashierPin = '';
            $this->dispatch('toast', message: 'Serah terima shift berhasil diinisiasi.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }

    public function recordCashMovement(): void
    {
        $this->loadActiveShift();
        if (! $this->activeShift || $this->activeShift->status !== 'open') {
            $this->dispatch('toast', message: 'Hanya shift aktif yang dapat mencatat pergerakan kas.', type: 'error');

            return;
        }

        Gate::authorize('recordCashMovement', $this->activeShift);

        $this->validate([
            'movementType' => 'required|in:cash_in,cash_out',
            'movementAmount' => 'required|integer|min:1',
            'movementCategory' => 'required|string|max:100',
            'movementReason' => 'required|string|min:3|max:255',
        ]);

        $user = auth()->user();
        $pin = $this->movementSupervisorPin ?: null;

        // If Cash Out / Drop, verify supervisor PIN if provided or required
        if ($this->movementType === 'cash_out' && ! empty($this->movementSupervisorPin)) {
            $isSupValid = app(CashierPinService::class)->verifySupervisorPin((int) $user->id, $this->movementSupervisorPin, $this->activeShift->tenant_id);
            if (! $isSupValid) {
                $this->addError('movementSupervisorPin', 'PIN Supervisor tidak sah.');
                $this->dispatch('toast', message: 'PIN Supervisor tidak sah.', type: 'error');

                return;
            }
        }

        try {
            app(ShiftService::class)->recordCashMovement(
                $this->activeShift->id,
                $this->activeShift->device_id ?? 1,
                (int) $user->id,
                $this->movementType,
                $this->movementCategory,
                (int) $this->movementAmount,
                $this->movementReason,
                $pin
            );

            $this->movementAmount = 0;
            $this->movementReason = '';
            $this->movementSupervisorPin = '';
            $this->isMovementModalOpen = false;
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Pergerakan kas berhasil dicatat.', type: 'success');
        } catch (\DomainException|\InvalidArgumentException|ValidationException $e) {
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $msg, type: 'error');
        }
    }

    public function cancelHandover(int $handoverId)
    {
        try {
            app(ShiftService::class)->cancelHandover($handoverId, (int) auth()->id());
            $this->activeHandoverId = null;
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Serah terima shift dibatalkan.', type: 'success');
        } catch (\DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function acceptHandover(int $handoverId, ?string $toPin = null, ?string $supPin = null, ?int $supUserId = null)
    {
        $pinToVerify = $toPin ?? $this->cashierPin;
        $user = auth()->user();

        try {
            app(ShiftService::class)->acceptHandover(
                $handoverId,
                (int) $user->id,
                $pinToVerify,
                $supPin ?? $this->supervisorPin,
                $supUserId
            );

            $this->activeHandoverId = null;
            $this->cashierPin = '';
            $this->supervisorPin = '';
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Serah terima shift berhasil diterima.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }

    public function forceCloseShift(?string $reason = null, ?string $supPin = null, ?int $shiftId = null)
    {
        $this->loadActiveShift();
        $targetShiftId = $shiftId ?? $this->forceClosingShiftId;
        $targetShift = $targetShiftId ? Shift::find($targetShiftId) : ($this->activeShift ?? Shift::where('status', 'open')->first());
        if (! $targetShift) {
            $this->dispatch('toast', message: 'Tidak ada shift aktif untuk ditutup paksa.', type: 'error');

            return;
        }

        Gate::authorize('forceClose', $targetShift);

        $actualReason = $reason ?? $this->forceCloseReason;
        $pinToVerify = $supPin ?? $this->forceCloseSupervisorPin ?: $this->supervisorPin;
        $user = auth()->user();

        if (empty($actualReason)) {
            $this->addError('forceCloseReason', 'Alasan penutupan paksa wajib diisi.');
            $this->dispatch('toast', message: 'Alasan penutupan paksa wajib diisi.', type: 'error');

            return;
        }

        try {
            app(ShiftService::class)->forceCloseShift(
                $targetShift->id,
                (int) $user->id,
                (int) $this->closing_balance,
                $pinToVerify,
                $actualReason
            );

            $this->loadActiveShift();
            $this->forceCloseReason = '';
            $this->forceCloseSupervisorPin = '';
            $this->isForceCloseModalOpen = false;
            $this->dispatch('toast', message: 'Shift berhasil ditutup paksa oleh supervisor.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }

    public function render()
    {
        $cashSales = 0;
        $expectedCash = 0;
        $cashMovements = collect();
        $handovers = collect();
        $pendingHandover = null;

        if ($this->activeShift) {
            $summary = app(ShiftService::class)->getCashSummary($this->activeShift);
            $cashSales = $summary['cash_sales'];
            $expectedCash = $summary['expected_cash'];
            $cashMovements = $this->activeShift->cashDrawerMovements()->with(['createdBy', 'approvedBy'])->latest()->get();
            $handovers = ShiftHandover::where('shift_id', $this->activeShift->id)->with(['fromUser', 'toUser'])->latest()->get();
            $pendingHandover = ShiftHandover::where('shift_id', $this->activeShift->id)->where('status', 'pending')->first();
        }

        $tenantId = app(TenantContext::class)->getTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        $deviceQuery = Device::query();
        if ($tenantId !== null) {
            $deviceQuery->where('tenant_id', $tenantId);
        }
        if ($storeId !== null) {
            $deviceQuery->where('store_id', $storeId);
        }
        $devices = $deviceQuery->where('is_active', true)->get();

        $userQuery = User::query();
        if ($tenantId !== null) {
            $userQuery->where('tenant_id', $tenantId);
        }
        $eligibleCashiers = $userQuery->where('id', '!=', auth()->id())->get();

        return view('livewire.shift-manager', [
            'cashSales' => $cashSales,
            'expectedCash' => $expectedCash,
            'cashMovements' => $cashMovements,
            'handovers' => $handovers,
            'pendingHandover' => $pendingHandover,
            'devices' => $devices,
            'eligibleCashiers' => $eligibleCashiers,
        ]);
    }
}
