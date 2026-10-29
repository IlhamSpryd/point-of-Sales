<x-app-layout>
    <x-slot:header>
        <x-erp.page-header
            title="Keuangan & Akuntansi (General Ledger)"
            description="Pusat manajemen akuntansi dual-entry, bagan akun (COA), pemantauan arus kas bank, dan audit mutasi buku besar multi-cabang."
            badge="MODUL 7"
            badgeVariant="neutral"
            :breadcrumbs="[['label' => 'Dashboard', 'href' => route('dashboard')], ['label' => 'Keuangan & Akuntansi']]"
        />
    </x-slot:header>

    <div class="space-y-6" data-gsap="page-enter">
        <!-- KPI Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4" data-gsap="kpi-card">
            <x-erp.stat-card
                label="Status Buku Besar"
                :value="$activePeriod ? 'Periode ' . $activePeriod->period_code : 'Belum Ada Periode Aktif'"
                :delta="$activePeriod ? 'Status: ' . $activePeriod->status->value : 'Perlu Setup'"
                :deltaVariant="$activePeriod ? 'positive' : 'warning'"
                icon="account_balance"
            />
            <x-erp.stat-card
                label="Jurnal Draf / Menunggu"
                :value="$unpostedJournalsCount . ' Transaksi'"
                :delta="$postedJournalsCount . ' Terposting'"
                :deltaVariant="$unpostedJournalsCount === 0 ? 'positive' : 'warning'"
                icon="rule"
            />
            <x-erp.stat-card
                label="Bagan Akun (CoA) Aktif"
                :value="$activeCoaCount . ' Akun Aktif'"
                delta="Standar PSAK"
                deltaVariant="positive"
                icon="account_tree"
            />
            <x-erp.stat-card
                label="Integritas Outbox"
                :value="$pendingOutboxCount === 0 ? '100% Sinkron' : $pendingOutboxCount . ' Tertunda'"
                :delta="$pendingOutboxCount === 0 ? '0 Antrean' : 'Perlu Dispatch'"
                :deltaVariant="$pendingOutboxCount === 0 ? 'positive' : 'warning'"
                icon="verified"
            />
        </div>

        <!-- Section Info Card -->
        <div class="card-surface p-6 rounded-2xl border border-zinc-200/80 dark:border-zinc-800 space-y-4 shadow-xs">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-900 dark:text-zinc-100">
                    <span class="material-symbols-rounded text-xl">account_balance_wallet</span>
                </div>
                <div>
                    <h3 class="text-base font-bold text-zinc-950 dark:text-white">Pusat Akuntansi & Rekonsiliasi Finansial</h3>
                    <p class="text-xs text-zinc-500">Mencatat mutasi GL dari transaksi kasir, penerimaan barang, faktur pemasok, piutang pelanggan, dan retur.</p>
                </div>
            </div>

            <p class="text-xs text-zinc-600 dark:text-zinc-400 leading-relaxed">
                Modul 7 (Finance & General Ledger) beroperasi menerima stream data melalui tabel outbox akuntansi
                (<code class="font-mono text-[11px] bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-zinc-800 dark:text-zinc-200">accounting_payable_outbox</code> dan
                <code class="font-mono text-[11px] bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 rounded text-zinc-800 dark:text-zinc-200">accounting_receivable_outbox</code>)
                menghasilkan jurnal otomatis dengan kepatuhan penuh pada prinsip dual-entry, imutabilitas catatan terposting, dan audit trail PSAK.
            </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Recent Journal Entries List (2 Cols) -->
            <div class="lg:col-span-2 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-rounded text-apeiron-primary text-base">receipt_long</span>
                        Jurnal Transaksi Terbaru
                    </h3>
                    <span class="text-xs text-zinc-400 font-mono">{{ $postedJournalsCount }} Jurnal Diposting</span>
                </div>

                <div class="card-surface rounded-2xl overflow-hidden border border-zinc-200/80 dark:border-zinc-800 shadow-xs">
                    @if($recentJournals->isEmpty())
                        <div class="p-8 text-center space-y-2">
                            <span class="material-symbols-rounded text-3xl text-zinc-300 dark:text-zinc-600">hourglass_empty</span>
                            <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Belum ada jurnal transaksi tercatat</p>
                            <p class="text-xs text-zinc-400 max-w-sm mx-auto">
                                Jurnal akan terbit secara otomatis saat transaksi kasir diselesaikan, penerimaan barang (GRN) disetujui, atau faktur diverifikasi.
                            </p>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th class="px-5 py-3">No. Jurnal</th>
                                        <th class="px-5 py-3">Tanggal</th>
                                        <th class="px-5 py-3">Modul Sumber</th>
                                        <th class="px-5 py-3 text-right">Debit / Kredit</th>
                                        <th class="px-5 py-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @foreach($recentJournals as $journal)
                                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                                            <td class="px-5 py-3 font-mono font-medium text-zinc-900 dark:text-white">
                                                {{ $journal->journal_number }}
                                            </td>
                                            <td class="px-5 py-3 text-xs text-zinc-500">
                                                {{ $journal->posting_date?->format('d M Y') ?? '-' }}
                                            </td>
                                            <td class="px-5 py-3 text-xs">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 font-mono text-[11px]">
                                                    {{ $journal->source_module ?? 'General' }}
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-right font-mono text-xs tabular-nums text-zinc-900 dark:text-white font-medium">
                                                Rp {{ number_format((float) $journal->total_debit, 2, ',', '.') }}
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <x-ui.badge :variant="$journal->status->value === 'POSTED' ? 'success' : 'neutral'">
                                                    {{ $journal->status->value }}
                                                </x-ui.badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Cash & Bank Accounts Sidebar (1 Col) -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                        <span class="material-symbols-rounded text-apeiron-primary text-base">payments</span>
                        Rekening Kas & Bank
                    </h3>
                    <span class="text-xs text-zinc-400 font-mono">{{ $cashBankAccounts->count() }} Akun</span>
                </div>

                <div class="card-surface rounded-2xl p-5 border border-zinc-200/80 dark:border-zinc-800 space-y-3 shadow-xs">
                    @if($cashBankAccounts->isEmpty())
                        <div class="text-center py-6 space-y-2">
                            <span class="material-symbols-rounded text-2xl text-zinc-300 dark:text-zinc-600">account_balance</span>
                            <p class="text-xs text-zinc-500">Belum ada akun bank/kas terkonfigurasi.</p>
                        </div>
                    @else
                        @foreach($cashBankAccounts as $account)
                            <div class="p-3 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-200/60 dark:border-zinc-800 flex items-center justify-between">
                                <div class="space-y-0.5">
                                    <div class="text-xs font-bold text-zinc-900 dark:text-white">
                                        {{ $account->account_name }}
                                    </div>
                                    <div class="text-[11px] font-mono text-zinc-400">
                                        {{ $account->bank_name ? $account->bank_name . ' • ' : '' }}{{ $account->bank_account_number ?? $account->account_code }}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-bold font-mono text-zinc-900 dark:text-white tabular-nums">
                                        Rp {{ number_format((float) $account->current_balance, 2, ',', '.') }}
                                    </div>
                                    <span class="text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">Aktif</span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
