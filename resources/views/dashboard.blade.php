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
