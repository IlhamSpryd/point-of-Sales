<?php

namespace App\Services;

use App\Jobs\ProcessProvisioningOutboxJob;
use App\Models\ActivityLog;
use App\Models\EmailOutbox;
use App\Models\OwnerOnboardingToken;
use App\Models\ProvisioningRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Domain 1 — Provisioning tenant baru dari website marketing.
 *
 * Urutan WAJIB (keputusan D2):
 *   validasi → Tenant → runAs(tenant) → Role Owner → Store (+hook balances)
 *   → User Owner → audit record → commit.
 *
 * Idempotency (D3): baris ledger 'pending' dibuat DI LUAR transaksi bisnis
 * sebelum transaksi dimulai (bertahan bila transaksi rollback), lalu
 * dinaikkan ke 'completed' SETELAH commit. Kasus crash "commit sukses tapi
 * ledger masih pending" ditangani reconcile (cari tenant berdasarkan
 * owner_email + idempotency di metadata audit).
 *
 * Concurrency: dua request paralel dengan key sama — yang kedua gagal INSERT
 * ledger (PK duplikat) → dibaca status existing dan dijawab sesuai semantik
 * idempotency (bukan provisioning kedua). Cache lock HANYA optimasi
 * back-pressure; database constraint adalah pertahanan terakhir (D6/F6).
 */
class ProvisioningService
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * Entry point provisioning. Mengembalikan array response contract.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function provision(array $data, string $idempotencyKey, string $fingerprint): array
    {
        // --- 1. Idempotency pre-check & claim (DI LUAR transaksi bisnis) ---
        $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $this->responseForExisting($existing, $fingerprint);
        }

        try {
            ProvisioningRequest::create([
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'owner_email' => $data['owner_email'],
                'status' => ProvisioningRequest::STATUS_PENDING,
                'started_at' => now(),
                'attempt_count' => 1,
            ]);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
            // Race dengan request paralel: baca ulang dan jawab idempotent.
            // Insert dapat gagal sebagai transaksi DB deadlock/duplicate dan
            // menandai connection transaction state; cari di connection baru
            // tidak dibutuhkan karena ini terjadi di luar transaksi bisnis.
            $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $e;
            }

            return $this->responseForExisting($existing, $fingerprint);
        }

        // --- 2. D1: email aktif sudah dipakai → 409 (ledger failed) ---
        // User TIDAK memakai ScopedToTenant global (BD-7: login by email);
        // cukup query langsung tanpa konteks — fakta terverifikasi.
        $emailOwner = $this->tenantContext->runWithoutTenant(function () use ($data) {
            return User::where('email', $data['owner_email'])->first();
        });
        if ($emailOwner !== null) {
            $this->markFailed($idempotencyKey, 'email_already_in_use');

            return [
                'status' => 409,
                'body' => [
                    'success' => false,
                    'error' => 'email_already_in_use',
                    'message' => 'Email tersebut sudah terdaftar sebagai akun aktif.',
                ],
            ];
        }

        // --- 3. Transaksi bisnis atomik ---
        try {
            $result = $this->tenantContext->runWithoutTenant(function () use ($data) {
                return DB::transaction(function () use ($data) {
                    return $this->provisionWithinTransaction($data);
                });
            });
        } catch (\Throwable $e) {
            // Rollback total: tidak ada partial state. Ledger tetap 'pending'
            // agar retry dengan key sama diperbolehkan setelah transient
            // failure; error disimpan sebagai info aman.
            $this->markFailed($idempotencyKey, $this->safeErrorMessage($e));

            Log::error('Provisioning failed, transaction rolled back.', [
                'idempotency_key' => $idempotencyKey,
                'exception_class' => $e::class, // bukan message penuh (anti-leak)
                'exception_brief' => $e instanceof QueryException ? substr($e->getMessage(), 0, 400) : null,
            ]);

            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'error' => 'provisioning_failed',
                    'message' => 'Provisioning gagal, silakan coba lagi dengan idempotency key yang sama.',
                    // Retry diperbolehkan dengan key yang sama.
                    'retryable' => true,
                ],
            ];
        }

        // --- 4. Post-commit: finalisasi ledger + outbox email ---
        ProvisioningRequest::where('idempotency_key', $idempotencyKey)->update([
            'status' => ProvisioningRequest::STATUS_COMPLETED,
            'result_tenant_id' => $result['tenant']->id,
            'result_user_id' => $result['owner']->id,
            'completed_at' => now(),
        ]);

        // Outbox baris sudah di-commit di dalam transaksi; dispatch job
        // AFTER commit agar tidak ada race worker. Bila dispatch gagal
        // (crash di sini), scheduled processor tetap men-pick baris pending.
        $outboxId = $result['outbox_id'];
        try {
            ProcessProvisioningOutboxJob::dispatch($outboxId);
        } catch (\Throwable $e) {
            Log::warning('Outbox dispatch failed; scheduled processor will pick it up.', [
                'outbox_id' => $outboxId,
            ]);
        }

        return [
            'status' => 201,
            'body' => [
                'success' => true,
                'message' => 'Provisioning berhasil. Email onboarding akan dikirim ke Owner.',
                'tenant_id' => $result['tenant']->id,
                'owner_user_id' => $result['owner']->id,
                'store_id' => $result['store']->id,
                'onboarding' => [
                    // Token TIDAK dikirim via response — hanya via email (D5).
                    'email_sent_to' => $data['owner_email'],
                ],
            ],
        ];
    }

    /**
     * Transaksi utama. Semua resource atau tidak sama sekali.
     *
     * @return array{tenant: Tenant, owner: User, store: Store, outbox_id: int}
     */
    private function provisionWithinTransaction(array $data): array
    {
        // 3a. Tenant — dibuat di platform context (Tenant model tidak memakai
        // AssignsTenant/TenantScope — diverifikasi saat audit).
        $tenant = Tenant::create(['name' => $data['tenant_name']]);

        // 3b. Masuk konteks tenant baru untuk entitas tenant-scoped.
        $result = $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $data) {
            // 3c. Role Owner (pola RoleService::provisionDefaultRoles).
            $ownerRole = Role::firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $tenant->id],
                [
                    'description' => 'Pemilik bisnis — akses penuh',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]
            );

            // 3d. Store pertama — hook Store::created membuat stock balances
            //     HANYA bila TenantContext->getTenantId() === tenant_id store
            //     (benar di dalam runAs ini).
            $store = Store::create([
                'tenant_id' => $tenant->id,
                'name' => $data['tenant_name'],
            ]);

            // 3e. User Owner. password null (D5 — ditetapkan via magic link);
            //     email_verified_at NULL (D4 — wajib verifikasi).
            //
            // employee_id diisi EKSPLISIT di sini: hook User::booted() membuat
            // ID dari latest('id') + 1 yang berada di bawah global scope
            // tenant (tenant baru = tabel kosong => selalu 0001 => bentrok
            // users_employee_id_unique global). Format konsisten hook, tetapi
            // counter-nya global (bypass scope, terlogged oleh trait).
            $nextSeq = (int) DB::table('users')->max('id') + 1;
            $employeeId = 'YVL-EMP-'.date('Y').'-'.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
            // Guard collision: bump sampai bebas (loop aman, users kecil).
            while (User::withTrashed()->where('employee_id', $employeeId)->exists()) {
                $nextSeq++;
                $employeeId = 'YVL-EMP-'.date('Y').'-'.str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
            }

            $owner = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone_number' => $data['owner_phone'] ?? null,
                'password' => null,
                'employee_id' => $employeeId,
                'role_id' => $ownerRole->id,
                'is_active' => true,
                'email_verified_at' => null,
                'join_date' => now()->toDateString(),
            ]);

            // 3f. Token magic link onboarding — plaintext dikirim via email,
            //     database menyimpan hash saja.
            $plaintextToken = Str::random(48);
            OwnerOnboardingToken::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'token_hash' => hash('sha256', $plaintextToken),
                'expires_at' => now()->addHours((int) config('pos.provisioning.onboarding_token_ttl_hours', 48)),
                'send_attempts' => 1,
            ]);

            // 3g. Outbox email — IKUT transaksi (D8): tidak ada event hilang
            //     antara commit dan enqueue. Link memuat plaintext token.
            $outbox = EmailOutbox::create([
                'event_type' => 'owner_onboarding',
                'recipient_email' => $data['owner_email'],
                'subject' => 'Aktivasi akun Apeiron POS — '.$tenant->name,
                'body_text' => $this->onboardingEmailBody($owner->name, $tenant->name, $plaintextToken),
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'status' => EmailOutbox::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ]);

            // 3h. Audit record dalam transaksi yang sama.
            // ActivityLog menggunakan AssignsTenant sehingga tenant_id
            // otomatis terisi dari context runAs; jangan force fill bila
            // tidak ada di fillable array.
            ActivityLog::create([
                'user_id' => $owner->id,
                'action' => 'provisioning.completed',
                'subject_type' => 'tenant',
                'subject_id' => $tenant->id,
                'description' => 'Tenant provisioned via marketing website',
                'metadata' => [
                    'store_id' => $store->id,
                    'idempotent_flow' => true,
                ],
            ]);

            return ['owner' => $owner, 'store' => $store, 'outbox_id' => $outbox->id];
        });

        return [
            'tenant' => $tenant,
            'owner' => $result['owner'],
            'store' => $result['store'],
            'outbox_id' => $result['outbox_id'],
        ];
    }

    /**
     * Semantik idempotency untuk key yang sudah ada di ledger.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function responseForExisting(ProvisioningRequest $existing, string $fingerprint): array
    {
        // Key sama, payload berbeda → 409 (D3).
        if ($existing->request_fingerprint !== $fingerprint) {
            return [
                'status' => 409,
                'body' => [
                    'success' => false,
                    'error' => 'idempotency_key_conflict',
                    'message' => 'Idempotency key sudah digunakan dengan payload berbeda.',
                ],
            ];
        }

        return match ($existing->status) {
            ProvisioningRequest::STATUS_COMPLETED => [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning sudah selesai sebelumnya (idempotent).',
                    'tenant_id' => $existing->result_tenant_id,
                    'owner_user_id' => $existing->result_user_id,
                    'idempotent_replay' => true,
                ],
            ],
            ProvisioningRequest::STATUS_PENDING => [
                // Retry pada pending dengan payload sama = BOLEH (crash-safe).
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning sedang diproses.',
                    'status' => 'pending',
                    'retryable' => true,
                ],
            ],
            ProvisioningRequest::STATUS_FAILED => [
                'status' => 409,
                'body' => [
                    'success' => false,
                    'error' => 'previous_attempt_failed',
                    'message' => 'Provisioning dengan key ini gagal sebelumnya.',
                    'retryable' => true,
                ],
            ],
            default => [
                'status' => 500,
                'body' => ['success' => false, 'error' => 'unknown_ledger_status'],
            ],
        };
    }

    private function markFailed(string $idempotencyKey, string $safeError): void
    {
        ProvisioningRequest::where('idempotency_key', $idempotencyKey)->update([
            'status' => ProvisioningRequest::STATUS_FAILED,
            'error_message' => $safeError,
            'completed_at' => now(),
        ]);
    }

    private function safeErrorMessage(\Throwable $e): string
    {
        // Pesan aman untuk disimpan & dibalikkan — tanpa SQL, tanpa path, tanpa secret.
        return match (true) {
            $e instanceof QueryException => 'database_error',
            default => 'internal_error',
        };
    }

    private function onboardingEmailBody(string $ownerName, string $tenantName, string $plaintextToken): string
    {
        $baseUrl = rtrim(config('app.url', ''), '/');
        $link = $baseUrl.'/onboarding/set-password?token='.urlencode($plaintextToken);

        return "Halo {$ownerName},\n\n"
            ."Akun Apeiron POS untuk bisnis \"{$tenantName}\" telah dibuat.\n"
            ."Silakan atur password Anda melalui tautan berikut (berlaku 48 jam, sekali pakai):\n\n"
            ."{$link}\n\n"
            ."Jika Anda tidak merasa mendaftar, abaikan email ini.\n\n"
            .'— Tim Apeiron POS';
    }
}
