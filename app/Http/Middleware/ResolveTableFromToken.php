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
            ->where('is_active', true)
            ->first();

        if (! $table) {
            // Token salah, meja nonaktif, atau QR palsu — tolak dengan pesan ramah,
            // BUKAN error 404 teknis yang membingungkan pelanggan awam.
            abort(404, 'QR Code tidak valid atau meja sedang tidak aktif. Silakan panggil staf kami.');
        }

        // PATCH FOR S-10: cegah session fixation — regenerasi ID sesi
        // saat pelanggan memulai sesi baru (scan QR meja beda ATAU pertama).
        $oldTableId = $request->session()->get('current_table_id');
        if ($oldTableId !== $table->id) {
            $request->session()->regenerate();
        }

        // Simpan ke session agar halaman Cart & Checkout (yang URL-nya TIDAK
        // membawa token) tetap tahu pelanggan ini duduk di meja mana.
        $request->session()->put('current_table_id', $table->id);
        $request->session()->put('current_table_name', $table->table_name);

        return $next($request);
    }
}
