{{-- ========================================================================= --}}
{{-- SLIDE-OVER INSPECTOR: WATERFALL STEP DRILLDOWN DRAWER                     --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="waterfallDrawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="waterfall-drawer-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="waterfallDrawerOpen"
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeWaterfallDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-8">
        <div x-show="waterfallDrawerOpen"
             x-transition:enter="transform transition ease-out duration-200"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in duration-150"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md lg:max-w-lg bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Header --}}
            <div class="p-4 sm:p-5 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-foreground text-2xl">waterfall_chart</span>
                    <div>
                        <h2 class="text-sm sm:text-base font-bold text-foreground" id="waterfall-drawer-title" x-text="waterfallDetailData?.step || 'Tahap Waterfall Bridge'">
                            Tahap Waterfall Bridge
                        </h2>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            Rincian kontribusi pendapatan dan potongan HPP
                        </p>
                    </div>
                </div>
                <button type="button"
                        @click="closeWaterfallDrawer()"
                        aria-label="Tutup panel waterfall"
                        class="rounded-md p-1 text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="p-4 sm:p-5 flex-1 overflow-y-auto space-y-4">
                <template x-if="isLoadingWaterfall">
                    <div class="py-16 text-center space-y-2">
                        <div class="inline-block w-6 h-6 border-2 border-foreground border-t-transparent rounded-full animate-spin"></div>
                        <p class="text-xs text-muted-foreground">Mengambil data rincian waterfall...</p>
                    </div>
                </template>

                <template x-if="!isLoadingWaterfall && waterfallDetailData">
                    <div class="space-y-4">
                        {{-- Step Highlight Card --}}
                        <div class="p-4 rounded-[8px] bg-secondary border border-border space-y-2">
                            <div class="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider" x-text="waterfallDetailData.step"></div>
                            <div class="text-2xl font-bold tabular-nums text-foreground"
                                 x-text="waterfallDetailData.step_data.amount_formatted || ('Rp ' + Math.round(waterfallDetailData.step_data.amount || 0).toLocaleString('id-ID'))"></div>
                            <p class="text-xs text-muted-foreground leading-relaxed" x-text="waterfallDetailData.explanation"></p>
                        </div>

                        {{-- Accounting Standards Note --}}
                        <div class="p-3 rounded-[8px] bg-secondary/50 border border-border text-xs space-y-1.5">
                            <div class="font-semibold text-foreground flex items-center gap-1.5">
                                <span class="material-symbols-rounded text-sm text-foreground">verified</span>
                                <span>Standar PSAK / Accrual Accounting</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground leading-relaxed">
                                Setiap pergerakan pendapatan kotor menuju laba kotor diakui secara akrual saat pesanan kasir berstatus <strong class="text-foreground">paid/completed</strong>. Nilai HPP didasarkan pada pergerakan stok keluar bahan resep (BOM) terdaftar.
                            </p>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer --}}
            <div class="p-3 sm:p-4 border-t border-border flex items-center justify-between bg-secondary/20">
                <span class="text-[10.5px] text-muted-foreground">PSAK Revenue Recognition Model</span>
                <button type="button"
                        @click="closeWaterfallDrawer()"
                        class="h-7 px-3 rounded-md bg-foreground text-background text-xs font-medium cursor-pointer hover:opacity-90 transition-opacity">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

