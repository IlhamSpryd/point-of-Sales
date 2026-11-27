<?php

declare(strict_types=1);

namespace App\Livewire\Kasir;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Livewire\Kasir\Concerns\ManagesOrderReturnsAndRefunds;
use App\Livewire\Kasir\Concerns\ManagesOrderVoidAndReprint;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\TransactionService;
use App\Support\Traits\RequiresActiveStoreContext;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OrderHistory extends Component
{
    use ManagesOrderReturnsAndRefunds;
    use ManagesOrderVoidAndReprint;
    use RequiresActiveStoreContext;
    use RequiresTenantContext;
    use WithPagination;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor', 'Admin'], true)) {
            abort(403, 'Unauthorized access to order history.');
        }
    }

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = 'all';

    #[Url(as: 'periode', history: true)]
    public string $datePreset = 'today';

    #[Url(as: 'dari', history: true)]
    public ?string $startDate = null;

    #[Url(as: 'sampai', history: true)]
    public ?string $endDate = null;

    #[Locked]
    public ?int $selectedOrderId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDatePreset(): void
    {
        if ($this->datePreset !== 'custom') {
            $this->startDate = null;
            $this->endDate = null;
        }
        $this->resetPage();
    }

    public function updatedStartDate(): void
    {
        $this->resetPage();
    }

    public function updatedEndDate(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'datePreset', 'startDate', 'endDate']);
        $this->resetPage();
    }

    public function viewDetail(int $orderId): void
    {
        $this->selectedOrderId = $this->getOrderQuery()->findOrFail($orderId)->id;
    }

    public function closeDetail(): void
    {
        $this->selectedOrderId = null;
    }

    public function syncMidtransStatus(int $orderId): void
    {
        $order = $this->getOrderQuery()->findOrFail($orderId);

        if ($order->order_status !== OrderStatus::Pending) {
            $this->dispatch('toast', message: "Order {$order->order_code} is already {$order->order_status->label()}.", type: 'info');

            return;
        }

        try {
            $updated = app(TransactionService::class)->syncOrderWithMidtrans($order);
            if ($updated->order_status === OrderStatus::Paid) {
                session()->flash('success', "Order {$updated->order_code} payment settled via Midtrans.");
                $this->dispatch('toast', message: "Order {$updated->order_code} payment settled via Midtrans.", type: 'success');
            } elseif ($updated->order_status->isFinal()) {
                $this->dispatch('toast', message: "Order {$updated->order_code} transitioned to {$updated->order_status->label()}.", type: 'info');
            } else {
                $this->dispatch('toast', message: "Order {$updated->order_code} is still awaiting payment.", type: 'info');
            }
        } catch (\Throwable $e) {
            Log::warning('OrderHistory syncMidtransStatus failed: '.$e->getMessage(), ['order_id' => $orderId]);
            $this->dispatch('toast', message: 'Unable to synchronize Midtrans status right now.', type: 'error');
        }
    }

    public function statusBadgeType(OrderStatus $status): string
    {
        return $status->badgeType();
    }

    private function resolveDateRange(): array
    {
        $now = Carbon::now();

        return match ($this->datePreset) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'custom' => [
                $this->startDate ? Carbon::parse($this->startDate)->startOfDay() : $now->copy()->startOfDay(),
                $this->endDate ? Carbon::parse($this->endDate)->endOfDay() : $now->copy()->endOfDay(),
            ],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    #[Computed]
    public function dateRangeLabel(): string
    {
        [$start, $end] = $this->resolveDateRange();

        if ($start->isSameDay($end)) {
            return $start->format('d M Y');
        }

        return $start->format('d M Y').' – '.$end->format('d M Y');
    }

    #[Computed]
    public function statusOptions(): array
    {
        return collect(OrderStatus::cases())
            ->mapWithKeys(fn (OrderStatus $status) => [$status->value => $status->label()])
            ->all();
    }

    private function isOwnerConsolidated(): bool
    {
        return auth()->user()?->role?->name === 'Owner' && session('active_store_id') === 'all';
    }

    private function getOrderQuery()
    {
        return $this->isOwnerConsolidated() ? Order::allStores() : Order::query();
    }

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        [$start, $end] = $this->resolveDateRange();

        $query = $this->getOrderQuery();

        if ($this->isOwnerConsolidated()) {
            $query->with([
                'orderItems' => fn ($q) => $q->allBranchesInTenant()->with('product:id,product_name'),
                'payments' => fn ($q) => $q->allBranchesInTenant(),
                'customer:id,name,phone',
                'user:id,name',
                'table' => fn ($q) => $q->allBranchesInTenant()->select('id', 'table_name'),
                'orderReturns' => fn ($q) => $q->allBranchesInTenant(),
                'paymentRefunds' => fn ($q) => $q->allBranchesInTenant(),
            ])->withCount(['orderItems' => fn ($q) => $q->allBranchesInTenant()]);
        } else {
            $query->with([
                'orderItems.product:id,product_name',
                'payments',
                'customer:id,name,phone',
                'user:id,name',
                'table:id,table_name',
                'orderReturns',
                'paymentRefunds',
            ])->withCount('orderItems');
        }

        return $query
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('order_status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('order_code', 'like', '%'.trim($this->search).'%'))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function summary(): object
    {
        [$start, $end] = $this->resolveDateRange();

        return $this->getOrderQuery()
            ->whereBetween('order_date', [$start->toDateString(), $end->toDateString()])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('order_status', $this->statusFilter))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('order_code', 'like', '%'.trim($this->search).'%'))
            ->selectRaw(
                'COUNT(*) as total_count, COALESCE(SUM(CASE WHEN order_status = ? THEN order_amount ELSE 0 END), 0) as total_paid_amount',
                [OrderStatus::Paid->value]
            )
            ->first();
    }

    #[Computed]
    public function selectedOrder(): ?Order
    {
        if (! $this->selectedOrderId) {
            return null;
        }

        $query = $this->getOrderQuery();

        $withRelations = [
            'orderItems.product.ingredients',
            'orderItems.modifiers',
            'payments.refunds',
            'customer.loyaltyAccount.currentTier',
            'user:id,name',
            'table',
            'discount',
            'voidedBy:id,name',
            'orderReturns.returnItems.orderItem.product',
            'paymentRefunds.approvedBy',
            'paymentRefunds.cashDrawerMovement',
            'device',
            'shift',
        ];

        if ($this->isOwnerConsolidated()) {
            $query->with([
                'orderItems' => fn ($q) => $q->allBranchesInTenant()->with('product.ingredients'),
                'payments' => fn ($q) => $q->allBranchesInTenant(),
                'customer.loyaltyAccount.currentTier',
                'user:id,name',
                'table' => fn ($q) => $q->allBranchesInTenant()->select('id', 'table_name'),
                'discount',
                'voidedBy:id,name',
                'orderReturns.returnItems' => fn ($q) => $q->allBranchesInTenant()->with('orderItem.product'),
                'paymentRefunds' => fn ($q) => $q->allBranchesInTenant(),
                'device',
                'shift',
            ]);
        } else {
            $query->with($withRelations);
        }

        return $query->find($this->selectedOrderId);
    }

    public function render()
    {
        return view('livewire.kasir.order-history');
    }
}
