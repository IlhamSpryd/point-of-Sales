<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Customers\Models\CustomerCreditAccount;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Sales\Enums\CustomerInvoiceStatusEnum;
use App\Modules\Sales\Models\ArLedgerEntry;
use App\Modules\Sales\Models\CustomerInvoice;
use Carbon\Carbon;

class ArGlReconciliationService
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Menghitung Jadwal Umur Piutang (AR Aging Schedule) per tanggal acuan ($asOfDate).
     *
     * @return array<string, mixed>
     */
    public function getArAgingSchedule(int $tenantId, ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate) : Carbon::today();

        $invoices = CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID])
            ->where('balance_due', '>', 0)
            ->with('customer')
            ->get();

        $buckets = [
            'current' => '0.00',
            '1_30' => '0.00',
            '31_60' => '0.00',
            '61_90' => '0.00',
            'over_90' => '0.00',
            'total_outstanding' => '0.00',
            'invoices_count' => $invoices->count(),
            'details' => [],
        ];

        foreach ($invoices as $inv) {
            $balance = (string) $inv->balance_due;
            $dueDate = Carbon::parse($inv->due_date);
            $diffDays = $asOf->diffInDays($dueDate, false);

            $bucketKey = 'current';
            if ($diffDays < 0) {
                $daysOverdue = abs((int) $diffDays);
                if ($daysOverdue <= 30) {
                    $bucketKey = '1_30';
                } elseif ($daysOverdue <= 60) {
                    $bucketKey = '31_60';
                } elseif ($daysOverdue <= 90) {
                    $bucketKey = '61_90';
                } else {
                    $bucketKey = 'over_90';
                }
            }

            $buckets[$bucketKey] = bcadd($buckets[$bucketKey], $balance, 2);
            $buckets['total_outstanding'] = bcadd($buckets['total_outstanding'], $balance, 2);

            $buckets['details'][] = [
                'invoice_id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'customer_name' => $inv->customer?->name ?? $inv->customer?->company_name ?? 'N/A',
                'due_date' => $inv->due_date->toDateString(),
                'balance_due' => $balance,
                'bucket' => $bucketKey,
                'days_overdue' => $diffDays < 0 ? abs((int) $diffDays) : 0,
            ];
        }

        return $buckets;
    }

    /**
     * Rekonsiliasi 4-Way antara Faktur Piutang, Subledger AR, Akun Kredit Pelanggan, dan Buku Besar GL (AR Control).
     *
     * @return array<string, mixed>
     */
    public function reconcileArToGl(int $tenantId): array
    {
        // 1. Total Outstanding pada Customer Invoices
        $invoiceTotal = (string) (CustomerInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [CustomerInvoiceStatusEnum::POSTED, CustomerInvoiceStatusEnum::PARTIALLY_PAID])
            ->sum('balance_due') ?? '0.00');

        // 2. Total Saldo pada Buku Pembantu Piutang (ar_ledger_entries)
        // Debit menambah piutang, Credit mengurangi piutang
        $ledgerDebit = (string) (ArLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->sum('debit_amount') ?? '0.00');

        $ledgerCredit = (string) (ArLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->sum('credit_amount') ?? '0.00');

        $subledgerTotal = bcsub($ledgerDebit, $ledgerCredit, 2);

        // 3. Total Tagihan pada Akun Kredit Pelanggan (invoiced_exposure)
        $exposureTotal = (string) (CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->sum('invoiced_exposure') ?? '0.00');

        // 4. Saldo Akun Kontrol Piutang (AR_CONTROL) di Buku Besar (GL)
        $arAccount = $this->coaService->getAccountBySystemKey('AR_CONTROL', $tenantId);

        $glDebit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $arAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('debit_amount') ?? '0.00');

        $glCredit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $arAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('credit_amount') ?? '0.00');

        $glTotal = bcsub($glDebit, $glCredit, 2);

        // 5. Hitung Selisih
        $diffSubledgerVsInvoices = bcsub($subledgerTotal, $invoiceTotal, 2);
        $diffSubledgerVsGl = bcsub($subledgerTotal, $glTotal, 2);
        $diffInvoicesVsGl = bcsub($invoiceTotal, $glTotal, 2);
        $diffExposureVsInvoices = bcsub($exposureTotal, $invoiceTotal, 2);

        $isReconciled = (bccomp($diffSubledgerVsInvoices, '0.00', 2) === 0)
            && (bccomp($diffSubledgerVsGl, '0.00', 2) === 0)
            && (bccomp($diffInvoicesVsGl, '0.00', 2) === 0);

        return [
            'is_reconciled' => $isReconciled,
            'customer_invoices_outstanding' => $invoiceTotal,
            'ar_subledger_balance' => $subledgerTotal,
            'customer_invoiced_exposure' => $exposureTotal,
            'gl_ar_control_balance' => $glTotal,
            'diff_subledger_vs_invoices' => $diffSubledgerVsInvoices,
            'diff_subledger_vs_gl' => $diffSubledgerVsGl,
            'diff_invoices_vs_gl' => $diffInvoicesVsGl,
            'diff_exposure_vs_invoices' => $diffExposureVsInvoices,
        ];
    }

    /**
     * Rekonsiliasi Kelebihan Bayar Pelanggan (Unapplied Credit) vs Liabilitas di GL.
     *
     * @return array<string, mixed>
     */
    public function reconcileCustomerUnappliedCredits(int $tenantId): array
    {
        // 1. Saldo operasional unapplied credit di modul pelanggan
        $operationalCredits = (string) (CustomerCreditAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->sum('unapplied_credit_balance') ?? '0.00');

        // 2. Saldo liabilitas CUSTOMER_UNAPPLIED_CREDIT_LIABILITY di GL (Kredit - Debit)
        $liabilityAccount = $this->coaService->getAccountBySystemKey('CUSTOMER_UNAPPLIED_CREDIT_LIABILITY', $tenantId);

        $glCredit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $liabilityAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('credit_amount') ?? '0.00');

        $glDebit = (string) (JournalLine::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $liabilityAccount->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'POSTED'))
            ->sum('debit_amount') ?? '0.00');

        $glLiabilityBalance = bcsub($glCredit, $glDebit, 2);
        $diff = bcsub($operationalCredits, $glLiabilityBalance, 2);

        return [
            'is_reconciled' => (bccomp($diff, '0.00', 2) === 0),
            'operational_unapplied_credits' => $operationalCredits,
            'gl_unapplied_credits_liability' => $glLiabilityBalance,
            'difference' => $diff,
        ];
    }
}
