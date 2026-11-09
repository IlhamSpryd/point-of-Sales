<?php

declare(strict_types=1);

namespace App\Livewire\Kds;

use App\Enums\KitchenStation;
use App\Enums\OrderStatus;
use App\Enums\PreparationStatus;
use App\Livewire\Concerns\RequiresTenantContext;
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
    use RequiresTenantContext;

    public string $stationFilter = 'all';

    public ?int $tenantId = null;

    public ?int $storeId = null;

    public function boot(): void
    {
        $this->bootRequiresTenantContext();

        $role = Auth::user()?->role;
        $roleName = $role?->name;
        if (! $role?->is_admin && ! in_array($roleName, ['Owner', 'Manager', 'Kasir', 'Barista', 'Waiter', 'Cook', 'Supervisor', 'Admin'], true)) {
            abort(403, 'Anda tidak memiliki hak akses KDS.');
        }

        $context = app(\App\Services\Context\TenantContext::class);
        $user = Auth::user();
        $this->tenantId = $context->getTenantId() ?? (int) $user->tenant_id;
        $this->storeId = $context->getStoreId();
    }

    /**
     * Listen for real-time WebSocket events per DEC-001 tenant & store channel.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $tenantId = $this->tenantId ?? (int) Auth::user()?->tenant_id;
        $storeId = $this->storeId ?? 0;

        $listeners = [
            'refreshKds' => '$refresh',
        ];

        if ($tenantId > 0 && $storeId > 0) {
            $listeners["echo-private:tenant.{$tenantId}.store.{$storeId}.kds,.kds.item.updated"] = '$refresh';
            $listeners["echo-private:tenant.{$tenantId}.store.{$storeId}.kds,kds.item.updated"] = '$refresh';
        }

        if ($tenantId > 0) {
            $listeners["echo-private:tenant.{$tenantId}.kds,.kds.item.updated"] = '$refresh';
            $listeners["echo-private:tenant.{$tenantId}.kds,kds.item.updated"] = '$refresh';
        }

        return $listeners;
    }

    public function claim(int $itemId): void
    {
        $this->ensureCanProcess();
        $this->guard(fn () => app(KdsService::class)->claim($itemId, (int) Auth::id()));
    }

    public function markReady(int $itemId): void
    {
        $this->ensureCanProcess();
        $this->guard(fn () => app(KdsService::class)->markReady($itemId, (int) Auth::id()));
    }

    private function ensureCanProcess(): void
    {
        $roleName = Auth::user()->role?->name;
        if (! in_array($roleName, ['Owner', 'Manager', 'Barista', 'Cook'], true)) {
            abort(403, 'Anda tidak memiliki akses untuk memproses pesanan di dapur.');
        }
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

    public function setStationFilter(string $station): void
    {
        $normalized = strtolower(trim($station));
        $this->stationFilter = in_array($normalized, ['kitchen', 'bar', 'dessert'], true) ? $normalized : 'all';
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
        $query = OrderItem::query()
            ->with(['product.category', 'order.table'])
            ->whereHas('order', fn ($q) => $q->where('order_status', OrderStatus::Paid->value));

        if ($this->stationFilter !== 'all') {
            $station = $this->stationFilter;
            $query->where(function ($filterQuery) use ($station) {
                // 1. Explicit product kitchen_station override
                $filterQuery->whereHas('product', fn ($p) => $p->where('kitchen_station', $station))
                    // 2. Fallback to product category kitchen_station
                    ->orWhere(function ($fallback) use ($station) {
                        $fallback->whereHas('product', function ($p) {
                            $p->whereNull('kitchen_station')
                                ->orWhere('kitchen_station', KitchenStation::None->value);
                        })->whereHas('product.category', function ($c) use ($station) {
                            $c->where('kitchen_station', $station);
                        });
                    });

                // Default orphan items without category to kitchen
                if ($station === 'kitchen') {
                    $filterQuery->orWhere(function ($orphan) {
                        $orphan->whereHas('product', function ($p) {
                            $p->whereNull('kitchen_station')
                                ->orWhere('kitchen_station', KitchenStation::None->value);
                        })->whereDoesntHave('product.category');
                    });
                }
            });
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.kds.board');
    }
}
