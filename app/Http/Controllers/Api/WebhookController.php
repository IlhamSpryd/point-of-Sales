<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookOrderJob;
use App\Modules\Organization\Models\Store;
use App\Modules\Organization\Models\Tenant;
use App\Modules\Sales\Models\ChannelOrderLog;
use App\Platform\Database\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $provider = (string) $request->route('provider');

        // 1. Kumpulkan seluruh identitas tenant yang dikirimkan oleh pemanggil
        $routeTenant = $request->route('tenant');
        $headerTenant = $request->header('X-Tenant-ID') ?? $request->header('x-tenant-id');
        $queryTenant = $request->query('tenant_id');

        $providedTenants = array_filter([
            'route' => $routeTenant,
            'header' => $headerTenant,
            'query' => $queryTenant,
        ], fn ($val) => $val !== null && $val !== '');

        // 2. Fail-closed jika tenant tidak terdefinisi (TIDAK PERNAH fallback ke default tenant)
        if (empty($providedTenants)) {
            Log::warning('Webhook rejected: tenant context missing from route, header, and query.');

            return response()->json(['message' => 'Tenant context missing'], 400);
        }

        // 3. Deteksi Tenant Mismatch / Manipulasi Route vs Header vs Query
        $uniqueTenants = array_unique(array_map('intval', $providedTenants));
        if (count($uniqueTenants) > 1) {
            Log::warning('Webhook rejected: conflicting tenant identifiers provided.', $providedTenants);

            return response()->json(['message' => 'Tenant ID mismatch detected'], 400);
        }

        $tenantId = reset($uniqueTenants);

        // 4. Verifikasi keberadaan dan keabsahan tenant di database
        $tenant = Tenant::find($tenantId);
        if (! $tenant || $tenant->trashed()) {
            Log::warning("Webhook rejected: tenant #{$tenantId} not found.");

            return response()->json(['message' => 'Tenant not found'], 404);
        }

        if ($tenant->isSuspended()) {
            Log::warning("Webhook rejected: tenant #{$tenantId} is suspended.");

            return response()->json(['message' => 'Tenant is suspended'], 403);
        }

        // 5. Verifikasi store jika disertakan pemanggil atau dari body
        $headerStore = $request->header('X-Store-ID') ?? $request->header('x-store-id');
        $queryStore = $request->query('store_id');
        $bodyStore = $request->input('store_id') ?? $request->input('branch_id');
        $providedStores = array_filter([
            'header' => $headerStore,
            'query' => $queryStore,
            'body' => $bodyStore,
        ], fn ($val) => $val !== null && $val !== '');

        $storeId = null;
        if (! empty($providedStores)) {
            $uniqueStores = array_unique(array_map('intval', $providedStores));
            if (count($uniqueStores) > 1) {
                return response()->json(['message' => 'Store ID mismatch detected'], 400);
            }
            $storeId = reset($uniqueStores);
            // @tenant-bypass: cross_tenant_reconciliation
            if (! Store::withoutTenantScope()->where('id', $storeId)->where('tenant_id', $tenantId)->exists()) {
                Log::warning("Webhook rejected: store #{$storeId} does not belong to tenant #{$tenantId}.");

                return response()->json(['message' => 'Store does not belong to tenant'], 422);
            }
        } else {
            // Selesaikan store default untuk tenant jika hanya ada 1 store atau fallback ke store pertama
            // @tenant-bypass: cross_tenant_reconciliation
            $storeId = Store::withoutTenantScope()->where('tenant_id', $tenantId)->orderBy('id')->value('id');
        }

        $externalOrderId = $request->input('id') ?? $request->input('order_id');
        if (! $externalOrderId) {
            return response()->json(['message' => 'Missing order_id'], 422);
        }

        // HMAC Signature Verification untuk OFD (GrabFood / GoFood)
        $ofdManager = app(\App\Modules\Integration\Services\OnlineFoodDeliveryManager::class);
        if ($ofdManager->hasDriver($provider)) {
            $normalizedProvider = strtolower($provider);
            $ofdConfig = \Illuminate\Support\Facades\DB::table('ofd_channel_configs')
                ->where('tenant_id', $tenantId)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->where('provider', $normalizedProvider)
                ->first();

            if ($ofdConfig !== null && ! $ofdConfig->is_active) {
                Log::warning("Webhook rejected: {$provider} OFD channel is inactive on tenant #{$tenantId}.");

                return response()->json(['message' => 'OFD channel is inactive'], 403);
            }

            if ($ofdConfig !== null) {
                if (empty($ofdConfig->signing_secret)) {
                    Log::warning("Webhook rejected: empty OFD signing secret for {$provider} on tenant #{$tenantId}.");

                    return response()->json(['message' => 'Webhook signing secret not configured'], 401);
                }

                try {
                    $secret = \Illuminate\Support\Facades\Crypt::decryptString($ofdConfig->signing_secret);
                } catch (\Throwable) {
                    $secret = $ofdConfig->signing_secret;
                }
            } else {
                $globalSecret = (string) config("services.webhooks.{$normalizedProvider}_secret", '');
                if ($globalSecret === '') {
                    Log::warning("Webhook rejected: missing active OFD config and signing secret for {$provider} on tenant #{$tenantId}.");

                    return response()->json(['message' => 'Webhook signing secret not configured'], 401);
                }
                $secret = $globalSecret;
            }

            $driver = $ofdManager->driver($provider);
            $signature = match ($normalizedProvider) {
                'grabfood' => $request->header('X-Grab-Signature') ?? $request->header('x-grab-signature') ?? $request->header('X-Signature'),
                'gofood' => $request->header('X-Gojek-Signature') ?? $request->header('x-gojek-signature') ?? $request->header('X-Signature'),
                default => $request->header('X-Signature') ?? $request->header('x-signature'),
            };

            $rawContent = $request->getContent();
            $timestamp = (string) ($request->header('X-Timestamp') ?? $request->header('x-timestamp') ?? '');
            $isTimestampedGlobalMatch = $ofdConfig === null
                && $timestamp !== ''
                && is_string($signature)
                && hash_equals(hash_hmac('sha256', $rawContent.$timestamp, (string) $secret), $signature);

            if (! $isTimestampedGlobalMatch && ! $driver->verifyWebhookSignature($rawContent, $signature, (string) $secret)) {
                Log::warning("Webhook rejected: invalid HMAC signature for {$provider} on tenant #{$tenantId}.");

                return response()->json(['message' => 'Invalid webhook signature'], 401);
            }
        }

        return app(TenantContext::class)->runAs($tenantId, $storeId ? (int) $storeId : null, function () use ($request, $provider, $tenantId, $storeId, $externalOrderId) {
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
                    // SEC-06/SEC-02: Hanya tangani duplicate entry (1062) aktual
                    if (DatabaseErrorClassifier::isDuplicateEntry($e)) {
                        return response()->json(['message' => 'Order already processed (duplicate)'], 200);
                    }
                    throw $e;
                }

                // Lempar ke Background Job agar respon API secepat kilat (Zero-Latency)
                ProcessWebhookOrderJob::dispatch($log, $tenantId, $storeId ? (int) $storeId : null);

                return response()->json(['message' => 'Accepted for processing'], 202);

            } catch (\Exception $e) {
                Log::error('Webhook DB error: '.$e->getMessage());

                return response()->json(['message' => 'Internal Server Error'], 500);
            }
        });
    }
}
