{{-- ========================================================================= --}}
{{-- MODAL PENGATURAN TARGET CABANG (Plan vs Actual - Owner / Admin Only)       --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="targetModalOpen" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="targetModalOpen"
         x-transition:enter="ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="targetModalOpen = false"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"></div>

    <div class="flex min-h-full items-center justify-center p-4 text-center sm:p-0">
        <div x-show="targetModalOpen"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="relative transform overflow-hidden rounded-2xl bg-card border border-border text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg p-6 sm:p-7 space-y-6">

            {{-- Modal Header --}}
            <div class="flex items-center justify-between border-b border-border pb-4">
                <div class="flex items-center gap-2.5">
                    <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <span class="material-symbols-rounded text-2xl">track_changes</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground" id="modal-title">
                            Atur Target Cabang (Plan vs Actual)
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            Konfigurasi target pendapatan bulanan, margin kotor, dan toleransi susut stok.
                        </p>
                    </div>
                </div>
                <button type="button" @click="targetModalOpen = false" class="rounded-lg p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Target Form --}}
            <form action="{{ route('dashboard.store-targets.save') }}" method="POST" id="storeTargetForm" class="space-y-4"
                  @submit.prevent="submitStoreTarget()">
                @csrf

                {{-- Alert Feedback --}}
                <div x-cloak x-show="targetError" class="p-3 rounded-xl bg-destructive-muted border border-destructive-border text-destructive text-xs font-semibold flex items-center gap-2">
                    <span class="material-symbols-rounded text-base">error</span>
                    <span x-text="targetError"></span>
                </div>
                <div x-cloak x-show="targetSuccess" class="p-3 rounded-xl bg-success-muted border border-success-border text-success text-xs font-semibold flex items-center gap-2">
                    <span class="material-symbols-rounded text-base">check_circle</span>
                    <span>Target cabang berhasil diperbarui! Memperbarui kalkulasi dasbor...</span>
                </div>

                {{-- Store Selection & Period --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="target_store_id" class="block text-xs font-semibold text-foreground mb-1.5">
                            Cabang / Outlet <span class="text-destructive">*</span>
                        </label>
                        <select id="target_store_id" name="store_id" x-model="targetForm.store_id" required
                                class="w-full rounded-xl border border-border bg-background px-3.5 py-2 text-xs font-medium text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary">
                            @foreach($availableStores ?? [] as $st)
                                <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->code ?: 'BR-'.$st->id }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="target_period" class="block text-xs font-semibold text-foreground mb-1.5">
                            Periode Bulan <span class="text-destructive">*</span>
                        </label>
                        <input type="month" id="target_period" name="target_period" x-model="targetForm.target_period" required
                               class="w-full rounded-xl border border-border bg-background px-3.5 py-2 text-xs font-medium text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary">
                    </div>
                </div>

                {{-- Target Net Sales --}}
                <div>
                    <label for="target_net_sales" class="block text-xs font-semibold text-foreground mb-1.5">
                        Target Net Sales Bulanan (Rp) <span class="text-destructive">*</span>
                    </label>
                    <div class="relative">
                        <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground">Rp</span>
                        <input type="number" step="1000" min="0" id="target_net_sales" name="target_net_sales" x-model="targetForm.target_net_sales" required placeholder="misal: 150000000"
                               class="w-full rounded-xl border border-border bg-background pl-10 pr-3.5 py-2 text-sm font-semibold tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary">
                    </div>
                    <p class="text-[11px] text-muted-foreground mt-1">Target omzet bersih (setelah diskon &amp; retur) untuk kalkulasi % ketercapaian dan proyeksi Run-Rate.</p>
                </div>

                {{-- Margin & Shrinkage Grid --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="target_gross_margin_pct" class="block text-xs font-semibold text-foreground mb-1.5">
                            Target Gross Margin (%)
                        </label>
                        <div class="relative">
                            <input type="number" step="0.1" min="0" max="100" id="target_gross_margin_pct" name="target_gross_margin_pct" x-model="targetForm.target_gross_margin_pct" placeholder="45.0"
                                   class="w-full rounded-xl border border-border bg-background px-3.5 py-2 text-xs font-semibold tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary">
                            <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground">%</span>
                        </div>
                    </div>

                    <div>
                        <label for="max_shrinkage_pct" class="block text-xs font-semibold text-foreground mb-1.5">
                            Batas Max Shrinkage (%)
                        </label>
                        <div class="relative">
                            <input type="number" step="0.1" min="0" max="100" id="max_shrinkage_pct" name="max_shrinkage_pct" x-model="targetForm.max_shrinkage_pct" placeholder="1.5"
                                   class="w-full rounded-xl border border-border bg-background px-3.5 py-2 text-xs font-semibold tabular-nums text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary">
                            <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground">%</span>
                        </div>
                    </div>
                </div>

                {{-- Modal Footer --}}
                <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                    <button type="button" @click="targetModalOpen = false" class="px-4 py-2.5 rounded-xl text-xs font-semibold text-foreground bg-muted hover:bg-muted/80 transition-colors cursor-pointer">
                        Batal
                    </button>
                    <button type="submit" :disabled="isSavingTarget" class="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-xl text-xs font-bold text-primary-foreground bg-primary hover:opacity-90 disabled:opacity-50 transition-opacity cursor-pointer">
                        <span class="material-symbols-rounded text-base" x-show="!isSavingTarget">save</span>
                        <span x-text="isSavingTarget ? 'Menyimpan...' : 'Simpan Target'"></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

