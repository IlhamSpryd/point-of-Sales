<?php

// [OMEGA-NODE2] Refactor Kiosk Kasir: dukungan pemilihan pelanggan (loyalti)
// dan Split Payment exact-sum via TransactionService::createTransaction().
// Skenario "Tarik Pesanan" TETAP memakai pembayaran tunggal lama -- lihat
// SYNC ALERT Node 1 untuk rencana migrasinya ke split payment. | 2026-09-22

namespace App\Livewire\Kasir;

use App\Enums\PaymentMethod;
use App\Enums\PaymentMethodEnum;
use App\Models\Customer;
use App\Models\Modifier;
use App\Models\Order;
use App\Models\Product;
use App\Models\Table;
use App\Services\MenuCacheService;
use App\Services\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateOrder extends Component
{
    public ?string $orderType = null;   // 'dine_in' | 'takeaway'

    public ?int $tableId = null;

    public array $cart = [];            // payload MENTAH, sama format dgn self-order

    public float $cashReceived = 0;     // HANYA dipakai skenario Tarik Pesanan (pembayaran tunggal)

    public string $search = '';

    public string $paymentMethod = 'cash'; // HANYA dipakai skenario Tarik Pesanan

    // Properti baru untuk mode "Retrieve Order"
    public ?string $pendingOrderCode = null;

    public ?int $pendingOrderId = null;

    // state modal pemilihan modifier
    public ?int $selectingProductId = null;

    public array $pendingModifierIds = [];

    public int $pendingQty = 1;

    public ?string $pendingNotes = null;

    // [OMEGA-NODE2] Customer selection (Loyalty) -- HANYA berlaku untuk
    // skenario Walk-in Baru (lihat SYNC ALERT #3 di Phase 1 untuk alasan
    // skenario Tarik Pesanan belum mendukung ini).
    public ?int $customerId = null;

    public ?string $customerName = null;

    public string $customerSearch = '';

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
            ->where('payment_method', 'cash')
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

        // [OMEGA-NODE2] Skenario ini tidak mendukung customer_id/loyalti --
        // pastikan tidak ada sisa pilihan pelanggan dari sesi sebelumnya.
        $this->customerId = null;
        $this->customerName = null;

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
        // [OMEGA-NODE2] Hygiene fix (lihat Phase 1 self-adversarial review):
        // customer terpilih tidak boleh ikut terbawa ke pesanan berikutnya.
        $this->customerId = null;
        $this->customerName = null;
    }

    #[Computed]
    public function activeTables()
    {
        return Table::where('is_active', true)->orderBy('table_name')->get();
    }

    #[Computed]
    public function categories()
    {
        // OPTIMASI:
        // 1. MenuCacheService::getCatalog() pakai Cache::remember (Laravel Cache, TTL 1 jam).
        // 2. #[Computed] mencegah query/cache-lookup diulang dalam siklus request Livewire yang sama.
        return app(MenuCacheService::class)->getCatalog();
    }

    // [OMEGA-NODE2] Pencarian pelanggan untuk Loyalty. Query DB tetap
    // dipertahankan server-side (tidak bisa full-client) tapi dibatasi
    // debounce 300ms + minimal 2 karakter di sisi Blade agar sesuai
    // prinsip Zero-Latency Concurrency sebisa mungkin.
    #[Computed]
    public function customerResults()
    {
        $q = trim($this->customerSearch);

        if (mb_strlen($q) < 2) {
            return collect();
        }

        return Customer::query()
            ->where('is_active', true)
            ->where(fn ($qq) => $qq->where('name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'phone']);
    }

    public function selectCustomer(int $id, string $name): void
    {
        if (! Customer::where('id', $id)->where('is_active', true)->exists()) {
            return;
        }

        $this->customerId = $id;
        $this->customerName = $name;
        $this->customerSearch = '';
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->customerName = null;
    }

    // Modal state reset
    public function openModifierPicker(int $productId): void
    {
        $this->selectingProductId = $productId;
        $this->pendingModifierIds = [];

        // Pre-select default modifiers
        $product = Product::with('modifierGroups.modifiers')->find($productId);
        if ($product) {
            foreach ($product->modifierGroups as $group) {
                foreach ($group->modifiers as $mod) {
                    if ($mod->is_default) {
                        $this->pendingModifierIds[] = $mod->id;
                        if ($group->selection_type === 'single') {
                            break; // only select the first default for single selection
                        }
                    }
                }
            }
        }

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
        // Try to find an identical item to group with
        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $this->selectingProductId &&
                $item['modifier_ids'] === $this->pendingModifierIds &&
                $item['notes'] === $this->pendingNotes
            ) {
                $this->cart[$index]['qty'] += $this->pendingQty;
                $this->selectingProductId = null;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $this->selectingProductId,
            'qty' => $this->pendingQty,
            'modifier_ids' => $this->pendingModifierIds,
            'notes' => $this->pendingNotes,
        ];
        $this->selectingProductId = null;
    }

    public function addToCartDirectly(int $productId): void
    {
        // Group with identical item if exists
        foreach ($this->cart as $index => $item) {
            if (
                $item['product_id'] === $productId &&
                empty($item['modifier_ids']) &&
                empty($item['notes'])
            ) {
                $this->cart[$index]['qty']++;

                return;
            }
        }

        $this->cart[] = [
            'product_id' => $productId,
            'qty' => 1,
            'modifier_ids' => [],
            'notes' => null,
        ];
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
        // HANYA relevan untuk skenario Tarik Pesanan (pembayaran tunggal).
        return max(0, (int) $this->cashReceived - $this->totalAmount);
    }

    /**
     * [OMEGA-NODE2] Menerima $legs dari Alpine (Split Payment modal) untuk
     * skenario Walk-in Baru. Skenario Tarik Pesanan tetap dilayani via
     * $this->paymentMethod/$this->cashReceived seperti sebelumnya --
     * $legs diabaikan sepenuhnya di cabang itu (lihat SYNC ALERT Node 1).
     *
     * @param  array<int, array{method: string, amount: int}>  $legs
     */
    public function submitOrder(array $legs = [], ?string $idempotencyKey = null)
    {
        $rules = [
            'orderType' => ['required', 'in:dine_in,takeaway'],
            'cart' => ['required', 'array', 'min:1'],
        ];

        if ($this->pendingOrderId) {
            $rules['cashReceived'] = ['required', 'numeric', 'min:0'];
        }

        // Jika bukan pending order, validasi meja diperlukan untuk dine_in
        if (! $this->pendingOrderId && $this->orderType === 'dine_in') {
            $rules['tableId'] = ['required'];
        }

        $this->validate($rules);

        // [OMEGA-NODE2] Idempotency guard -- pola direplikasi persis dari
        // TransactionController::store() agar kasir yang panik menekan
        // tombol berkali-kali tidak menghasilkan order duplikat.
        if ($idempotencyKey && Order::where('idempotency_key', $idempotencyKey)->exists()) {
            return redirect()->route(
                'transaction.receipt',
                Order::where('idempotency_key', $idempotencyKey)->value('order_code')
            );
        }

        $lock = null;
        if ($idempotencyKey) {
            $lock = Cache::lock('order_idempotency_'.$idempotencyKey, 10);
            if (! $lock->get()) {
                $this->addError('cart', 'Transaksi serupa sedang diproses, coba lagi.');

                return;
            }
        }

        try {
            DB::beginTransaction();

            $transactionService = app(TransactionService::class);

            // [OMEGA-NODE1] Zero-Trust shift guard, dipusatkan lewat Service
            // agar identik dengan alur TransactionController::store().
            $shiftId = $transactionService->resolveOpenShiftOrFail((int) Auth::id());

            // Normalisasi payment legs untuk KEDUA skenario (Walk-in & Pending)
            $normalizedLegs = [];
            foreach ($legs as $leg) {
                $method = PaymentMethodEnum::tryFrom((string) ($leg['method'] ?? ''));
                $amount = (int) ($leg['amount'] ?? 0);

                if (! $method || $amount <= 0) {
                    throw ValidationException::withMessages([
                        'cart' => 'Salah satu metode pembayaran tidak valid.',
                    ]);
                }

                $normalizedLegs[] = ['method' => $method->value, 'amount' => $amount];
            }

            if (empty($normalizedLegs)) {
                throw ValidationException::withMessages([
                    'cart' => 'Minimal satu metode pembayaran wajib diisi sebelum memproses transaksi.',
                ]);
            }

            if ($this->pendingOrderId) {
                // Skenario 2: Membayar Pesanan Self-Order yang tertunda.
                // Sekarang menggunakan TransactionService untuk insert ledger payments & loyalty.
                $order = Order::findOrFail($this->pendingOrderId);
                $transactionService->payPendingOrder(
                    $order,
                    $normalizedLegs,
                    (int) Auth::id(),
                    $shiftId
                );
            } else {
                // Skenario 1: Pesanan Walk-in Baru + Split Payment + Loyalti.

                // Batch-load produk (pola sama dengan cartLines() di atas) agar
                // resolveOrderItemLine() tidak memicu N+1 saat membangun payload.
                $productIds = collect($this->cart)->pluck('product_id')->unique()->values();
                $products = Product::with('modifierGroups')->whereIn('id', $productIds)->get()->keyBy('id');

                $items = [];
                foreach ($this->cart as $raw) {
                    $product = $products->get($raw['product_id']) ?? Product::findOrFail($raw['product_id']);
                    $line = $transactionService->resolveOrderItemLine(
                        $product, $raw['modifier_ids'], $raw['qty'], $raw['notes'],
                    );

                    $items[] = [
                        'product_id' => $line['product_id'],
                        'quantity' => $line['qty'],
                        'extra_price' => $line['order_price'] - $product->product_price,
                        'options' => $line['options'],
                        'notes' => $line['notes'] ?? null,
                    ];
                }

                $order = $transactionService->createTransaction([
                    'items' => $items,
                    'payments' => $normalizedLegs,
                    'customer_id' => $this->customerId,
                    'table_id' => $this->tableId,
                    'order_type' => $this->orderType,
                    'shift_id' => $shiftId,
                    'idempotency_key' => $idempotencyKey,
                ], (int) Auth::id());
            }

            DB::commit();

            $this->dispatch('transaction-success', orderCode: $order->order_code, isCash: collect($normalizedLegs)->contains('method', 'cash'));

            return;

        } catch (\Exception $e) {
            DB::rollBack();
            if ($e instanceof ValidationException) {
                $this->addError('cart', collect($e->errors())->flatten()->first());
            } else {
                $this->addError('cart', $e->getMessage());
            }

            return;
        } finally {
            if ($lock) {
                $lock->release();
            }
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
