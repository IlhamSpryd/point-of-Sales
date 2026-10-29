<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\DataTransferObjects\JournalLineDto;
use App\Modules\Finance\DataTransferObjects\ManualJournalDto;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalEntry;
use App\Services\Context\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    public function __construct(
        protected TenantContext $tenantContext,
        protected PostingEngineService $postingEngine,
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Memposting saldo awal neraca cutover ke buku besar umum.
     *
     * @param  array<array{account_id: int, debit: string, credit: string, description?: string}>  $balances
     */
    public function postOpeningBalances(array $balances, string $cutoverDate, User $actor, ?int $storeId = null): JournalEntry
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        // 1. Validasi Rekonsiliasi Subledger terhadap Saldo Awal (Bagian 14.3)
        $this->validateOpeningBalanceReconciliations($tenantId, $balances);

        // 2. Hitung total debit dan credit
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $lines = [];

        foreach ($balances as $b) {
            $dr = bcadd((string) ($b['debit'] ?? '0.00'), '0', 2);
            $cr = bcadd((string) ($b['credit'] ?? '0.00'), '0', 2);

            if (bccomp($dr, '0.00', 2) === 0 && bccomp($cr, '0.00', 2) === 0) {
                continue;
            }

            $totalDebit = bcadd($totalDebit, $dr, 2);
            $totalCredit = bcadd($totalCredit, $cr, 2);

            $lines[] = new JournalLineDto(
                accountId: (int) $b['account_id'],
                debitAmount: $dr,
                creditAmount: $cr,
                description: $b['description'] ?? 'Saldo Awal Cutover Akuntansi',
                storeId: $storeId
            );
        }

        // 3. Jika debit != credit, gunakan akun penyeimbang Opening Balance Equity (3900)
        $equityAccount = $this->coaService->getAccountBySystemKey('OPENING_BALANCE_EQUITY', $tenantId);

        $diff = bcsub($totalDebit, $totalCredit, 2);
        if (bccomp($diff, '0.00', 2) !== 0) {
            if (bccomp($diff, '0.00', 2) > 0) {
                // Debit > Credit: Credit Opening Balance Equity
                $lines[] = new JournalLineDto(
                    accountId: $equityAccount->id,
                    debitAmount: '0.00',
                    creditAmount: $diff,
                    description: 'Penyeimbang Saldo Awal (Opening Balance Equity)',
                    storeId: $storeId
                );
            } else {
                // Credit > Debit: Debit Opening Balance Equity
                $lines[] = new JournalLineDto(
                    accountId: $equityAccount->id,
                    debitAmount: bcmul($diff, '-1', 2),
                    creditAmount: '0.00',
                    description: 'Penyeimbang Saldo Awal (Opening Balance Equity)',
                    storeId: $storeId
                );
            }
        }

        $idempotencyKey = "opening_balance:{$tenantId}:{$cutoverDate}";

        $dto = new ManualJournalDto(
            tenantId: $tenantId,
            storeId: $storeId,
            postingDate: $cutoverDate,
            idempotencyKey: $idempotencyKey,
            lines: $lines,
            notes: "Saldo Awal Pembukuan Cutover per {$cutoverDate}",
            currencyCode: 'IDR',
            journalType: 'OPENING_BALANCE'
        );

        return $this->postingEngine->postManualJournal($dto, $actor);
    }

    protected function validateOpeningBalanceReconciliations(int $tenantId, array $balances): void
    {
        $accounts = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_column($balances, 'account_id'))
            ->get()
            ->keyBy('id');

        foreach ($balances as $b) {
            $account = $accounts[$b['account_id']] ?? null;
            if (! $account || empty($account->system_account_key)) {
                continue;
            }

            $key = $account->system_account_key;
            $balanceAmount = bccomp((string) ($b['debit'] ?? '0'), '0', 2) > 0 ? (string) $b['debit'] : (string) ($b['credit'] ?? '0');

            // (a) AR Control Check
            if ($key === 'AR_CONTROL' && DB::getSchemaBuilder()->hasTable('customer_invoices')) {
                $actualAr = DB::table('customer_invoices')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['POSTED', 'PARTIALLY_PAID'])
                    ->sum('balance_due');
                if (bccomp($balanceAmount, (string) $actualAr, 2) !== 0) {
                    throw new DomainException("FIN-M7-09: Saldo awal AR Control ({$balanceAmount}) tidak cocok dengan total piutang invoice terbuka ({$actualAr}).");
                }
            }

            // (b) AP Control Check
            if ($key === 'AP_CONTROL' && DB::getSchemaBuilder()->hasTable('supplier_invoices')) {
                $actualAp = DB::table('supplier_invoices')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['approved', 'posted'])
                    ->sum('outstanding_amount');
                if (bccomp($balanceAmount, (string) $actualAp, 2) !== 0) {
                    throw new DomainException("FIN-M7-09: Saldo awal AP Control ({$balanceAmount}) tidak cocok dengan total utang faktur supplier terbuka ({$actualAp}).");
                }
            }
        }
    }
}
