<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Enums\TaxCalculationTypeEnum;
use App\Modules\Finance\Enums\TaxTypeEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\TaxAccountMapping;
use App\Modules\Finance\Models\TaxCode;
use App\Modules\Finance\Models\TaxRate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class TaxConfigurationService
{
    /**
     * Mendaftarkan Master Kode Pajak baru beserta tarif awal dan pemetaan akun GL jika disediakan.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTaxCode(array $data, ?User $actor = null): TaxCode
    {
        $tenantId = (int) ($data['tenant_id'] ?? ($actor ? $actor->tenant_id : 0));
        if ($tenantId <= 0) {
            throw new DomainException('Tenant ID wajib ditentukan.');
        }

        $codeStr = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($codeStr === '') {
            throw new DomainException('Kode pajak wajib diisi.');
        }

        $taxType = $data['tax_type'] ?? TaxTypeEnum::VAT_PPN;
        if (! ($taxType instanceof TaxTypeEnum)) {
            $taxType = TaxTypeEnum::from((string) $taxType);
        }

        $calcType = $data['calculation_type'] ?? TaxCalculationTypeEnum::EXCLUSIVE;
        if (! ($calcType instanceof TaxCalculationTypeEnum)) {
            $calcType = TaxCalculationTypeEnum::from((string) $calcType);
        }

        return DB::transaction(function () use ($tenantId, $codeStr, $data, $taxType, $calcType, $actor) {
            $taxCode = TaxCode::withoutTenantScope()->create([
                'tenant_id' => $tenantId,
                'code' => $codeStr,
                'name' => (string) ($data['name'] ?? $codeStr),
                'tax_type' => $taxType,
                'calculation_type' => $calcType,
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            // Jika ada rate awal
            if (isset($data['rate_percent'])) {
                $effectiveFrom = $data['effective_from'] ?? now()->toDateString();
                $this->addTaxRate(
                    code: $taxCode,
                    ratePercent: (string) $data['rate_percent'],
                    effectiveFrom: $effectiveFrom,
                    effectiveTo: $data['effective_to'] ?? null,
                    actor: $actor
                );
            }

            // Jika ada pemetaan akun GL awal
            if (! empty($data['sales_tax_account_id'])) {
                $this->setAccountMapping(
                    code: $taxCode,
                    salesTaxAccountId: (int) $data['sales_tax_account_id'],
                    purchaseTaxAccountId: ! empty($data['purchase_tax_account_id']) ? (int) $data['purchase_tax_account_id'] : null
                );
            }

            return $taxCode->load(['rates', 'accountMapping']);
        });
    }

    /**
     * Menambahkan tarif pajak ber-tanggal efektif (effective-dated rate).
     * Jika ada tarif aktif sebelumnya tanpa batas akhir, tanggal akhirnya disesuaikan secara otomatis.
     */
    public function addTaxRate(
        TaxCode $code,
        string $ratePercent,
        CarbonInterface|string $effectiveFrom,
        CarbonInterface|string|null $effectiveTo = null,
        ?User $actor = null
    ): TaxRate {
        $fromStr = $effectiveFrom instanceof CarbonInterface ? $effectiveFrom->toDateString() : Carbon::parse($effectiveFrom)->toDateString();
        $toStr = $effectiveTo ? ($effectiveTo instanceof CarbonInterface ? $effectiveTo->toDateString() : Carbon::parse($effectiveTo)->toDateString()) : null;

        $normRate = bcadd($ratePercent, '0', 4);
        if (bccomp($normRate, '0.0000', 4) < 0) {
            throw new DomainException('FIN-M7-03: Tarif persentase pajak tidak boleh bernilai negatif.');
        }

        return DB::transaction(function () use ($code, $normRate, $fromStr, $toStr) {
            // Tutup tarif sebelumnya yang tidak memiliki tanggal akhir jika tanggal efektif barunya lebih baru
            $previousOpenRate = TaxRate::withoutTenantScope()
                ->where('tenant_id', $code->tenant_id)
                ->where('tax_code_id', $code->id)
                ->whereNull('effective_to')
                ->where('effective_from', '<', $fromStr)
                ->first();

            if ($previousOpenRate) {
                $dayBefore = Carbon::parse($fromStr)->subDay()->toDateString();
                $previousOpenRate->effective_to = $dayBefore;
                $previousOpenRate->save();
            }

            return TaxRate::withoutTenantScope()->create([
                'tenant_id' => $code->tenant_id,
                'tax_code_id' => $code->id,
                'rate_percent' => $normRate,
                'effective_from' => $fromStr,
                'effective_to' => $toStr,
                'is_active' => true,
            ]);
        });
    }

    /**
     * Menghubungkan Kode Pajak ke Akun Chart of Account (Pajak Keluaran & Pajak Masukan).
     */
    public function setAccountMapping(
        TaxCode $code,
        int $salesTaxAccountId,
        ?int $purchaseTaxAccountId = null
    ): TaxAccountMapping {
        $tenantId = $code->tenant_id;

        $salesAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($salesTaxAccountId);
        if (! $salesAccount->is_postable || ! $salesAccount->is_active) {
            throw new DomainException("Akun GL Pajak Penjualan '{$salesAccount->account_code}' tidak aktif atau bukan akun postable.");
        }

        if ($purchaseTaxAccountId) {
            $purchaseAccount = ChartOfAccount::withoutTenantScope()->where('tenant_id', $tenantId)->findOrFail($purchaseTaxAccountId);
            if (! $purchaseAccount->is_postable || ! $purchaseAccount->is_active) {
                throw new DomainException("Akun GL Pajak Pembelian '{$purchaseAccount->account_code}' tidak aktif atau bukan akun postable.");
            }
        }

        return TaxAccountMapping::withoutTenantScope()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'tax_code_id' => $code->id,
            ],
            [
                'sales_tax_account_id' => $salesTaxAccountId,
                'purchase_tax_account_id' => $purchaseTaxAccountId,
            ]
        );
    }

    /**
     * Menyelesaikan tarif pajak efektif untuk tanggal tertentu.
     */
    public function resolveEffectiveRate(TaxCode $code, CarbonInterface|string $date): ?TaxRate
    {
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        return TaxRate::withoutTenantScope()
            ->where('tenant_id', $code->tenant_id)
            ->where('tax_code_id', $code->id)
            ->where('is_active', true)
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Menghitung Dasar Pengenaan Pajak (DPP), Nilai Pajak, dan Nilai Kotor berdasarkan mode INCLUSIVE atau EXCLUSIVE.
     * Menggunakan bcmath ketat 2 desimal uang dan 4 desimal tarif.
     *
     * @return array{tax_code: TaxCode, rate: ?TaxRate, applied_rate: string, calculation_type: TaxCalculationTypeEnum, tax_base_amount: string, tax_amount: string, gross_amount: string}
     */
    public function calculateTax(TaxCode $code, string $amount, CarbonInterface|string $date): array
    {
        $rate = $this->resolveEffectiveRate($code, $date);
        $ratePercent = $rate ? $rate->rate_percent : '0.0000';
        $normAmount = bcadd($amount, '0', 2);

        // r = ratePercent / 100
        $r = bcdiv($ratePercent, '100', 6);

        if (bccomp($r, '0.000000', 6) === 0) {
            return [
                'tax_code' => $code,
                'rate' => $rate,
                'applied_rate' => '0.0000',
                'calculation_type' => $code->calculation_type,
                'tax_base_amount' => $normAmount,
                'tax_amount' => '0.00',
                'gross_amount' => $normAmount,
            ];
        }

        if ($code->calculation_type === TaxCalculationTypeEnum::INCLUSIVE) {
            // INCLUSIVE: amount = Gross
            // DPP = Gross / (1 + r)
            // Tax = Gross - DPP
            $divisor = bcadd('1.000000', $r, 6);
            $dpp = bcdiv($normAmount, $divisor, 2);
            $tax = bcsub($normAmount, $dpp, 2);
            $gross = $normAmount;
        } else {
            // EXCLUSIVE: amount = DPP
            // Tax = DPP * r
            // Gross = DPP + Tax
            $dpp = $normAmount;
            $tax = bcmul($dpp, $r, 2);
            $gross = bcadd($dpp, $tax, 2);
        }

        return [
            'tax_code' => $code,
            'rate' => $rate,
            'applied_rate' => $ratePercent,
            'calculation_type' => $code->calculation_type,
            'tax_base_amount' => $dpp,
            'tax_amount' => $tax,
            'gross_amount' => $gross,
        ];
    }

    /**
     * Menyinkronkan tax_classes existing milik Modul 3 ke dalam sistem tax_codes & tax_rates Modul 7.
     */
    public function syncFromProductTaxClasses(int $tenantId): int
    {
        if (! DB::getSchemaBuilder()->hasTable('tax_classes')) {
            return 0;
        }

        $taxClasses = DB::table('tax_classes')
            ->where('tenant_id', $tenantId)
            ->get();

        $count = 0;
        foreach ($taxClasses as $tc) {
            $codeStr = strtoupper(trim((string) ($tc->code ?? 'TAX-'.$tc->id)));
            $ratePercent = bcadd((string) ($tc->rate ?? $tc->percentage ?? '11.0000'), '0', 4);

            $taxCode = TaxCode::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $codeStr,
                ],
                [
                    'name' => (string) ($tc->name ?? $codeStr),
                    'tax_type' => TaxTypeEnum::VAT_PPN,
                    'calculation_type' => TaxCalculationTypeEnum::EXCLUSIVE,
                    'is_active' => true,
                ]
            );

            TaxRate::withoutTenantScope()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'tax_code_id' => $taxCode->id,
                    'effective_from' => '2026-01-01',
                ],
                [
                    'rate_percent' => $ratePercent,
                    'is_active' => true,
                ]
            );

            $count++;
        }

        return $count;
    }
}
