<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Services\DepreciationService;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunMonthlyDepreciationCommand extends Command
{
    protected $signature = 'finance:run-depreciation
                            {tenant? : Filter tenant ID tertentu}
                            {--period= : Kode periode dalam format YYYY-MM (default: bulan berjalan)}';

    protected $description = 'Menjalankan proses penyusutan aset tetap bulanan untuk seluruh aset aktif';

    public function handle(
        DepreciationService $depreciationService,
        TenantContext $tenantContext
    ): int {
        $targetTenantId = $this->argument('tenant');
        $periodCode = $this->option('period') ?: now()->format('Y-m');

        $tenants = $targetTenantId
            ? Tenant::where('id', (int) $targetTenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->warn('Tidak ada tenant yang ditemukan untuk diproses.');

            return self::SUCCESS;
        }

        $this->info("Memulai eksekusi penyusutan aset tetap untuk periode [{$periodCode}]...");

        $totalAllProcessed = 0;
        $totalAllDepreciation = '0.00';

        foreach ($tenants as $tenant) {
            $tenantContext->setTenantId($tenant->id);

            // Cari atau buat periode akuntansi
            $date = Carbon::createFromFormat('Y-m', $periodCode);
            $year = (int) $date->year;
            $month = (int) $date->month;

            $period = AccountingPeriod::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'fiscal_year' => $year,
                    'period_number' => $month,
                ],
                [
                    'period_code' => $periodCode,
                    'start_date' => $date->copy()->startOfMonth()->toDateString(),
                    'end_date' => $date->copy()->endOfMonth()->toDateString(),
                    'status' => AccountingPeriodStatusEnum::OPEN,
                    'is_reconciled' => false,
                ]
            );

            $actor = User::where('tenant_id', $tenant->id)->first();
            if (! $actor) {
                $this->warn("Tenant #{$tenant->id} [{$tenant->name}] dilewati: Tidak ada user actor.");

                continue;
            }

            $result = $depreciationService->postMonthlyDepreciation($period, $tenant->id, $actor);

            $this->line("Tenant #{$tenant->id} [{$tenant->name}]: {$result['total_processed']} aset disusutkan, Total: Rp ".number_format((float) $result['total_depreciation_amount'], 2));

            $totalAllProcessed += $result['total_processed'];
            $totalAllDepreciation = bcadd($totalAllDepreciation, (string) $result['total_depreciation_amount'], 2);
        }

        $this->info("Eksekusi penyusutan selesai. Total diproses: {$totalAllProcessed} aset, Total depresiasi: Rp ".number_format((float) $totalAllDepreciation, 2));

        return self::SUCCESS;
    }
}
