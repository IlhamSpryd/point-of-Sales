<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\JournalTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use DomainException;
use Illuminate\Support\Str;

class ForexGainLossPostingService
{
    public const SYSTEM_KEY_REALIZED_GAIN = 'FOREX_REALIZED_GAIN';
    public const SYSTEM_KEY_REALIZED_LOSS = 'FOREX_REALIZED_LOSS';
    public const SYSTEM_KEY_UNREALIZED_GAIN = 'FOREX_UNREALIZED_GAIN';
    public const SYSTEM_KEY_UNREALIZED_LOSS = 'FOREX_UNREALIZED_LOSS';

    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected CurrencyExchangeService $currencyExchangeService
    ) {}

    /**
     * Memastikan akun-akun selisih kurs standar tersedia pada tenant.
     */
    public function resolveForexAccount(int $tenantId, string $systemKey): ChartOfAccount
    {
        $existing = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('system_account_key', $systemKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $config = match ($systemKey) {
            self::SYSTEM_KEY_REALIZED_GAIN => [
                'code' => '7100',
                'name' => 'Pendapatan Selisih Kurs Terealisasi (Realized Forex Gain)',
                'type' => AccountTypeEnum::OTHER_INCOME,
                'subtype' => AccountSubtypeEnum::OTHER,
                'balance' => NormalBalanceEnum::CREDIT,
            ],
            self::SYSTEM_KEY_REALIZED_LOSS => [
                'code' => '8100',
                'name' => 'Kerugian Selisih Kurs Terealisasi (Realized Forex Loss)',
                'type' => AccountTypeEnum::OTHER_EXPENSE,
                'subtype' => AccountSubtypeEnum::OTHER,
                'balance' => NormalBalanceEnum::DEBIT,
            ],
            self::SYSTEM_KEY_UNREALIZED_GAIN => [
                'code' => '7200',
                'name' => 'Keuntungan Selisih Kurs Belum Terealisasi (Unrealized Forex Gain)',
                'type' => AccountTypeEnum::OTHER_INCOME,
                'subtype' => AccountSubtypeEnum::OTHER,
                'balance' => NormalBalanceEnum::CREDIT,
            ],
            self::SYSTEM_KEY_UNREALIZED_LOSS => [
                'code' => '8200',
                'name' => 'Kerugian Selisih Kurs Belum Terealisasi (Unrealized Forex Loss)',
                'type' => AccountTypeEnum::OTHER_EXPENSE,
                'subtype' => AccountSubtypeEnum::OTHER,
                'balance' => NormalBalanceEnum::DEBIT,
            ],
            default => throw new DomainException("Unknown forex account key: {$systemKey}"),
        };

        $code = $config['code'];
        $conflict = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_code', $code)
            ->exists();

        if ($conflict) {
            $code = $code . '-' . Str::random(3);
        }

        return ChartOfAccount::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'account_code' => $code,
            'account_name' => $config['name'],
            'account_type' => $config['type'],
            'account_subtype' => $config['subtype'],
            'normal_balance' => $config['balance'],
            'system_account_key' => $systemKey,
            'level' => 3,
            'is_postable' => true,
            'is_control_account' => false,
            'allow_manual_journal' => true,
            'is_reconcilable' => false,
            'is_active' => true,
        ]);
    }

    /**
     * Memposting jurnal selisih kurs terealisasi (Realized Forex Gain/Loss) saat pelunasan AR/AP.
     */
    public function postRealizedForexJournal(
        int $tenantId,
        ?int $storeId,
        string $date,
        string $foreignCurrency,
        string $foreignAmount,
        string $bookingRate,
        string $settlementRate,
        int $bankAccountId,
        int $arOrApAccountId,
        bool $isReceivable,
        string $referenceNumber,
        ?string $idempotencyKey = null,
        ?User $actor = null
    ): JournalEntry {
        $foreign = bcadd($foreignAmount, '0', 2);
        $bookingRateStr = bcadd($bookingRate, '0', 6);
        $settlementRateStr = bcadd($settlementRate, '0', 6);

        $bookingIdr = bcmul($foreign, $bookingRateStr, 2);
        $settlementIdr = bcmul($foreign, $settlementRateStr, 2);

        $variance = bcsub($settlementIdr, $bookingIdr, 2);
        $cmp = bccomp($variance, '0.00', 2);
        $diff = $cmp < 0 ? bcmul($variance, '-1', 2) : $variance;

        $lines = [];
        $key = $idempotencyKey ?? ('FX-REAL-' . Str::uuid()->toString());

        if ($isReceivable) {
            // Pelunasan Piutang Usaha (AR Settlement)
            if ($cmp > 0) {
                // Settlement > Booking -> Gain
                $gainAccount = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_REALIZED_GAIN);
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: $settlementIdr,
                    creditAmount: '0.00',
                    description: "Penerimaan Kas/Bank Valas ({$foreign} {$foreignCurrency} @ {$settlementRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: '0.00',
                    creditAmount: $bookingIdr,
                    description: "Pelunasan Piutang Usaha ({$foreign} {$foreignCurrency} @ kurs booking {$bookingRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $gainAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $diff,
                    description: "Keuntungan Selisih Kurs Terealisasi ({$referenceNumber})",
                    storeId: $storeId
                );
            } elseif ($cmp < 0) {
                // Settlement < Booking -> Loss
                $lossAccount = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_REALIZED_LOSS);
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: $settlementIdr,
                    creditAmount: '0.00',
                    description: "Penerimaan Kas/Bank Valas ({$foreign} {$foreignCurrency} @ {$settlementRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $lossAccount->id,
                    debitAmount: $diff,
                    creditAmount: '0.00',
                    description: "Kerugian Selisih Kurs Terealisasi ({$referenceNumber})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: '0.00',
                    creditAmount: $bookingIdr,
                    description: "Pelunasan Piutang Usaha ({$foreign} {$foreignCurrency} @ kurs booking {$bookingRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
            } else {
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: $settlementIdr,
                    creditAmount: '0.00',
                    description: "Penerimaan Kas/Bank Valas ({$foreign} {$foreignCurrency})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: '0.00',
                    creditAmount: $bookingIdr,
                    description: "Pelunasan Piutang Usaha ({$foreign} {$foreignCurrency})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
            }
        } else {
            // Pembayaran Hutang Usaha (AP Settlement)
            if ($cmp > 0) {
                // Settlement > Booking -> Loss (We pay more IDR than booked)
                $lossAccount = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_REALIZED_LOSS);
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: $bookingIdr,
                    creditAmount: '0.00',
                    description: "Pelunasan Hutang Usaha ({$foreign} {$foreignCurrency} @ kurs booking {$bookingRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $lossAccount->id,
                    debitAmount: $diff,
                    creditAmount: '0.00',
                    description: "Kerugian Selisih Kurs Terealisasi Hutang ({$referenceNumber})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: '0.00',
                    creditAmount: $settlementIdr,
                    description: "Pengeluaran Kas/Bank Valas ({$foreign} {$foreignCurrency} @ {$settlementRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
            } elseif ($cmp < 0) {
                // Settlement < Booking -> Gain (We pay less IDR than booked)
                $gainAccount = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_REALIZED_GAIN);
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: $bookingIdr,
                    creditAmount: '0.00',
                    description: "Pelunasan Hutang Usaha ({$foreign} {$foreignCurrency} @ kurs booking {$bookingRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: '0.00',
                    creditAmount: $settlementIdr,
                    description: "Pengeluaran Kas/Bank Valas ({$foreign} {$foreignCurrency} @ {$settlementRateStr})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $gainAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $diff,
                    description: "Keuntungan Selisih Kurs Terealisasi Hutang ({$referenceNumber})",
                    storeId: $storeId
                );
            } else {
                $lines[] = new JournalLineDto(
                    accountId: $arOrApAccountId,
                    debitAmount: $bookingIdr,
                    creditAmount: '0.00',
                    description: "Pelunasan Hutang Usaha ({$foreign} {$foreignCurrency})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignDebitAmount: $foreign,
                    exchangeRate: $bookingRateStr
                );
                $lines[] = new JournalLineDto(
                    accountId: $bankAccountId,
                    debitAmount: '0.00',
                    creditAmount: $settlementIdr,
                    description: "Pengeluaran Kas/Bank Valas ({$foreign} {$foreignCurrency})",
                    storeId: $storeId,
                    foreignCurrencyCode: $foreignCurrency,
                    foreignCreditAmount: $foreign,
                    exchangeRate: $settlementRateStr
                );
            }
        }

        return $this->journalEntryService->createAndPostJournal(
            tenantId: $tenantId,
            storeId: $storeId,
            postingDate: $date,
            idempotencyKey: $key,
            lines: $lines,
            sourceModule: 'FINANCE',
            sourceType: 'FOREX_SETTLEMENT',
            sourceNumber: $referenceNumber,
            journalType: JournalTypeEnum::SYSTEM_AUTO,
            notes: "Jurnal penyesuaian selisih kurs transaksi {$referenceNumber}",
            currencyCode: 'IDR',
            actor: $actor
        );
    }

    /**
     * Memposting jurnal penyesuaian revaluasi selisih kurs belum terealisasi (Unrealized Forex Revaluation) akhir periode.
     */
    public function postUnrealizedRevaluationJournal(
        int $tenantId,
        ?int $storeId,
        string $periodEndDate,
        string $foreignCurrency,
        string $foreignBalance,
        string $bookValueBase,
        string $closingRate,
        int $assetOrLiabilityAccountId,
        bool $isAsset,
        string $reference,
        ?string $idempotencyKey = null,
        ?User $actor = null
    ): JournalEntry {
        $foreign = bcadd($foreignBalance, '0', 2);
        $closingRateStr = bcadd($closingRate, '0', 6);
        $newBaseAmount = bcmul($foreign, $closingRateStr, 2);
        $bookBaseAmount = bcadd($bookValueBase, '0', 2);

        $variance = bcsub($newBaseAmount, $bookBaseAmount, 2);
        $cmp = bccomp($variance, '0.00', 2);

        if ($cmp === 0) {
            throw new DomainException("Tidak ada selisih revaluasi valas untuk saldo {$foreignBalance} {$foreignCurrency}.");
        }

        $lines = [];
        $key = $idempotencyKey ?? ('FX-REVAL-' . Str::uuid()->toString());

        if ($cmp > 0) {
            // Nilai buku naik
            if ($isAsset) {
                // Aset bertambah: Gain
                $gainAcc = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_UNREALIZED_GAIN);
                $lines[] = new JournalLineDto(
                    accountId: $assetOrLiabilityAccountId,
                    debitAmount: $variance,
                    creditAmount: '0.00',
                    description: "Penyesuaian Revaluasi Kurs Penutupan Aset ({$foreign} {$foreignCurrency} @ {$closingRateStr})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $gainAcc->id,
                    debitAmount: '0.00',
                    creditAmount: $variance,
                    description: "Keuntungan Selisih Kurs Belum Terealisasi ({$reference})",
                    storeId: $storeId
                );
            } else {
                // Liabilitas bertambah: Loss
                $lossAcc = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_UNREALIZED_LOSS);
                $lines[] = new JournalLineDto(
                    accountId: $lossAcc->id,
                    debitAmount: $variance,
                    creditAmount: '0.00',
                    description: "Kerugian Selisih Kurs Belum Terealisasi Liabilitas ({$reference})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $assetOrLiabilityAccountId,
                    debitAmount: '0.00',
                    creditAmount: $variance,
                    description: "Penyesuaian Revaluasi Kurs Penutupan Liabilitas ({$foreign} {$foreignCurrency} @ {$closingRateStr})",
                    storeId: $storeId
                );
            }
        } else {
            // Nilai buku turun
            $absVariance = bcmul($variance, '-1', 2);
            if ($isAsset) {
                // Aset berkurang: Loss
                $lossAcc = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_UNREALIZED_LOSS);
                $lines[] = new JournalLineDto(
                    accountId: $lossAcc->id,
                    debitAmount: $absVariance,
                    creditAmount: '0.00',
                    description: "Kerugian Selisih Kurs Belum Terealisasi Aset ({$reference})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $assetOrLiabilityAccountId,
                    debitAmount: '0.00',
                    creditAmount: $absVariance,
                    description: "Penyesuaian Penurunan Nilai Kurs Aset ({$foreign} {$foreignCurrency} @ {$closingRateStr})",
                    storeId: $storeId
                );
            } else {
                // Liabilitas berkurang: Gain
                $gainAcc = $this->resolveForexAccount($tenantId, self::SYSTEM_KEY_UNREALIZED_GAIN);
                $lines[] = new JournalLineDto(
                    accountId: $assetOrLiabilityAccountId,
                    debitAmount: $absVariance,
                    creditAmount: '0.00',
                    description: "Penyesuaian Penurunan Beban Liabilitas ({$foreign} {$foreignCurrency} @ {$closingRateStr})",
                    storeId: $storeId
                );
                $lines[] = new JournalLineDto(
                    accountId: $gainAcc->id,
                    debitAmount: '0.00',
                    creditAmount: $absVariance,
                    description: "Keuntungan Selisih Kurs Belum Terealisasi Liabilitas ({$reference})",
                    storeId: $storeId
                );
            }
        }

        return $this->journalEntryService->createAndPostJournal(
            tenantId: $tenantId,
            storeId: $storeId,
            postingDate: $periodEndDate,
            idempotencyKey: $key,
            lines: $lines,
            sourceModule: 'FINANCE',
            sourceType: 'FOREX_REVALUATION',
            sourceNumber: $reference,
            journalType: JournalTypeEnum::SYSTEM_AUTO,
            notes: "Jurnal penyesuaian revaluasi kurs valas akhir periode {$reference}",
            currencyCode: 'IDR',
            actor: $actor
        );
    }
}
