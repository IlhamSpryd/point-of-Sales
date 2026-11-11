<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\WebhookDeliveryLog;
use App\Modules\Integration\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OutboundWebhookDispatcherService
{
    public const REPLAY_ATTACK_TOLERANCE_SECONDS = 300; // 5 menit

    /**
     * Hitung tanda tangan digital HMAC-SHA256 sesuai standar Apeiron Webhook Contract.
     * Formula: HMAC-SHA256("{timestamp}.{payload}", secret)
     */
    public function computeSignature(string $payload, string $timestamp, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }

    /**
     * Verifikasi keaslian payload dan tanda tangan di sisi penerima webhook.
     */
    public function verifySignature(
        string $payload,
        string $signature,
        string|int $timestamp,
        string $secret
    ): bool {
        $ts = (int) $timestamp;
        $currentTime = time();

        // Mencegah Replay Attack: toleransi selisih waktu maksimal 300 detik
        if (abs($currentTime - $ts) > self::REPLAY_ATTACK_TOLERANCE_SECONDS) {
            return false;
        }

        $expected = $this->computeSignature($payload, (string) $ts, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Menyiapkan payload standar dan header tanda tangan untuk webhook keluar.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     headers: array<string, string>,
     *     payload: string,
     *     delivery_id: string,
     *     timestamp: int
     * }
     */
    public function prepareWebhook(
        string $event,
        array $data,
        int $tenantId,
        ?int $storeId,
        string $secret
    ): array {
        $deliveryId = Str::uuid()->toString();
        $timestamp = time();

        $payloadArray = [
            'event' => $event,
            'delivery_id' => $deliveryId,
            'timestamp' => now()->toIso8601String(),
            'tenant_id' => $tenantId,
            'store_id' => $storeId,
            'data' => $data,
        ];

        $payloadJson = json_encode($payloadArray, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = $this->computeSignature($payloadJson, (string) $timestamp, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Apeiron-Event' => $event,
            'X-Apeiron-Delivery-ID' => $deliveryId,
            'X-Apeiron-Timestamp' => (string) $timestamp,
            'X-Apeiron-Signature' => $signature,
            'User-Agent' => 'Apeiron-Webhook-Worker/1.0',
        ];

        return [
            'headers' => $headers,
            'payload' => $payloadJson,
            'delivery_id' => $deliveryId,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Kirim webhook keluar ke URL target dengan penanganan timeout dan exponential backoff schedule.
     *
     * @param  array<string, mixed>  $data
     * @return array{status: int, success: bool, delivery_id: string, response: string}
     */
    public function dispatch(
        string $url,
        string $event,
        array $data,
        int $tenantId,
        ?int $storeId,
        string $secret
    ): array {
        $prepared = $this->prepareWebhook($event, $data, $tenantId, $storeId, $secret);

        try {
            $response = Http::withHeaders($prepared['headers'])
                ->timeout(10)
                ->withBody($prepared['payload'], 'application/json')
                ->post($url);

            return [
                'status' => $response->status(),
                'success' => $response->successful(),
                'delivery_id' => $prepared['delivery_id'],
                'response' => $response->body(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 0,
                'success' => false,
                'delivery_id' => $prepared['delivery_id'],
                'response' => $e->getMessage(),
            ];
        }
    }

    /**
     * Memancarkan event webhook keluar ke seluruh pelanggan aktif (WebhookSubscription) tenant (TASK-F3-01).
     *
     * @param  array<string, mixed>  $data
     * @return list<array{subscription_id: int, delivery_id: string, success: bool, status: int}>
     */
    public function dispatchToSubscribers(
        int $tenantId,
        string $event,
        array $data,
        ?int $storeId = null
    ): array {
        /** @var \Illuminate\Database\Eloquent\Collection<int, WebhookSubscription> $subscriptions */
        $subscriptions = WebhookSubscription::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        $results = [];

        foreach ($subscriptions as $sub) {
            if (! $sub->isSubscribedTo($event)) {
                continue;
            }

            $prepared = $this->prepareWebhook($event, $data, $tenantId, $storeId, $sub->secret_key);
            $deliveryId = $prepared['delivery_id'];

            $result = $this->dispatch(
                url: $sub->target_url,
                event: $event,
                data: $data,
                tenantId: $tenantId,
                storeId: $storeId,
                secret: $sub->secret_key
            );

            // Catat log pengiriman imutabel
            WebhookDeliveryLog::create([
                'tenant_id' => $tenantId,
                'subscription_id' => $sub->id,
                'delivery_id' => $deliveryId,
                'event' => $event,
                'payload' => $prepared['payload'],
                'response_status' => $result['status'] > 0 ? $result['status'] : null,
                'response_body' => substr((string) $result['response'], 0, 1000),
                'attempt' => 1,
                'status' => $result['success'] ? 'delivered' : 'failed',
                'delivered_at' => $result['success'] ? now() : null,
            ]);

            // Update statistik langganan
            $sub->last_delivery_at = now();
            if ($result['success']) {
                $sub->failure_count = 0;
            } else {
                $sub->failure_count++;
            }
            $sub->save();

            $results[] = [
                'subscription_id' => $sub->id,
                'delivery_id' => $deliveryId,
                'success' => $result['success'],
                'status' => $result['status'],
            ];
        }

        return $results;
    }

    /**
     * Menghitung penundaan pengulangan (retry delay) dengan strategi exponential backoff.
     * Percobaan 1: 15s, Percobaan 2: 60s, Percobaan 3: 300s, Percobaan 4: 1800s, Percobaan 5: 7200s.
     */
    public function getRetryDelaySeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 15,
            2 => 60,
            3 => 300,
            4 => 1800,
            default => 7200,
        };
    }
}
