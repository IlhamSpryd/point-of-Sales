<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Table;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan pelanggan sudah memiliki sesi meja aktif (hasil scan QR)
 * sebelum boleh mengakses cart dan checkout. Mencegah akses langsung
 * ke /cart atau /checkout tanpa pernah melewati validasi QR Code.
 */
class EnsureTableSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $tableId = $request->session()->get('current_table_id');

        if (! $tableId) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sesi meja tidak ditemukan. Silakan scan ulang QR Code.',
                ], 403);
            }
            abort(403, 'Silakan scan QR Code di meja Anda terlebih dahulu.');
        }

        // PATCH FOR S-10: validasi meja masih aktif di SETIAP request, bukan
        // hanya saat scan QR awal — mencegah sesi basi mengakses meja nonaktif.
        $table = Table::where('id', $tableId)->where('is_active', true)->first();
        if (! $table) {
            $request->session()->forget(['current_table_id', 'current_table_name']);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Meja ini sudah tidak aktif. Silakan scan ulang QR Code.',
                ], 403);
            }
            abort(403, 'Meja ini sudah tidak aktif. Silakan scan ulang QR Code.');
        }

        return $next($request);
    }
}
