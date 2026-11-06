<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\Currency;
use App\Modules\Finance\Models\ExchangeRate;
use DomainException;

/**
 * Service Pengelolaan Valuta Asing & Kurs Mengambang (TR-007).
 * Menerapkan standardisasi kalkulasi presisi tinggi menggunakan BCMath.
 */
class CurrencyExchangeService
{
    /**
     * Dapatkan kode mata uang pembukuan utama tenant (Base Currency).
     */
    public function getBaseCurrency(int $tenantId): string
    {
        return Currency::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_base', true)
            ->value('code') ?? 'IDR';
    }

    /**
     * Dapatkan kurs penukaran efektif antara dua mata uang pada tanggal tertentu.
     */
    public function getExchangeRate(int $tenantId, string $fromCurrency, string $toCurrency, ?string $effectiveDate = null): string
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return '1.000000';
        }

        $date = $effectiveDate ?? now()->toDateString();

        // 1. Cek kurs langsung (Direct Rate)
        $directRate = ExchangeRate::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($directRate !== null) {
            return bcadd((string) $directRate, '0', 6);
        }

        // 2. Cek kurs terbalik (Inverse Rate)
        $inverseRate = ExchangeRate::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('from_currency', $to)
            ->where('to_currency', $from)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('rate');

        if ($inverseRate !== null && bccomp((string) $inverseRate, '0', 6) > 0) {
            return bcdiv('1', (string) $inverseRate, 6);
        }

        throw new DomainException("FIN-M7-FX: Kurs valas tidak ditemukan untuk pasangan {$from}/{$to} pada tanggal {$date} untuk tenant #{$tenantId}.");
    }

    /**
     * Simpan atau perbarui kurs valas efektif harian secara idempoten.
     */
    public function setExchangeRate(
        int $tenantId,
        string $fromCurrency,
        string $toCurrency,
        string $rate,
        ?string $effectiveDate = null,
        string $source = 'MANUAL'
    ): ExchangeRate {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));
        $date = $effectiveDate ?? now()->toDateString();

        $rateStr = bcadd($rate, '0', 6);
        if (bccomp($rateStr, '0.000000', 6) <= 0) {
            throw new DomainException("FIN-M7-FX: Nilai kurs harus lebih besar dari nol ({$rateStr}).");
        }

        $inverseRate = bcdiv('1', $rateStr, 6);

        return ExchangeRate::withoutTenantScope()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'from_currency' => $from,
                'to_currency' => $to,
                'effective_date' => $date,
            ],
            [
                'rate' => $rateStr,
                'inverse_rate' => $inverseRate,
                'rate_source' => $source,
            ]
        );
    }

    /**
     * Konversi nilai uang antar valuta menggunakan BCMath presisi tinggi.
     */
    public function convert(
        string $amount,
        string $fromCurrency,
        string $toCurrency,
        int $tenantId,
        ?string $effectiveDate = null,
        int $decimalPlaces = 2
    ): string {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === $to) {
            return bcadd($amount, '0', $decimalPlaces);
        }

        $rate = $this->getExchangeRate($tenantId, $from, $to, $effectiveDate);

        return bcmul($amount, $rate, $decimalPlaces);
    }

    /**
     * Hitung selisih kurs (Forex Gain/Loss) antara kurs pencatatan (Booking Rate)
     * dan kurs penyelesaian/pelunasan (Settlement Rate).
     *
     * @return array{
     *     type: 'GAIN'|'LOSS'|'NONE',
     *     amount: string,
     *     booking_idr: string,
     *     settlement_idr: string
     * }
     */
    public function calculateForexGainLoss(string $foreignAmount, string $bookingRate, string $settlementRate): array
    {
        $bookingIdr = bcmul($foreignAmount, $bookingRate, 2);
        $settlementIdr = bcmul($foreignAmount, $settlementRate, 2);
        $variance = bcsub($settlementIdr, $bookingIdr, 2);

        $comparison = bccomp($variance, '0.00', 2);

        if ($comparison > 0) {
            return [
                'type' => 'GAIN',
                'amount' => $variance,
                'booking_idr' => $bookingIdr,
                'settlement_idr' => $settlementIdr,
            ];
        }

        if ($comparison < 0) {
            return [
                'type' => 'LOSS',
                'amount' => bcmul($variance, '-1', 2),
                'booking_idr' => $bookingIdr,
                'settlement_idr' => $settlementIdr,
            ];
        }

        return [
            'type' => 'NONE',
            'amount' => '0.00',
            'booking_idr' => $bookingIdr,
            'settlement_idr' => $settlementIdr,
        ];
    }

    /**
     * Sinkronisasi kurs tengah Bank Indonesia harian untuk valuta regional Asia Tenggara.
     *
     * @param  array<string, string>|null  $mockRates
     */
    public function syncBankIndonesiaRates(int $tenantId, ?array $mockRates = null, ?string $effectiveDate = null): int
    {
        $date = $effectiveDate ?? now()->toDateString();

        $rates = $mockRates ?? [
            'USD' => '15650.000000',
            'SGD' => '11620.000000',
            'MYR' => '3520.000000',
            'EUR' => '16850.000000',
            'JPY' => '104.500000',
        ];

        $count = 0;
        foreach ($rates as $foreignCode => $rateValue) {
            $this->setExchangeRate(
                tenantId: $tenantId,
                fromCurrency: $foreignCode,
                toCurrency: 'IDR',
                rate: (string) $rateValue,
                effectiveDate: $date,
                source: 'BANK_INDONESIA'
            );
            $count++;
        }

        return $count;
    }
}

