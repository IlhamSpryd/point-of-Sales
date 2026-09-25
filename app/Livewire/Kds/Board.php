<?php

declare(strict_types=1);

namespace App\Livewire\Kds;

use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Models\OrderItem;
use App\Services\KdsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('kds.index')]
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

    public function recall(int $itemId): void
    {
        $this->guard(fn () => app(KdsService::class)->recall(
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
        } catch (ModelNotFoundException) {
            // Item sudah dihapus/ID sudah tidak valid saat aksi diproses.
            $this->addError('kds', 'Item ini sudah tidak ada. Layar akan diperbarui otomatis.');
        } catch (QueryException $e) {
            // Pola yang SAMA sudah terbukti terjadi di TransactionConcurrencyTest
            // untuk lockForUpdate() lain di codebase ini -- lock wait timeout NYATA
            // bisa terjadi di beban concurrent tinggi, bukan hipotesis.
            report($e);
            $this->addError('kds', 'Sistem sedang sibuk, silakan coba lagi dalam beberapa detik.');
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
            // Menggunakan rolling window 12 jam. Menyelesaikan bug blind window UTC/WIB
            // untuk shift tengah malam tanpa membebani query planner.
            ->where('order_items.updated_at', '>=', now()->subHours(12))
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
