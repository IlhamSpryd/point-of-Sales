<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Services\AccountingPeriodService;
use App\Services\Context\TenantContext;
use Illuminate\Console\Command;

class ReconcileFinancialLedgersCommand extends Command
{
    protected $signature = 'finance:reconcile-ledgers
                            {tenant? : Filter tenant ID tertentu}
                            {--period= : Kode periode YYYY-MM atau ID periode}
                            {--all : Rekonsiliasi seluruh periode yang masih OPEN}';

    protected $description = 'Menjalankan evaluasi 7 Gerbang Rekonsiliasi Finansial (Reconciliation Gates) sebelum penutupan periode akuntansi';

    public function handle(
        AccountingPeriodService $periodService,
        TenantContext $tenantContext
    ): int {
        $targetTenantId = $this->argument('tenant');
        $periodInput = $this->option('period');
        $allOpen = (bool) $this->option('all');

        $tenants = $targetTenantId
            ? Tenant::where('id', (int) $targetTenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('Tidak ada tenant yang ditemukan untuk direkonsiliasi.');

            return self::SUCCESS;
        }

        $overallPassed = true;

        foreach ($tenants as $tenant) {
            $tenantContext->setTenantId($tenant->id);
            $this->info("=== Memeriksa Rekonsiliasi Tenant #{$tenant->id} [{$tenant->name}] ===");

            $periods = collect();

            if ($periodInput) {
                if (is_numeric($periodInput)) {
                    $p = AccountingPeriod::withoutTenantScope()
                        ->where('tenant_id', $tenant->id)
                        ->where('id', (int) $periodInput)
                        ->first();
                } else {
                    $p = AccountingPeriod::withoutTenantScope()
                        ->where('tenant_id', $tenant->id)
                        ->where('period_code', $periodInput)
                        ->first();
                }

                if (! $p) {
                    $this->error("Periode [{$periodInput}] tidak ditemukan untuk tenant #{$tenant->id}.");
                    $overallPassed = false;

                    continue;
                }
                $periods->push($p);
            } elseif ($allOpen) {
                $periods = AccountingPeriod::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('status', ['OPEN', 'SOFT_CLOSED'])
                    ->orderBy('start_date')
                    ->get();
            } else {
                // Default: periode bulan berjalan
                $currentPeriodCode = now()->format('Y-m');
                $p = AccountingPeriod::withoutTenantScope()
                    ->where('tenant_id', $tenant->id)
                    ->where('period_code', $currentPeriodCode)
                    ->first();

                if (! $p) {
                    // Coba resolve atau buat periode berjalan
                    $p = $periodService->createFiscalYearPeriods((int) now()->year, 1, $tenant->id)
                        ->firstWhere('period_code', $currentPeriodCode);
                }

                if ($p) {
                    $periods->push($p);
                }
            }

            if ($periods->isEmpty()) {
                $this->warn("Tidak ada periode aktif yang ditemukan untuk tenant #{$tenant->id}.");

                continue;
            }

            $actor = User::where('tenant_id', $tenant->id)->first();

            foreach ($periods as $period) {
                $this->line("--> Mengevaluasi Periode: [{$period->period_code}] (Status: {$period->status->value})");

                $results = $periodService->evaluateClosingGates($period, $actor);
                $rows = [];
                $periodPassed = true;

                foreach ($results as $gate) {
                    $statusFormatted = match ($gate['status']) {
                        'PASSED' => '<info>PASSED</info>',
                        'FAILED' => '<error>FAILED</error>',
                        'WAIVED_BY_DIRECTOR' => '<comment>WAIVED</comment>',
                        default => $gate['status'],
                    };

                    if ($gate['status'] === 'FAILED') {
                        $periodPassed = false;
                        $overallPassed = false;
                    }

                    $diagStr = json_encode($gate['diagnostics'], JSON_UNESCAPED_SLASHES);
                    if (strlen($diagStr) > 50) {
                        $diagStr = substr($diagStr, 0, 47).'...';
                    }

                    $rows[] = [
                        $gate['gate_code'],
                        $gate['gate_name'],
                        $statusFormatted,
                        number_format((float) $gate['discrepancy_amount'], 2),
                        $diagStr,
                    ];
                }

                $this->table(
                    ['Gate Code', 'Gate Name', 'Status', 'Discrepancy (IDR)', 'Diagnostics'],
                    $rows
                );

                if ($periodPassed) {
                    $this->info("V Periode {$period->period_code}: Seluruh 7 Gerbang Rekonsiliasi PASSED.");
                } else {
                    $this->error("X Periode {$period->period_code}: Terdapat gerbang rekonsiliasi yang GAGAL. Penutupan periode akan ditolak.");
                }
            }
        }

        return $overallPassed ? self::SUCCESS : self::FAILURE;
    }
}
