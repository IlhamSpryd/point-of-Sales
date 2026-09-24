<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Events\WaiterCalled;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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
            return response()->json(['message' => 'Sesi meja tidak valid.'], 422);
        }

        // Debounce: maks 1 panggilan per meja per 60 detik.
        $lockKey = "waiter_call_table_{$tableId}";
        if (! Cache::add($lockKey, true, 60)) {
            return response()->json(['message' => "Panggilan untuk {$tableName} sudah dikirim, staf sedang menuju ke sana."]);
        }

        Log::channel('stack')->info("[WAITER-CALL] Pelanggan di {$tableName} (ID:{$tableId}) memanggil staf.");

        // PATCH FOR P-09: Dispatch event push notification ke KDS/Kasir
        WaiterCalled::dispatch($tableId, $tableName);

        return response()->json(['message' => "Panggilan terkirim. Staf sedang menuju {$tableName}."]);
    }
}
