<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\StatutoryRuleVersion;
use Carbon\CarbonInterface;
use DomainException;

class StatutoryRuleRegistryService
{
    /**
     * Resolusi versi aturan hukum aktif berdasarkan kategori dan tanggal efektif (effective date resolution).
     *
     * @throws DomainException
     */
    public function resolveActiveRule(
        string $category,
        CarbonInterface|string $effectiveDate,
        string $jurisdiction = 'ID'
    ): StatutoryRuleVersion {
        $dateStr = is_string($effectiveDate) ? $effectiveDate : $effectiveDate->toDateString();

        /** @var StatutoryRuleVersion|null $rule */
        $rule = StatutoryRuleVersion::where('jurisdiction', $jurisdiction)
            ->where('rule_category', $category)
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $dateStr);
            })
            ->orderByDesc('effective_from')
            ->first();

        if (! $rule) {
            throw new DomainException(
                "Tidak ditemukan aturan hukum aktif untuk kategori [{$category}], yurisdiksi [{$jurisdiction}] pada tanggal [{$dateStr}]."
            );
        }

        return $rule;
    }

    /**
     * Ambil parameter BPJS TK & BPJS Kesehatan aktif.
     *
     * @return array<string, mixed>
     */
    public function getBpjsParameters(CarbonInterface|string $effectiveDate, string $jurisdiction = 'ID'): array
    {
        $bpjsTk = $this->resolveActiveRule('BPJS_TK', $effectiveDate, $jurisdiction);
        $bpjsKs = $this->resolveActiveRule('BPJS_KS', $effectiveDate, $jurisdiction);

        return [
            'tk_version_id' => $bpjsTk->id,
            'ks_version_id' => $bpjsKs->id,
            'tk' => $bpjsTk->rule_parameters,
            'ks' => $bpjsKs->rule_parameters,
        ];
    }

    /**
     * Ambil matriks tarif TER (PP 58/2023 & PMK 168/2023).
     *
     * @return array<string, mixed>
     */
    public function getTerParameters(CarbonInterface|string $effectiveDate, string $jurisdiction = 'ID'): array
    {
        $ter = $this->resolveActiveRule('PPH21_TER', $effectiveDate, $jurisdiction);

        return [
            'version_id' => $ter->id,
            'rules' => $ter->rule_parameters,
        ];
    }

    /**
     * Ambil nilai PTKP tahunan.
     *
     * @return array<string, mixed>
     */
    public function getPtkpValues(CarbonInterface|string $effectiveDate, string $jurisdiction = 'ID'): array
    {
        $ptkp = $this->resolveActiveRule('PTKP_VALUES', $effectiveDate, $jurisdiction);

        return [
            'version_id' => $ptkp->id,
            'values' => $ptkp->rule_parameters,
        ];
    }

    /**
     * Ambil parameter tarif progresif Pasal 17 UU HPP.
     *
     * @return array<string, mixed>
     */
    public function getPasal17Parameters(CarbonInterface|string $effectiveDate, string $jurisdiction = 'ID'): array
    {
        $pasal17 = $this->resolveActiveRule('PPH21_PASAL17', $effectiveDate, $jurisdiction);

        return [
            'version_id' => $pasal17->id,
            'rules' => $pasal17->rule_parameters,
        ];
    }
}
