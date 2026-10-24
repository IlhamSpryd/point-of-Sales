<?php

namespace App\Console\Commands;

use App\Models\OwnerOnboardingToken;
use App\Models\ProvisioningRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Domain 1 (DB-005 / DB-006) — rekonsiliasi klaim ledger provisioning yang
 * terbengkalai.
 *
 * Dua bentuk kerusakan yang ditangani, keduanya timbul dari crash:
 *
 *  1. Klaim 'processing' yang ditinggalkan. Worker mati di tengah transaksi
 *     bisnis. Transaksi tersebut sudah rollback (tidak ada tenant), tetapi
 *     ledger tetap 'processing' selamanya. Command ini mengembalikannya ke
 *     'pending' agar retry dengan key yang sama boleh jalan lagi.
 *
 *  2. UNDO yang belum ditulis. Proses mati setelah transaksi bisnis COMMIT
 *     tetapi sebelum ledger di-update (crash/timeout/reboot). Tenant, owner,
 *     token, dan baris outbox SUDAH ada, ledger masih 'pending'/'processing'.
 *     Command ini MENYELESAIKAN ledger dari fakta yang sudah commit — bukan
 *     dengan membuat tenant kedua.
 *
 * Deteksi bentuk 2 memakai KUNCI IDEMPOTENCY yang ditulis di dalam transaksi
 * bisnis yang sama (kolom provisioning_requests.idempotency_key diisi saat
 * transaksi berjalan, bukan setelah commit). Karena penanda itu ikut commit
 * bersama tenant, keberadaannya adalah bukti sah bahwa transaksi bisnis
 * selesai. Pencocokan lewat email pemilik saja TIDAK dipakai: email tidak
 * unik per request (satu pemilik boleh mendaftarkan dua tenant berbeda), jadi
 * ia tidak dapat membedakan "commit yang hilang" dari "tenant sah milik
 * orang lain".
 *
 * Idempoten dan aman untuk worker konkuren: setiap perbaikan adalah
 * conditional UPDATE (status + attempt_count + lease) yang hanya berhasil
 * pada satu pemanggil; sisanya menjadi no-op. Tidak ada INSERT di sini.
 */
class ReconcileProvisioningLedger extends Command
{
    protected $signature = 'provisioning:reconcile
        {--limit=100 : Jumlah maksimum baris yang diperiksa}
        {--dry-run : Hanya laporkan, tidak mengubah data}';

    protected $description = 'Rekonsiliasi klaim ledger provisioning yang terbengkalai (idempoten)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now();

        // TX-009: State machine rekonsiliasi non-terminal:
        // 1. Baris PROCESSING dengan expired lease (atau null)
        // 2. Baris PENDING / FAILED yang berpotensi memiliki committed tenant di database
        $candidates = ProvisioningRequest::withoutGlobalScopes()
            ->where(function ($q) use ($cutoff) {
                // Klaim processing yang sudah expired
                $q->where(function ($proc) use ($cutoff) {
                    $proc->where('status', ProvisioningRequest::STATUS_PROCESSING)
                        ->where(function ($time) use ($cutoff) {
                            $time->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', $cutoff);
                        });
                })
                // Atau baris non-terminal lain yang mungkin transaksi bisnisnya sebenarnya sudah commit
                    ->orWhere(function ($other) {
                        $other->whereIn('status', [ProvisioningRequest::STATUS_PENDING, ProvisioningRequest::STATUS_FAILED])
                            ->where('attempt_count', '>', 0);
                    });
            })
            ->where('status', '!=', ProvisioningRequest::STATUS_COMPLETED)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $completed = 0;
        $released = 0;

        foreach ($candidates as $ledger) {
            $facts = $this->committedFacts($ledger);

            if ($facts !== null) {
                // Bukti commit ditemukan (tenant + user committed) -> rekonsiliasi ke COMPLETED
                if ($dryRun) {
                    $this->line("[dry-run] complete {$ledger->idempotency_key} to tenant {$facts['tenant_id']}");
                    $completed++;

                    continue;
                }

                $affected = ProvisioningRequest::withoutGlobalScopes()
                    ->whereKey($ledger->id)
                    ->where('status', $ledger->status)
                    ->where('attempt_count', $ledger->attempt_count)
                    ->update([
                        'status' => ProvisioningRequest::STATUS_COMPLETED,
                        'result_tenant_id' => $facts['tenant_id'],
                        'result_user_id' => $facts['user_id'],
                        'completed_at' => now(),
                        'error_message' => null,
                        'lease_expires_at' => null,
                    ]);

                if ($affected === 1) {
                    $completed++;
                    Log::info('Provisioning ledger reconciled to completed from committed tenant.', [
                        'idempotency_key' => $ledger->idempotency_key,
                        'tenant_id' => $facts['tenant_id'],
                        'user_id' => $facts['user_id'],
                    ]);
                    $this->line("completed {$ledger->idempotency_key} to tenant {$facts['tenant_id']}");
                }

                continue;
            }

            // Tidak ada bukti commit tenant
            // Jika status PROCESSING dan lease kedaluwarsa: buka kembali klaim ke PENDING
            if ($ledger->status === ProvisioningRequest::STATUS_PROCESSING) {
                if ($dryRun) {
                    $this->line("[dry-run] release claim {$ledger->idempotency_key}");
                    $released++;

                    continue;
                }

                $affected = ProvisioningRequest::withoutGlobalScopes()
                    ->whereKey($ledger->id)
                    ->where('status', ProvisioningRequest::STATUS_PROCESSING)
                    ->where('attempt_count', $ledger->attempt_count)
                    ->where(function ($q) {
                        $q->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
                    })
                    ->update([
                        'status' => ProvisioningRequest::STATUS_PENDING,
                        'lease_expires_at' => null,
                        'error_message' => 'claim_expired_released',
                    ]);

                if ($affected === 1) {
                    $released++;
                    Log::warning('Provisioning ledger stale claim released to pending.', [
                        'idempotency_key' => $ledger->idempotency_key,
                        'attempt_count' => $ledger->attempt_count,
                    ]);
                    $this->line("released stale claim {$ledger->idempotency_key}");
                }
            }
        }

        $this->info("Reconcile selesai: {$completed} diselesaikan, {$released} klaim dibuka kembali.");

        return self::SUCCESS;
    }

    /**
     * Bukti commit transaksi bisnis, atau null bila tidak ada.
     *
     * @return array{tenant_id: int, user_id: int|null}|null
     */
    private function committedFacts(ProvisioningRequest $ledger): ?array
    {
        // Penanda idempotency ditulis DI DALAM transaksi bisnis yang sama
        // dengan tenant, jadi ia hanya ada bila transaksi itu benar-benar
        // commit.
        $tenantId = Tenant::withTrashed()
            ->where('provisioning_idempotency_key', $ledger->idempotency_key)
            ->value('id');
        if ($tenantId === null) {
            $tenantId = DB::table('tenants')->where('provisioning_idempotency_key', $ledger->idempotency_key)->value('id');
        }

        if ($tenantId === null) {
            return null;
        }

        // Owner diambil dari token onboarding (dibuat di transaksi yang sama)
        // — bukan lewat pencarian email, yang tidak unik per request.
        $userId = OwnerOnboardingToken::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('user_id');
        if ($userId === null) {
            $userId = DB::table('owner_onboarding_tokens')->where('tenant_id', $tenantId)->orderBy('id')->value('user_id');
        }

        if ($userId !== null && ! DB::table('users')->where('tenant_id', $tenantId)->where('id', $userId)->exists()) {
            return null;
        }

        if ($userId === null) {
            $userId = app(TenantContext::class)->runWithoutTenant(
                fn () => User::query()->where('tenant_id', $tenantId)->orderBy('id')->value('id')
            );
        }

        return ['tenant_id' => (int) $tenantId, 'user_id' => $userId !== null ? (int) $userId : null];
    }
}
