<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\TaxDirectionEnum;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\TaxCode;
use App\Modules\Finance\Models\TaxLedgerEntry;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TaxLedgerService
{
    public function __construct(
        protected TaxConfigurationService $taxConfigService
    ) {}

    /**
     * Mencatat entri baru ke dalam Buku Pembantu Pajak Imutabel (tax_ledger_entries).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordTaxEntry(array $data, ?User $actor = null): TaxLedgerEntry
    {
        $tenantId = (int) ($data['tenant_id'] ?? ($actor ? $actor->tenant_id : 0));
        if ($tenantId <= 0) {
            throw new DomainException('Tenant ID wajib ditentukan.');
        }

        $taxCodeId = (int) ($data['tax_code_id'] ?? 0);
        $taxCode = TaxCode::withoutTenantScope()->where('tenant_id', $tenantId)->find($taxCodeId);
        if (! $taxCode) {
            throw new DomainException("FIN-M7-07: Kode pajak #{$taxCodeId} tidak ditemukan untuk tenant #{$tenantId}.");
        }

        $direction = $data['tax_direction'];
        if (! ($direction instanceof TaxDirectionEnum)) {
            $direction = TaxDirectionEnum::from((string) $direction);
        }

        $date = $data['transaction_date'] ?? now()->toDateString();
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $taxBaseAmount = bcadd((string) ($data['tax_base_amount'] ?? '0.00'), '0', 2);
        $taxAmount = bcadd((string) ($data['tax_amount'] ?? '0.00'), '0', 2);

        // Ambil tarif efektif jika tidak diberikan
        $ratePercent = isset($data['applied_rate'])
            ? bcadd((string) $data['applied_rate'], '0', 4)
            : ($this->taxConfigService->resolveEffectiveRate($taxCode, $dateStr)?->rate_percent ?? '0.0000');

        $taxRateId = $data['tax_rate_id'] ?? $this->taxConfigService->resolveEffectiveRate($taxCode, $dateStr)?->id;

        $entryNumber = $data['entry_number'] ?? ('TLE-'.$tenantId.'-'.date('Ymd').'-'.Str::upper(Str::random(6)));

        return TaxLedgerEntry::withoutTenantScope()->create([
            'tenant_id' => $tenantId,
            'tax_code_id' => $taxCode->id,
            'tax_rate_id' => $taxRateId,
            'journal_entry_id' => ! empty($data['journal_entry_id']) ? (int) $data['journal_entry_id'] : null,
            'journal_line_id' => ! empty($data['journal_line_id']) ? (int) $data['journal_line_id'] : null,
            'entry_number' => $entryNumber,
            'transaction_date' => $dateStr,
            'tax_direction' => $direction,
            'tax_base_amount' => $taxBaseAmount,
            'applied_rate' => $ratePercent,
            'tax_amount' => $taxAmount,
            'counterparty_type' => $data['counterparty_type'] ?? null,
            'counterparty_id' => ! empty($data['counterparty_id']) ? (int) $data['counterparty_id'] : null,
            'counterparty_name' => $data['counterparty_name'] ?? null,
            'counterparty_tax_id' => $data['counterparty_tax_id'] ?? null,
            'invoice_reference' => $data['invoice_reference'] ?? null,
            'tax_invoice_number' => $data['tax_invoice_number'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Merekam kumpulan entri buku pajak yang terikat ke JournalEntry tertentu.
     *
     * @param  array<int, array<string, mixed>>  $taxEntriesData
     * @return array<int, TaxLedgerEntry>
     */
    public function recordTaxEntriesForJournal(JournalEntry $journal, array $taxEntriesData, ?User $actor = null): array
    {
        $created = [];
        foreach ($taxEntriesData as $entry) {
            $entry['tenant_id'] = $journal->tenant_id;
            $entry['journal_entry_id'] = $journal->id;
            $entry['transaction_date'] = $entry['transaction_date'] ?? $journal->posting_date;
            $created[] = $this->recordTaxEntry($entry, $actor);
        }

        return $created;
    }

    /**
     * Rekonsiliasi buku pembantu pajak terhadap akun GL Pajak Keluaran (Liability) & Pajak Masukan (Asset).
     *
     * @return array{tax_code_id: int, output_tax_subledger: string, output_tax_gl: string, output_tax_diff: string, input_tax_subledger: string, input_tax_gl: string, input_tax_diff: string, is_reconciled: bool}
     */
    public function reconcileWithGl(
        int $tenantId,
        int $taxCodeId,
        CarbonInterface|string|null $startDate = null,
        CarbonInterface|string|null $endDate = null
    ): array {
        $taxCode = TaxCode::withoutTenantScope()->where('tenant_id', $tenantId)->with('accountMapping')->findOrFail($taxCodeId);
        $mapping = $taxCode->accountMapping;

        $startStr = $startDate ? ($startDate instanceof CarbonInterface ? $startDate->toDateString() : Carbon::parse($startDate)->toDateString()) : null;
        $endStr = $endDate ? ($endDate instanceof CarbonInterface ? $endDate->toDateString() : Carbon::parse($endDate)->toDateString()) : null;

        // 1. Subledger Output Tax
        $outputSubledgerQuery = TaxLedgerEntry::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('tax_code_id', $taxCodeId)
            ->where('tax_direction', TaxDirectionEnum::OUTPUT_TAX);

        if ($startStr) {
            $outputSubledgerQuery->where('transaction_date', '>=', $startStr);
        }
        if ($endStr) {
            $outputSubledgerQuery->where('transaction_date', '<=', $endStr);
        }
        $outputSubledger = bcadd((string) $outputSubledgerQuery->sum('tax_amount'), '0', 2);

        // 2. GL Output Tax (Kredit - Debit untuk akun Kewajiban PPN Keluaran)
        $outputGl = '0.00';
        if ($mapping && $mapping->sales_tax_account_id) {
            $glLines = DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_lines.tenant_id', $tenantId)
                ->where('journal_lines.account_id', $mapping->sales_tax_account_id)
                ->where('journal_entries.status', JournalStatusEnum::POSTED->value);

            if ($startStr) {
                $glLines->where('journal_entries.posting_date', '>=', $startStr);
            }
            if ($endStr) {
                $glLines->where('journal_entries.posting_date', '<=', $endStr);
            }

            $sums = $glLines->selectRaw('COALESCE(SUM(credit_amount), 0) as total_cr, COALESCE(SUM(debit_amount), 0) as total_db')->first();
            $outputGl = bcsub((string) ($sums->total_cr ?? '0.00'), (string) ($sums->total_db ?? '0.00'), 2);
        }

        $outputDiff = bcsub($outputSubledger, $outputGl, 2);

        // 3. Subledger Input Tax
        $inputSubledgerQuery = TaxLedgerEntry::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('tax_code_id', $taxCodeId)
            ->where('tax_direction', TaxDirectionEnum::INPUT_TAX);

        if ($startStr) {
            $inputSubledgerQuery->where('transaction_date', '>=', $startStr);
        }
        if ($endStr) {
            $inputSubledgerQuery->where('transaction_date', '<=', $endStr);
        }
        $inputSubledger = bcadd((string) $inputSubledgerQuery->sum('tax_amount'), '0', 2);

        // 4. GL Input Tax (Debit - Kredit untuk akun Aset PPN Masukan)
        $inputGl = '0.00';
        if ($mapping && $mapping->purchase_tax_account_id) {
            $glLines = DB::table('journal_lines')
                ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('journal_lines.tenant_id', $tenantId)
                ->where('journal_lines.account_id', $mapping->purchase_tax_account_id)
                ->where('journal_entries.status', JournalStatusEnum::POSTED->value);

            if ($startStr) {
                $glLines->where('journal_entries.posting_date', '>=', $startStr);
            }
            if ($endStr) {
                $glLines->where('journal_entries.posting_date', '<=', $endStr);
            }

            $sums = $glLines->selectRaw('COALESCE(SUM(debit_amount), 0) as total_db, COALESCE(SUM(credit_amount), 0) as total_cr')->first();
            $inputGl = bcsub((string) ($sums->total_db ?? '0.00'), (string) ($sums->total_cr ?? '0.00'), 2);
        }

        $inputDiff = bcsub($inputSubledger, $inputGl, 2);

        $isReconciled = (bccomp($outputDiff, '0.00', 2) === 0) && (bccomp($inputDiff, '0.00', 2) === 0);

        return [
            'tax_code_id' => $taxCodeId,
            'output_tax_subledger' => $outputSubledger,
            'output_tax_gl' => $outputGl,
            'output_tax_diff' => $outputDiff,
            'input_tax_subledger' => $inputSubledger,
            'input_tax_gl' => $inputGl,
            'input_tax_diff' => $inputDiff,
            'is_reconciled' => $isReconciled,
        ];
    }
}
