<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\NormalBalanceEnum;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Services\Context\TenantContext;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeneralLedgerInquiryService
{
    public function __construct(
        protected TenantContext $tenantContext
    ) {}

    /**
     * Mengambil laporan mutasi buku besar umum untuk akun tertentu dalam rentang tanggal.
     *
     * @return array{
     *     account: ChartOfAccount,
     *     opening_balance: string,
     *     lines: Collection,
     *     total_debit: string,
     *     total_credit: string,
     *     closing_balance: string
     * }
     */
    public function getAccountLedger(
        int $accountId,
        string $startDate,
        string $endDate,
        ?int $storeId = null,
        ?int $tenantId = null
    ): array {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            throw new DomainException('TenantContext tidak valid.');
        }

        $account = ChartOfAccount::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('id', $accountId)
            ->firstOrFail();

        $startStr = Carbon::parse($startDate)->toDateString();
        $endStr = Carbon::parse($endDate)->toDateString();

        // 1. Hitung Opening Balance dari seluruh jurnal POSTED sebelum startDate
        $priorQuery = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.tenant_id', $tenantId)
            ->where('jl.account_id', $accountId)
            ->where('je.status', 'POSTED')
            ->where('je.posting_date', '<', $startStr);

        if ($storeId !== null) {
            $priorQuery->where('jl.store_id', $storeId);
        }

        $priorTotals = $priorQuery->selectRaw('COALESCE(SUM(jl.debit_amount), 0) as prior_debit, COALESCE(SUM(jl.credit_amount), 0) as prior_credit')->first();

        $priorDebit = (string) ($priorTotals->prior_debit ?? '0.00');
        $priorCredit = (string) ($priorTotals->prior_credit ?? '0.00');

        $openingBalance = ($account->normal_balance === NormalBalanceEnum::DEBIT)
            ? bcsub($priorDebit, $priorCredit, 2)
            : bcsub($priorCredit, $priorDebit, 2);

        // 2. Ambil mutasi dalam periode
        $linesQuery = JournalLine::withoutTenantScope()
            ->with(['journalEntry'])
            ->where('tenant_id', $tenantId)
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($startStr, $endStr) {
                $q->where('status', 'POSTED')
                    ->whereBetween('posting_date', [$startStr, $endStr]);
            })
            ->orderBy('id');

        if ($storeId !== null) {
            $linesQuery->where('store_id', $storeId);
        }

        $rawLines = $linesQuery->get();

        // 3. Kalkulasi running balance
        $currentBalance = $openingBalance;
        $totalDebit = '0.00';
        $totalCredit = '0.00';
        $processedLines = new Collection;

        foreach ($rawLines as $line) {
            $dr = (string) $line->debit_amount;
            $cr = (string) $line->credit_amount;

            $totalDebit = bcadd($totalDebit, $dr, 2);
            $totalCredit = bcadd($totalCredit, $cr, 2);

            $movement = ($account->normal_balance === NormalBalanceEnum::DEBIT)
                ? bcsub($dr, $cr, 2)
                : bcsub($cr, $dr, 2);

            $currentBalance = bcadd($currentBalance, $movement, 2);

            $processedLines->push([
                'id' => $line->id,
                'journal_id' => $line->journal_entry_id,
                'journal_number' => $line->journalEntry->journal_number,
                'posting_date' => $line->journalEntry->posting_date->toDateString(),
                'source_type' => $line->journalEntry->source_type,
                'source_number' => $line->journalEntry->source_number,
                'description' => $line->description,
                'debit_amount' => $dr,
                'credit_amount' => $cr,
                'running_balance' => $currentBalance,
            ]);
        }

        return [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'lines' => $processedLines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $currentBalance,
        ];
    }
}
