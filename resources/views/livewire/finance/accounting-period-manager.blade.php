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

    @if (session()->has('error'))
        <div class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-600 dark:text-rose-400 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-lg">error</span>
                <span>{{ session('error') }}</span>
            </div>
            <button type="button" @click="$el.parentElement.remove()" class="text-rose-500 hover:text-rose-700">
                <span class="material-symbols-rounded text-sm">close</span>
            </button>
        </div>
    @endif

    <!-- Header Action Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-950 dark:text-white tracking-tight">Periode Akuntansi & Penutupan Buku</h1>
            <p class="text-xs text-zinc-500">Kelola siklus penutupan buku bulanan (soft-close / hard-close) dan penguncian permanen tahun buku.</p>
        </div>

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-2 text-xs">
                <span class="text-zinc-500 font-medium">Tahun Fiskal:</span>
                <select wire:model.live="selectedFiscalYear"
                        class="px-3 py-1.5 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 font-bold focus:outline-none">
                    @foreach ($availableYears as $year)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforeach
                </select>
            </div>

            @if ($periods->isEmpty())
                <button type="button"
                        wire:click="generateFiscalYearPeriods"
                        class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                    <span class="material-symbols-rounded text-base">calendar_month</span>
                    <span>Generate 12 Periode ({{ $selectedFiscalYear }})</span>
                </button>
            @endif
        </div>
    </div>

    <!-- Periods Table -->
    <div class="card-surface rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-zinc-50 dark:bg-zinc-900/60 text-zinc-500 font-semibold border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-4 py-3">Kode Periode</th>
                        <th class="px-4 py-3">Bulan / Nomor</th>
                        <th class="px-4 py-3">Rentang Tanggal</th>
                        <th class="px-4 py-3 text-center">Status Periode</th>
                        <th class="px-4 py-3">Hak Posting Jurnal</th>
                        <th class="px-4 py-3 text-right">Aksi Manajemen Siklus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @forelse ($periods as $period)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="px-4 py-3 font-mono font-bold text-zinc-950 dark:text-white">
                                {{ $period->period_code }}
                            </td>
                            <td class="px-4 py-3 font-medium text-zinc-800 dark:text-zinc-200">
                                Periode #{{ $period->period_number }} ({{ \Carbon\Carbon::parse($period->start_date)->format('F') }})
                            </td>
                            <td class="px-4 py-3 font-mono text-zinc-600 dark:text-zinc-400">
                                {{ \Carbon\Carbon::parse($period->start_date)->format('d M Y') }} — {{ \Carbon\Carbon::parse($period->end_date)->format('d M Y') }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="px-2.5 py-1 rounded-full text-[10px] font-bold tracking-wide
                                    @if($period->status->value === 'OPEN') bg-emerald-500/10 text-emerald-600 dark:text-emerald-400
                                    @elseif($period->status->value === 'SOFT_CLOSED') bg-amber-500/10 text-amber-600 dark:text-amber-400
                                    @elseif($period->status->value === 'CLOSED') bg-blue-500/10 text-blue-600 dark:text-blue-400
                                    @else bg-zinc-500/10 text-zinc-500 dark:text-zinc-400 @endif">
                                    {{ $period->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($period->status->isPostingAllowed())
                                    <span class="inline-flex items-center gap-1 text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">
                                        <span class="material-symbols-rounded text-sm">lock_open</span> Diizinkan
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-[11px] text-rose-500 font-medium">
                                        <span class="material-symbols-rounded text-sm">lock</span> Terkunci (Posting Ditolak)
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <button type="button"
                                            wire:click="evaluateGates({{ $period->id }})"
                                            class="px-2 py-1 rounded-lg bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium transition-colors"
                                            title="Evaluasi Closing Gates">
                                        Gates
                                    </button>

                                    @if ($period->status->value === 'OPEN')
                                        <button type="button"
                                                wire:click="softClose({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 text-amber-600 dark:text-amber-400 font-medium transition-colors">
                                            Soft Close
                                        </button>
                                        <button type="button"
                                                wire:click="hardClose({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 dark:text-blue-400 font-medium transition-colors">
                                            Close
                                        </button>
                                    @elseif ($period->status->value === 'SOFT_CLOSED')
                                        <button type="button"
                                                wire:click="hardClose({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-blue-500/10 hover:bg-blue-500/20 text-blue-600 dark:text-blue-400 font-medium transition-colors">
                                            Close
                                        </button>
                                        <button type="button"
                                                wire:click="openReopenModal({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-medium transition-colors">
                                            Reopen
                                        </button>
                                    @elseif ($period->status->value === 'CLOSED')
                                        <button type="button"
                                                wire:click="lock({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-zinc-900 hover:bg-black text-white dark:bg-zinc-700 dark:hover:bg-zinc-600 font-medium transition-colors">
                                            Lock Permanen
                                        </button>
                                        <button type="button"
                                                wire:click="openReopenModal({{ $period->id }})"
                                                class="px-2 py-1 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-600 dark:text-emerald-400 font-medium transition-colors">
                                            Reopen
                                        </button>
                                    @else
                                        <span class="text-[11px] text-zinc-400 italic">Locked</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-3xl mb-1 text-zinc-300">event_busy</span>
                                <p class="text-xs">Belum ada periode akuntansi untuk tahun fiskal {{ $selectedFiscalYear }}.</p>
                                <button type="button"
                                        wire:click="generateFiscalYearPeriods"
                                        class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold text-white bg-zinc-950 hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:hover:bg-zinc-200 rounded-xl transition-colors shadow-xs">
                                    <span class="material-symbols-rounded text-base">add</span>
                                    <span>Buat 12 Periode Sekarang</span>
                                </button>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reopen Modal -->
    @if ($showReopenModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-md rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4"
                 @click.outside="$wire.set('showReopenModal', false)">
                
                <div class="flex items-center gap-3 text-emerald-600 dark:text-emerald-400">
                    <span class="material-symbols-rounded text-2xl">lock_open</span>
                    <h3 class="text-base font-bold text-zinc-950 dark:text-white">Buka Kembali Periode (Reopen)</h3>
                </div>

                <p class="text-xs text-zinc-600 dark:text-zinc-400">
                    Pembukaan kembali periode akuntansi yang telah ditutup membutuhkan otorisasi dan pencatatan audit log resmi. Masukkan alasan pembukaan kembali:
                </p>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Alasan Reopen (Wajib)</label>
                    <textarea wire:model="reopenReason"
                              rows="3"
                              placeholder="Contoh: Koreksi penyesuaian audit eksternal..."
                              class="w-full px-3 py-2 text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none"></textarea>
                    @error('reopenReason') <span class="text-[10px] text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button"
                            wire:click="$set('showReopenModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                        Batal
                    </button>
                    <button type="button"
                            wire:click="confirmReopen"
                            class="px-4 py-2 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-xl transition-colors shadow-xs">
                        Konfirmasi Buka Periode
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Gates Evaluation Modal -->
    @if ($showGatesModal && $gatesPeriod)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="card-surface w-full max-w-lg rounded-2xl border border-zinc-200 dark:border-zinc-800 p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto"
                 @click.outside="$wire.set('showGatesModal', false)">
                
                <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div>
                        <h3 class="text-base font-bold text-zinc-950 dark:text-white">
                            Closing Gates Checklist — Periode {{ $gatesPeriod->period_code }}
                        </h3>
                        <p class="text-xs text-zinc-500">Evaluasi pra-syarat penutupan buku General Ledger.</p>
                    </div>
                    <button type="button" wire:click="$set('showGatesModal', false)" class="text-zinc-400 hover:text-zinc-600">
                        <span class="material-symbols-rounded text-lg">close</span>
                    </button>
                </div>

                <div class="space-y-2">
                    @forelse ($gatesResults as $gate => $result)
                        <div class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 flex items-center justify-between text-xs">
                            <span class="font-medium text-zinc-800 dark:text-zinc-200">{{ ucwords(str_replace('_', ' ', (string) $gate)) }}</span>
                            @if (is_array($result) ? ($result['passed'] ?? false) : (bool) $result)
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-xs">check</span> PASSED
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-600 dark:text-amber-400 inline-flex items-center gap-1">
                                    <span class="material-symbols-rounded text-xs">warning</span> PENDING
                                </span>
                            @endif
                        </div>
                    @empty
                        <div class="p-4 text-center text-zinc-400 text-xs">
                            Semua gerbang penutupan (closing gates) terpenuhi.
                        </div>
                    @endforelse
                </div>

                <div class="flex justify-end pt-3 border-t border-zinc-200 dark:border-zinc-800">
                    <button type="button"
                            wire:click="$set('showGatesModal', false)"
                            class="px-4 py-2 text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white rounded-xl transition-colors">
                        Tutup
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
