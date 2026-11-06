<?php

declare(strict_types=1);

namespace App\Modules\Integration\Services\Adapters;

use App\Modules\Integration\Contracts\WhatsAppGatewayInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adapter resmi untuk Qiscus Omnichannel WhatsApp Business Solution Provider (DEC-007).
 */
class QiscusWhatsAppAdapter implements WhatsAppGatewayInterface
{
    protected string $apiUrl;
    protected string $appId;
    protected string $secretKey;
    protected string $channelId;

    public function __construct()
    {
        $this->apiUrl = (string) config('services.whatsapp.api_url', 'https://multichannel.qiscus.com/api/v2');
        $this->appId = (string) config('services.whatsapp.app_id', 'apeiron_pos');
        $this->secretKey = (string) config('services.whatsapp.secret_key', 'mock_secret');
        $this->channelId = (string) config('services.whatsapp.channel_id', '0');
    }

    /**
     * {@inheritdoc}
     */
    public function sendTemplateMessage(string $toPhone, string $templateId, array $parameters): array
    {
        try {
            $endpoint = rtrim($this->apiUrl, '/').'/whatsapp/send_template';

            $response = Http::timeout(5)
                ->withHeaders([
                    'Qiscus-App-Id' => $this->appId,
                    'Qiscus-Secret-Key' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'channel_id' => $this->channelId,
                    'to' => $toPhone,
                    'template_name' => $templateId,
                    'namespace' => 'apeiron_system',
                    'language' => ['code' => 'id'],
                    'parameters' => $parameters,
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $messageId = $body['data']['message_id'] ?? $body['message_id'] ?? ('msg_'.Str::uuid());

                return [
                    'success' => true,
                    'message_id' => (string) $messageId,
                    'error' => null,
                ];
            }

            $errorMessage = 'Qiscus HTTP Error '.$response->status().': '.$response->body();
            Log::warning('WhatsApp Gateway Template Message Failed.', [
                'phone' => $toPhone,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $errorMessage,
            ];
        } catch (Throwable $e) {
            Log::error('WhatsApp Gateway Connection Exception.', [
                'phone' => $toPhone,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function sendTextMessage(string $toPhone, string $message): array
    {
        try {
            $endpoint = rtrim($this->apiUrl, '/').'/whatsapp/send_message';

            $response = Http::timeout(5)
                ->withHeaders([
                    'Qiscus-App-Id' => $this->appId,
                    'Qiscus-Secret-Key' => $this->secretKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($endpoint, [
                    'channel_id' => $this->channelId,
                    'to' => $toPhone,
                    'message' => $message,
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $messageId = $body['data']['message_id'] ?? $body['message_id'] ?? ('msg_'.Str::uuid());

                return [
                    'success' => true,
                    'message_id' => (string) $messageId,
                    'error' => null,
                ];
            }

            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Qiscus HTTP Error '.$response->status().': '.$response->body(),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}

