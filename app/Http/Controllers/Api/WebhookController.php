<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookOrderJob;
use App\Models\ChannelOrderLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $provider)
    {
        $externalOrderId = $request->input('order_id');

        if (! $externalOrderId) {
            return response()->json(['message' => 'Missing order_id'], 422);
        }

        try {
            // Gunakan firstOrCreate untuk mengecek Idempotency
            $log = ChannelOrderLog::firstOrCreate(
                ['provider' => $provider, 'external_order_id' => $externalOrderId],
                ['payload' => $request->all(), 'status' => 'pending']
            );

            // Jika status bukan pending (misal completed/processing), berarti ini request duplikat.
            // Tetap jawab 200/202 agar Grab/GoFood berhenti me-retry, tapi JANGAN diproses ulang.
            if (! $log->wasRecentlyCreated && $log->status !== 'pending') {
                return response()->json(['message' => 'Order already processed'], 200);
            }

            // Lempar ke Background Job agar respon API secepat kilat (Zero-Latency)
            ProcessWebhookOrderJob::dispatch($log);

            return response()->json(['message' => 'Accepted for processing'], 202);

        } catch (\Exception $e) {
            Log::error('Webhook DB error: '.$e->getMessage());

            return response()->json(['message' => 'Internal Server Error'], 500);
        }
    }
}
