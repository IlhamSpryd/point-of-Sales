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
        try {
            app(KdsService::class)->claim($itemId, (int) Auth::id());
        } catch (ValidationException $e) {
            $this->addError('kds', collect($e->errors())->flatten()->first());
        }
    }

    public function markReady(int $itemId): void
    {
        try {
            app(KdsService::class)->markReady($itemId, (int) Auth::id());
        } catch (ValidationException $e) {
            $this->addError('kds', collect($e->errors())->flatten()->first());
        }
    }

    public function release(int $itemId): void
    {
        try {
            app(KdsService::class)->release(
                $itemId,
                (int) Auth::id(),
                canManage: in_array(Auth::user()->role?->name, ['Owner', 'Manager'], true),
            );
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
            ->whereDate('order_items.updated_at', today())
            ->orderByDesc('order_items.updated_at')
            ->limit(12)
            ->get();
    }

    /**
     * Zero-Trust gate: HANYA tampilkan item dari order yang sudah LUNAS
     * (paid). Menutup celah dapur menyiapkan pesanan self-order QRIS/E-Wallet
     * yang secara teknis belum benar-benar dibayar (masih menunggu callback
     * atau sync manual Midtrans di /api/orders/{id}/sync-status).
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
