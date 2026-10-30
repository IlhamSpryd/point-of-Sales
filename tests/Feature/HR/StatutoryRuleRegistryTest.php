<?php

declare(strict_types=1);

namespace Tests\Feature\HR;

use App\Modules\HR\Services\StatutoryRuleRegistryService;
use Database\Seeders\StatutoryRuleVersionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class StatutoryRuleRegistryTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $optOutFromDefaultTenant = true;

    protected StatutoryRuleRegistryService $service;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        // Ensure database has standard seeded rules
        $this->seed(StatutoryRuleVersionsSeeder::class);
        $this->service = app(StatutoryRuleRegistryService::class);
    }

    public function test_resolve_active_bpjs_parameters(): void
    {
        $params = $this->service->getBpjsParameters('2026-03-15');

        $this->assertArrayHasKey('tk', $params);
        $this->assertArrayHasKey('ks', $params);

        // BPJS TK JP Plafon 2026 = Rp 11.086.300
        $this->assertEquals(11086300.00, $params['tk']['jp_wage_cap']);
        $this->assertEquals(0.02, $params['tk']['jht_employee_rate']);
        $this->assertEquals(0.01, $params['tk']['jp_employee_rate']);

        // BPJS KS Plafon = Rp 12.000.000
        $this->assertEquals(12000000.00, $params['ks']['wage_cap']);
        $this->assertEquals(0.04, $params['ks']['employer_rate']);
        $this->assertEquals(0.01, $params['ks']['employee_rate']);
    }

    public function test_resolve_ter_parameters_and_golden_case_brackets(): void
    {
        $terData = $this->service->getTerParameters('2026-10-15');
        $rules = $terData['rules'];

        $this->assertContains('K/0', $rules['category_mapping']['A']);
        $this->assertContains('TK/0', $rules['category_mapping']['A']);

        // Uji Golden Case 1: Rp 15.561.000 harus kena 6.00% pada Kategori A
        $gross1 = 15561000.00;
        $matchedBracket1 = collect($rules['brackets_a'])->first(function ($b) use ($gross1) {
            return $gross1 > $b['min'] && ($b['max'] === null || $gross1 <= $b['max']);
        });

        $this->assertNotNull($matchedBracket1);
        $this->assertEquals(0.06, $matchedBracket1['rate']);

        // Uji Golden Case 2: Rp 7.840.500 harus kena 1.50% pada Kategori A
        $gross2 = 7840500.00;
        $matchedBracket2 = collect($rules['brackets_a'])->first(function ($b) use ($gross2) {
            return $gross2 > $b['min'] && ($b['max'] === null || $gross2 <= $b['max']);
        });

        $this->assertNotNull($matchedBracket2);
        $this->assertEquals(0.015, $matchedBracket2['rate']);
    }

    public function test_resolve_ptkp_values(): void
    {
        $ptkp = $this->service->getPtkpValues('2026-01-01');
        $values = $ptkp['values'];

        $this->assertEquals(54000000.00, $values['TK/0']);
        $this->assertEquals(58500000.00, $values['K/0']);
        $this->assertEquals(72000000.00, $values['K/3']);
    }

    public function test_throws_exception_for_unknown_category(): void
    {
        $this->expectException(DomainException::class);
        $this->service->resolveActiveRule('NON_EXISTENT_RULE', '2026-01-01');
    }
}
