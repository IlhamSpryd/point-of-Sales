<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Enums\AccountSubtypeEnum;
use App\Modules\Finance\Enums\AccountTypeEnum;
use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Exceptions\UnresolvableAccountMappingException;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Services\ChartOfAccountService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartOfAccountServiceTest extends TestCase
{
    protected ChartOfAccountService $service;

    protected Tenant $tenant;

    protected User $user;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = app(ChartOfAccountService::class);
    }

    public function test_create_account_succeeds_with_defaults(): void
    {
        $account = $this->service->createAccount([
            'account_code' => '1105',
            'account_name' => 'Kas Kecil Cabang',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK,
            'system_account_key' => 'PETTY_CASH_TEST',
        ], $this->user);

        $this->assertInstanceOf(ChartOfAccount::class, $account);
        $this->assertEquals('1105', $account->account_code);
        $this->assertEquals(NormalBalanceEnum::DEBIT, $account->normal_balance);
        $this->assertEquals(1, $account->level);
        $this->assertTrue($account->is_active);
    }

    public function test_create_child_account_calculates_level(): void
    {
        $parent = $this->service->createAccount([
            'account_code' => '1100',
            'account_name' => 'Kas dan Setara Kas',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK,
            'is_postable' => false,
        ], $this->user);

        $child = $this->service->createAccount([
            'account_code' => '1101_SUB',
            'account_name' => 'Kas Utama',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CASH_BANK,
            'parent_id' => $parent->id,
        ], $this->user);

        $this->assertEquals(1, $parent->level);
        $this->assertEquals(2, $child->level);
    }

    public function test_cannot_modify_account_type_when_transactions_exist(): void
    {
        $account = $this->service->createAccount([
            'account_code' => '1199',
            'account_name' => 'Akun Uji Transaksi',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::CURRENT_ASSET,
        ], $this->user);

        $periodId = DB::table('accounting_periods')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'period_code' => '2026-11-TEST',
            'fiscal_year' => 2026,
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $journalId = DB::table('journal_entries')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'journal_number' => 'JE-TEST-MUTASI',
            'accounting_period_id' => $periodId,
            'posting_date' => '2026-11-05',
            'journal_type' => 'SYSTEM_AUTO',
            'status' => 'POSTED',
            'idempotency_key' => 'idemp-mutasi-test',
            'total_debit' => 10000.00,
            'total_credit' => 10000.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            'tenant_id' => $this->tenant->id,
            'journal_entry_id' => $journalId,
            'line_number' => 1,
            'account_id' => $account->id,
            'debit_amount' => 10000.00,
            'credit_amount' => 0.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FIN-M7-08: Tipe akun tidak dapat diubah karena akun telah memiliki transaksi jurnal.');

        $this->service->updateAccount($account->id, [
            'account_type' => AccountTypeEnum::LIABILITY,
        ], $this->user);
    }

    public function test_get_account_by_system_key_works_or_throws_exception(): void
    {
        $this->service->createAccount([
            'account_code' => '1130_SYS',
            'account_name' => 'Piutang Sistem Key',
            'account_type' => AccountTypeEnum::ASSET,
            'account_subtype' => AccountSubtypeEnum::RECEIVABLE,
            'system_account_key' => 'AR_TEST_KEY',
        ], $this->user);

        $found = $this->service->getAccountBySystemKey('AR_TEST_KEY');
        $this->assertEquals('1130_SYS', $found->account_code);

        $this->expectException(UnresolvableAccountMappingException::class);
        $this->service->getAccountBySystemKey('KEY_TIDAK_ADA');
    }
}
