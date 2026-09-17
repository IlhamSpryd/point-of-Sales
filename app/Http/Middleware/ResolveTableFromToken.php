<?php

namespace App\Http\Middleware;

use App\Models\Table;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTableFromToken: Memvalidasi secure_token dari QR Code yang di-scan
 * pelanggan, lalu mengikat sesi browser mereka ke meja tersebut.
 * Setelah ini, seluruh alur cart & checkout TIDAK PERNAH menanyakan ulang
 * nomor meja ke pelanggan — mencegah pelanggan salah ketik atau sengaja
 * mengetik nomor meja orang lain.
 */
class ResolveTableFromToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        $table = Table::where('secure_token', $token)
            ->where('status', 'active')
            ->first();

        if (! $table) {
            // Token salah, meja nonaktif, atau QR palsu — tolak dengan pesan ramah,
            // BUKAN error 404 teknis yang membingungkan pelanggan awam.
            abort(404, 'QR Code tidak valid atau meja sedang tidak aktif. Silakan panggil staf kami.');
        }

        // Simpan ke session agar halaman Cart & Checkout (yang URL-nya TIDAK
        // membawa token) tetap tahu pelanggan ini duduk di meja mana.
        $request->session()->put('current_table_id', $table->id);
        $request->session()->put('current_table_number', $table->table_number);

        return $next($request);
    }
}
