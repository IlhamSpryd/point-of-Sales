<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\AccountingPeriod;
use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\FixedAssetDepreciationSchedule;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
    public function __construct(
        protected PostingEngineService $postingEngineService,
        protected JournalEntryService $journalEntryService
    ) {}

    /**
     * Memproses dan memposting seluruh jadwal penyusutan aset tetap bulanan untuk suatu periode.
     * Mengubah status schedule menjadi POSTED, memposting jurnal PR-FA-02,
     * dan mengupdate accumulated_depreciation serta net_book_value pada aset.
     *
     * @return array<string, mixed>
     */
    public function postMonthlyDepreciation(AccountingPeriod $period, int $tenantId, User $actor): array
    {
        return DB::transaction(function () use ($period, $tenantId, $actor) {
            $schedules = FixedAssetDepreciationSchedule::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('accounting_period_id', $period->id)
                ->where('status', DepreciationScheduleStatusEnum::PENDING)
                ->with(['asset.category'])
                ->get();

            $processed = [];
            $totalAmount = '0.00';

            foreach ($schedules as $schedule) {
                $asset = $schedule->asset;
                if (! $asset || $asset->status !== FixedAssetStatusEnum::ACTIVE) {
                    continue;
                }

                $amount = (string) $schedule->depreciation_amount;

                // Posting jurnal penyusutan bulanan (PR-FA-02)
                $eventDto = new FinancialEventDto(
                    tenantId: $tenantId,
                    storeId: $asset->store_id,
                    sourceModule: 'FINANCE',
                    sourceType: 'FIXED_ASSET_DEPRECIATION',
                    sourceId: $schedule->id,
                    sourceDocumentNumber: "{$asset->asset_code}-{$period->period_code}",
                    eventCode: 'fixed_asset.depreciation.posted',
                    idempotencyKey: "fa:depr:{$tenantId}:{$schedule->id}",
                    occurredAt: now(),
                    accountingDate: $period->end_date,
                    payload: [
                        'fixed_asset_category_id' => $asset->category_id,
                        'depreciation_amount' => $amount,
                        'asset_code' => $asset->asset_code,
                    ],
                    userId: $actor->id
                );

                $journal = $this->postingEngineService->postEvent($eventDto);

                // Update jadwal penyusutan
                $schedule->status = DepreciationScheduleStatusEnum::POSTED;
                $schedule->journal_entry_id = $journal->id;
                $schedule->posted_at = now();
                $schedule->save();

                // Update saldo akumulasi penyusutan dan nilai buku pada aset
                $asset->accumulated_depreciation = (string) $schedule->accumulated_depreciation_after;
                $asset->net_book_value = (string) $schedule->net_book_value_after;

                // Transisi ke FULLY_DEPRECIATED jika nilai buku mencapai salvage value
                if (bccomp((string) $asset->net_book_value, (string) $asset->salvage_value, 2) <= 0) {
                    $asset->status = FixedAssetStatusEnum::FULLY_DEPRECIATED;
                }

                $asset->save();

                $totalAmount = bcadd($totalAmount, $amount, 2);
                $processed[] = $schedule;
            }

            return [
                'total_processed' => count($processed),
                'total_depreciation_amount' => $totalAmount,
                'schedules' => $processed,
            ];
        });
    }
}
