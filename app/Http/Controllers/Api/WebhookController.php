<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookOrderJob;
use App\Models\ChannelOrderLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $provider)
    {
        $externalOrderId = $request->input('id') ?? $request->input('order_id');

        if (! $externalOrderId) {
            return response()->json(['message' => 'Missing order_id'], 422);
        }

        try {
            try {
                $log = ChannelOrderLog::create([
                    'provider' => $provider,
                    'external_order_id' => $externalOrderId,
                    'payload' => $request->all(),
                    'status' => 'pending',
                ]);
            } catch (QueryException $e) {
                // 23000 = Integrity constraint violation (duplicate key)
                if ($e->getCode() === '23000') {
                    return response()->json(['message' => 'Order already processed (duplicate)'], 200);
                }
                throw $e;
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
