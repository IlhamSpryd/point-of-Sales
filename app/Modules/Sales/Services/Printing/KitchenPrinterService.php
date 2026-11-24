<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services\Printing;

use App\Enums\KitchenStation;
use App\Modules\Sales\Models\Order;

/**
 * Service khusus untuk pencetakan tiket dapur (Kitchen Printer).
 * Bertindak sebagai fallback mekanis ketika perangkat tablet KDS mengalami gangguan
 * koneksi atau mati daya. Tiket ini TIDAK mencetak harga, total, atau metode pembayaran,
 * melainkan berfokus penuh pada instruksi peracikan (Produk, Qty, Modifier, Catatan, Meja).
 *
 * Mendukung pemisahan stasiun cetak (Kitchen vs Bar) dan banner status lunas / open tab (PRT-02).
 */
class KitchenPrinterService
{
    /**
     * Menghasilkan struktur payload heksadesimal mentah (Raw ESC/POS) untuk dicetak via QZ Tray
     * ke printer dapur (biasanya printer thermal 80mm/58mm yang ditempatkan di area dapur/bar).
     *
     * @param  string|null  $station  Nama stasiun target (misal 'kitchen', 'bar', 'dessert') atau null untuk seluruh item
     * @return list<string>
     */
    public function generatePayload(Order $order, ?string $station = null): array
    {
        // Pastikan order dimuat beserta relasi yang diperlukan dapur
        $order->loadMissing(['orderItems.product.category', 'table']);

        $payload = [];

        // 1. Inisialisasi ESC/POS
        $payload[] = "\x1B\x40"; // ESC @ (Initialize printer)

        // 2. Header Tiket (Besar & Jelas)
        $stationLabel = $station !== null ? strtoupper(trim($station)) : 'DAPUR';
        $payload[] = "\x1B\x61\x01"; // Justifikasi Tengah
        $payload[] = "\x1B\x21\x30"; // Double Height & Width
        $payload[] = "TIKET {$stationLabel}\n";
        $payload[] = "\x1B\x21\x00"; // Reset Font
        $payload[] = "\n";

        // 3. Status Pembayaran Banner (PRT-02)
        $statusVal = $order->order_status->value;
        $isPaid = in_array(strtolower($statusVal), ['paid', 'completed'], true);
        $statusBanner = $isPaid ? '[SUDAH LUNAS]' : '[BELUM BAYAR / OPEN TAB]';

        $payload[] = "\x1B\x45\x01"; // Bold ON
        $payload[] = "STATUS: {$statusBanner}\n";
        $payload[] = "\x1B\x45\x00"; // Bold OFF
        $payload[] = "\n";

        // 4. Metadata Pesanan
        $payload[] = "\x1B\x61\x00"; // Justifikasi Kiri
        $isTakeaway = $order->order_type?->value === 'takeaway';
        $place = $order->table?->table_name ?? ($isTakeaway ? 'TAKEAWAY' : 'KASIR');

        $payload[] = 'Order : '.$order->order_code."\n";
        $payload[] = 'Meja  : '.$place."\n";
        $payload[] = 'Waktu : '.($order->created_at?->format('d/m/Y H:i') ?? '')."\n";
        $payload[] = str_repeat('-', 32)."\n";

        // 5. Filter Item berdasarkan stasiun jika dispesifikasikan (PRT-02)
        $items = $order->orderItems;
        if ($station !== null) {
            $targetStation = strtolower(trim($station));
            $items = $items->filter(function ($item) use ($targetStation) {
                $product = $item->product;
                if ($product === null) {
                    return true;
                }
                $st = $product->kitchen_station instanceof KitchenStation
                    ? $product->kitchen_station->value
                    : (string) ($product->kitchen_station ?? '');

                if ($st === '' || $st === KitchenStation::None->value) {
                    $catStation = $product->category?->kitchen_station;
                    $st = $catStation instanceof KitchenStation ? $catStation->value : KitchenStation::Kitchen->value;
                }

                return strtolower($st) === $targetStation;
            });
        }

        // 6. Daftar Item (Hanya Qty, Nama, Modifier, Notes)
        foreach ($items as $item) {
            // Qty & Nama Produk (Ditebalkan)
            $payload[] = "\x1B\x45\x01"; // Bold ON
            $payload[] = "{$item->qty}x ".($item->product->product_name ?? 'Produk')."\n";
            $payload[] = "\x1B\x45\x00"; // Bold OFF

            // Modifiers
            $options = collect($item->options ?? []);
            if ($options->isNotEmpty()) {
                foreach ($options as $opt) {
                    $optName = $opt['name'] ?? $opt['modifier_name'] ?? '';
                    $payload[] = "   + {$optName}\n";
                }
            }

            // Catatan Pelanggan
            if (! empty($item->notes)) {
                $payload[] = "\x1B\x45\x01"; // Bold ON untuk Catatan agar chef notice
                $payload[] = "   Catatan: {$item->notes}\n";
                $payload[] = "\x1B\x45\x00"; // Bold OFF
            }
            $payload[] = "\n";
        }

        $payload[] = str_repeat('-', 32)."\n";
        $payload[] = "\x1B\x61\x01"; // Justifikasi Tengah
        $payload[] = "*** MOHON SEGERA DIPROSES ***\n";

        // 7. Penutup (Cut & Beeper jika didukung)
        $payload[] = "\n\n";
        $payload[] = "\x1D\x56\x41\x10"; // Partial cut

        return $payload;
    }
}
