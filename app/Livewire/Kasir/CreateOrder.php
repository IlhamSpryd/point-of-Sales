<?php

namespace App\Livewire\Kasir;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PreparationStatus;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use App\Services\MenuCacheService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateOrder extends Component
{
    public ?string $orderType = null;   // 'dine_in' | 'takeaway'

    public ?int $tableId = null;

    public array $cart = [];            // payload MENTAH, sama format dgn self-order

    public float $cashReceived = 0;

    public string $search = '';

    public string $paymentMethod = 'cash';

    // Properti baru untuk mode "Retrieve Order"
    public ?string $pendingOrderCode = null;

    public ?int $pendingOrderId = null;

    // state modal pemilihan modifier
    public ?int $selectingProductId = null;

    public array $pendingModifierIds = [];

    public int $pendingQty = 1;

    public ?string $pendingNotes = null;

    public function updatedOrderType(): void
    {
        if ($this->orderType === 'takeaway') {
            $this->tableId = null; // dropdown meja disembunyikan otomatis via Blade
        }
    }

    public function updatedSearch(): void
    {
        // Jika input pencarian adalah Kode Pesanan (dimulai dengan POS-)
        if (str_starts_with(strtoupper($this->search), 'POS-')) {
            $this->loadPendingOrder(strtoupper($this->search));
            $this->search = ''; // bersihkan input
        }
    }

    public function loadPendingOrder(string $orderCode): void
    {
        $order = Order::with('orderItems.product')
            ->where('order_code', $orderCode)
            ->where('order_status', 'pending')
            ->first();

        if (! $order) {
            $this->dispatch('toast', message: 'Pesanan tidak ditemukan atau sudah dibayar.', type: 'error');

            return;
        }

        // Pindahkan data pesanan ke cart kasir
        $this->cart = [];
        foreach ($order->orderItems as $item) {
            $this->cart[] = [
                'product_id' => $item->product_id,
                'qty' => $item->qty,
                'modifier_ids' => collect($item->options)->pluck('modifier_id')->filter()->values()->toArray(),
                'notes' => $item->notes,
            ];
        }

        $this->pendingOrderCode = $order->order_code;
        $this->pendingOrderId = $order->id;
        $this->orderType = $order->order_type?->value;
        $this->tableId = $order->table_id;

        $this->dispatch('toast', message: 'Pesanan '.$orderCode.' berhasil ditarik!', type: 'success');
    }

    public function cancelPendingOrderMode(): void
    {
        $this->pendingOrderCode = null;
        $this->pendingOrderId = null;
        $this->cart = [];
        $this->orderType = null;
        $this->tableId = null;
    }

    #[Computed]
    public function activeTables()
    {
        return Table::where('status', 'active')->orderBy('table_number')->get();
    }

    #[Computed(cache: true, key: 'kasir-categories-catalog')]
    public function categories()
    {
        // OPTIMASI GANDA:
        // 1. MenuCacheService::getCatalog() kini benar-benar pakai Cache::remember (Laravel Cache, TTL 1 jam).
        // 2. #[Computed(cache: true)] mencegah query/cache-lookup diulang dalam siklus request Livewire yang sama.
        return app(MenuCacheService::class)->getCatalog();
    }

    // Modal state reset
    public function openModifierPicker(int $productId): void
    {
        $this->selectingProductId = $productId;
        $this->pendingModifierIds = [];
        $this->pendingQty = 1;
        $this->pendingNotes = null;
    }

    public function closeModifierPicker(): void
    {
        $this->selectingProductId = null;
    }

    public function toggleModifier(int $groupId, int $modifierId, string $selectionType): void
    {
        if ($selectionType === 'single') {
            // Hapus modifier lain yang berada dalam grup yang sama, lalu tambahkan yang baru
            $groupModifierIds = Modifier::where('modifier_group_id', $groupId)->pluck('id')->toArray();
            $this->pendingModifierIds = array_diff($this->pendingModifierIds, $groupModifierIds);
            $this->pendingModifierIds[] = $modifierId;
        } else {
            // Toggle multiple
            if (in_array($modifierId, $this->pendingModifierIds)) {
                $this->pendingModifierIds = array_diff($this->pendingModifierIds, [$modifierId]);
            } else {
                $this->pendingModifierIds[] = $modifierId;
            }
        }
        $this->pendingModifierIds = array_values($this->pendingModifierIds); // reindex
    }

    public function confirmAddToCart(): void
    {
        $this->cart[] = [
            'product_id' => $this->selectingProductId,
            'qty' => $this->pendingQty,
            'modifier_ids' => $this->pendingModifierIds,
            'notes' => $this->pendingNotes,
        ];
        $this->selectingProductId = null;
    }

    public function removeFromCart(int $index): void
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    // READ-ONLY preview -- panggil ulang method Service yang sama persis
    // dengan yang dipakai saat persist, supaya angka di layar TIDAK PERNAH
    // berbeda dari yang tersimpan ke DB nanti.
    #[Computed]
    public function cartLines()
    {
        $service = app(TransactionService::class);

        // OPTIMASI N+1: computed property ini dieksekusi ulang pada SETIAP interaksi
        // Livewire. Versi lama menjalankan Product::findOrFail() SATU KALI PER ITEM
        // keranjang pada SETIAP render -- kasir dengan 5 item bisa memicu puluhan query
        // hanya untuk preview keranjang. Sekarang semua produk relevan diambil dalam
        // SATU query batch, lengkap dengan modifierGroups eager-loaded.
        $productIds = collect($this->cart)->pluck('product_id')->unique()->values();
        $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

        return collect($this->cart)->map(function ($raw, $i) use ($service, $products) {
            $product = $products->get($raw['product_id']) ?? Product::findOrFail($raw['product_id']);

            $line = $service->resolveOrderItemLine(
                $product,
                $raw['modifier_ids'], $raw['qty'], $raw['notes'],
            );

            return [...$line, 'index' => $i];
        });
    }

    #[Computed]
    public function subtotal(): int
    {
        return $this->cartLines->sum('order_subtotal');
    }

    #[Computed]
    public function taxAmount(): int
    {
        return (int) round($this->subtotal * config('pos.tax_rate', 0.11));
    }

    #[Computed]
    public function totalAmount(): int
    {
        return $this->subtotal + $this->taxAmount;
    }

    #[Computed]
    public function changeAmount(): int
    {
        return max(0, (int) $this->cashReceived - $this->totalAmount);
    }

    public function submitOrder()
    {
        $rules = [
            'orderType' => ['required', 'in:dine_in,takeaway'],
            'cart' => ['required', 'array', 'min:1'],
            'cashReceived' => ['required', 'numeric', 'min:0'],
        ];

        // Jika bukan pending order, validasi meja diperlukan untuk dine_in
        // Jika pending order, mungkin meja sudah diset dari awal oleh pelanggan
        if (! $this->pendingOrderId && $this->orderType === 'dine_in') {
            $rules['tableId'] = ['required'];
        }

        $this->validate($rules);

        try {
            DB::beginTransaction();

            $transactionService = app(TransactionService::class);

            // [OMEGA-NODE1] Zero-Trust shift guard, dipusatkan lewat Service
            // agar identik dengan alur TransactionController::store() --
            // sebelumnya cabang "Skenario 2" mengambil shift_id TANPA
            // validasi (bisa NULL diam-diam), dan "Skenario 1" tidak
            // mengambil shift_id SAMA SEKALI.
            $shiftId = $transactionService->resolveOpenShiftOrFail((int) \Illuminate\Support\Facades\Auth::id());

            if ($this->pendingOrderId) {
                // Skenario 2: Membayar Pesanan Self-Order yang tertunda
                $order = Order::findOrFail($this->pendingOrderId);
                $order->forceFill([
                    'payment_method' => PaymentMethod::tryFrom($this->paymentMethod) ?? PaymentMethod::Cash,
                    'cash_received' => $this->paymentMethod === 'cash' ? $this->cashReceived : null,
                    'order_change' => $this->paymentMethod === 'cash' ? ($this->cashReceived - $order->order_amount) : 0,
                    'order_status' => OrderStatus::Paid,
                    'shift_id' => $shiftId,
                ])->save();

                // Ubah status KDS menjadi Pending (masuk dapur)
                $order->orderItems()->update(['preparation_status' => PreparationStatus::Pending->value]);
            } else {
                // Skenario 1: Pesanan Walk-in Baru (Logic Lama)
                // [OMEGA-NODE1] FIX KRITIS: userId & shiftId sekarang WAJIB
                // dioper eksplisit -- lihat TransactionService::createWalkInOrder().
                $order = $transactionService->createWalkInOrder(
                    itemsPayload: $this->cart,
                    orderType: OrderType::from($this->orderType),
                    tableId: $this->tableId,
                    paymentMethod: $this->paymentMethod,
                    cashReceived: (int) $this->cashReceived,
                    userId: (int) \Illuminate\Support\Facades\Auth::id(),
                    shiftId: $shiftId,
                );
            }

            DB::commit();

            session()->flash('success', "Order {$order->order_code} berhasil diproses.");

            return redirect()->route('transaction.receipt', $order->order_code);

        } catch (\Exception $e) {
            DB::rollBack();
            if ($e instanceof ValidationException) {
                $this->addError('cart', collect($e->errors())->flatten()->first());
            } else {
                $this->addError('cart', $e->getMessage());
            }

            return;
        }
    }

    public function render()
    {
        // Resolve selectingProduct for the modal if present
        $selectingProduct = null;
        if ($this->selectingProductId) {
            $selectingProduct = Product::with('modifierGroups.modifiers')->find($this->selectingProductId);
        }

        return view('livewire.kasir.create-order', compact('selectingProduct'));
    }
}
