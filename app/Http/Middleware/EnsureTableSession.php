<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Context\TenantContext;
use App\Enums\TableStatusEnum;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Sales\Models\Table;
use App\Modules\Subscription\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memastikan pelanggan sudah memiliki sesi meja aktif (hasil scan QR)
 * sebelum boleh mengakses cart dan checkout. Mencegah akses langsung
 * ke /cart atau /checkout tanpa pernah melewati validasi QR Code (INV-TBL-05).
 */
class EnsureTableSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $tableId = $request->session()->get('current_table_id');
        $sessionVersion = $request->session()->get('current_qr_token_version');
        $sessionTenantId = $request->session()->get('current_tenant_id');
        $sessionStoreId = $request->session()->get('current_store_id');

        if (! $tableId) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table session not found. Please scan the QR code again.',
                ], 403);
            }
            abort(403, 'Please scan the table QR code first.');
        }

        // Validasi meja masih aktif di SETIAP request
        $table = Table::withoutGlobalScopes()
            ->where('id', $tableId)
            ->whereNull('deleted_at')
            ->first();

        if (! $table || ! $table->is_active || $table->operational_status === TableStatusEnum::Disabled->value || $table->operational_status === 'disabled') {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This table is currently inactive. Please scan the QR code again.',
                ], 403);
            }
            abort(403, 'This table is currently inactive. Please scan the QR code again.');
        }

        // Validasi rotasi token: jika admin merotasi QR, versi berubah dan sesi lama harus ditolak seketika
        if ($sessionVersion !== null) {
            if ((int) $table->qr_token_version !== (int) $sessionVersion) {
                $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Table QR code has been refreshed. Please scan the latest physical QR code.',
                    ], 410);
                }
                abort(410, 'Table QR code has been refreshed. Please scan the latest physical QR code.');
            }
        } else {
            $request->session()->put('current_qr_token_version', (int) ($table->qr_token_version ?? 1));
        }

        if (! $table->tenant_id || ! $table->store_id) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);
            abort(403, 'Table is not linked to any tenant or branch.');
        }

        // Validasi batas tenant dan store
        if ($sessionTenantId && (int) $table->tenant_id !== (int) $sessionTenantId) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table session does not match the active tenant.',
                ], 403);
            }
            abort(403, 'Table session does not match the active tenant.');
        }

        if ($sessionStoreId && (int) $table->store_id !== (int) $sessionStoreId) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Table session does not match the active branch.',
                ], 403);
            }
            abort(403, 'Table session does not match the active branch.');
        }

        $tenant = Tenant::find($table->tenant_id);
        if (! $tenant || $tenant->trashed() || $tenant->isSuspended()) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ordering service is currently unavailable or suspended.',
                ], 403);
            }
            abort(403, 'Ordering service is currently unavailable or suspended.');
        }

        $store = Store::withoutTenantScope()
            ->where('tenant_id', $table->tenant_id)
            ->where('id', $table->store_id)
            ->first();

        if (! $store || ! $store->is_active) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Branch is currently inactive.',
                ], 403);
            }
            abort(403, 'Branch is currently inactive.');
        }

        if (! $store->supportsFnb()) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);
            abort(403, 'Branch does not support table management.');
        }

        $sessionId = $request->session()->get('current_table_session_id');
        $sessionToken = $request->session()->get('current_table_session_token');

        if ($sessionId !== null) {
            $tableSession = \Illuminate\Support\Facades\DB::table('table_sessions')
                ->where('id', $sessionId)
                ->where('table_id', $tableId)
                ->where('tenant_id', $table->tenant_id)
                ->first();

            if (! $tableSession || $tableSession->status !== 'active' || ($tableSession->expires_at && \Illuminate\Support\Carbon::parse($tableSession->expires_at)->isPast())) {
                $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Table session has expired or been closed. Please scan the QR code again.',
                    ], 410);
                }
                abort(410, 'Table session has expired or been closed. Please scan the QR code again.');
            }

            if ($sessionToken && $tableSession->session_token !== $sessionToken) {
                $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);
                abort(403, 'Invalid table session token.');
            }
        }

        $entitlementService = app(EntitlementService::class);
        if (! $entitlementService->isSelfOrderEnabled((int) $table->tenant_id, (int) $table->store_id)) {
            $this->clearSession($request, $sessionTenantId, $sessionStoreId, $tableId);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Self-ordering feature is disabled for this branch.',
                ], 403);
            }
            abort(403, 'Self-ordering feature is disabled for this branch.');
        }

        $context = app(TenantContext::class);
        $context->setTenantId($table->tenant_id);
        $context->setStoreId($table->store_id);

        return $next($request);
    }

    private function clearSession(Request $request, mixed $tenantId, mixed $storeId, mixed $tableId): void
    {
        $keys = [
            'current_table_id',
            'current_table_name',
            'current_tenant_id',
            'current_store_id',
            'current_qr_token_version',
            'current_table_session_id',
            'current_table_session_token',
            'customer_cart',
        ];

        if ($tenantId && $storeId && $tableId) {
            $keys[] = "customer_cart_{$tenantId}_{$storeId}_{$tableId}";
        }

        $request->session()->forget($keys);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(TenantContext::class)->clear();
    }
}
