<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Modules\Organization\Services\CashierPinService;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Services\ShiftService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait ManagesShiftHandoverAndMovements
{
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

    #[Locked]
    public ?int $activeHandoverId = null;

    public bool $isHandoverModalOpen = false;

    // Force Close state
    public ?int $forceClosingShiftId = null;

    public string $forceCloseReason = '';

    public string $forceCloseSupervisorPin = '';

    public bool $isForceCloseModalOpen = false;

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
            $this->dispatch('toast', message: 'No active register shift to hand over.', type: 'error');

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
            $this->dispatch('toast', message: 'Shift handover initiated successfully.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }

    public function cancelHandover(int $handoverId)
    {
        try {
            app(ShiftService::class)->cancelHandover($handoverId, (int) auth()->id());
            $this->activeHandoverId = null;
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'Shift handover cancelled.', type: 'success');
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
            $this->dispatch('toast', message: 'Shift handover accepted successfully.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }

    public function recordCashMovement(): void
    {
        $this->loadActiveShift();
        if (! $this->activeShift || $this->activeShift->status !== 'open') {
            $this->dispatch('toast', message: 'Cash movements can only be recorded for active register shifts.', type: 'error');

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
                $this->addError('movementSupervisorPin', 'Invalid supervisor PIN.');
                $this->dispatch('toast', message: 'Invalid supervisor PIN.', type: 'error');

                return;
            }
        }

        try {
            $resolvedDeviceId = $this->resolveActiveDeviceId(
                $this->deviceId,
                $this->activeShift->device_id,
                (int) $this->activeShift->store_id,
                (int) $this->activeShift->tenant_id
            );

            app(ShiftService::class)->recordCashMovement(
                $this->activeShift->id,
                $resolvedDeviceId,
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
            $this->dispatch('toast', message: 'Cash movement recorded successfully.', type: 'success');
        } catch (\DomainException|\InvalidArgumentException|ValidationException $e) {
            $msg = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $msg, type: 'error');
        }
    }

    public function forceCloseShift(?string $reason = null, ?string $supPin = null, ?int $shiftId = null)
    {
        $this->loadActiveShift();
        $targetShiftId = $shiftId ?? $this->forceClosingShiftId;
        $targetShift = $targetShiftId ? Shift::find($targetShiftId) : ($this->activeShift ?? Shift::where('status', 'open')->first());
        if (! $targetShift) {
            $this->dispatch('toast', message: 'No active register shift to force close.', type: 'error');

            return;
        }

        Gate::authorize('forceClose', $targetShift);

        $actualReason = $reason ?? $this->forceCloseReason;
        $pinToVerify = $supPin ?? $this->forceCloseSupervisorPin ?: $this->supervisorPin;
        $user = auth()->user();

        if (empty($actualReason)) {
            $this->addError('forceCloseReason', 'Force close reason is required.');
            $this->dispatch('toast', message: 'Force close reason is required.', type: 'error');

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
            $this->dispatch('toast', message: 'Register shift force closed by supervisor.', type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->dispatch('toast', message: $message, type: 'error');
        }
    }
}
