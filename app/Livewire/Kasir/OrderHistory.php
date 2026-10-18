<?php

declare(strict_types=1);

namespace App\Livewire\Kasir;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Order;
use App\Models\OrderReturnItem;
use App\Models\PaymentAttempt;
use App\Models\PaymentRefund;
use App\Models\Scopes\StoreScope;
use App\Models\Shift;
use App\Services\CashierPinService;
use App\Services\Printing\ReceiptPrinterService;
use App\Services\ReturnRefundService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public string $supervisorPin = '';

    // === RETURN & REFUND MODAL STATE ===
    public bool $showReturnModal = false;

    public ?int $returningOrderId = null;

    public array $returnItems = [];

    public array $refundAllocations = [];

    public string $returnReason = '';

    public string $returnSupervisorPin = '';

    public ?string $returnErrorMessage = null;

    // === REPRINT MODAL STATE ===
    public bool $showReprintModal = false;

    public ?int $reprintingOrderId = null;

    public string $reprintReason = '';

    public string $reprintSupervisorPin = '';

    public int $reprintOrderPrintCount = 0;

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

    public function voidOrder(?int $orderId = null, ?string $voidReason = null, ?string $pin = null): void
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

        // Safeguard: Periksa apakah ada PaymentAttempt yang berstatus pending/timeout (AC-2.4)
        $hasUnresolvedAttempts = PaymentAttempt::where('order_id', $order->id)
            ->whereIn('status', [
                PaymentAttemptStatus::PendingProvider,
                PaymentAttemptStatus::UnknownTimeout,
            ])->exists();

        if ($hasUnresolvedAttempts) {
            $this->addError('voidReason', 'Pesanan memiliki pembayaran elektronik yang sedang dalam proses verifikasi (pending/timeout). Lakukan rekonsiliasi pembayaran terlebih dahulu sebelum membatalkan (void).');
            $this->dispatch('toast', message: 'Pesanan memiliki pembayaran elektronik yang sedang dalam proses verifikasi (pending/timeout). Lakukan rekonsiliasi pembayaran terlebih dahulu sebelum membatalkan (void).', type: 'error');

            return;
        }

        $inputPin = $pin ?? $this->supervisorPin;
        $user = Auth::user();

        if ($user && ! empty($inputPin)) {
            $pinValid = false;
            try {
                $pinValid = app(CashierPinService::class)->verifySupervisorPin((int) $user->id, (string) $inputPin, (int) $order->tenant_id);
            } catch (\Throwable $e) {
                $pinValid = app(CashierPinService::class)->verifyPin($user, (string) $inputPin);
            }

            if (! $pinValid) {
                $this->addError('supervisorPin', 'PIN Supervisor tidak sah.');
                $this->dispatch('toast', message: 'PIN Supervisor tidak sah.', type: 'error');

                return;
            }
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
        $this->supervisorPin = '';
        if ($this->selectedOrderId === $order->id) {
            $this->closeDetail();
        }
    }

    // === RETURN & REFUND ORCHESTRATION ===

    public function openReturnModal(int $orderId): void
    {
        $this->returningOrderId = $orderId;
        $order = $this->getOrderQuery()
            ->with(['orderItems.product.ingredients', 'payments'])
            ->findOrFail($orderId);

        $this->returnItems = [];
        foreach ($order->orderItems as $item) {
            $alreadyReturned = (int) OrderReturnItem::withoutGlobalScopes()
                ->where('order_item_id', $item->id)
                ->sum('quantity_returned');
            $remaining = max(0, (int) $item->qty - $alreadyReturned);
            $isRecipe = $item->product && $item->product->ingredients && $item->product->ingredients->isNotEmpty();

            $this->returnItems[$item->id] = [
                'order_item_id' => $item->id,
                'product_name' => $item->product->product_name ?? 'Produk',
                'unit_price' => (float) $item->order_price,
                'original_qty' => (int) $item->qty,
                'already_returned_qty' => $alreadyReturned,
                'remaining_qty' => $remaining,
                'selected' => $remaining > 0,
                'qty' => $remaining,
                'disposition' => $isRecipe ? 'write_off_waste' : 'restock',
                'disposition_reason' => '',
                'is_recipe_product' => (bool) $isRecipe,
            ];
        }

        $this->refundAllocations = [];
        foreach ($order->payments as $payment) {
            $alreadyRefunded = (float) PaymentRefund::withoutGlobalScopes()
                ->where('payment_id', $payment->id)
                ->whereNotIn('request_status', [PaymentRefund::REQUEST_FAILED, PaymentRefund::REQUEST_CANCELLED])
                ->sum('amount');
            $remainingRefundable = max(0.00, (float) $payment->amount - $alreadyRefunded);
            $origMethod = is_string($payment->payment_method) ? $payment->payment_method : $payment->payment_method->value;

            $this->refundAllocations[$payment->id] = [
                'payment_id' => $payment->id,
                'orig_method' => $origMethod,
                'refund_method' => $origMethod,
                'amount' => (int) $remainingRefundable,
                'max_amount' => (int) $remainingRefundable,
                'is_method_override' => false,
                'method_override_reason' => '',
            ];
        }

        $this->returnReason = '';
        $this->returnSupervisorPin = '';
        $this->returnErrorMessage = null;
        $this->showReturnModal = true;
    }

    public function closeReturnModal(): void
    {
        $this->showReturnModal = false;
        $this->returningOrderId = null;
        $this->returnItems = [];
        $this->refundAllocations = [];
        $this->returnReason = '';
        $this->returnSupervisorPin = '';
        $this->returnErrorMessage = null;
    }

    public function processReturn(ReturnRefundService $returnRefundService): void
    {
        if (! $this->returningOrderId) {
            return;
        }

        $this->returnErrorMessage = null;
        $order = $this->getOrderQuery()->findOrFail($this->returningOrderId);

        $selectedItems = collect($this->returnItems)->filter(fn ($item) => ! empty($item['selected']) && (int) $item['qty'] > 0)->values()->all();

        if (empty($selectedItems)) {
            $this->returnErrorMessage = 'Pilih minimal satu item untuk diretur.';

            return;
        }

        foreach ($selectedItems as $item) {
            if (! empty($item['is_recipe_product']) && $item['disposition'] === 'restock') {
                $this->returnErrorMessage = "Larangan Restock: Produk F&B berbasis resep ('{$item['product_name']}') tidak boleh direstock.";

                return;
            }
        }

        if (strlen(trim($this->returnReason)) < 5) {
            $this->returnErrorMessage = 'Alasan retur/refund harus minimal 5 karakter.';

            return;
        }

        if (empty($this->returnSupervisorPin)) {
            $this->returnErrorMessage = 'PIN Supervisor/Manager wajib diisi untuk otorisasi retur/refund.';

            return;
        }

        $activeShift = Shift::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('store_id', $order->store_id)
            ->where('status', 'open')
            ->first();

        $shiftId = $activeShift ? $activeShift->id : (int) $order->shift_id;
        $deviceId = (int) ($order->created_by_device_id ?? 1);

        $returnItemsInput = array_map(function ($item) {
            return [
                'order_item_id' => (int) $item['order_item_id'],
                'quantity' => (int) $item['qty'],
                'disposition' => $item['disposition'],
                'disposition_reason' => $item['disposition_reason'] ?? '',
            ];
        }, $selectedItems);

        $allocationsInput = [];
        foreach ($this->refundAllocations as $alloc) {
            if ((float) $alloc['amount'] > 0) {
                $isOverride = ($alloc['orig_method'] !== 'cash' && $alloc['refund_method'] === 'cash');
                $allocationsInput[] = [
                    'payment_id' => (int) $alloc['payment_id'],
                    'amount' => (float) $alloc['amount'],
                    'refund_method' => $alloc['refund_method'],
                    'is_method_override' => $isOverride,
                    'method_override_reason' => $alloc['method_override_reason'] ?? ($isOverride ? 'Cash Refund Exception' : null),
                ];
            }
        }

        if (empty($allocationsInput)) {
            $this->returnErrorMessage = 'Nominal refund harus lebih dari 0.';

            return;
        }

        try {
            $idempotencyKey = 'ret-'.Str::uuid()->toString();
            $orderReturn = $returnRefundService->createReturnAndRefund(
                $order,
                $returnItemsInput,
                $allocationsInput,
                $shiftId,
                $deviceId,
                (int) Auth::id(),
                $this->returnSupervisorPin,
                $this->returnReason,
                $idempotencyKey
            );

            $this->showReturnModal = false;
            $this->returningOrderId = null;
            session()->flash('success', "Retur #{$orderReturn->return_number} dan refund berhasil diproses.");
            $this->dispatch('toast', message: "Retur #{$orderReturn->return_number} dan refund berhasil diproses.", type: 'success');
        } catch (\DomainException|ValidationException $e) {
            $this->returnErrorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            Log::error('Process return failed: '.$e->getMessage(), ['exception' => $e]);
            $this->returnErrorMessage = 'Terjadi kesalahan sistem saat memproses retur/refund: '.$e->getMessage();
        }
    }

    // === REPRINT ORCHESTRATION ===

    public function requestReprint(int $orderId): void
    {
        $order = $this->getOrderQuery()->findOrFail($orderId);
        $printCount = (int) $order->print_count;

        if ($printCount === 0) {
            try {
                app(ReceiptPrinterService::class)->generatePrintPayload(
                    $order,
                    (int) Auth::id(),
                    $order->created_by_device_id,
                    $order->shift_id
                );
            } catch (\Throwable $e) {
                // Ignore fallback to normal receipt view
            }
            $this->redirect(route('transaction.receipt', ['orderCode' => $order->order_code]), navigate: false);

            return;
        }

        $this->reprintingOrderId = $orderId;
        $this->reprintOrderPrintCount = $printCount;
        $this->reprintReason = '';
        $this->reprintSupervisorPin = '';
        $this->showReprintModal = true;
    }

    public function closeReprintModal(): void
    {
        $this->showReprintModal = false;
        $this->reprintingOrderId = null;
        $this->reprintReason = '';
        $this->reprintSupervisorPin = '';
    }

    public function confirmReprint(ReceiptPrinterService $printerService): void
    {
        if (! $this->reprintingOrderId) {
            return;
        }

        if (strlen(trim($this->reprintReason)) < 5) {
            $this->addError('reprintReason', 'Alasan cetak ulang minimal 5 karakter.');

            return;
        }

        $order = $this->getOrderQuery()->findOrFail($this->reprintingOrderId);
        $newSequence = (int) $order->print_count + 1;

        if ($newSequence > 2 && empty($this->reprintSupervisorPin)) {
            $this->addError('reprintSupervisorPin', 'PIN Supervisor diperlukan untuk cetak ulang ke-3 atau lebih.');

            return;
        }

        try {
            $printerService->generatePrintPayload(
                $order,
                (int) Auth::id(),
                $order->created_by_device_id,
                $order->shift_id,
                $this->reprintReason,
                $this->reprintSupervisorPin ?: null
            );

            $this->showReprintModal = false;
            $this->reprintingOrderId = null;
            $this->redirect(route('transaction.receipt', ['orderCode' => $order->order_code, 'is_reprint' => 1]), navigate: false);
        } catch (ValidationException $e) {
            $this->addError('reprintSupervisorPin', $e->getMessage());
        } catch (\Throwable $e) {
            $this->addError('reprintReason', $e->getMessage());
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
                'orderReturns' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
                'paymentRefunds' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
            ])->withCount(['orderItems' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)]);
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
            'orderReturns.items.orderItem.product',
            'paymentRefunds.approvedByUser',
            'paymentRefunds.cashDrawerMovement',
            'device',
            'shift',
        ];

        if ($this->isOwnerConsolidated()) {
            $query->with([
                'orderItems' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->with('product.ingredients'),
                'payments' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
                'customer.loyaltyAccount.currentTier',
                'user:id,name',
                'table' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->select('id', 'table_name'),
                'discount',
                'voidedBy:id,name',
                'orderReturns.items' => fn ($q) => $q->withoutGlobalScope(StoreScope::class)->with('orderItem.product'),
                'paymentRefunds' => fn ($q) => $q->withoutGlobalScope(StoreScope::class),
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
