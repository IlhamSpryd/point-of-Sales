<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\FinancialEventDto;
use App\Modules\Finance\Enums\CashBankTransactionStatusEnum;
use App\Modules\Finance\Enums\CashBankTransactionTypeEnum;
use App\Modules\Finance\Enums\DepreciationScheduleStatusEnum;
use App\Modules\Finance\Enums\FixedAssetDisposalTypeEnum;
use App\Modules\Finance\Enums\FixedAssetStatusEnum;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\CashBankTransaction;
use App\Modules\Finance\Models\FixedAsset;
use App\Modules\Finance\Models\FixedAssetDisposal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FixedAssetDisposalService
{
    public function __construct(
        protected PostingEngineService $postingEngineService,
        protected JournalEntryService $journalEntryService,
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Memproses pelepasan aset tetap (Disposal: Penjualan / Scrap / Write-Off).
     * Menghitung laba/rugi pelepasan secara presisi, memposting jurnal PR-FA-03,
     * mengupdate saldo kas jika ada proceeds, dan membatalkan sisa jadwal penyusutan pending.
     *
     * @param  array<string, mixed>  $data
     */
    public function disposeAsset(FixedAsset $asset, array $data, User $actor): FixedAssetDisposal
    {
        if (! in_array($asset->status, [FixedAssetStatusEnum::ACTIVE, FixedAssetStatusEnum::FULLY_DEPRECIATED], true)) {
            throw new DomainException("Hanya aset berstatus ACTIVE atau FULLY_DEPRECIATED yang dapat dilepas. Status saat ini: {$asset->status->value}.");
        }

        $tenantId = $asset->tenant_id;

        return DB::transaction(function () use ($tenantId, $asset, $data, $actor) {
            $disposalDate = $data['disposal_date'] ?? now()->toDateString();
            $dateStr = $disposalDate instanceof CarbonInterface ? $disposalDate->toDateString() : Carbon::parse($disposalDate)->toDateString();

            $disposalType = $data['disposal_type'] ?? FixedAssetDisposalTypeEnum::SALE;
            if (! ($disposalType instanceof FixedAssetDisposalTypeEnum)) {
                $disposalType = FixedAssetDisposalTypeEnum::from((string) $disposalType);
            }

            $bookValue = (string) $asset->net_book_value;
            $proceeds = bcadd((string) ($data['proceeds_amount'] ?? '0.00'), '0', 2);
            $gainLoss = bcsub($proceeds, $bookValue, 2);

            $gainAmount = '0.00';
            $lossAmount = '0.00';

            if (bccomp($gainLoss, '0.00', 2) > 0) {
                $gainAmount = $gainLoss;
            } elseif (bccomp($gainLoss, '0.00', 2) < 0) {
                $lossAmount = bcmul($gainLoss, '-1', 2);
            }

            $cbaId = ! empty($data['cash_bank_account_id']) ? (int) $data['cash_bank_account_id'] : null;

            // Jika ada penerimaan penjualan (proceeds), tambahkan ke saldo kas/bank
            if (bccomp($proceeds, '0.00', 2) > 0 && $cbaId) {
                $lockedAccount = CashBankAccount::withoutTenantScope()
                    ->where('tenant_id', $tenantId)
                    ->lockForUpdate()
                    ->findOrFail($cbaId);

                $newBalance = bcadd((string) $lockedAccount->current_balance, $proceeds, 2);
                $lockedAccount->current_balance = $newBalance;
                $lockedAccount->save();

                CashBankTransaction::withoutTenantScope()->create([
                    'tenant_id' => $tenantId,
                    'store_id' => $asset->store_id ?? $lockedAccount->store_id,
                    'cash_bank_account_id' => $lockedAccount->id,
                    'transaction_number' => 'CBT-DISP-'.$lockedAccount->id.'-'.date('Ymd').'-'.Str::upper(Str::random(4)),
                    'transaction_date' => $dateStr,
                    'transaction_type' => CashBankTransactionTypeEnum::RECEIPT,
                    'amount' => $proceeds,
                    'running_balance_after' => $newBalance,
                    'reference_number' => $asset->asset_code,
                    'description' => "Penerimaan Penjualan Aset Tetap: {$asset->asset_code}",
                    'status' => CashBankTransactionStatusEnum::POSTED,
                    'idempotency_key' => "cbt:disp:{$tenantId}:{$asset->id}",
                ]);
            }

            // Posting jurnal pelepasan aset (PR-FA-03) via Posting Engine
            $eventDto = new FinancialEventDto(
                tenantId: $tenantId,
                storeId: $asset->store_id,
                sourceModule: 'FINANCE',
                sourceType: 'FIXED_ASSET_DISPOSAL',
                sourceId: $asset->id,
                sourceDocumentNumber: $asset->asset_code,
                eventCode: 'fixed_asset.disposed',
                idempotencyKey: "fa:disp:{$tenantId}:{$asset->id}",
                occurredAt: now(),
                accountingDate: $dateStr,
                payload: [
                    'fixed_asset_category_id' => $asset->category_id,
                    'asset_code' => $asset->asset_code,
                    'acquisition_cost' => $asset->acquisition_cost,
                    'accumulated_depreciation' => $asset->accumulated_depreciation,
                    'proceeds_amount' => $proceeds,
                    'gain_amount' => $gainAmount,
                    'loss_amount' => $lossAmount,
                    'cash_bank_account_id' => $cbaId,
                    'destination_cash_bank_id' => $cbaId,
                ],
                userId: $actor->id
            );

            $journal = $this->postingEngineService->postEvent($eventDto);

            // Batalkan sisa jadwal penyusutan pending
            $asset->schedules()->where('status', DepreciationScheduleStatusEnum::PENDING)->delete();

            // Ubah status aset menjadi DISPOSED
            $asset->status = FixedAssetStatusEnum::DISPOSED;
            $asset->save();

            // Simpan catatan pelepasan aset
            return FixedAssetDisposal::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'store_id' => $asset->store_id,
                'fixed_asset_id' => $asset->id,
                'disposal_date' => $dateStr,
                'disposal_type' => $disposalType,
                'proceeds_amount' => $proceeds,
                'book_value_at_disposal' => $bookValue,
                'gain_loss_amount' => $gainLoss,
                'journal_entry_id' => $journal->id,
                'cash_bank_account_id' => $cbaId,
                'reason' => $data['reason'] ?? null,
                'approved_by_user_id' => $actor->id,
            ]);
        });
    }
}
