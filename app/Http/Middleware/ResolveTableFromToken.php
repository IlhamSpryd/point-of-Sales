<?php

namespace App\Http\Middleware;

use App\Models\Table;
use App\Services\Context\TenantContext;
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

        $table = Table::withoutGlobalScopes()
            ->where('secure_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $table) {
            abort(404, 'QR Code tidak valid atau meja sedang tidak aktif. Silakan panggil staf kami.');
        }

        if (! $table->tenant_id) {
            abort(404, 'Meja tidak terhubung dengan tenant mana pun.');
        }

        $context = app(TenantContext::class);
        $context->setTenantId($table->tenant_id);
        $context->setStoreId($table->store_id);

        $oldTableId = $request->session()->get('current_table_id');
        if ($oldTableId !== $table->id) {
            $request->session()->regenerate();
        }

        $request->session()->put('current_table_id', $table->id);
        $request->session()->put('current_table_name', $table->table_name);
        $request->session()->put('current_tenant_id', $table->tenant_id);
        $request->session()->put('current_store_id', $table->store_id);

        return $next($request);
    }
}
