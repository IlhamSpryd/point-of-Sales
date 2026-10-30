<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\HR\Models\StatutoryRuleVersion;
use Illuminate\Database\Seeder;

class StatutoryRuleVersionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. BPJS Ketenagakerjaan (ID)
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'BPJS_TK',
                'version_code' => 'BPJS_TK_2026',
            ],
            [
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'legal_reference' => 'PP No. 44, 45, 46 Tahun 2015 & Batas JP 2026',
                'rule_parameters' => [
                    'jht_employer_rate' => 0.037, // 3.7%
                    'jht_employee_rate' => 0.020, // 2.0%
                    'jp_employer_rate' => 0.020,  // 2.0%
                    'jp_employee_rate' => 0.010,  // 1.0%
                    'jp_wage_cap' => 11086300.00, // Plafon JP 2026
                    'jkk_rates' => [
                        'class_1' => 0.0024, // 0.24% Sangat Rendah
                        'class_2' => 0.0054, // 0.54% Rendah
                        'class_3' => 0.0089, // 0.89% Sedang
                        'class_4' => 0.0127, // 1.27% Tinggi
                        'class_5' => 0.0174, // 1.74% Sangat Tinggi
                    ],
                    'jkm_rate' => 0.0030, // 0.30%
                    'jkp_rate' => 0.0046, // 0.46% (Rekomposisi/APBN)
                    'jkp_wage_cap' => 5000000.00,
                ],
                'is_locked' => true,
            ]
        );

        // 2. BPJS Kesehatan (ID)
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'BPJS_KS',
                'version_code' => 'BPJS_KS_2024',
            ],
            [
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'legal_reference' => 'Perpres No. 82/2018 jo. Perpres No. 59/2024',
                'rule_parameters' => [
                    'employer_rate' => 0.040,  // 4.0%
                    'employee_rate' => 0.010,  // 1.0%
                    'wage_cap' => 12000000.00, // Plafon upah Rp12.000.000
                ],
                'is_locked' => true,
            ]
        );

        // 3. Batas PTKP Indonesia
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'PTKP_VALUES',
                'version_code' => 'PTKP_2024',
            ],
            [
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'legal_reference' => 'UU No. 7 Tahun 2021 (UU HPP)',
                'rule_parameters' => [
                    'TK/0' => 54000000.00,
                    'TK/1' => 58500000.00,
                    'TK/2' => 63000000.00,
                    'TK/3' => 67500000.00,
                    'K/0' => 58500000.00,
                    'K/1' => 63000000.00,
                    'K/2' => 67500000.00,
                    'K/3' => 72000000.00,
                ],
                'is_locked' => true,
            ]
        );

        // 4. Tarif Efektif Rata-Rata (TER) PPh 21 Bulanan (PP 58/2023 & PMK 168/2023)
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'PPH21_TER',
                'version_code' => 'TER_PP58_2023',
            ],
            [
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'legal_reference' => 'PP No. 58 Tahun 2023 jo. PMK No. 168 Tahun 2023',
                'rule_parameters' => [
                    'category_mapping' => [
                        'A' => ['TK/0', 'TK/1', 'K/0'],
                        'B' => ['TK/2', 'TK/3', 'K/1', 'K/2'],
                        'C' => ['K/3'],
                    ],
                    'brackets_a' => [
                        ['min' => 0, 'max' => 5400000, 'rate' => 0.0000],
                        ['min' => 5400000, 'max' => 5650000, 'rate' => 0.0025],
                        ['min' => 5650000, 'max' => 5950000, 'rate' => 0.0050],
                        ['min' => 5950000, 'max' => 6300000, 'rate' => 0.0075],
                        ['min' => 6300000, 'max' => 6750000, 'rate' => 0.0100],
                        ['min' => 6750000, 'max' => 7500000, 'rate' => 0.0125],
                        ['min' => 7500000, 'max' => 8550000, 'rate' => 0.0150],
                        ['min' => 8550000, 'max' => 9650000, 'rate' => 0.0175],
                        ['min' => 9650000, 'max' => 10050000, 'rate' => 0.0200],
                        ['min' => 10050000, 'max' => 10350000, 'rate' => 0.0225],
                        ['min' => 10350000, 'max' => 10700000, 'rate' => 0.0250],
                        ['min' => 10700000, 'max' => 11050000, 'rate' => 0.0300],
                        ['min' => 11050000, 'max' => 11600000, 'rate' => 0.0350],
                        ['min' => 11600000, 'max' => 12500000, 'rate' => 0.0400],
                        ['min' => 12500000, 'max' => 13750000, 'rate' => 0.0500],
                        ['min' => 13750000, 'max' => 15150000, 'rate' => 0.0600],
                        ['min' => 15150000, 'max' => 16950000, 'rate' => 0.0600],
                        ['min' => 16950000, 'max' => 19750000, 'rate' => 0.0700],
                        ['min' => 19750000, 'max' => 24150000, 'rate' => 0.0800],
                        ['min' => 24150000, 'max' => 26450000, 'rate' => 0.0900],
                        ['min' => 26450000, 'max' => 28000000, 'rate' => 0.1000],
                        ['min' => 28000000, 'max' => 30050000, 'rate' => 0.1100],
                        ['min' => 30050000, 'max' => 32400000, 'rate' => 0.1200],
                        ['min' => 32400000, 'max' => 35400000, 'rate' => 0.1300],
                        ['min' => 35400000, 'max' => 39100000, 'rate' => 0.1400],
                        ['min' => 39100000, 'max' => 43850000, 'rate' => 0.1500],
                        ['min' => 43850000, 'max' => 47800000, 'rate' => 0.1600],
                        ['min' => 47800000, 'max' => 51400000, 'rate' => 0.1700],
                        ['min' => 51400000, 'max' => 56300000, 'rate' => 0.1800],
                        ['min' => 56300000, 'max' => 62200000, 'rate' => 0.1900],
                        ['min' => 62200000, 'max' => 68600000, 'rate' => 0.2000],
                        ['min' => 68600000, 'max' => 77500000, 'rate' => 0.2100],
                        ['min' => 77500000, 'max' => 89000000, 'rate' => 0.2200],
                        ['min' => 89000000, 'max' => 103000000, 'rate' => 0.2300],
                        ['min' => 103000000, 'max' => 125000000, 'rate' => 0.2400],
                        ['min' => 125000000, 'max' => 157000000, 'rate' => 0.2500],
                        ['min' => 157000000, 'max' => 206000000, 'rate' => 0.2600],
                        ['min' => 206000000, 'max' => 337000000, 'rate' => 0.3000],
                        ['min' => 337000000, 'max' => 454000000, 'rate' => 0.3100],
                        ['min' => 454000000, 'max' => 550000000, 'rate' => 0.3200],
                        ['min' => 550000000, 'max' => 1400000000, 'rate' => 0.3300],
                        ['min' => 1400000000, 'max' => null, 'rate' => 0.3400],
                    ],
                    'brackets_b' => [
                        ['min' => 0, 'max' => 6200000, 'rate' => 0.0000],
                        ['min' => 6200000, 'max' => 6500000, 'rate' => 0.0025],
                        ['min' => 6500000, 'max' => 6850000, 'rate' => 0.0050],
                        ['min' => 6850000, 'max' => 7300000, 'rate' => 0.0075],
                        ['min' => 7300000, 'max' => 9200000, 'rate' => 0.0100],
                        ['min' => 9200000, 'max' => 10750000, 'rate' => 0.0150],
                        ['min' => 10750000, 'max' => 12500000, 'rate' => 0.0200],
                        ['min' => 12500000, 'max' => 14150000, 'rate' => 0.0300],
                        ['min' => 14150000, 'max' => 16150000, 'rate' => 0.0400],
                        ['min' => 16150000, 'max' => 18500000, 'rate' => 0.0500],
                        ['min' => 18500000, 'max' => 21500000, 'rate' => 0.0600],
                        ['min' => 21500000, 'max' => 25500000, 'rate' => 0.0700],
                        ['min' => 25500000, 'max' => 30000000, 'rate' => 0.0800],
                        ['min' => 30000000, 'max' => 35000000, 'rate' => 0.0900],
                        ['min' => 35000000, 'max' => 40000000, 'rate' => 0.1000],
                        ['min' => 40000000, 'max' => 50000000, 'rate' => 0.1200],
                        ['min' => 50000000, 'max' => 70000000, 'rate' => 0.1500],
                        ['min' => 70000000, 'max' => 100000000, 'rate' => 0.2000],
                        ['min' => 100000000, 'max' => 1405000000, 'rate' => 0.2500],
                        ['min' => 1405000000, 'max' => null, 'rate' => 0.3400],
                    ],
                    'brackets_c' => [
                        ['min' => 0, 'max' => 6600000, 'rate' => 0.0000],
                        ['min' => 6600000, 'max' => 6950000, 'rate' => 0.0025],
                        ['min' => 6950000, 'max' => 7350000, 'rate' => 0.0050],
                        ['min' => 7350000, 'max' => 7800000, 'rate' => 0.0075],
                        ['min' => 7800000, 'max' => 8850000, 'rate' => 0.0100],
                        ['min' => 8850000, 'max' => 9800000, 'rate' => 0.0125],
                        ['min' => 9800000, 'max' => 10950000, 'rate' => 0.0150],
                        ['min' => 10950000, 'max' => 11200000, 'rate' => 0.0175],
                        ['min' => 11200000, 'max' => 12050000, 'rate' => 0.0200],
                        ['min' => 12050000, 'max' => 12950000, 'rate' => 0.0225],
                        ['min' => 12950000, 'max' => 14150000, 'rate' => 0.0250],
                        ['min' => 14150000, 'max' => 15550000, 'rate' => 0.0300],
                        ['min' => 15550000, 'max' => 17050000, 'rate' => 0.0400],
                        ['min' => 17050000, 'max' => 19500000, 'rate' => 0.0500],
                        ['min' => 19500000, 'max' => 22700000, 'rate' => 0.0600],
                        ['min' => 22700000, 'max' => 26600000, 'rate' => 0.0700],
                        ['min' => 26600000, 'max' => 31000000, 'rate' => 0.0800],
                        ['min' => 31000000, 'max' => 36500000, 'rate' => 0.0900],
                        ['min' => 36500000, 'max' => 42800000, 'rate' => 0.1000],
                        ['min' => 42800000, 'max' => 50000000, 'rate' => 0.1200],
                        ['min' => 50000000, 'max' => 70000000, 'rate' => 0.1500],
                        ['min' => 70000000, 'max' => 100000000, 'rate' => 0.2000],
                        ['min' => 100000000, 'max' => 1419000000, 'rate' => 0.2500],
                        ['min' => 1419000000, 'max' => null, 'rate' => 0.3400],
                    ],
                ],
                'is_locked' => true,
            ]
        );

        // 5. Tarif Progresif Pasal 17 UU HPP
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'PPH21_PASAL17',
                'version_code' => 'PASAL17_UUHPP',
            ],
            [
                'effective_from' => '2024-01-01',
                'effective_to' => null,
                'legal_reference' => 'Pasal 17 ayat (1) huruf a UU No. 7 Tahun 2021',
                'rule_parameters' => [
                    'brackets' => [
                        ['min' => 0, 'max' => 60000000, 'rate' => 0.05],
                        ['min' => 60000000, 'max' => 250000000, 'rate' => 0.15],
                        ['min' => 250000000, 'max' => 500000000, 'rate' => 0.25],
                        ['min' => 500000000, 'max' => 5000000000, 'rate' => 0.30],
                        ['min' => 5000000000, 'max' => null, 'rate' => 0.35],
                    ],
                    'biaya_jabatan' => [
                        'rate' => 0.05,
                        'max_monthly' => 500000.00,
                        'max_annual' => 6000000.00,
                    ],
                ],
                'is_locked' => true,
            ]
        );

        // 6. Insentif PPh 21 DTP PMK 105/2025 Sektor Pariwisata & F&B
        StatutoryRuleVersion::updateOrCreate(
            [
                'jurisdiction' => 'ID',
                'rule_category' => 'PPH21_DTP',
                'version_code' => 'DTP_PMK105_2025',
            ],
            [
                'effective_from' => '2025-01-01',
                'effective_to' => null,
                'legal_reference' => 'PMK No. 105 Tahun 2025',
                'rule_parameters' => [
                    'max_gross_monthly' => 10000000.00,
                    'eligible_sectors' => ['RESTO', 'CAFE', 'FNB', 'HOTEL', 'TOURISM'],
                ],
                'is_locked' => true,
            ]
        );
    }
}
