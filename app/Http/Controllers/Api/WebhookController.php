<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookOrderJob;
use App\Models\ChannelOrderLog;
use App\Services\Context\TenantContext;
use App\Services\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $provider)
    {
        $tenantId = config('services.webhooks.default_tenant_id');
        if (! $tenantId) {
            Log::critical('Webhook received but WEBHOOK_DEFAULT_TENANT_ID is not set in environment. Failing closed to prevent cross-tenant data corruption.');

            return response()->json(['message' => 'Service Unavailable: Tenant Context Missing'], 503);
        }
        $storeId = config('services.webhooks.default_store_id');

        $externalOrderId = $request->input('id') ?? $request->input('order_id');
        if (! $externalOrderId) {
            return response()->json(['message' => 'Missing order_id'], 422);
        }

        return app(TenantContext::class)->runAs((int) $tenantId, $storeId ? (int) $storeId : null, function () use ($request, $provider, $tenantId, $storeId, $externalOrderId) {
            try {
                try {
                    $log = ChannelOrderLog::create([
                        'tenant_id' => $tenantId,
                        'provider' => $provider,
                        'external_order_id' => $externalOrderId,
                        'payload' => $request->all(),
                        'status' => 'pending',
                    ]);
                } catch (QueryException $e) {
                    // SEC-06/SEC-02: Hanya tangani duplicate entry (1062) aktual, bukan SQLSTATE 23000 generik.
                    if (DatabaseErrorClassifier::isDuplicateEntry($e)) {
                        return response()->json(['message' => 'Order already processed (duplicate)'], 200);
                    }
                    throw $e;
                }

                // Lempar ke Background Job agar respon API secepat kilat (Zero-Latency)
                ProcessWebhookOrderJob::dispatch($log, (int) $tenantId, $storeId ? (int) $storeId : null);

                return response()->json(['message' => 'Accepted for processing'], 202);

            } catch (\Exception $e) {
                Log::error('Webhook DB error: '.$e->getMessage());

                return response()->json(['message' => 'Internal Server Error'], 500);
            }
        });
    }
}
