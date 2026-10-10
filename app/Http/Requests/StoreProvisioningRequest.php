<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Domain 1 — allowlist ketat untuk request provisioning dari marketing.
 * Field internal (tenant_id, role_id, permissions, email_verified_at,
 * is_active, idempotency KEY dari body, dsb) TIDAK diterima.
 *
 * D1: bila email sudah dipakai akun aktif → 409 (dicek di ProvisioningService,
 * bukan Rule::unique biasa, supaya response bermakna bisnis, bukan 422).
 */
class StoreProvisioningRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi dilakukan HMAC middleware; request ini tidak terkait sesi user.
        return true;
    }

    public function rules(): array
    {
        return [
            'tenant_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_email' => ['required', 'string', 'lowercase', 'email:rfc', 'max:255'],
            'owner_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s()]{6,32}$/'],
        ];
    }

    /**
     * Terima HANYA allowlist; payload lain diabaikan total (anti mass-assignment).
     */
    public function validationData(): array
    {
        return $this->only(['tenant_name', 'owner_name', 'owner_email', 'owner_phone']);
    }
}
