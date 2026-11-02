<div class="space-y-6">
    @include('finance.partials.sub-nav')

    <!-- Flash Notifications -->
    @if (session()->has('success'))
        <div class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">check_circle</span>
                <span>{{ session('success') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-emerald-500 hover:text-emerald-700">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @endif

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-950 dark:text-white tracking-tight">Jurnal Umum (General Ledger)</h1>
            <p class="text-xs text-zinc-500">Buku besar mutasi keuangan ganda, posting otomatis transaksi dan workbench penyesuaian manual.</p>
        </div>
        <button type="button"
                wire:click="openWorkbenchModal"
                class="inline-flex items-center justify-center gap-2 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
            <span class="material-symbols-rounded text-base">post_add</span>
            <span>Buat Jurnal Manual</span>
        </button>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card-surface p-4 rounded-2xl border border-border flex flex-col lg:flex-row gap-3 items-center justify-between shadow-xs">
        <div class="relative w-full lg:w-72">
            <span class="material-symbols-rounded absolute left-3 top-2.5 text-zinc-400 text-sm">search</span>
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="Cari nomor jurnal, referensi..."
                   class="w-full pl-9 pr-3 py-1.5 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400 focus:outline-none">
        </div>

        <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
            <select wire:model.live="statusFilter" class="text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 px-3 py-1.5 focus:outline-none">
                <option value="">Semua Status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}">{{ $status->value }}</option>
                @endforeach
            </select>

            <div class="flex items-center gap-1.5 text-xs text-zinc-500">
                <span>Dari:</span>
                <input type="date"
                       wire:model.live="dateFrom"
                       class="px-2.5 py-1.5 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none font-mono">
                <span>Sampai:</span>
                <input type="date"
                       wire:model.live="dateTo"
                       class="px-2.5 py-1.5 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none font-mono">
            </div>
        </div>
    </div>

    @php
        $journalColumns = [
            ['key' => 'journal_number', 'label' => 'Nomor Jurnal', 'sortable' => true],
            ['key' => 'posting_date', 'label' => 'Tanggal Posting', 'sortable' => true],
            ['key' => 'source_module', 'label' => 'Sumber & Tipe'],
            ['key' => 'notes', 'label' => 'Keterangan'],
            ['key' => 'total_debit', 'label' => 'Total Debit', 'numeric' => true, 'sortable' => true],
            ['key' => 'total_credit', 'label' => 'Total Credit', 'numeric' => true, 'sortable' => true],
            ['key' => 'status', 'label' => 'Status', 'align' => 'center'],
            ['key' => 'actions', 'label' => 'Aksi', 'align' => 'right'],
        ];
    @endphp

    <!-- Table Container -->
    <x-erp.data-table
        :columns="$journalColumns"
        :rows="$journals"
        loading-target="search, filterModule, filterStatus"
        :is-empty="$journals->isEmpty()"
        empty-title="Belum ada catatan jurnal umum yang terdaftar."
        empty-description="Buat jurnal manual baru atau periksa filter yang aktif."
        empty-icon="receipt_long"
        :colspan="8"
    >
        @foreach ($journals as $journal)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="px-4 py-3 font-mono font-medium text-zinc-950 dark:text-white">
                                {{ $journal->journal_number }}
                            </td>
                            <td class="px-4 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                                {{ $journal->posting_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-semibold text-zinc-900 dark:text-zinc-200">{{ $journal->source_module }}</span>
                                <span class="text-[10px] text-zinc-400 block">{{ $journal->journal_type->value }}</span>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300 max-w-xs truncate" title="{{ $journal->notes }}">
                                {{ $journal->notes ?: '-' }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                Rp {{ number_format((float) $journal->total_debit, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono font-semibold text-zinc-900 dark:text-zinc-100">
                                Rp {{ number_format((float) $journal->total_credit, 2) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold
                                    @if($journal->status->value === 'POSTED') bg-emerald-500/10 text-emerald-600 dark:text-emerald-400
                                    @elseif($journal->status->value === 'REVERSED') bg-rose-500/10 text-rose-600 dark:text-rose-400
                                    @else bg-amber-500/10 text-amber-600 dark:text-amber-400 @endif">
                                    {{ $journal->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button"
                                            wire:click="viewJournal({{ $journal->id }})"
                                            class="p-1 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-700 text-zinc-600 dark:text-zinc-300 transition-colors"
                                            title="Lihat Rincian Baris">
                                        <span class="material-symbols-rounded text-base">visibility</span>
                                    </button>
                                    @if ($journal->status->value === 'POSTED' && ! $journal->reversed_by_journal_id)
                                        <button type="button"
                                                wire:click="openReversalModal({{ $journal->id }})"
                                                class="p-1 rounded-lg hover:bg-rose-500/10 text-rose-500 transition-colors"
                                                title="Reverse Jurnal">
                                            <span class="material-symbols-rounded text-base">undo</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
        @endforeach
    </x-erp.data-table>

    <!-- Workbench Modal: Manual Journal Creation -->
    @if ($showWorkbenchModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-4xl rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-5 max-h-[90vh] overflow-y-auto"
                 @click.outside="$wire.set('showWorkbenchModal', false)">
                
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div>
                        <h3 class="text-base font-bold text-zinc-950 dark:text-white">Workbench Jurnal Manual (Dual-Entry)</h3>
                        <p class="text-xs text-zinc-500">Validasi keseimbangan debit dan credit secara real-time sebelum memposting ke General Ledger.</p>
                    </div>
                    <button type="button" wire:click="$set('showWorkbenchModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                </div>

                <form wire:submit="postManualJournal" class="space-y-4">
                    <x-erp.dirty-guard watch="lines" />
                    <!-- General Header Information -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Tanggal Posting</label>
                            <input type="date"
                                   wire:model="postingDate"
                                   class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-mono">
                            @error('postingDate') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Tipe Jurnal</label>
                            <select wire:model="journalType"
                                    class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                <option value="MANUAL_ADJUSTMENT">MANUAL_ADJUSTMENT (Jurnal Penyesuaian)</option>
                                <option value="OPENING_BALANCE">OPENING_BALANCE (Saldo Awal)</option>
                            </select>
                            @error('journalType') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Catatan / Keterangan</label>
                            <input type="text"
                                   wire:model="notes"
                                   placeholder="Contoh: Penyesuaian beban operasional akhir bulan..."
                                   class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                            @error('notes') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <!-- Journal Lines Editor Table -->
                    <div class="space-y-2 pt-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-zinc-900 dark:text-zinc-100">Rincian Baris Jurnal</span>
                            <button type="button"
                                    wire:click="addLine"
                                    class="inline-flex items-center gap-1 px-3 py-1 text-xs font-semibold text-zinc-800 dark:text-zinc-200 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 dark:hover:bg-zinc-700 rounded-lg transition-colors">
                                <span class="material-symbols-rounded text-sm">add</span>
                                <span>Tambah Baris</span>
                            </button>
                        </div>

                        @error('lines')
                            <div class="p-3 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-xs">
                                {{ $message }}
                            </div>
                        @enderror

                        <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                                    <tr>
                                        <th class="px-3 py-2.5 w-1/3">Akun COA</th>
                                        <th class="px-3 py-2.5 w-1/4">Keterangan Baris</th>
                                        <th class="px-3 py-2.5 text-right w-1/6">Debit (Rp)</th>
                                        <th class="px-3 py-2.5 text-right w-1/6">Credit (Rp)</th>
                                        <th class="px-2 py-2.5 text-center w-10"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                                    @foreach ($lines as $index => $line)
                                        <tr>
                                            <td class="p-2">
                                                @php
                                                    $accOptions = collect($accounts)->map(fn($acc) => [
                                                        'value' => (string) $acc->id,
                                                        'label' => "[{$acc->account_code}] {$acc->account_name} ({$acc->normal_balance->value})"
                                                    ])->all();
                                                @endphp
                                                <x-erp.searchable-select
                                                    wire:model.live="lines.{{ $index }}.account_id"
                                                    :options="$accOptions"
                                                    placeholder="-- Pilih Akun --"
                                                />
                                                @error("lines.{$index}.account_id") <span class="text-[10px] text-destructive block mt-1">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2">
                                                <input type="text"
                                                       wire:model="lines.{{ $index }}.description"
                                                       placeholder="Keterangan baris (opsional)"
                                                       class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100">
                                            </td>
                                            <td class="p-2">
                                                <input type="number"
                                                       step="0.01"
                                                       min="0"
                                                       wire:model.live.debounce.300ms="lines.{{ $index }}.debit_amount"
                                                       class="w-full px-2.5 py-1.5 text-xs text-right font-mono rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-semibold">
                                                @error("lines.{$index}.debit_amount") <span class="text-[10px] text-rose-500 block text-right">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2">
                                                <input type="number"
                                                       step="0.01"
                                                       min="0"
                                                       wire:model.live.debounce.300ms="lines.{{ $index }}.credit_amount"
                                                       class="w-full px-2.5 py-1.5 text-xs text-right font-mono rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-semibold">
                                                @error("lines.{$index}.credit_amount") <span class="text-[10px] text-rose-500 block text-right">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="p-2 text-center">
                                                @if (count($lines) > 2)
                                                    <button type="button"
                                                            wire:click="removeLine({{ $index }})"
                                                            class="p-1 rounded text-zinc-400 hover:text-rose-500 transition-colors">
                                                        <span class="material-symbols-rounded text-sm">delete</span>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Real-Time Balancing Summary Banner -->
                    @php $balance = $this->balanceSummary; @endphp
                    <div class="p-4 rounded-xl border {{ $balance['isBalanced'] ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-rose-500/5 border-rose-500/20' }} flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-lg {{ $balance['isBalanced'] ? 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-400' : 'bg-rose-500/20 text-rose-600 dark:text-rose-400' }} flex items-center justify-center">
                                <span class="material-symbols-rounded text-lg">{{ $balance['isBalanced'] ? 'check_circle' : 'warning' }}</span>
                            </div>
                            <div>
                                <span class="text-xs font-bold {{ $balance['isBalanced'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                    {{ $balance['isBalanced'] ? 'Jurnal Seimbang (Ready to Post)' : 'Jurnal Tidak Seimbang' }}
                                </span>
                                <span class="text-[11px] text-zinc-500 block font-mono">
                                    Selisih: Rp {{ number_format((float) abs((float) $balance['difference']), 2) }}
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-6 font-mono text-xs">
                            <div class="text-right">
                                <span class="text-[10px] text-zinc-400 block uppercase">Total Debit</span>
                                <span class="font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $balance['totalDebit'], 2) }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-[10px] text-zinc-400 block uppercase">Total Credit</span>
                                <span class="font-bold text-zinc-950 dark:text-white">Rp {{ number_format((float) $balance['totalCredit'], 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Form Footer Buttons -->
                    <div class="flex items-center justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button type="button"
                                wire:click="$set('showWorkbenchModal', false)"
                                class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                            Batal
                        </button>
                        <button type="submit"
                                wire:loading.attr="disabled"
                                wire:target="postManualJournal"
                                @if(! $balance['isBalanced']) disabled @endif
                                class="inline-flex items-center gap-2 px-5 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs disabled:opacity-40 disabled:cursor-not-allowed">
                            <span class="material-symbols-rounded text-sm">send</span>
                            <span>Posting ke General Ledger</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Journal Detail Modal -->
    @if ($showDetailModal && $selectedJournal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-3xl rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
                 @click.outside="$wire.set('showDetailModal', false)">
                
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div>
                        <h3 class="text-base font-bold text-zinc-950 dark:text-white font-mono">
                            {{ $selectedJournal->journal_number }}
                        </h3>
                        <p class="text-xs text-zinc-500">Posting Date: {{ $selectedJournal->posting_date->format('d M Y') }} • Type: {{ $selectedJournal->journal_type->value }}</p>
                    </div>
                    <button type="button" wire:click="$set('showDetailModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                </div>

                <div class="space-y-3">
                    <div class="p-3 bg-zinc-50 dark:bg-zinc-900 rounded-xl text-xs space-y-1">
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Keterangan:</span>
                            <span class="text-zinc-900 dark:text-zinc-100 font-medium">{{ $selectedJournal->notes ?: '-' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500">Diposting Oleh:</span>
                            <span class="text-zinc-900 dark:text-zinc-100 font-medium">{{ $selectedJournal->createdByUser?->name ?? 'System Auto' }}</span>
                        </div>
                        @if($selectedJournal->reversed_by_journal_id)
                            <div class="flex justify-between text-rose-500">
                                <span>Status Pembalikan:</span>
                                <span>Reversed by #{{ $selectedJournal->reversed_by_journal_id }} ({{ $selectedJournal->reversal_reason }})</span>
                            </div>
                        @endif
                    </div>

                    <div class="border border-zinc-200 dark:border-zinc-800 rounded-xl overflow-hidden">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-3 py-2">Kode Akun</th>
                                    <th class="px-3 py-2">Nama Akun</th>
                                    <th class="px-3 py-2">Keterangan Baris</th>
                                    <th class="px-3 py-2 text-right">Debit</th>
                                    <th class="px-3 py-2 text-right">Credit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($selectedJournal->lines as $line)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-zinc-900 dark:text-zinc-100 font-medium">{{ $line->account?->account_code ?? $line->account_id }}</td>
                                        <td class="px-3 py-2 text-zinc-800 dark:text-zinc-200">{{ $line->account?->account_name ?? '-' }}</td>
                                        <td class="px-3 py-2 text-zinc-500">{{ $line->description ?: '-' }}</td>
                                        <td class="px-3 py-2 text-right font-mono font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ (float) $line->debit_amount > 0 ? 'Rp ' . number_format((float) $line->debit_amount, 2) : '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ (float) $line->credit_amount > 0 ? 'Rp ' . number_format((float) $line->credit_amount, 2) : '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-zinc-50 dark:bg-zinc-900 font-mono font-bold text-xs border-t border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <td colspan="3" class="px-3 py-2 text-right">Total</td>
                                    <td class="px-3 py-2 text-right">Rp {{ number_format((float) $selectedJournal->total_debit, 2) }}</td>
                                    <td class="px-3 py-2 text-right">Rp {{ number_format((float) $selectedJournal->total_credit, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="flex justify-end pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button"
                            wire:click="$set('showDetailModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Reversal Confirmation Modal -->
    @if ($showReversalModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4"
                 @click.outside="$wire.set('showReversalModal', false)">
                
                <div class="flex items-center gap-3 text-rose-600 dark:text-rose-400">
                    <span class="material-symbols-rounded text-2xl">undo</span>
                    <h3 class="text-base font-bold text-zinc-950 dark:text-white">Konfirmasi Pembalikan Jurnal</h3>
                </div>

                <p class="text-xs text-zinc-600 dark:text-zinc-400">
                    Tindakan ini akan membuat jurnal pembalik simetris (debit menjadi credit dan sebaliknya) secara otomatis di General Ledger dan mengunci status jurnal ini menjadi REVERSED.
                </p>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Alasan Pembalikan (Wajib)</label>
                    <textarea wire:model="reversalReason"
                              rows="3"
                              placeholder="Masukkan alasan pembatalan atau revisi jurnal..."
                              class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none"></textarea>
                    @error('reversalReason') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button"
                            wire:click="$set('showReversalModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                        Batal
                    </button>
                    <button type="button"
                            wire:click="confirmReversal"
                            class="px-4 py-2 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-xl transition-colors shadow-xs">
                        Lakukan Pembalikan
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
