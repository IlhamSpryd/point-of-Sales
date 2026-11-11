<x-app-layout :noPadding="true">
    <div class="w-full min-h-full flex flex-col flex-1 p-4 sm:p-6 lg:p-8 gap-6 bg-transparent text-foreground"
         x-data="{
             drawerOpen: false,
             selectedTicket: null,
             supervisorPin: '',
             supervisorNote: '',
             actionStatus: null,
             errorMessage: '',
             isSubmittingApproval: false,

             targetModalOpen: false,
             isSavingTarget: false,
             targetSuccess: false,
             targetError: '',
             targetForm: {
                 store_id: '{{ $availableStores->first()->id ?? 1 }}',
                 target_period: '{{ now()->setTimezone('Asia/Jakarta')->format('Y-m') }}',
                 target_net_sales: '{{ (int) ($executiveData['planVsActual']['target_revenue'] ?? 0) }}',
                 target_gross_margin_pct: '45.0',
                 max_shrinkage_pct: '1.5',
                 max_labor_cost_pct: '20.0'
             },

             openApprovalDrawer(ticket) {
                 this.selectedTicket = ticket;
                 this.supervisorPin = '';
                 this.supervisorNote = '';
                 this.actionStatus = null;
                 this.errorMessage = '';
                 this.isSubmittingApproval = false;
                 this.drawerOpen = true;
             },
             closeApprovalDrawer() {
                 this.drawerOpen = false;
                 this.selectedTicket = null;
                 this.supervisorNote = '';
                 this.actionStatus = null;
                 this.errorMessage = '';
                 this.isSubmittingApproval = false;
             },
             async submitApproval(decision) {
                 if (!this.supervisorPin || this.supervisorPin.length < 4) {
                     this.actionStatus = 'pin_required';
                     return;
                 }
                 this.isSubmittingApproval = true;
                 this.errorMessage = '';
                 try {
                     const response = await fetch('{{ route('dashboard.approve-ticket') }}', {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '{{ csrf_token() }}',
                             'Accept': 'application/json',
                         },
                         body: JSON.stringify({
                             ticket_id: this.selectedTicket.id,
                             ticket_type: this.selectedTicket.type,
                             decision: decision,
                             supervisor_pin: this.supervisorPin,
                             reason: this.supervisorNote ? this.supervisorNote : (this.selectedTicket.reason || ''),
                         })
                     });
                     const data = await response.json();
                     if (response.ok && data.success) {
                         this.actionStatus = decision === 'approve' ? 'approved' : 'rejected';
                         setTimeout(() => {
                             this.closeApprovalDrawer();
                             window.location.reload();
                         }, 1300);
                     } else {
                         this.actionStatus = 'error';
                         this.errorMessage = data.message || 'Otorisasi gagal diproses.';
                     }
                 } catch (err) {
                     this.actionStatus = 'error';
                     this.errorMessage = err.message || 'Terjadi kesalahan jaringan.';
                 } finally {
                     this.isSubmittingApproval = false;
                 }
             },

             openStoreTargetModal() {
                 this.targetSuccess = false;
                 this.targetError = '';
                 this.targetModalOpen = true;
             },
             async submitStoreTarget() {
                 this.isSavingTarget = true;
                 this.targetError = '';
                 this.targetSuccess = false;
                 try {
                     const response = await fetch('{{ route('dashboard.store-targets.save') }}', {
                         method: 'POST',
                         headers: {
                             'Content-Type': 'application/json',
                             'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '{{ csrf_token() }}',
                             'Accept': 'application/json',
                         },
                         body: JSON.stringify(this.targetForm)
                     });
                     const data = await response.json();
                     if (response.ok && data.success) {
                         this.targetSuccess = true;
                         setTimeout(() => {
                             this.targetModalOpen = false;
                             window.location.reload();
                         }, 1200);
                     } else {
                         this.targetError = data.message || 'Gagal menyimpan target cabang.';
                     }
                 } catch (err) {
                     this.targetError = err.message || 'Terjadi kesalahan jaringan.';
                 } finally {
                     this.isSavingTarget = false;
                 }
             }
         }"
         @keydown.escape.window="closeApprovalDrawer(); targetModalOpen = false">

        {{-- FLASH SESSION NOTIFICATIONS --}}
        @if (session('success'))
            <x-ui.alert type="success" dismissible>{{ session('success') }}</x-ui.alert>
        @endif
        @if (session('error'))
            <x-ui.alert type="error" dismissible>{{ session('error') }}</x-ui.alert>
        @endif
        @if (session('warning'))
            <x-ui.alert type="warning" dismissible>{{ session('warning') }}</x-ui.alert>
        @endif

        {{-- 1. TOP HEADER & ACTIONS --}}
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 pb-2 border-b border-border/60">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
                        Dashboard
                    </h1>
                    @if($activeArchetype === 'executive')
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-primary text-primary-foreground">
                            Executive BI
                        </span>
                    @elseif($activeArchetype === 'branch_ops')
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-secondary text-secondary-foreground">
                            Branch Ops
                        </span>
                    @elseif($activeArchetype === 'cashier_workspace')
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-accent text-accent-foreground">
                            Cashier Workspace
                        </span>
                    @endif
                </div>
                <p class="text-xs text-muted-foreground mt-0.5">
                    Welcome back, {{ auth()->user()?->name ?? 'User' }} · {{ auth()->user()?->tenant?->name ?? 'Apeiron Enterprise' }} · Branch: {{ $activeStoreName ?? 'All Stores' }}
                </p>
            </div>

            <div class="flex items-center gap-3 sm:gap-4 flex-wrap">
                {{-- Dynamic RBAC Archetype Switcher for Admins/Multi-Branch Users --}}
                @if($canSwitchArchetype)
                    <div class="inline-flex items-center bg-muted/70 p-1 rounded-xl border border-border text-xs font-semibold">
                        <a href="{{ route('dashboard', ['view' => 'executive']) }}" wire:navigate
                           class="px-3 py-1.5 rounded-lg transition-all {{ $activeArchetype === 'executive' ? 'bg-card text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}">
                            Executive
                        </a>
                        <a href="{{ route('dashboard', ['view' => 'branch_ops']) }}" wire:navigate
                           class="px-3 py-1.5 rounded-lg transition-all {{ $activeArchetype === 'branch_ops' ? 'bg-card text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}">
                            Branch Ops
                        </a>
                        <a href="{{ route('dashboard', ['view' => 'cashier_workspace']) }}" wire:navigate
                           class="px-3 py-1.5 rounded-lg transition-all {{ $activeArchetype === 'cashier_workspace' ? 'bg-card text-foreground shadow-xs font-bold' : 'text-muted-foreground hover:text-foreground' }}">
                            Cashier
                        </a>
                    </div>
                @endif

                {{-- Fast Navigation Links --}}
                <a href="{{ route('orders.index') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-foreground bg-card border border-border hover:bg-muted shadow-xs active:scale-95 transition-all">
                    <span class="material-symbols-rounded text-[16px]">receipt_long</span>
                    <span>All Orders</span>
                </a>
                <a href="{{ route('transaction.create') }}" wire:navigate
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-semibold text-primary-foreground bg-primary hover:opacity-90 shadow-xs active:scale-95 transition-all">
                    <span class="material-symbols-rounded text-[16px]">point_of_sale</span>
                    <span>POS Terminal</span>
                </a>
            </div>
        </div>

        {{-- ========================================================================= --}}
        {{-- MODULAR WORKSPACE ARCHETYPES                                              --}}
        {{-- ========================================================================= --}}
        @include('dashboard.partials.cashier-workspace')
        @include('dashboard.partials.manager-workspace')
        @include('dashboard.partials.executive-workspace')

        {{-- ========================================================================= --}}
        {{-- IN-PLACE SLIDE-OVER ACTION DRAWER & MODALS                                --}}
        {{-- ========================================================================= --}}
        @include('dashboard.partials.approval-drawer')
        @include('dashboard.partials.store-target-modal')

    </div>
</x-app-layout>
