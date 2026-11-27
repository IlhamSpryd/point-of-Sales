<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Core\Context\TenantContext;
use App\Livewire\Concerns\ManagesShiftHandoverAndMovements;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Modules\Organization\Models\Device;
use App\Modules\Organization\Models\User;
use App\Modules\Organization\Services\CashierPinService;
use App\Modules\Sales\Models\Shift;
use App\Modules\Sales\Models\ShiftHandover;
use App\Modules\Sales\Services\ShiftService;
use App\Support\Traits\RequiresActiveStoreContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ShiftManager extends Component
{
    use ManagesShiftHandoverAndMovements;
    use RequiresActiveStoreContext;
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

    protected function allowsSuspendedTenantAccess(): bool
    {
        $refererPath = trim((string) parse_url((string) request()->headers->get('referer', ''), PHP_URL_PATH), '/');

        return request()->is('*shifts/*/close*', '*shifts/close*', '*kasir/shift/close*')
            || str_contains($refererPath, 'shift');
    }

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = auth()->user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Supervisor'], true)) {
            abort(403, 'You do not have permission to manage register shifts.');
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
            $this->dispatch('toast', message: 'Cashiers must set a PIN before opening a register shift.', type: 'error');
            $this->dispatch('require-pin-setup');

            return;
        }

        if (! empty($pinToVerify)) {
            if (! app(CashierPinService::class)->verifyPin($user, $pinToVerify)) {
                $this->addError('cashierPin', 'Invalid cashier PIN.');
                $this->dispatch('toast', message: 'Invalid cashier PIN.', type: 'error');

                return;
            }
        }

        $this->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $storeId = $this->resolveActiveStoreId(app(TenantContext::class)->getStoreId(), $tenantId);
            $shiftService = app(ShiftService::class);
            $devId = $this->selectedDeviceId ?? $this->deviceId;

            if ($devId !== null && ! empty($pinToVerify)) {
                $opened = $shiftService->openShift(
                    $tenantId,
                    $storeId,
                    $devId,
                    (int) $user->id,
                    (int) $this->opening_balance,
                    $pinToVerify
                );
            } else {
                $opened = $shiftService->openShift((int) $user->id, (int) $this->opening_balance);
            }
        } catch (\DomainException $e) {
            $this->addError('selectedDeviceId', $e->getMessage());
            $this->addError('deviceId', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');

            return;
        } catch (ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first();
            $this->addError('selectedDeviceId', $msg);
            $this->addError('deviceId', $msg);
            $this->dispatch('toast', message: $msg, type: 'error');

            return;
        }

        if ($opened === null) {
            $this->loadActiveShift();
            $this->dispatch('toast', message: 'You already have an active register shift.', type: 'info');

            return;
        }

        $this->cashierPin = '';
        $this->loadActiveShift();
        $this->dispatch('toast', message: 'Register shift opened successfully.', type: 'success');
    }

    public function setCashierPin(string $pin): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(CashierPinService::class)->setPin($user, $pin);
            $this->dispatch('toast', message: 'Cashier PIN updated successfully.', type: 'success');
        } catch (\InvalidArgumentException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }
    }

    public function closeShift(?string $pin = null)
    {
        $this->loadActiveShift();

        if (! $this->activeShift) {
            $this->dispatch('toast', message: 'No active register shift to close.', type: 'info');

            return;
        }

        Gate::authorize('close', $this->activeShift);

        $pinToVerify = $pin ?? $this->cashierPin;
        $user = auth()->user();

        if (! empty($pinToVerify)) {
            if (! app(CashierPinService::class)->verifyPin($user, $pinToVerify, $this->activeShift)) {
                $this->addError('cashierPin', 'Invalid cashier PIN.');
                $this->dispatch('toast', message: 'Invalid cashier PIN.', type: 'error');

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
            $this->dispatch('toast', message: 'No active register shift to close.', type: 'info');

            return;
        }

        $this->opening_balance = 0;
        $this->closing_balance = 0;
        $this->notes = '';
        $this->cashierPin = '';

        $this->dispatch('toast', message: 'Register shift closed successfully.', type: 'success');
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

        $occupiedQuery = Shift::whereIn('status', ['open', 'handover_pending', 'closing'])
            ->whereNotNull('device_id');
        if ($tenantId !== null) {
            $occupiedQuery->where('tenant_id', $tenantId);
        }
        if ($storeId !== null) {
            $occupiedQuery->where('store_id', $storeId);
        }
        $occupiedDeviceIds = $occupiedQuery->pluck('device_id')->map(fn ($id) => (int) $id)->toArray();

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
            'occupiedDeviceIds' => $occupiedDeviceIds,
            'eligibleCashiers' => $eligibleCashiers,
        ]);
    }
}
