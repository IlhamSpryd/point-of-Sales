<?php

namespace App\Livewire\Kasir;

use App\Enums\OrderType;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\Table;
use App\Services\TransactionService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreateOrder extends Component
{
    public ?string $orderType = null;   // 'dine_in' | 'takeaway'

    public ?int $tableId = null;

    public array $cart = [];            // payload MENTAH, sama format dgn self-order

    public float $cashReceived = 0;

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

    #[Computed]
    public function activeTables()
    {
        return Table::where('status', 'active')->orderBy('table_number')->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::with([
            'products' => fn ($q) => $q->availableForOrder()->with('modifierGroups.modifiers'),
        ])->get();
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

        return collect($this->cart)->map(function ($raw, $i) use ($service) {
            $line = $service->resolveOrderItemLine(
                Product::findOrFail($raw['product_id']),
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
        $this->validate([
            'orderType' => ['required', 'in:dine_in,takeaway'],
            'tableId' => ['required_if:orderType,dine_in'],
            'cart' => ['required', 'array', 'min:1'],
            'cashReceived' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $order = app(TransactionService::class)->createWalkInOrder(
                itemsPayload: $this->cart,
                orderType: OrderType::from($this->orderType),
                tableId: $this->tableId,
                paymentMethod: 'cash',
                cashReceived: (int) $this->cashReceived,
            );
        } catch (ValidationException $e) {
            $this->addError('cart', collect($e->errors())->flatten()->first());

            return;
        }

        session()->flash('success', "Order {$order->order_code} berhasil dibuat.");

        return redirect()->route('transaction.receipt', $order->order_code);
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
