{{-- ========================================================================= --}}
{{-- IN-PLACE SLIDE-OVER ACTION DRAWER (Supervisor Overrides & Drawer Audits)   --}}
{{-- ========================================================================= --}}
<div x-cloak x-show="drawerOpen" class="fixed inset-0 z-50 overflow-hidden" aria-labelledby="slide-over-title" role="dialog" aria-modal="true">
    {{-- Backdrop --}}
    <div x-show="drawerOpen"
         x-transition:enter="ease-in-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in-out duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="closeApprovalDrawer()"
         class="fixed inset-0 bg-background/80 backdrop-blur-xs transition-opacity"></div>

    <div class="fixed inset-y-0 right-0 max-w-full flex pl-10">
        <div x-show="drawerOpen"
             x-transition:enter="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transform transition ease-in-out duration-300 sm:duration-400"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full"
             class="w-screen max-w-md bg-card border-l border-border shadow-2xl flex flex-col justify-between">

            {{-- Drawer Header --}}
            <div class="p-6 border-b border-border">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-rounded text-primary text-2xl">verified</span>
                        <h2 class="text-base font-bold text-foreground" id="slide-over-title">
                            Operational Authorization
                        </h2>
                    </div>
                    <button type="button"
                            @click="closeApprovalDrawer()"
                            class="rounded-lg p-1.5 text-muted-foreground hover:text-foreground hover:bg-muted transition-colors cursor-pointer">
                        <span class="material-symbols-rounded text-xl">close</span>
                    </button>
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    Verify cashier request against physical ticket and authorize with your supervisor credentials.
                </p>
            </div>

            {{-- Drawer Body --}}
            <div class="p-6 flex-1 overflow-y-auto space-y-6">
                <template x-if="selectedTicket">
                    <div class="space-y-4">
                        {{-- Ticket Overview Card --}}
                        <div class="p-4 rounded-xl bg-muted/40 border border-border/80 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-primary/10 text-primary border border-primary/20"
                                      x-text="selectedTicket.type"></span>
                                <span class="text-xs font-mono font-bold text-foreground" x-text="'#' + selectedTicket.ticket_no"></span>
                            </div>
                            <div class="text-2xl font-black text-foreground tabular-nums"
                                 x-text="'Rp ' + new Intl.NumberFormat('id-ID').format(selectedTicket.amount)"></div>
                            <div class="text-xs text-muted-foreground">
                                Cashier: <strong class="text-foreground" x-text="selectedTicket.cashier_name"></strong>
                            </div>
                            <div class="text-xs bg-card p-3 rounded-lg border border-border">
                                <span class="text-muted-foreground block text-[11px] mb-0.5">Submitted Reason:</span>
                                <span class="italic text-foreground font-medium" x-text="'“' + selectedTicket.reason + '”'"></span>
                            </div>
                        </div>

                        {{-- Supervisor Credentials Form --}}
                        <div class="space-y-3">
                            <label for="supervisor-pin-input" class="block text-xs font-bold text-foreground">
                                Supervisor Security PIN (4-6 Digits)
                            </label>
                            <input type="password"
                                   id="supervisor-pin-input"
                                   maxlength="6"
                                   x-model="supervisorPin"
                                   placeholder="••••••"
                                   class="w-full text-center tracking-widest text-lg font-mono px-4 py-2.5 rounded-xl border border-border bg-background text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-transparent">

                            <template x-if="actionStatus === 'pin_required'">
                                <div class="text-xs text-destructive flex items-center gap-1 font-semibold">
                                    <span class="material-symbols-rounded text-sm">error</span> Please enter a valid supervisor PIN (min 4 digits).
                                </div>
                            </template>
                            <template x-if="actionStatus === 'error'">
                                <div class="text-xs text-destructive flex items-center gap-1 font-semibold">
                                    <span class="material-symbols-rounded text-sm">error</span> <span x-text="errorMessage"></span>
                                </div>
                            </template>

                            <div class="space-y-1.5 pt-2 border-t border-border/60">
                                <label for="supervisor-note-input" class="block text-xs font-semibold text-foreground">
                                    Catatan / Alasan Otorisasi Supervisor (Opsional)
                                </label>
                                <input type="text"
                                       id="supervisor-note-input"
                                       x-model="supervisorNote"
                                       placeholder="misal: Disetujui kasir benar, atau Alasan penolakan..."
                                       class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-border bg-background text-foreground focus:outline-hidden focus:ring-2 focus:ring-primary focus:border-transparent">
                            </div>
                        </div>

                        {{-- Feedback status --}}
                        <template x-if="actionStatus === 'approved'">
                            <div class="p-3 rounded-xl bg-success-muted border border-success-border text-success text-xs font-bold flex items-center gap-2">
                                <span class="material-symbols-rounded text-lg">check_circle</span>
                                <span>Request Approved! Audit log posted to General Ledger.</span>
                            </div>
                        </template>
                        <template x-if="actionStatus === 'rejected'">
                            <div class="p-3 rounded-xl bg-destructive-muted border border-destructive-border text-destructive text-xs font-bold flex items-center gap-2">
                                <span class="material-symbols-rounded text-lg">cancel</span>
                                <span>Request Rejected. Cashier notified.</span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Drawer Footer --}}
            <div class="p-6 border-t border-border bg-muted/20 flex items-center justify-end gap-3">
                <button type="button"
                        @click="closeApprovalDrawer()"
                        class="px-4 py-2.5 rounded-xl text-xs font-semibold text-foreground bg-card border border-border hover:bg-muted cursor-pointer transition-colors">
                    Cancel
                </button>
                <button type="button"
                        @click="submitApproval('reject')"
                        :disabled="actionStatus === 'approved' || actionStatus === 'rejected' || isSubmittingApproval"
                        class="px-4 py-2.5 rounded-xl text-xs font-semibold text-destructive-foreground bg-destructive hover:opacity-90 disabled:opacity-50 cursor-pointer transition-opacity">
                    Reject
                </button>
                <button type="button"
                        @click="submitApproval('approve')"
                        :disabled="actionStatus === 'approved' || actionStatus === 'rejected' || isSubmittingApproval"
                        class="px-5 py-2.5 rounded-xl text-xs font-bold text-primary-foreground bg-primary hover:opacity-90 disabled:opacity-50 cursor-pointer transition-opacity flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-base" x-show="!isSubmittingApproval">verified_user</span>
                    <span x-text="isSubmittingApproval ? 'Verifying...' : 'Authorize & Sign'"></span>
                </button>
            </div>

        </div>
    </div>
</div>

