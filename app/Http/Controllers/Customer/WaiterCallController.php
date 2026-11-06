<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Events\WaiterCalled;
use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Services\Context\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PATCH FOR P-09: Panggil waiter nyata (logging + rate-limit via cache lock,
 * bukan toast palsu). Integrasi ke notifikasi aktif (Push/Pusher/KDS bell)
 * bisa ditambah di sini tanpa mengubah frontend.
 */
class WaiterCallController extends Controller
{
    public function store(): JsonResponse
    {
        $tableId = session('current_table_id');
        $tableName = session('current_table_name');

        if (! $tableId) {
            return response()->json(['message' => 'Invalid table session.'], 422);
        }

        // Debounce: maks 1 panggilan per meja per 60 detik.
        $lockKey = "waiter_call_table_{$tableId}";
        if (! Cache::add($lockKey, true, 60)) {
            return response()->json(['message' => "Staff has already been notified for {$tableName} and is on their way."]);
        }

        Log::channel('stack')->info("[WAITER-CALL] Customer at {$tableName} (ID:{$tableId}) called staff.");

        $table = Table::withoutGlobalScopes()->find($tableId);
        $tenantId = $table ? (int) $table->tenant_id : app(TenantContext::class)->getTenantId();
        $storeId = $table ? (int) $table->store_id : app(TenantContext::class)->getStoreId();

        // Dispatch event push notification ke KDS/Kasir inside afterCommit
        DB::afterCommit(function () use ($tableId, $tableName, $tenantId, $storeId) {
            WaiterCalled::dispatch($tableId, $tableName, $tenantId, $storeId);
        });

        return response()->json(['message' => "Call sent. Staff is on the way to {$tableName}."]);
    }
}
