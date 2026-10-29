<?php

namespace Tests\Feature\Finance;

use App\Models\AccountingPeriod;
use App\Models\Device;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Services\PostingEngineService;
use App\Modules\Sales\Services\ShiftService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DefaultPostingRulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCloseAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;

    protected $store;

    protected User $cashier;

    protected Device $device;

    protected AccountingPeriod $period;

    protected ShiftService $shiftService;

    protected PostingEngineService $postingEngine;

    protected function afterSubclassSetUp(): void
    {
        parent::afterSubclassSetUp();

        $this->tenant = $this->defaultTenant;
        $this->store = $this->defaultStore;

        app(ChartOfAccountsSeeder::class)->seedForTenant($this->tenant->id);
        app(DefaultPostingRulesSeeder::class)->seedForTenant($this->tenant->id);

        $this->shiftService = app(ShiftService::class);
        $this->postingEngine = app(PostingEngineService::class);

        $this->period = AccountingPeriod::create([
            'tenant_id' => $this->tenant->id,
            'period_code' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'fiscal_year' => (int) now()->format('Y'),
            'period_number' => (int) now()->format('n'),
            'status' => AccountingPeriodStatusEnum::OPEN->value,
            'is_adjustment_period' => false,
        ]);

        $cashierRole = Role::firstOrCreate(
            ['name' => 'Cashier'],
            ['tenant_id' => $this->tenant->id, 'display_name' => 'Cashier']
        );

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role_id' => $cashierRole->id,
            'pin_hash' => '123456',
        ]);

        $this->device = Device::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'device_name' => 'POS Register Shift',
            'device_code' => 'DEV-SHIFT-01',
            'device_type' => 'pos',
            'status' => 'active',
        ]);
    }

    public function test_shift_close_with_cash_overage_posts_balanced_gl_journal(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 500000,
            'starting_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Tutup shift dengan kelebihan kas: dihitung 550.000 vs expected 500.000 (+50.000)
        $closedShift = $this->shiftService->closeShift(
            $shift->id,
            $this->device->id,
            $this->cashier->id,
            550000,
            '123456'
        );

        $this->assertNotNull($closedShift);
        $this->assertEquals(50000, $closedShift->cash_difference);

        // Verifikasi outbox
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'pos.shift.closed')
            ->where('source_id', $shift->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('50000.00', $outbox->payload['overage_amount']);
        $this->assertEquals('0.00', $outbox->payload['shortage_amount']);

        // Verifikasi Jurnal GL
        $journal = JournalEntry::find($outbox->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals($journal->total_debit, $journal->total_credit);

        // Overage: Debit CASH_MAIN (50k) vs Credit CASH_OVERAGE_INCOME (50k)
        $lines = $journal->lines;
        $cashLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_MAIN');
        $this->assertNotNull($cashLine);
        $this->assertEquals('50000.00', $cashLine->debit_amount);

        $incomeLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_OVERAGE_INCOME');
        $this->assertNotNull($incomeLine);
        $this->assertEquals('50000.00', $incomeLine->credit_amount);
    }

    public function test_shift_close_with_cash_shortage_posts_balanced_gl_journal(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 500000,
            'starting_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Tutup shift dengan kekurangan kas: dihitung 480.000 vs expected 500.000 (-20.000)
        $closedShift = $this->shiftService->closeShift(
            $shift->id,
            $this->device->id,
            $this->cashier->id,
            480000,
            '123456'
        );

        $this->assertNotNull($closedShift);
        $this->assertEquals(-20000, $closedShift->cash_difference);

        // Verifikasi outbox
        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'pos.shift.closed')
            ->where('source_id', $shift->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertEquals('0.00', $outbox->payload['overage_amount']);
        $this->assertEquals('20000.00', $outbox->payload['shortage_amount']);

        // Verifikasi Jurnal GL
        $journal = JournalEntry::find($outbox->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::POSTED->value, $journal->status->value);
        $this->assertEquals($journal->total_debit, $journal->total_credit);

        // Shortage: Debit CASH_SHORTAGE_EXPENSE (20k) vs Credit CASH_MAIN (20k)
        $lines = $journal->lines;
        $expenseLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_SHORTAGE_EXPENSE');
        $this->assertNotNull($expenseLine);
        $this->assertEquals('20000.00', $expenseLine->debit_amount);

        $cashLine = $lines->first(fn ($l) => $l->account->system_account_key === 'CASH_MAIN');
        $this->assertNotNull($cashLine);
        $this->assertEquals('20000.00', $cashLine->credit_amount);
    }

    public function test_shift_close_with_zero_difference_does_not_emit_outbox(): void
    {
        $shift = Shift::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'user_id' => $this->cashier->id,
            'active_cashier_id' => $this->cashier->id,
            'opening_balance' => 500000,
            'starting_balance' => 500000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        // Tutup shift pas (0 diff)
        $closedShift = $this->shiftService->closeShift(
            $shift->id,
            $this->device->id,
            $this->cashier->id,
            500000,
            '123456'
        );

        $this->assertNotNull($closedShift);
        $this->assertEquals(0, $closedShift->cash_difference);

        $outbox = FinancialEventOutbox::where('tenant_id', $this->tenant->id)
            ->where('event_type', 'pos.shift.closed')
            ->where('source_id', $shift->id)
            ->first();

        $this->assertNull($outbox, 'Selisih kas 0 tidak boleh menerbitkan outbox finansial');
    }
}
