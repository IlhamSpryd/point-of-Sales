<?php

declare(strict_types=1);

namespace App\Livewire\Kasir;

use App\Enums\OrderStatus;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Order;
use App\Models\Scopes\StoreScope;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class OrderHistory extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Waiter', 'Supervisor', 'Admin'], true)) {
            abort(403, 'Anda tidak memiliki hak akses riwayat pesanan.');
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

    public string $voidReason = '';

    public ?int $voidingOrderId = null;

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

    public function voidOrder(?int $orderId = null, ?string $voidReason = null): void
    {
        $targetOrderId = $orderId ?? $this->voidingOrderId;
        if (! $targetOrderId) {
            return;
        }

        $reason = trim((string) ($voidReason ?? $this->voidReason));

        if (strlen($reason) < 5) {
            $this->addError('voidReason', 'Alasan void harus minimal 5 karakter.');

            return;
        }

        $order = $this->getOrderQuery()->findOrFail($targetOrderId);
        // [D2-BE-004] Otorisasi policy void: tenant/store match + peran berwenang.
        Gate::authorize('void', $order);
        if (! $order || $order->order_status !== OrderStatus::Paid) {
            session()->flash('error', 'Pesanan tidak ditemukan atau tidak dalam status Lunas.');
            $this->dispatch('toast', message: 'Pesanan tidak ditemukan atau tidak dalam status Lunas.', type: 'error');

            return;
        }

        try {
            app(TransactionService::class)->voidOrder($order, $reason, Auth::id());
            session()->flash('success', 'Pesanan berhasil di-void dan stok dikembalikan.');
            $this->dispatch('toast', message: 'Pesanan berhasil di-void dan stok dikembalikan.', type: 'success');
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        } catch (\Throwable $e) {
            Log::error('Void order failed: '.$e->getMessage(), [
                'exception' => $e,
                'order_id' => $targetOrderId,
                'user_id' => Auth::id(),
            ]);
            session()->flash('error', 'Terjadi kesalahan sistem saat memproses pembatalan pesanan. Silakan coba beberapa saat lagi.');
            $this->dispatch('toast', message: 'Terjadi kesalahan sistem saat memproses pembatalan pesanan. Silakan coba beberapa saat lagi.', type: 'error');
        }

        $this->voidingOrderId = null;
        $this->voidReason = '';
        if ($this->selectedOrderId === $order->id) {
            $this->closeDetail();
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
                'orderItems' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->with('product:id,product_name'),
                'payments' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
                'customer:id,name,phone',
                'user:id,name',
                'table' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->select('id', 'table_name'),
            ])->withCount(['orderItems' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)]);
        } else {
            $query->with([
                'orderItems.product:id,product_name',
                'payments',
                'customer:id,name,phone',
                'user:id,name',
                'table:id,table_name',
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

        if ($this->isOwnerConsolidated()) {
            $query->with([
                'orderItems' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->with('product'),
                'payments' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
                'customer.loyaltyAccount.currentTier',
                'user:id,name',
                'table' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->select('id', 'table_name'),
                'discount',
                'voidedBy:id,name',
            ]);
        } else {
            $query->with([
                'orderItems.product',
                'payments',
                'customer.loyaltyAccount.currentTier',
                'user:id,name',
                'table:id,table_name',
                'discount',
                'voidedBy:id,name',
            ]);
        }

        return $query->find($this->selectedOrderId);
    }

    public function render()
    {
        return view('livewire.kasir.order-history');
    }
}
