<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\Product;
use Illuminate\Support\Facades\Session;
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
        // Validasi keanggotaan grup + is_required + selection_type single/multiple.
        // Melempar ValidationException jika payload tidak sah — persis aturan Kasir.
        app(TransactionService::class)->resolveOrderItemLine($product, $modifierIds, $qty, null);

        // Ambil detail modifier yang dipilih dari database, sekaligus validasi
        // bahwa modifier tersebut memang ada (mencegah ID palsu dari request manual).
        // PATCH FOR S-15: hanya modifier aktif.
        $modifiers = Modifier::whereIn('id', $modifierIds)->where('is_active', true)->with('modifierGroup')->get();

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
        Session::forget(self::SESSION_KEY);
    }

    /**
     * PATCH FOR U-03/P-19: Simpan snapshot keranjang sebelum clear,
     * agar pelanggan bisa "Pesan ulang" jika pembayaran gagal/expired.
     */
    public function snapshot(): void
    {
        Session::put('customer_cart_last', $this->getCartArray());
    }

    /**
     * PATCH FOR U-03/P-19: Pulihkan keranjang dari snapshot terakhir.
     * Harga/varian disegarkan dari DB saat dipulihkan.
     */
    public function restoreSnapshot(): bool
    {
        $snap = Session::pull('customer_cart_last');
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

    /** Helper internal: ambil array keranjang mentah dari session. */
    private function getCartArray(): array
    {
        return Session::get(self::SESSION_KEY, []);
    }

    /** Helper internal: simpan array keranjang kembali ke session. */
    private function saveCartArray(array $cart): void
    {
        Session::put(self::SESSION_KEY, $cart);
    }
}
