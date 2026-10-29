<?php

declare(strict_types=1);

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Enums\AccountingPeriodStatusEnum;
use App\Modules\Finance\Enums\JournalStatusEnum;
use App\Modules\Finance\Enums\OutboxStatusEnum;
use App\Modules\Finance\Models\AccountingPeriod;
use App\Modules\Finance\Models\CashBankAccount;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\FinancialEventOutbox;
use App\Modules\Finance\Models\JournalEntry;
use App\Services\Context\TenantContext;
use Illuminate\View\View;

class FinanceDashboardController extends Controller
{
    /**
     * Menampilkan dashboard dan ringkasan eksekutif Keuangan & Akuntansi (Modul 7).
     */
    public function index(): View
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        $activePeriod = AccountingPeriod::where('tenant_id', $tenantId)
            ->where('status', AccountingPeriodStatusEnum::OPEN->value)
            ->latest('start_date')
            ->first();

        $unpostedJournalsCount = JournalEntry::where('tenant_id', $tenantId)
            ->whereIn('status', [JournalStatusEnum::DRAFT->value, JournalStatusEnum::PENDING_APPROVAL->value])
            ->count();

        $postedJournalsCount = JournalEntry::where('tenant_id', $tenantId)
            ->where('status', JournalStatusEnum::POSTED->value)
            ->count();

        $activeCoaCount = ChartOfAccount::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->count();

        $pendingOutboxCount = FinancialEventOutbox::where('tenant_id', $tenantId)
            ->where('status', OutboxStatusEnum::PENDING->value)
            ->count();

        $recentJournals = JournalEntry::where('tenant_id', $tenantId)
            ->with(['lines.account'])
            ->latest('posting_date')
            ->take(6)
            ->get();

        $cashBankAccounts = CashBankAccount::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->take(6)
            ->get();

        return view('finance.index', compact(
            'activePeriod',
            'unpostedJournalsCount',
            'postedJournalsCount',
            'activeCoaCount',
            'pendingOutboxCount',
            'recentJournals',
            'cashBankAccounts'
        ));
    }
}
