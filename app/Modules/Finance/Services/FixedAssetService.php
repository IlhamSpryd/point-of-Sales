<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Context\TenantContext;
use App\Models\AccountingPeriod;
use App\Models\Store;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetCategory;
use App\Modules\Finance\Models\FixedAssetDepreciationSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FixedAssetService
{
    public function __construct(
        protected PostingEngineService $postingEngineService,
        protected JournalEntryService $journalEntryService,
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Mendaftarkan aset tetap baru (status awal DRAFT).
     *
     * @param  array<string, mixed>  $data
     */
    public function createAsset(array $data, User $actor): FixedAsset
    {
        $tenantId = $actor->tenant_id;

        return DB::transaction(function () use ($tenantId, $data, $actor) {
            $categoryId = (int) ($data['category_id'] ?? 0);
            $category = FixedAssetCategory::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $categoryId)
                ->firstOrFail();

            $storeId = (int) ($data['store_id'] ?? $actor->store_id ?? (app()->bound(TenantContext::class) ? app(TenantContext::class)->getStoreId() : null) ?? Store::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id'));
            if ($storeId <= 0) {
                throw new DomainException('FIN-M7-01: Cabang (store_id) aktif wajib tersedia untuk tenant ini.');
            }
            $cost = bcadd((string) ($data['acquisition_cost'] ?? '0.00'), '0', 2);
            $salvage = bcadd((string) ($data['salvage_value'] ?? '0.00'), '0', 2);

            if (bccomp($cost, '0.00', 2) <= 0) {
                throw new DomainException('FIN-M7-01: Nilai perolehan aset tetap wajib lebih besar dari 0.00.');
            }

            if (bccomp($salvage, $cost, 2) >= 0) {
                throw new DomainException('FIN-M7-01: Nilai residu (salvage value) harus lebih kecil dari nilai perolehan aset.');
            }

            $lifeMonths = (int) ($data['useful_life_months'] ?? $category->useful_life_months);
            if ($lifeMonths <= 0) {
                throw new DomainException('FIN-M7-01: Masa manfaat aset tetap minimal 1 bulan.');
            }

            $acqDate = $data['acquisition_date'] ?? now()->toDateString();
            $acqDateStr = $acqDate instanceof CarbonInterface ? $acqDate->toDateString() : Carbon::parse($acqDate)->toDateString();

            $assetCode = $data['asset_code'] ?? ('FA-'.$category->category_code.'-'.date('Ymd').'-'.Str::upper(Str::random(4)));

            return FixedAsset::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'category_id' => $category->id,
                'asset_code' => $assetCode,
                'asset_name' => (string) ($data['asset_name'] ?? "Aset {$category->category_name}"),
                'acquisition_date' => $acqDateStr,
                'acquisition_cost' => $cost,
                'salvage_value' => $salvage,
                'useful_life_months' => $lifeMonths,
                'depreciation_method' => $data['depreciation_method'] ?? $category->depreciation_method,
                'status' => FixedAssetStatusEnum::DRAFT,
                'accumulated_depreciation' => '0.00',
                'net_book_value' => $cost,
                'supplier_id' => ! empty($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'location_note' => $data['location_note'] ?? null,
            ]);
        });
    }

    /**
     * Mengkapitalisasi aset tetap (DRAFT -> ACTIVE).
     * Memposting jurnal kapitalisasi (PR-FA-01): Debit Aset Tetap, Kredit Kas/Bank.
     * Mengenerate jadwal penyusutan (depreciation schedule) garis lurus secara atomik.
     */
    public function capitalize(FixedAsset $asset, User $actor, ?int $paymentAccountId = null): FixedAsset
    {
        if ($asset->status !== FixedAssetStatusEnum::DRAFT) {
            throw new DomainException("Hanya aset berstatus DRAFT yang dapat dikapitalisasi. Status saat ini: {$asset->status->value}.");
        }

        return DB::transaction(function () use ($asset, $actor, $paymentAccountId) {
            $tenantId = $asset->tenant_id;
            $acqDateStr = $asset->acquisition_date instanceof CarbonInterface ? $asset->acquisition_date->toDateString() : (string) $asset->acquisition_date;

            // 1. Posting jurnal kapitalisasi via Posting Engine
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $asset->store_id,
                sourceModule: 'FINANCE',
                sourceType: 'FIXED_ASSET',
                sourceId: $asset->id,
                sourceDocumentNumber: $asset->asset_code,
                eventCode: 'fixed_asset.capitalized',
                idempotencyKey: "fa:cap:{$tenantId}:{$asset->id}",
                occurredAt: now(),
                accountingDate: $acqDateStr,
                payload: [
                    'fixed_asset_category_id' => $asset->category_id,
                    'acquisition_cost' => $asset->acquisition_cost,
                    'asset_code' => $asset->asset_code,
                    'cash_bank_account_id' => $paymentAccountId,
                    'source_cash_bank_id' => $paymentAccountId,
                ],
                userId: $actor->id
            );

            $journal = $this->postingEngineService->postEvent($eventDto);

            // 2. Generate jadwal penyusutan bulanan
            $this->generateDepreciationSchedule($asset);

            // 3. Update status aset menjadi ACTIVE
            $asset->status = FixedAssetStatusEnum::ACTIVE;
            $asset->acquisition_journal_id = $journal->id;
            $asset->save();

            return $asset->fresh(['category', 'schedules', 'acquisitionJournal']);
        });
    }

    /**
     * Mengenerate jadwal penyusutan bulanan menggunakan metode garis lurus (Straight Line).
     * Mencegah rounding error drift dengan menyesuaikan bulan terakhir.
     *
     * @return Collection<int, FixedAssetDepreciationSchedule>
     */
    public function generateDepreciationSchedule(FixedAsset $asset): Collection
    {
        $tenantId = $asset->tenant_id;
        $cost = (string) $asset->acquisition_cost;
        $salvage = (string) $asset->salvage_value;
        $depreciableBase = bcsub($cost, $salvage, 2);
        $totalMonths = $asset->useful_life_months;

        if ($totalMonths <= 0) {
            throw new DomainException('Masa manfaat harus lebih besar dari 0 bulan.');
        }

        // Hapus jadwal PENDING sebelumnya jika ada regenerasi
        $asset->schedules()->where('status', DepreciationScheduleStatusEnum::PENDING)->delete();

        $baseMonthly = bcdiv($depreciableBase, (string) $totalMonths, 2);
        $accumulated = '0.00';
        $startDate = Carbon::parse($asset->acquisition_date)->startOfMonth();

        $schedules = collect();

        for ($i = 1; $i <= $totalMonths; $i++) {
            $periodDate = $startDate->copy()->addMonths($i - 1);
            $year = (int) $periodDate->year;
            $month = (int) $periodDate->month;
            $periodCode = $periodDate->format('Y-m');

            // Temukan atau buat periode akuntansi
            $period = AccountingPeriod::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'fiscal_year' => $year,
                    'period_number' => $month,
                ],
                [
                    'period_code' => $periodCode,
                    'start_date' => $periodDate->copy()->startOfMonth()->toDateString(),
                    'end_date' => $periodDate->copy()->endOfMonth()->toDateString(),
                    'status' => AccountingPeriodStatusEnum::OPEN,
                    'is_reconciled' => false,
                ]
            );

            // Pada bulan terakhir, selisihkan akumulasi agar total tepat sama dengan depreciableBase
            if ($i === $totalMonths) {
                $monthlyAmount = bcsub($depreciableBase, $accumulated, 2);
            } else {
                $monthlyAmount = $baseMonthly;
            }

            $accumulated = bcadd($accumulated, $monthlyAmount, 2);
            $nbvAfter = bcsub($cost, $accumulated, 2);

            $schedule = FixedAssetDepreciationSchedule::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'fixed_asset_id' => $asset->id,
                'accounting_period_id' => $period->id,
                'period_year' => $year,
                'period_month' => $month,
                'depreciation_amount' => $monthlyAmount,
                'accumulated_depreciation_after' => $accumulated,
                'net_book_value_after' => $nbvAfter,
                'status' => DepreciationScheduleStatusEnum::PENDING,
            ]);

            $schedules->push($schedule);
        }

        return $schedules;
    }
}
