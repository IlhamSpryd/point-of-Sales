<?php

declare(strict_types=1);

namespace App\Modules\Integration\Contracts;

/**
 * Kontrak Gateway WhatsApp Business API (DEC-007).
 * Mengabstraksi komunikasi eksternal ke WhatsApp BSP (Qiscus, Twilio, dll).
 */
interface WhatsAppGatewayInterface
{
    /**
     * Kirim pesan berbasis template resmi WhatsApp Business.
     *
     * @param  string  $toPhone  Nomor tujuan format E.164 (mis. +628123456789)
     * @param  string  $templateId  Nama template yang disetujui Meta
     * @param  array<string, mixed>  $parameters  Variabel substitusi template
     * @return array{
     *     success: bool,
     *     message_id: string|null,
     *     error: string|null
     * }
     */
    public function sendTemplateMessage(string $toPhone, string $templateId, array $parameters): array;

    /**
     * Kirim pesan teks bebas (untuk sesi percakapan 24 jam aktif).
     *
     * @param  string  $toPhone
     * @param  string  $message
     * @return array{
     *     success: bool,
     *     message_id: string|null,
     *     error: string|null
     * }
     */
    public function sendTextMessage(string $toPhone, string $message): array;
}

