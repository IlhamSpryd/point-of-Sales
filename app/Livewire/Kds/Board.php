<?php

declare(strict_types=1);

namespace App\Livewire\Kds;

use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Models\OrderItem;
use App\Services\KdsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Board extends Component
{
    public function claim(int $itemId): void
    {
        $this->guard(fn () => app(KdsService::class)->claim($itemId, (int) Auth::id()));
    }

    public function markReady(int $itemId): void
    {
        $this->guard(fn () => app(KdsService::class)->markReady($itemId, (int) Auth::id()));
    }

    public function release(int $itemId): void
    {
        $this->guard(fn () => app(KdsService::class)->release(
            $itemId,
            (int) Auth::id(),
            canManage: in_array(Auth::user()->role?->name, ['Owner', 'Manager'], true),
        ));
    }

    public function dismissError(): void
    {
        $this->resetErrorBag('kds');
    }

    /** Bersihkan error lama sebelum setiap aksi, agar banner tidak menempel selamanya. */
    private function guard(callable $action): void
    {
        $this->resetErrorBag('kds');

        try {
            $action();
        } catch (ValidationException $e) {
            $this->addError('kds', collect($e->errors())->flatten()->first());
        }
    }

    #[Computed]
    public function pendingItems()
    {
        return $this->baseQuery()
            ->where('preparation_status', PreparationStatus::Pending->value)
            ->orderBy('order_items.created_at')
            ->get();
    }

    #[Computed]
    public function brewingItems()
    {
        return $this->baseQuery()
            ->with('processedBy')
            ->where('preparation_status', PreparationStatus::Brewing->value)
            ->orderBy('order_items.updated_at')
            ->get();
    }

    #[Computed]
    public function readyItems()
    {
        return $this->baseQuery()
            ->with('processedBy')
            ->where('preparation_status', PreparationStatus::Ready->value)
            // whereBetween tetap sargable dan memakai index (preparation_status, updated_at).
            ->whereBetween('order_items.updated_at', [today(), today()->endOfDay()])
            ->orderByDesc('order_items.updated_at')
            ->limit(12)
            ->get();
    }

    /**
     * Zero-Trust gate: HANYA item dari order LUNAS (paid) yang tampil di dapur.
     */
    private function baseQuery(): Builder
    {
        return OrderItem::query()
            ->with(['product', 'order.table'])
            ->whereHas('order', fn ($q) => $q->where('order_status', OrderStatus::Paid->value));
    }

    public function render()
    {
        return view('livewire.kds.board');
    }
}
