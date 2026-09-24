<?php

namespace App\Services;

use App\Models\Modifier;
use App\Models\Product;
use Illuminate\Support\Facades\Session;

/**
 * CartService menangani seluruh logika keranjang belanja pelanggan self-order.
 * Data disimpan di SESSION (bukan database), karena satu pelanggan = satu
 * device = satu sesi browser, sehingga tidak perlu tabel sementara terpisah.
 */
class CartService
{
    /** Key session tempat array keranjang disimpan. */
    private const SESSION_KEY = 'customer_cart';

    /**
     * Menambahkan produk + kombinasi modifier ke keranjang.
     * Jika kombinasi produk+modifier yang SAMA sudah ada di keranjang,
     * qty-nya akan bertambah (merge), bukan membuat baris baru.
     *
     * @param  array<int>  $modifierIds  ID modifier yang dipilih pelanggan (bisa lebih dari satu grup)
     */
    // [OMEGA-NODE1] PATCH FOR M-01: sebelumnya CartService::addItem() TIDAK
    // memvalidasi bahwa modifier_id benar-benar milik grup varian yang
    // ditautkan ke product ini, dan TIDAK menegakkan is_required/selection_type
    // -- padahal aturan ini SUDAH BENAR di TransactionService::resolveOrderItemLine(),
    // hanya jalur self-order yang tidak pernah memanggilnya. Memanggil ulang
    // method yang sama di sini menutup drift dua-aturan secara permanen. | 2026-09-24
    public function addItem(Product $product, array $modifierIds, int $qty, ?string $notes = null): array
    {
        // Validasi keanggotaan grup + is_required + selection_type single/multiple.
        // Melempar ValidationException jika payload tidak sah -- persis aturan Kasir.
        app(TransactionService::class)->resolveOrderItemLine($product, $modifierIds, $qty, null);

        // Ambil detail modifier yang dipilih dari database, sekaligus validasi
        // bahwa modifier tersebut memang ada (mencegah ID palsu dari request manual).
        $modifiers = Modifier::whereIn('id', $modifierIds)->with('modifierGroup')->get();

        // Urutkan ID modifier agar kombinasi yang sama selalu menghasilkan
        // line_id yang identik, apapun urutan pelanggan memilihnya di form.
        $sortedIds = $modifiers->pluck('id')->sort()->values()->all();
        $lineId = md5($product->id.'-'.implode('-', $sortedIds));

        // Total tambahan harga dari semua modifier yang dipilih (misal: Oat Milk +5000).
        $extraPrice = $modifiers->sum('extra_price');
        $unitPrice = $product->product_price + $extraPrice;

        $cart = $this->getCartArray();

        if (isset($cart[$lineId])) {
            // [OMEGA-NODE1] PATCH FOR M-06: sebelumnya notes dari
            // penambahan kedua dibuang senyap. Sekarang digabung (bukan
            // ditimpa) agar tidak ada instruksi khusus pelanggan yang
            // hilang -- barista tetap melihat SEMUA catatan yang pernah
            // ditulis untuk kombinasi item ini. | 2026-09-24
            $cart[$lineId]['qty'] += $qty;

            if (! empty($notes)) {
                $cart[$lineId]['notes'] = trim(implode(' | ', array_filter([
                    $cart[$lineId]['notes'] ?? null,
                    $notes,
                ])));
            }
        } else {
            // Kombinasi baru → buat baris keranjang baru.
            $cart[$lineId] = [
                'line_id' => $lineId,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_photo' => $product->product_photo,
                'base_price' => $product->product_price,
                'qty' => $qty,
                'notes' => $notes,
                // "options" inilah kolom JSON yang diminta di requirement awal Anda.
                // Disimpan sebagai array asosiatif agar mudah di-render ulang di UI keranjang.
                'options' => $modifiers->map(fn (Modifier $m) => [
                    'modifier_id' => $m->id,
                    'group_name' => $m->modifierGroup->name,
                    'modifier_name' => $m->name,
                    'extra_price' => $m->extra_price,
                ])->values()->all(),
                'unit_price' => $unitPrice,
            ];
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
            $cart[$lineId]['qty'] = $qty;
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
            if (! $product) {
                // Produk dihapus, skip atau hapus dari keranjang.
                unset($cart[$lineId]);

                continue;
            }

            $modifierIds = array_column($item['options'] ?? [], 'modifier_id');
            $modifiers = Modifier::whereIn('id', $modifierIds)->with('modifierGroup')->get();

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
