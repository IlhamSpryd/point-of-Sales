<?php

namespace App\Services;

use App\Exceptions\StaleWorkerException;
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
use App\Services\DatabaseErrorClassifier;
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
 * Idempotency (D3) — DUA penulisan yang berbeda tujuan:
 *  1. KLAIM atas baris ledger (di luar transaksi bisnis), menyimpan siapa
 *     yang sedang mengerjakan. Berkas transisi klaim ada di {@see claim()}.
 *  2. FINALISASI (post-commit): satu conditional UPDATE menulis status
 *     terminal + hasil + penanda idempotency tenant.
 *
 * Fencing token: setiap klaim menaikkan `attempt_count`, dan setiap penulisan
 * terminal menyertakannya. Worker lambat tidak dapat menimpa klaim yang lebih
 * baru maupun baris yang sudah `completed` (DB-006).
 *
 * Concurrency: dua request paralel dengan key sama — yang kedua gagal INSERT
 * ledger (unique duplikat) atau kalah klaim → dijawab sesuai semantik
 * idempotency (bukan provisioning kedua). Database constraint adalah
 * pertahanan terakhir; cache/lock apa pun hanya optimasi (D6/F6).
 */
class ProvisioningService
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantProvisioningService $tenantProvisioning,
    ) {}

    /**
     * Entry point provisioning. Mengembalikan array response contract.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function provision(array $data, string $idempotencyKey, string $fingerprint): array
    {
        // --- 1. Idempotency pre-check & klaim (DI LUAR transaksi bisnis) ---
        $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            $response = $this->responseForExisting($existing, $fingerprint);
            if ($response !== null) {
                return $response;
            }
        } else {
            try {
                ProvisioningRequest::create([
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'owner_email' => $data['owner_email'],
                    'status' => ProvisioningRequest::STATUS_PENDING,
                    'started_at' => now(),
                    'attempt_count' => 0,
                ]);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                // Race dengan request paralel: baca ulang dan jawab sesuai
                // semantik idempotency (bukan provisioning kedua).
                $existing = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
                if ($existing === null) {
                    throw $e;
                }

                $response = $this->responseForExisting($existing, $fingerprint);
                if ($response !== null) {
                    return $response;
                }
            }
        }

        // Klaim atomik. Hanya satu worker yang berhasil menaikkan
        // attempt_count dari nilai yang dibacanya; yang kalah langsung
        // dijawab 200/pending (bukan provisioning kedua).
        $claim = $this->claim($idempotencyKey, $fingerprint);
        if ($claim === null) {
            return $this->pendingResponse();
        }
        $fencingToken = $claim->attempt_count;

        // A previous worker may have committed the business transaction and
        // crashed before ledger finalization. Detect its in-transaction marker
        // before the duplicate-email guard can misclassify that owner as a
        // conflicting registration.
        $committed = $this->committedProvisioning($idempotencyKey);
        if ($committed !== null) {
            $this->finaliseLedger($idempotencyKey, $fencingToken, [
                'status' => ProvisioningRequest::STATUS_COMPLETED,
                'result_tenant_id' => $committed['tenant']->id,
                'result_user_id' => $committed['owner']->id,
                'completed_at' => now(),
                'error_message' => null,
            ]);
            $this->tenantProvisioning->refreshReadiness($committed['tenant']);

            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning dipulihkan dari transaksi yang sudah commit.',
                    'tenant_id' => $committed['tenant']->id,
                    'owner_user_id' => $committed['owner']->id,
                    'idempotent_replay' => true,
                ],
            ];
        }

        // --- 2. D1: email aktif sudah dipakai -> 409 permanen ---
        // User TIDAK memakai ScopedToTenant global (BD-7: login by email),
        // tetapi query tetap dijalankan tanpa konteks agar tidak bergantung
        // pada scope model.
        $emailOwner = $this->tenantContext->runWithoutTenant(function () use ($data) {
            return User::where('email', $data['owner_email'])->first();
        });
        if ($emailOwner !== null) {
            $this->finaliseLedger($idempotencyKey, $fencingToken, [
                'status' => ProvisioningRequest::STATUS_FAILED,
                'error_message' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                'completed_at' => now(),
            ]);

            return $this->emailConflictResponse();
        }

        // --- 3. Transaksi bisnis atomik (maks. 2 percobaan) ---
        $result = null;
        $attempts = 0;
        $maxBusinessAttempts = 2;

        while ($result === null && $attempts < $maxBusinessAttempts) {
            $attempts++;

            try {
                $result = $this->tenantContext->runWithoutTenant(function () use ($data, $idempotencyKey, $fencingToken) {
                    return DB::transaction(function () use ($data, $idempotencyKey, $fencingToken) {
                        return $this->provisionWithinTransaction($data, $idempotencyKey, $fencingToken);
                    });
                });
            } catch (StaleWorkerException $e) {
                Log::warning('Provisioning worker stale; transaction rolled back.', [
                    'idempotency_key' => $idempotencyKey,
                    'fencing_token' => $fencingToken,
                    'reason' => $e->getMessage(),
                ]);

                // Worker stale (TX-001): transaksi bisnis dibatalkan total.
                // Worker ini tidak boleh mengubah ledger attempt terbaru, tidak boleh
                // mendispatch email outbox, dan tidak boleh mengembalikan 201.
                $currentLedger = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
                if ($currentLedger !== null) {
                    $existingResponse = $this->responseForExisting($currentLedger, $fingerprint);
                    if ($existingResponse !== null) {
                        return $existingResponse;
                    }
                }

                return $this->pendingResponse();
            } catch (\Throwable $e) {
                if ($e instanceof QueryException && DatabaseErrorClassifier::isDuplicateEntry($e)) {
                    // Cek apakah email benturan dengan user yang sudah ada
                    $emailConflict = $this->tenantContext->runWithoutTenant(
                        fn () => User::withTrashed()->where('email', $data['owner_email'])->first()
                    );

                    if ($emailConflict !== null) {
                        $this->finaliseLedger($idempotencyKey, $fencingToken, [
                            'status' => ProvisioningRequest::STATUS_FAILED,
                            'error_message' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                            'completed_at' => now(),
                        ]);

                        return $this->emailConflictResponse();
                    }
                }

                // Retry transien untuk deadlock atau lock wait timeout InnoDB (TX-010)
                if ($e instanceof QueryException && DatabaseErrorClassifier::isLockConflict($e) && $attempts < $maxBusinessAttempts) {
                    usleep(random_int(20_000, 80_000));

                    continue;
                }

                // Rollback total: tidak ada partial state. Ledger ditandai
                // failed (transient) bila worker masih memegang fencing token (TX-012).
                $this->finaliseLedger($idempotencyKey, $fencingToken, [
                    'status' => ProvisioningRequest::STATUS_FAILED,
                    'error_message' => $this->safeErrorMessage($e),
                    'completed_at' => now(),
                ]);

                $rawBrief = $e instanceof QueryException
                    ? (string) ($e->errorInfo[2] ?? $e->getCode())
                    : $e->getMessage();
                $redactedBrief = (string) preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $rawBrief);

                Log::error('Provisioning failed, transaction rolled back.', [
                    'idempotency_key' => $idempotencyKey,
                    'attempt' => $attempts,
                    'exception_class' => $e::class,
                    'exception_brief' => $redactedBrief,
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'success' => false,
                        'error' => 'provisioning_failed',
                        'message' => 'Provisioning gagal, silakan coba lagi dengan idempotency key yang sama.',
                        'retryable' => true,
                    ],
                ];
            }
        }

        if ($result === null) {
            return [
                'status' => 500,
                'body' => [
                    'success' => false,
                    'error' => 'provisioning_failed',
                    'message' => 'Provisioning gagal, silakan coba lagi dengan idempotency key yang sama.',
                    'retryable' => true,
                ],
            ];
        }

        // Ledger telah difinalisasi menjadi STATUS_COMPLETED di DALAM transaksi bisnis (TX-001).
        // Refresh readiness fail-closed pasca commit
        try {
            $this->tenantProvisioning->refreshReadiness($result['tenant']);
        } catch (\Throwable $e) {
            Log::error('Tenant readiness refresh failed after provisioning commit.', [
                'tenant_id' => $result['tenant']->id,
                'exception_class' => $e::class,
            ]);
        }

        // Outbox baris sudah di-commit di dalam transaksi; dispatch job AFTER commit (TX-001)
        try {
            ProcessProvisioningOutboxJob::dispatch($result['outbox_id'], $result['onboarding_token'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('Outbox dispatch failed; scheduled processor will pick it up.', [
                'outbox_id' => $result['outbox_id'],
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
     * Ambil klaim atas baris ledger. Mengembalikan baris yang SUDAH terklaim
     * (dengan attempt_count baru), atau null bila worker lain menang atau
     * baris tidak layak diklaim.
     *
     * Transisi status yang diizinkan (eksplisit):
     *  - pending    -> processing : bila belum ada pemilik aktif, atau lease
     *                              pemilik sebelumnya sudah lewat.
     *  - processing -> processing : HANYA bila lease-nya kedaluwarsa (worker
     *                              pemegang klaim dianggap mati).
     *  - failed     -> processing : HANYA bila kegagalannya transient.
     *                              'email_already_in_use' permanen, tidak
     *                              pernah diklaim ulang.
     *  - completed  -> (tidak pernah) terminal.
     */
    private function claim(string $idempotencyKey, string $fingerprint): ?ProvisioningRequest
    {
        for ($i = 0; $i < 5; $i++) {
            $row = ProvisioningRequest::where('idempotency_key', $idempotencyKey)->first();
            if ($row === null) {
                return null;
            }

            // Fingerprint berbeda = key dipakai ulang untuk payload lain.
            if ($row->request_fingerprint !== $fingerprint) {
                return null;
            }

            if (! $this->isClaimable($row)) {
                return null;
            }

            $nextAttempt = $row->attempt_count + 1;
            $leaseMinutes = max(1, (int) config('pos.provisioning.ledger_lease_minutes', 10));

            $updated = ProvisioningRequest::whereKey($row->id)
                ->where('status', $row->status)
                ->where('attempt_count', $row->attempt_count)
                ->update([
                    'status' => ProvisioningRequest::STATUS_PROCESSING,
                    'attempt_count' => $nextAttempt,
                    'started_at' => now(),
                    'lease_expires_at' => now()->addMinutes($leaseMinutes),
                ]);

            if ($updated === 1) {
                $row->attempt_count = $nextAttempt;
                $row->status = ProvisioningRequest::STATUS_PROCESSING;
                $row->lease_expires_at = now()->addMinutes($leaseMinutes);

                return $row;
            }
        }

        return null;
    }

    private function isClaimable(ProvisioningRequest $row): bool
    {
        if ($row->status === ProvisioningRequest::STATUS_COMPLETED) {
            return false;
        }

        if ($row->status === ProvisioningRequest::STATUS_FAILED) {
            return ! $row->isPermanentlyFailed();
        }

        if ($row->status === ProvisioningRequest::STATUS_PENDING) {
            return $row->lease_expires_at === null || $row->lease_expires_at->isPast();
        }

        if ($row->status === ProvisioningRequest::STATUS_PROCESSING) {
            // Konservatif: klaim lama hanya boleh diambil alih bila leasenya
            // benar-benar kedaluwarsa.
            return $row->lease_expires_at !== null && $row->lease_expires_at->isPast();
        }

        return false;
    }

    /**
     * Penulisan terminal. Menggabungkan status + hasil + penanda idempotency
     * tenant dalam SATU penulisan, sehingga tidak ada jendela di mana tenant
     * sudah ada tetapi ledger belum lengkap.
     *
     * Idempoten & tahan retry: hasil yang sudah tercatat tidak pernah ditimpa
     * (fakta commit), dan baris yang sudah `completed` tidak pernah diturunkan
     * statusnya.
     */
    /**
     * Penulisan terminal (TX-012: return bool hasil conditional update).
     *
     * Idempoten & tahan retry: hasil yang sudah tercatat tidak pernah ditimpa
     * (fakta commit), dan baris yang sudah `completed` tidak pernah diturunkan
     * statusnya.
     */
    private function finaliseLedger(string $idempotencyKey, int $fencingToken, array $attributes): bool
    {
        if ($fencingToken < 1) {
            return false;
        }

        // Single conditional UPDATE is the fencing point. A stale worker cannot
        // pass a read/check then race a newer claim or terminal transition.
        $affected = ProvisioningRequest::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', ProvisioningRequest::STATUS_PROCESSING)
            ->where('attempt_count', $fencingToken)
            ->update([
                'status' => $attributes['status'],
                'completed_at' => $attributes['completed_at'] ?? now(),
                'error_message' => $attributes['error_message'] ?? null,
                'result_tenant_id' => $attributes['result_tenant_id'] ?? null,
                'result_user_id' => $attributes['result_user_id'] ?? null,
                'lease_expires_at' => null,
            ]);

        return $affected === 1;
    }

    /**
     * Transaksi utama (TX-001): Validasi fencing token, business writes, dan finalisasi
     * ledger disatukan ke dalam SATU batas transaksi InnoDB atomik.
     *
     * @return array{tenant: Tenant, owner: User, store: Store, outbox_id: int, onboarding_token: string}
     */
    private function provisionWithinTransaction(array $data, string $idempotencyKey, int $fencingToken): array
    {
        // 1. Kunci baris ledger dan verifikasi hak eksklusif worker (TX-001)
        $ledger = ProvisioningRequest::where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($ledger === null) {
            throw new StaleWorkerException("Baris ledger hilang untuk key {$idempotencyKey}", $idempotencyKey, $fencingToken);
        }

        if ($ledger->status !== ProvisioningRequest::STATUS_PROCESSING) {
            throw new StaleWorkerException("Status ledger bukan processing ({$ledger->status})", $idempotencyKey, $fencingToken);
        }

        if ((int) $ledger->attempt_count !== $fencingToken) {
            throw new StaleWorkerException("Fencing token mismatch (ledger: {$ledger->attempt_count}, worker: {$fencingToken})", $idempotencyKey, $fencingToken);
        }

        if ($ledger->lease_expires_at !== null && $ledger->lease_expires_at->isPast()) {
            throw new StaleWorkerException("Lease worker sudah kedaluwarsa ({$ledger->lease_expires_at})", $idempotencyKey, $fencingToken);
        }

        // 3a. Tenant — dibuat di platform context
        $tenant = Tenant::create([
            'name' => $data['tenant_name'],
            'provisioning_idempotency_key' => $idempotencyKey,
            'readiness_status' => TenantProvisioningService::READINESS_PENDING,
        ]);

        // 3b. Masuk konteks tenant baru untuk entitas tenant-scoped.
        $result = $this->tenantContext->runAs($tenant->id, null, function () use ($tenant, $data) {
            // 3c. Role Owner (deterministik role_code ROL-OWNER — TX-008).
            $ownerRole = Role::firstOrCreate(
                ['name' => 'Owner', 'tenant_id' => $tenant->id],
                [
                    'role_code' => 'ROL-OWNER',
                    'description' => 'Pemilik bisnis — akses penuh',
                    'permissions' => ['*'],
                    'is_active' => true,
                ]
            );

            // 3d. Store pertama
            $store = Store::create([
                'tenant_id' => $tenant->id,
                'name' => $data['tenant_name'],
            ]);

            // 3e. User Owner
            $owner = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'phone_number' => $data['owner_phone'] ?? null,
                'password' => null,
                'role_id' => $ownerRole->id,
                'is_active' => true,
                'email_verified_at' => null,
                'join_date' => now()->toDateString(),
            ]);

            // 3f. Token magic link onboarding (hash SHA-256 tersimpan di database).
            $plaintextToken = Str::random(48);
            $tokenHash = hash('sha256', $plaintextToken);
            OwnerOnboardingToken::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addHours((int) config('pos.provisioning.onboarding_token_ttl_hours', 48)),
                'send_attempts' => 1,
            ]);

            // 3g. Outbox email — IKUT transaksi (D8).
            //     Tautan onboarding memuat plaintext token yang terenkripsi at-rest
            //     di kolom body_text (TX-005, TX-011) sehingga retry tidak merotasi token.
            $outbox = EmailOutbox::create([
                'event_type' => 'owner_onboarding',
                'recipient_email' => $data['owner_email'],
                'subject' => 'Aktivasi akun Apeiron System — '.$tenant->name,
                'body_text' => $this->onboardingEmailBody($owner->name, $tenant->name, $plaintextToken),
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'status' => EmailOutbox::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ]);

            // 3h. Baseline kesiapan tenant
            $this->tenantProvisioning->provisionBaseline($tenant, $owner);

            // 3i. Audit record dalam transaksi yang sama.
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

            return [
                'owner' => $owner,
                'store' => $store,
                'outbox_id' => $outbox->id,
                'onboarding_token' => $plaintextToken,
            ];
        });

        // TX-001 & TX-012: Finalisasi ledger di DALAM batas transaksi yang sama SEBELUM commit!
        $finalized = ProvisioningRequest::whereKey($ledger->id)
            ->where('status', ProvisioningRequest::STATUS_PROCESSING)
            ->where('attempt_count', $fencingToken)
            ->where(function ($q) {
                $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '>', now());
            })
            ->update([
                'status' => ProvisioningRequest::STATUS_COMPLETED,
                'result_tenant_id' => $tenant->id,
                'result_user_id' => $result['owner']->id,
                'completed_at' => now(),
                'error_message' => null,
                'lease_expires_at' => null,
            ]);

        if ($finalized !== 1) {
            throw new StaleWorkerException('Gagal memfinalisasi ledger di dalam transaksi; fencing atau lease hilang.', $idempotencyKey, $fencingToken);
        }

        return [
            'tenant' => $tenant,
            'owner' => $result['owner'],
            'store' => $result['store'],
            'outbox_id' => $result['outbox_id'],
            'onboarding_token' => $result['onboarding_token'],
        ];
    }

    /**
     * Semantik idempotency untuk key yang sudah ada di ledger.
     * Mengembalikan array response, ATAU null bila request boleh diklaim
     * (retry) lewat {@see claim()}.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function responseForExisting(ProvisioningRequest $existing, string $fingerprint): ?array
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

        if ($existing->status === ProvisioningRequest::STATUS_COMPLETED) {
            // Baris selesai tetapi hasil belum tercatat (crash tepat di antara
            // dua penulisan): jangan jawab 200 dengan tenant_id null — minta
            // pemanggil retry, yang akan direkonsiliasi/dilengkapi.
            if (! $existing->result_tenant_id) {
                return $this->pendingResponse();
            }

            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Provisioning sudah selesai sebelumnya (idempotent).',
                    'tenant_id' => $existing->result_tenant_id,
                    'owner_user_id' => $existing->result_user_id,
                    'idempotent_replay' => true,
                ],
            ];
        }

        if ($existing->status === ProvisioningRequest::STATUS_FAILED) {
            // Kegagalan bisnis PERMANEN (email sudah dipakai): retry dengan
            // key yang sama tidak akan pernah berhasil — jawab 409.
            if ($existing->isPermanentlyFailed()) {
                return $this->emailConflictResponse();
            }

            // Transient error (mis. database): boleh diretry dengan key yang
            // sama → serahkan ke claim().
            return null;
        }

        // pending / processing: bila masih ada pemilik klaim aktif, jawab
        // 200/pending agar pemanggil menunggu, bukan membuat provisioning
        // kedua. Klaim yang leasenya sudah lewat akan dikembalikan null,
        // sehingga bisa diambil alih lewat claim().
        if ($existing->status === ProvisioningRequest::STATUS_PROCESSING
            && $existing->lease_expires_at !== null
            && $existing->lease_expires_at->isFuture()) {
            return $this->pendingResponse();
        }

        return null;
    }

    /**
     * Return committed tenant/owner facts written atomically with business data.
     * OwnerOnboardingToken is the primary owner reference; the tenant-scoped
     * query is a defensive fallback for partial legacy records.
     *
     * @return array{tenant: Tenant, owner: User}|null
     */
    private function committedProvisioning(string $idempotencyKey): ?array
    {
        $tenant = Tenant::query()->where('provisioning_idempotency_key', $idempotencyKey)->first();
        if ($tenant === null) {
            return null;
        }

        $ownerId = OwnerOnboardingToken::query()->where('tenant_id', $tenant->id)->value('user_id');
        $owner = $ownerId !== null
            ? $this->tenantContext->runWithoutTenant(fn () => User::query()->where('tenant_id', $tenant->id)->find($ownerId))
            : null;

        if ($owner === null) {
            return null;
        }

        return ['tenant' => $tenant, 'owner' => $owner];
    }

    private function pendingResponse(): array
    {
        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => 'Provisioning sedang diproses.',
                'status' => 'pending',
                'retryable' => true,
            ],
        ];
    }

    private function emailConflictResponse(): array
    {
        return [
            'status' => 409,
            'body' => [
                'success' => false,
                'error' => ProvisioningRequest::ERROR_EMAIL_ALREADY_IN_USE,
                'message' => 'Email tersebut sudah terdaftar sebagai akun aktif.',
            ],
        ];
    }

    /**
     * Hanya benturan unique yang ditangani di sini (TX-010). Exception lain (koneksi
     * mati, FK, disk penuh, dsb) TIDAK boleh disamarkan sebagai benturan duplicate key.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return DatabaseErrorClassifier::isDuplicateEntry($e);
    }

    private function safeErrorMessage(\Throwable $e): string
    {
        // Pesan aman untuk disimpan & dibalikkan — tanpa SQL, tanpa path, tanpa secret.
        return match (true) {
            $e instanceof QueryException => 'database_error',
            default => 'internal_error',
        };
    }

    /**
     * Body email onboarding.
     *
     * PENTING (DB-003): parameter `$tokenHash` adalah HASH token, bukan
     * plaintext. Link yang TERSIMPAN karena itu tidak dapat dipakai untuk
     * menukar password — dump database tidak pernah memuat token yang bisa
     * dipakai ulang. Plaintext hanya ada di memori selama transaksi dan
     * disuntikkan tepat sebelum pengiriman oleh ProcessProvisioningOutboxJob.
     */
    private function onboardingEmailBody(string $ownerName, string $tenantName, string $tokenHash): string
    {
        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
        $relative = route('onboarding.set-password', ['token' => $tokenHash], false);
        $link = $baseUrl.$relative;

        return "Halo {$ownerName},\n\n"
            ."Akun Apeiron System untuk bisnis \"{$tenantName}\" telah dibuat.\n"
            ."Silakan atur password Anda melalui tautan berikut (berlaku 48 jam, sekali pakai):\n\n"
            ."{$link}\n\n"
            ."Jika Anda tidak merasa mendaftar, abaikan email ini.\n\n"
            .'— Tim Apeiron System';
    }

    /**
     * Dipakai ProcessProvisioningOutboxJob: sisipkan plaintext token ke body
     * yang tersimpan. Disediakan sebagai satu titik agar format tautan hanya
     * didefinisikan sekali.
     */
    public static function injectOnboardingToken(string $body, string $tokenHash, string $plaintextToken): string
    {
        return str_replace(
            '/onboarding/set-password/'.$tokenHash,
            '/onboarding/set-password/'.$plaintextToken,
            $body,
        );
    }
}
