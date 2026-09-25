<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\Order;

/**
 * Service khusus untuk pencetakan tiket dapur (Kitchen Printer).
 * Bertindak sebagai fallback mekanis ketika perangkat tablet KDS mengalami gangguan
 * koneksi atau mati daya. Tiket ini TIDAK mencetak harga, total, atau metode pembayaran,
 * melainkan berfokus penuh pada instruksi peracikan (Produk, Qty, Modifier, Catatan, Meja).
 */
class KitchenPrinterService
{
    /**
     * Menghasilkan struktur payload heksadesimal mentah (Raw ESC/POS) untuk dicetak via QZ Tray
     * ke printer dapur (biasanya printer thermal 80mm/58mm yang ditempatkan di area dapur/bar).
     */
    public function generatePayload(Order $order): array
    {
        // Pastikan order dimuat beserta relasi yang diperlukan dapur
        $order->loadMissing(['orderItems.product', 'table']);

        $payload = [];

        // 1. Inisialisasi ESC/POS
        $payload[] = "\x1B\x40"; // ESC @ (Initialize printer)

        // 2. Header Tiket (Besar & Jelas)
        $payload[] = "\x1B\x61\x01"; // Justifikasi Tengah
        $payload[] = "\x1B\x21\x30"; // Double Height & Width
        $payload[] = "TIKET DAPUR\n";
        $payload[] = "\x1B\x21\x00"; // Reset Font
        $payload[] = "\n";

        // 3. Metadata Pesanan
        $payload[] = "\x1B\x61\x00"; // Justifikasi Kiri
        $isTakeaway = $order->order_type?->value === 'takeaway';
        $place = $order->table?->table_name ?? ($isTakeaway ? 'TAKEAWAY' : 'KASIR');

        $payload[] = 'Order : '.$order->order_code."\n";
        $payload[] = 'Meja  : '.$place."\n";
        $payload[] = 'Waktu : '.$order->created_at->format('d/m/Y H:i')."\n";
        $payload[] = str_repeat('-', 32)."\n";

        // 4. Daftar Item (Hanya Qty, Nama, Modifier, Notes)
        foreach ($order->orderItems as $item) {
            // Qty & Nama Produk (Ditebalkan)
            $payload[] = "\x1B\x45\x01"; // Bold ON
            $payload[] = "{$item->qty}x {$item->product->product_name}\n";
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

        // 5. Penutup (Cut & Beeper jika didukung)
        $payload[] = "\n\n";
        // Hex command bervariasi tergantung tipe printer, ini ESC/POS standard cut
        $payload[] = "\x1D\x56\x41\x10"; // Partial cut

        // Optional Beeper (Buzzer) untuk menarik perhatian dapur
        // $payload[] = "\x1B\x42\x05\x02"; // ESC B 5 2 (Bunyi 5 kali, interval 2x50ms)

        return $payload;
    }
}
