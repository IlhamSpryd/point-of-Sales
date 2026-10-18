<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Discount;
use App\Models\Modifier;
use App\Models\ParkedCart;
use App\Models\Product;
use App\Models\Shift;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CartService menangani seluruh logika keranjang belanja pelanggan self-order.
 * Data disimpan di SESSION (bukan database), karena satu pelanggan = satu
 * device = satu sesi browser, sehingga tidak perlu tabel sementara terpisah.
 */
class CartService
{
    /** Key session tempat array keranjang disimpan. */
    private const SESSION_KEY = 'customer_cart';

    // PATCH FOR S-05: plafon qty per baris dan per keranjang.
    private const MAX_LINE_QTY = 20;

    private const MAX_CART_QTY = 40;

    /**
     * Menambahkan produk + kombinasi modifier ke keranjang.
     * Jika kombinasi produk+modifier yang SAMA sudah ada di keranjang,
     * qty-nya akan bertambah (merge), bukan membuat baris baru.
     *
     * @param  array<int>  $modifierIds  ID modifier yang dipilih pelanggan (bisa lebih dari satu grup)
     */
    public function addItem(Product $product, array $modifierIds, int $qty, ?string $notes = null): array
    {
        $tenantId = session('current_tenant_id') ?? app(TenantContext::class)->getTenantId();
        $storeId = session('current_store_id') ?? app(TenantContext::class)->getStoreId();

        if ($tenantId !== null && (int) $product->tenant_id !== (int) $tenantId) {
            throw ValidationException::withMessages(['product_id' => 'Produk tidak ditemukan di tenant ini.']);
        }
        if ($storeId !== null && isset($product->store_id) && $product->store_id !== null && (int) $product->store_id !== (int) $storeId) {
            throw ValidationException::withMessages(['product_id' => 'Produk tidak ditemukan di cabang ini.']);
        }

        // Validasi keanggotaan grup + is_required + selection_type single/multiple.
        // Melempar ValidationException jika payload tidak sah — persis aturan Kasir.
        app(TransactionService::class)->resolveOrderItemLine($product, $modifierIds, $qty, null);

        // Ambil detail modifier yang dipilih dari database, sekaligus validasi
        // bahwa modifier tersebut memang ada (mencegah ID palsu dari request manual).
        // PATCH FOR S-15: hanya modifier aktif.
        $modifierQuery = Modifier::whereIn('id', $modifierIds)->where('is_active', true);
        if ($tenantId !== null) {
            $modifierQuery->where('tenant_id', $tenantId);
        }
        $modifiers = $modifierQuery->with('modifierGroup')->get();

        if (count($modifiers) !== count($modifierIds)) {
            throw ValidationException::withMessages(['modifier_ids' => 'Pilihan varian tidak valid untuk cabang ini.']);
        }

        // Urutkan ID modifier agar kombinasi yang sama selalu menghasilkan
        // line_id yang identik, apapun urutan pelanggan memilihnya di form.
        $sortedIds = $modifiers->pluck('id')->sort()->values()->all();
        $lineId = md5($product->id.'-'.implode('-', $sortedIds));

        // Total tambahan harga dari semua modifier yang dipilih (misal: Oat Milk +5000).
        $extraPrice = $modifiers->sum('extra_price');
        $unitPrice = $product->product_price + $extraPrice;

        $cart = $this->getCartArray();

        if (isset($cart[$lineId])) {
            // PATCH FOR S-05: plafon qty per baris saat merge.
            $cart[$lineId]['qty'] = min(self::MAX_LINE_QTY, $cart[$lineId]['qty'] + $qty);

            // PATCH FOR S-12: batasi panjang notes gabungan.
            if (! empty($notes)) {
                $cart[$lineId]['notes'] = mb_substr(trim(implode(' | ', array_filter([
                    $cart[$lineId]['notes'] ?? null,
                    $notes,
                ]))), 0, 255);
            }
        } else {
            // Kombinasi baru → buat baris keranjang baru.
            $cart[$lineId] = [
                'line_id' => $lineId,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_photo' => $product->product_photo,
                'base_price' => $product->product_price,
                'qty' => min(self::MAX_LINE_QTY, $qty),
                'notes' => $notes ? mb_substr($notes, 0, 255) : null,
                'options' => $modifiers->map(fn (Modifier $m) => [
                    'modifier_id' => $m->id,
                    'group_name' => $m->modifierGroup->name,
                    'modifier_name' => $m->name,
                    'extra_price' => $m->extra_price,
                ])->values()->all(),
                'unit_price' => $unitPrice,
            ];
        }

        // PATCH FOR S-05: plafon total item per keranjang.
        if (array_sum(array_column($cart, 'qty')) > self::MAX_CART_QTY) {
            throw ValidationException::withMessages([
                'items' => 'Maksimal '.self::MAX_CART_QTY.' item per pesanan. Silakan panggil staf untuk pesanan besar.',
            ]);
        }

        $this->saveCartArray($cart);

        return $cart[$lineId];
    }

    /**
     * Mengubah quantity satu baris keranjang. Jika qty menjadi 0 atau kurang,
     * baris tersebut otomatis dihapus dari keranjang.
     */
    public function updateQty(string $lineId, int $qty): void
    {
        $cart = $this->getCartArray();

        if (! isset($cart[$lineId])) {
            return;
        }

        if ($qty <= 0) {
            unset($cart[$lineId]);
        } else {
            $cart[$lineId]['qty'] = min(self::MAX_LINE_QTY, $qty);
        }

        if (array_sum(array_column($cart, 'qty')) > self::MAX_CART_QTY) {
            throw ValidationException::withMessages([
                'items' => 'Maksimal '.self::MAX_CART_QTY.' item per pesanan. Silakan panggil staf untuk pesanan besar.',
            ]);
        }

        $this->saveCartArray($cart);
    }

    /**
     * Menghapus satu baris keranjang secara permanen (tombol "hapus item").
     */
    public function removeItem(string $lineId): void
    {
        $cart = $this->getCartArray();
        unset($cart[$lineId]);
        $this->saveCartArray($cart);
    }

    /**
     * Mengosongkan seluruh keranjang (dipanggil setelah checkout sukses).
     */
    public function clear(): void
    {
        Session::forget($this->getSessionKey());
        Session::forget(self::SESSION_KEY);
    }

    /**
     * PATCH FOR U-03/P-19: Simpan snapshot keranjang sebelum clear,
     * agar pelanggan bisa "Pesan ulang" jika pembayaran gagal/expired.
     */
    public function snapshot(): void
    {
        Session::put('customer_cart_last_'.$this->getSessionKey(), $this->getCartArray());
    }

    /**
     * PATCH FOR U-03/P-19: Pulihkan keranjang dari snapshot terakhir.
     * Harga/varian disegarkan dari DB saat dipulihkan.
     */
    public function restoreSnapshot(): bool
    {
        $scopedKey = 'customer_cart_last_'.$this->getSessionKey();
        $snap = Session::pull($scopedKey);
        if (! $snap) {
            return false;
        }
        $this->saveCartArray($snap);
        $this->refreshCartPrices();

        return true;
    }

    /**
     * Mengembalikan semua baris keranjang beserta subtotal per baris,
     * siap ditampilkan di halaman keranjang/checkout.
     */
    public function getItems(): array
    {
        $cart = $this->getCartArray();

        return array_map(function (array $item) {
            $item['subtotal'] = $item['unit_price'] * $item['qty'];

            return $item;
        }, $cart);
    }

    /**
     * Total keseluruhan harga di keranjang (belum termasuk pajak,
     * pajak tetap dihitung ulang di backend saat checkout — sama seperti
     * pola TransactionService::calculateOrderTotals() yang sudah ada di kasir).
     */
    public function getSubtotal(): int
    {
        return array_sum(array_map(
            fn (array $item) => $item['unit_price'] * $item['qty'],
            $this->getCartArray()
        ));
    }

    public function getTotalQty(): int
    {
        return array_sum(array_column($this->getCartArray(), 'qty'));
    }

    /**
     * Memperbarui harga di keranjang sesuai harga terbaru dari database.
     * Dipanggil jika terdeteksi perubahan harga saat checkout.
     */
    public function refreshCartPrices(): void
    {
        $cart = $this->getCartArray();

        foreach ($cart as $lineId => $item) {
            $product = Product::find($item['product_id']);
            if (! $product || ! $product->is_active) {
                // Produk dihapus atau nonaktif, hapus dari keranjang.
                unset($cart[$lineId]);

                continue;
            }

            $modifierIds = array_column($item['options'] ?? [], 'modifier_id');
            // PATCH FOR S-15: hanya modifier aktif.
            $modifiers = Modifier::whereIn('id', $modifierIds)->where('is_active', true)->with('modifierGroup')->get();

            $extraPrice = $modifiers->sum('extra_price');
            $unitPrice = $product->product_price + $extraPrice;

            $cart[$lineId]['base_price'] = $product->product_price;
            $cart[$lineId]['unit_price'] = $unitPrice;

            // Perbarui juga data options agar sesuai
            $cart[$lineId]['options'] = $modifiers->map(fn (Modifier $m) => [
                'modifier_id' => $m->id,
                'group_name' => $m->modifierGroup->name,
                'modifier_name' => $m->name,
                'extra_price' => $m->extra_price,
            ])->values()->all();
        }

        $this->saveCartArray($cart);
    }

    public function getSessionKey(): string
    {
        $tenantId = session('current_tenant_id') ?? app(TenantContext::class)->getTenantId();
        $storeId = session('current_store_id') ?? app(TenantContext::class)->getStoreId();
        $tableId = session('current_table_id');

        if ($tenantId !== null && $tableId !== null) {
            return "customer_cart_{$tenantId}_{$storeId}_{$tableId}";
        }

        return self::SESSION_KEY;
    }

    /** Helper internal: ambil array keranjang mentah dari session. */
    private function getCartArray(): array
    {
        return Session::get($this->getSessionKey(), []);
    }

    /** Helper internal: simpan array keranjang kembali ke session. */
    private function saveCartArray(array $cart): void
    {
        Session::put($this->getSessionKey(), $cart);
    }

    /**
     * Park (Hold) keranjang transaksi ke server database.
     *
     * @param  array<string, mixed>  $cartState
     */
    public function parkCart(
        int $tenantId,
        int $storeId,
        int $deviceId,
        int $shiftId,
        int $userId,
        array $cartState,
        ?string $customerLabel = null
    ): ParkedCart {
        // Validasi keberadaan & keaktifan device
        $device = Device::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('id', $deviceId)
            ->first();

        if (! $device || ! $device->is_active) {
            throw ValidationException::withMessages([
                'device_id' => 'Perangkat tidak valid atau tidak aktif di cabang ini.',
            ]);
        }

        // Validasi shift terbuka
        $shift = Shift::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('id', $shiftId)
            ->first();

        if (! $shift || $shift->status !== 'open') {
            throw ValidationException::withMessages([
                'shift_id' => 'Shift tidak aktif atau sudah ditutup.',
            ]);
        }

        // Validasi isi keranjang
        $items = $cartState['items'] ?? $cartState;
        if (empty($items) || ! is_array($items)) {
            throw ValidationException::withMessages([
                'cart' => 'Tidak dapat menahan keranjang yang kosong.',
            ]);
        }

        $holdReference = 'HOLD-'.date('Ymd').'-'.strtoupper(Str::random(6));

        $parkedCart = ParkedCart::create([
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'device_id' => $deviceId,
            'shift_id' => $shiftId,
            'parked_by_user_id' => $userId,
            'customer_id' => $cartState['customer_id'] ?? null,
            'table_id' => $cartState['table_id'] ?? null,
            'hold_reference' => $holdReference,
            'customer_label' => $customerLabel ?? $cartState['customer_label'] ?? null,
            'order_type' => $cartState['order_type'] ?? 'dine_in',
            'cart_payload' => $cartState,
            'discount_id' => $cartState['discount_id'] ?? null,
            'notes' => $cartState['notes'] ?? null,
            'status' => ParkedCart::STATUS_PARKED,
            'expires_at' => now()->addHours(24),
        ]);

        app(AuditTrailService::class)->log(
            tenantId: $tenantId,
            action: 'cart.parked',
            userId: $userId,
            storeId: $storeId,
            subjectType: ParkedCart::class,
            subjectId: $parkedCart->id,
            description: "Keranjang ditahan dengan referensi {$holdReference}",
            metadata: [
                'hold_reference' => $holdReference,
                'device_id' => $deviceId,
                'shift_id' => $shiftId,
                'items_count' => count($items),
            ]
        );

        return $parkedCart;
    }

    /**
     * Melanjutkan kembali (resume) keranjang yang ditahan dengan validasi ulang server.
     *
     * @return array{parked_cart: ParkedCart, cart: array, warnings: array<string>, has_blocking_issues: bool}
     */
    public function resumeParkedCart(
        int $parkedCartId,
        int $tenantId,
        int $storeId,
        int $deviceId,
        int $shiftId,
        int $userId
    ): array {
        return DB::transaction(function () use ($parkedCartId, $tenantId, $storeId, $shiftId, $userId) {
            $shift = Shift::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $shiftId)
                ->first();

            if (! $shift || $shift->status !== 'open') {
                throw ValidationException::withMessages([
                    'shift_id' => 'Kasir harus memiliki shift aktif untuk melanjutkan keranjang.',
                ]);
            }

            $parkedCart = ParkedCart::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $parkedCartId)
                ->lockForUpdate()
                ->first();

            if (! $parkedCart) {
                throw ValidationException::withMessages([
                    'parked_cart' => 'Keranjang tertunda tidak ditemukan.',
                ]);
            }

            if ($parkedCart->status !== ParkedCart::STATUS_PARKED) {
                throw ValidationException::withMessages([
                    'parked_cart' => "Keranjang ini sudah {$parkedCart->status} dan tidak dapat diambil kembali.",
                ]);
            }

            $parkedCart->update([
                'status' => ParkedCart::STATUS_RESUMED,
                'resumed_by_user_id' => $userId,
            ]);

            $payload = (array) $parkedCart->cart_payload;
            $items = (array) ($payload['items'] ?? $payload);
            $isAssociativeItems = isset($payload['items']);

            $warnings = [];
            $hasBlockingIssues = false;
            $revalidatedItems = [];

            foreach ($items as $key => $item) {
                $productId = $item['product_id'] ?? null;
                $product = Product::withTrashed()->find($productId);

                if (! $product || $product->trashed() || ! $product->is_active) {
                    $warnings[] = "Produk '".($item['product_name'] ?? 'ID: '.$productId)."' sudah tidak aktif atau dihapus.";
                    $hasBlockingIssues = true;
                    $item['is_available'] = false;
                    $revalidatedItems[$key] = $item;

                    continue;
                }

                $item['product_name'] = $product->product_name;
                $item['is_available'] = true;

                // Re-validasi modifiers & harga
                $modifierIds = array_column($item['options'] ?? [], 'modifier_id');
                $modifiers = Modifier::whereIn('id', $modifierIds)->where('is_active', true)->with('modifierGroup')->get();

                if (count($modifiers) !== count($modifierIds)) {
                    $warnings[] = "Beberapa opsi varian untuk produk '{$product->product_name}' sudah tidak aktif.";
                    $hasBlockingIssues = true;
                    $item['is_available'] = false;
                }

                $extraPrice = (int) $modifiers->sum('extra_price');
                $newUnitPrice = (int) ($product->product_price + $extraPrice);
                $oldUnitPrice = (int) ($item['unit_price'] ?? $item['base_price'] ?? 0);

                if ($newUnitPrice !== $oldUnitPrice) {
                    $warnings[] = "Harga produk '{$product->product_name}' berubah dari Rp".number_format($oldUnitPrice, 0, ',', '.').' menjadi Rp'.number_format($newUnitPrice, 0, ',', '.').'.';
                    $item['base_price'] = (int) $product->product_price;
                    $item['unit_price'] = $newUnitPrice;
                }

                // Re-validasi stok
                $requestedQty = (int) ($item['qty'] ?? $item['quantity'] ?? 1);
                if ($product->ingredients()->exists()) {
                    foreach ($product->ingredients as $ingredient) {
                        $requiredBOM = (float) bcmul((string) $ingredient->pivot->quantity_required, (string) $requestedQty, 4);
                        if ((float) $ingredient->current_stock < $requiredBOM) {
                            $warnings[] = "Bahan baku '{$ingredient->name}' untuk produk '{$product->product_name}' tidak mencukupi (sisa: {$ingredient->current_stock}).";
                            $hasBlockingIssues = true;
                            $item['is_available'] = false;
                            $item['insufficient_stock'] = true;
                            break;
                        }
                    }
                } else {
                    $availableStock = (int) $product->stock;
                    if ($availableStock < $requestedQty) {
                        $warnings[] = "Stok produk '{$product->product_name}' tidak mencukupi (tersedia: {$availableStock}, diminta: {$requestedQty}).";
                        $hasBlockingIssues = true;
                        $item['is_available'] = false;
                        $item['insufficient_stock'] = true;
                    }
                }

                $revalidatedItems[$key] = $item;
            }

            // Re-validasi voucher/diskon
            $discountId = $payload['discount_id'] ?? $parkedCart->discount_id;
            if ($discountId) {
                $discount = Discount::find($discountId);
                $now = now();
                $isDiscountValid = $discount && $discount->is_active;

                if ($isDiscountValid && $discount->valid_from && $now->lt($discount->valid_from)) {
                    $isDiscountValid = false;
                }
                if ($isDiscountValid && $discount->valid_until && $now->gt($discount->valid_until)) {
                    $isDiscountValid = false;
                }
                if ($isDiscountValid && ! $discount->hasAvailableQuota()) {
                    $isDiscountValid = false;
                }

                if (! $isDiscountValid) {
                    $warnings[] = 'Voucher diskon sudah tidak berlaku atau kuota telah habis. Diskon dilepas.';
                    if ($isAssociativeItems) {
                        $payload['discount_id'] = null;
                    }
                }
            }

            if ($isAssociativeItems) {
                $payload['items'] = $revalidatedItems;
            } else {
                $payload = $revalidatedItems;
            }

            app(AuditTrailService::class)->log(
                tenantId: $tenantId,
                action: 'cart.resumed',
                userId: $userId,
                storeId: $storeId,
                subjectType: ParkedCart::class,
                subjectId: $parkedCart->id,
                description: "Keranjang {$parkedCart->hold_reference} dilanjutkan oleh kasir",
                metadata: [
                    'warnings_count' => count($warnings),
                    'has_blocking_issues' => $hasBlockingIssues,
                ]
            );

            return [
                'parked_cart' => $parkedCart,
                'cart' => $payload,
                'warnings' => $warnings,
                'has_blocking_issues' => $hasBlockingIssues,
            ];
        });
    }

    /**
     * Membuang (discard) keranjang yang ditahan jika pelanggan batal pesan.
     */
    public function discardParkedCart(int $parkedCartId, int $tenantId, int $storeId, int $userId): ParkedCart
    {
        return DB::transaction(function () use ($parkedCartId, $tenantId, $storeId, $userId) {
            $parkedCart = ParkedCart::where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('id', $parkedCartId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($parkedCart->status, [ParkedCart::STATUS_CONVERTED, ParkedCart::STATUS_DISCARDED], true)) {
                throw ValidationException::withMessages([
                    'parked_cart' => "Keranjang ini sudah {$parkedCart->status}.",
                ]);
            }

            $parkedCart->update([
                'status' => ParkedCart::STATUS_DISCARDED,
            ]);

            app(AuditTrailService::class)->log(
                tenantId: $tenantId,
                action: 'cart.discarded',
                userId: $userId,
                storeId: $storeId,
                subjectType: ParkedCart::class,
                subjectId: $parkedCart->id,
                description: "Keranjang {$parkedCart->hold_reference} dibatalkan",
            );

            return $parkedCart;
        });
    }

    /**
     * Mengambil daftar keranjang yang sedang ditahan untuk cabang terkait.
     *
     * @return Collection<int, ParkedCart>
     */
    public function getParkedCarts(int $tenantId, int $storeId)
    {
        return ParkedCart::where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('status', ParkedCart::STATUS_PARKED)
            ->orderByDesc('id')
            ->get();
    }
}
