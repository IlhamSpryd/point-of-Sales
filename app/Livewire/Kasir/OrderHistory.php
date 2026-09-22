<?php

declare(strict_types=1);

namespace App\Livewire\Kasir;

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OrderHistory extends Component
{
    use WithPagination;

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
        $this->selectedOrderId = $orderId;
    }

    public function closeDetail(): void
    {
        $this->selectedOrderId = null;
    }

    public function statusBadgeType(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Paid, OrderStatus::Completed => 'success',
            OrderStatus::Pending => 'warning',
            OrderStatus::Failed => 'danger',
            OrderStatus::Cancelled, OrderStatus::Expired => 'secondary',
        };
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

    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        [$start, $end] = $this->resolveDateRange();

        return Order::query()
            ->with([
                'orderItems.product:id,product_name',
                'payments',
                'customer:id,name,phone',
                'user:id,name',
                'table:id,table_name',
            ])
            ->withCount('orderItems')
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

        return Order::query()
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

        return Order::query()
            ->with([
                'orderItems.product',
                'payments',
                'customer.loyaltyAccount.currentTier',
                'user:id,name',
                'table:id,table_name',
                'discount',
                'voidedBy:id,name',
            ])
            ->find($this->selectedOrderId);
    }

    public function render()
    {
        return view('livewire.kasir.order-history');
    }
}
