<?php

namespace App\Http\Middleware;

use App\Enums\TableStatusEnum;
use App\Models\Store;
use App\Models\Table;
use App\Models\Tenant;
use App\Services\Context\TenantContext;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTableFromToken: Memvalidasi qr_token dari QR Code yang di-scan
 * pelanggan, lalu mengikat sesi browser mereka ke meja dan cabang tersebut.
 * Setelah ini, seluruh alur cart & checkout TIDAK PERNAH menanyakan ulang
 * nomor meja ke pelanggan — mencegah pelanggan salah ketik atau sengaja
 * mengetik nomor meja orang lain (INV-TBL-05).
 */
class ResolveTableFromToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        $table = Table::withoutGlobalScopes()
            ->where(function ($q) use ($token) {
                $q->where('qr_token', $token)
                    ->orWhere('secure_token', $token);
            })
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $table) {
            abort(404, 'QR Code tidak valid atau meja sedang tidak aktif. Silakan panggil staf kami.');
        }

        if ($table->operational_status === TableStatusEnum::Disabled->value || $table->operational_status === 'disabled') {
            abort(404, 'Meja sedang dinonaktifkan.');
        }

        if (! $table->tenant_id || ! $table->store_id) {
            abort(404, 'Meja tidak terhubung dengan tenant atau cabang mana pun.');
        }

        $tenant = Tenant::find($table->tenant_id);
        if (! $tenant || $tenant->trashed() || $tenant->isSuspended()) {
            abort(403, 'Layanan pemesanan sedang tidak aktif atau ditangguhkan.');
        }

        $store = Store::withoutTenantScope()
            ->where('tenant_id', $table->tenant_id)
            ->where('id', $table->store_id)
            ->first();

        if (! $store || ! $store->is_active) {
            abort(403, 'Cabang sedang tidak aktif.');
        }

        $entitlementService = app(EntitlementService::class);
        if (! $entitlementService->isSelfOrderEnabled((int) $table->tenant_id, (int) $table->store_id)) {
            abort(403, 'Fitur pemesanan mandiri (self-order) tidak aktif untuk cabang ini.');
        }

        $context = app(TenantContext::class);
        $context->setTenantId($table->tenant_id);
        $context->setStoreId($table->store_id);

        // Telemetri token legacy: catat event terstruktur jika meja valid masih memakai token non-UUID
        try {
            if ($token && strlen($token) !== 64 && ! Str::isUuid((string) $token)) {
                Log::info('[SECURITY] Legacy QR token access detected', [
                    'event' => 'legacy_qr_token_accessed',
                    'table_id' => $table->id,
                    'tenant_id' => $table->tenant_id,
                    'store_id' => $table->store_id,
                    'is_legacy_token' => true,
                ]);
            }
        } catch (\Throwable) {
            // Fail-safe: kegagalan logging tidak boleh memutus request pelanggan
        }

        $oldTableId = $request->session()->get('current_table_id');
        $oldTokenVersion = $request->session()->get('current_qr_token_version');

        if ($oldTableId !== $table->id || (int) $oldTokenVersion !== (int) $table->qr_token_version) {
            $oldTenantId = $request->session()->get('current_tenant_id');
            $oldStoreId = $request->session()->get('current_store_id');
            if ($oldTenantId && $oldStoreId && $oldTableId) {
                $request->session()->forget("customer_cart_{$oldTenantId}_{$oldStoreId}_{$oldTableId}");
            }
            $request->session()->forget('customer_cart');
            $request->session()->regenerate();
        }

        $request->session()->put('current_table_id', $table->id);
        $request->session()->put('current_table_name', $table->table_name);
        $request->session()->put('current_tenant_id', $table->tenant_id);
        $request->session()->put('current_store_id', $table->store_id);
        $request->session()->put('current_qr_token_version', (int) ($table->qr_token_version ?? 1));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(TenantContext::class)->clear();
    }
}
