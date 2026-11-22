<div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
    {{-- Header Section --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-foreground tracking-tight">
                Kotak Masuk Persetujuan (Unified Approval Inbox)
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                Pusat tata kelola persetujuan berjenjang dengan penegakan Pemisahan Tugas (Maker-Checker SoD) dan jejak audit mutlak.
            </p>
        </div>
    </div>

    {{-- Alert Messages --}}
    @if (session()->has('message'))
        <div class="p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-xl flex items-center gap-3 text-emerald-600 dark:text-emerald-400 text-sm font-medium">
            <span class="material-symbols-rounded text-emerald-500 text-[20px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="p-4 bg-destructive/10 border border-destructive/20 rounded-xl flex items-center gap-3 text-destructive text-sm font-medium">
            <span class="material-symbols-rounded text-destructive text-[20px]">error</span>
            {{ session('error') }}
        </div>
    @endif

    {{-- Filter Tabs and Search Bar --}}
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        {{-- Status Filter Tabs --}}
        <div class="inline-flex p-1 bg-muted rounded-xl gap-1 text-xs font-semibold">
            <button wire:click="setStatusFilter('PENDING')"
                class="px-3 py-1.5 rounded-lg transition-colors {{ $statusFilter === 'PENDING' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                Menunggu (Pending)
            </button>
            <button wire:click="setStatusFilter('APPROVED')"
                class="px-3 py-1.5 rounded-lg transition-colors {{ $statusFilter === 'APPROVED' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                Disetujui
            </button>
            <button wire:click="setStatusFilter('REJECTED')"
                class="px-3 py-1.5 rounded-lg transition-colors {{ $statusFilter === 'REJECTED' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                Ditolak
            </button>
            <button wire:click="setStatusFilter('ALL')"
                class="px-3 py-1.5 rounded-lg transition-colors {{ $statusFilter === 'ALL' ? 'bg-card text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground' }}">
                Semua
            </button>
        </div>

        {{-- Search Input --}}
        <div class="relative w-full md:w-72">
            <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-muted-foreground">
                <span class="material-symbols-rounded text-[18px]">search</span>
            </span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari ID, jenis dokumen, pengaju..."
                class="w-full bg-card border border-border rounded-xl pl-9 pr-3 py-1.5 text-xs text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring">
        </div>
    </div>

    {{-- Main Table --}}
    <div class="bg-card rounded-2xl border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-foreground">
                <thead class="bg-muted/50 text-muted-foreground font-semibold text-2xs uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-6 py-3.5">ID & Dokumen</th>
                        <th class="px-6 py-3.5">Diajukan Oleh</th>
                        <th class="px-6 py-3.5">Level / Peran</th>
                        <th class="px-6 py-3.5">Waktu Pengajuan</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Tindakan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($requests as $req)
                        @php
                            $shortType = class_basename($req->approvable_type);
                            $statusDot = match($req->status->value ?? $req->status) {
                                'approved', 'APPROVED' => 'bg-emerald-500',
                                'rejected', 'REJECTED' => 'bg-destructive',
                                default => 'bg-amber-500',
                            };
                            $statusText = match($req->status->value ?? $req->status) {
                                'approved', 'APPROVED' => 'Disetujui',
                                'rejected', 'REJECTED' => 'Ditolak',
                                default => 'Menunggu Review',
                            };
                            $isPending = in_array($req->status->value ?? $req->status, ['pending', 'PENDING'], true);
                            $isOwnSubmission = (int) $req->submitted_by_user_id === (int) auth()->id();
                        @endphp
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-mono text-xs font-semibold text-foreground">
                                    #{{ $req->id }} &bull; {{ $shortType }} #{{ $req->approvable_id }}
                                </div>
                                <div class="text-2xs text-muted-foreground mt-0.5">
                                    Dokumen: {{ $req->approvable_type }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-foreground text-xs">
                                    {{ $req->submittedByUser?->name ?? 'Pengguna #' . $req->submitted_by_user_id }}
                                </div>
                                @if ($isOwnSubmission)
                                    <span class="inline-block mt-0.5 text-2xs text-muted-foreground font-medium">
                                        (Pengajuan Saya - SoD Terkunci)
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-xs font-medium text-foreground">
                                    Tingkat {{ $req->step_order }}
                                </span>
                                @if ($req->rule)
                                    <div class="text-2xs text-muted-foreground">
                                        Role ID: {{ $req->rule->approver_role_id ?? 'Semua' }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-muted-foreground">
                                {{ $req->created_at?->format('d M Y H:i') ?? '-' }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $statusDot }}"></span>
                                    {{ $statusText }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-2">
                                    <button wire:click="openDetail({{ $req->id }})"
                                        class="px-2.5 py-1 text-xs font-medium text-muted-foreground hover:text-foreground bg-muted hover:bg-muted/80 rounded-lg transition-colors">
                                        Detail
                                    </button>

                                    @if ($isPending)
                                        <button wire:click="approve({{ $req->id }})"
                                            @if ($isOwnSubmission) disabled title="Pemisahan Tugas: Tidak dapat menyetujui dokumen sendiri" @endif
                                            class="px-2.5 py-1 text-xs font-medium bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg shadow-xs transition-colors">
                                            Setujui
                                        </button>
                                        <button wire:click="openRejectModal({{ $req->id }})"
                                            @if ($isOwnSubmission) disabled title="Pemisahan Tugas: Tidak dapat menolak dokumen sendiri" @endif
                                            class="px-2.5 py-1 text-xs font-medium bg-destructive text-destructive-foreground hover:bg-destructive/90 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg shadow-xs transition-colors">
                                            Tolak
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">
                                <span class="material-symbols-rounded text-4xl block mb-2 text-muted-foreground/50">inbox</span>
                                Tidak ada permohonan persetujuan dalam kategori ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($requests->hasPages())
            <div class="p-4 border-t border-border">
                {{ $requests->links() }}
            </div>
        @endif
    </div>

    {{-- Modal Konfirmasi Penolakan --}}
    @if ($activeRejectingId)
        <x-ui.modal :open="true" maxWidth="md" onClose="$wire.closeRejectModal()">
            <div class="p-6 space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                        <span class="material-symbols-rounded text-destructive text-[22px]">cancel</span>
                        Tolak Pengajuan #{{ $activeRejectingId }}
                    </h3>
                    <button wire:click="closeRejectModal" class="text-muted-foreground hover:text-foreground">
                        <span class="material-symbols-rounded text-[20px]">close</span>
                    </button>
                </div>

                <div class="space-y-3">
                    <label class="block text-xs font-semibold text-foreground">
                        Alasan Penolakan (Wajib dicantumkan untuk jejak audit)
                    </label>
                    <textarea wire:model="rejectionReason" rows="3"
                        class="w-full bg-background border border-border rounded-xl text-sm p-3 text-foreground placeholder:text-muted-foreground focus:ring-1 focus:ring-ring"
                        placeholder="Contoh: Anggaran melebihi kuota kuartal berjalan..."></textarea>
                    @error('rejectionReason')
                        <span class="text-2xs text-destructive block mt-1">{{ $message }}</span>
                    @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="closeRejectModal"
                        class="px-4 py-2 text-xs font-semibold text-muted-foreground hover:text-foreground rounded-xl transition-colors">
                        Batal
                    </button>
                    <button type="button" wire:click="confirmReject"
                        class="px-4 py-2 text-xs font-semibold bg-destructive text-destructive-foreground hover:bg-destructive/90 rounded-xl transition-colors shadow-xs">
                        Konfirmasi Penolakan
                    </button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    {{-- Drawer / Modal Detail Permohonan --}}
    @if ($isDetailModalOpen && $selectedRequest)
        <x-ui.modal :open="true" maxWidth="2xl" onClose="$wire.closeDetailModal()">
            <div class="p-6 space-y-5 max-h-[90vh] overflow-y-auto">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div>
                        <h3 class="text-base font-bold text-foreground">
                            Detail Persetujuan #{{ $selectedRequest->id }}
                        </h3>
                        <p class="text-xs text-muted-foreground">
                            {{ class_basename($selectedRequest->approvable_type) }} #{{ $selectedRequest->approvable_id }}
                        </p>
                    </div>
                    <button wire:click="closeDetailModal" class="text-muted-foreground hover:text-foreground">
                        <span class="material-symbols-rounded text-[20px]">close</span>
                    </button>
                </div>

                {{-- Summary Grid --}}
                <div class="grid grid-cols-2 gap-4 text-xs bg-muted/40 p-4 rounded-xl border border-border">
                    <div>
                        <span class="text-muted-foreground block text-2xs">Pengaju (Maker):</span>
                        <span class="font-semibold text-foreground">{{ $selectedRequest->submittedByUser?->name ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block text-2xs">Waktu Diajukan:</span>
                        <span class="font-semibold text-foreground">{{ $selectedRequest->created_at?->format('d M Y H:i:s') ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block text-2xs">Status Saat Ini:</span>
                        <span class="font-semibold text-foreground">{{ strtoupper($selectedRequest->status->value ?? $selectedRequest->status) }}</span>
                    </div>
                    <div>
                        <span class="text-muted-foreground block text-2xs">Penyetuju / Pengambil Keputusan:</span>
                        <span class="font-semibold text-foreground">{{ $selectedRequest->approvedByUser?->name ?? '-' }}</span>
                    </div>
                    @if ($selectedRequest->rejection_reason)
                        <div class="col-span-2">
                            <span class="text-muted-foreground block text-2xs">Alasan Penolakan:</span>
                            <span class="font-medium text-destructive">{{ $selectedRequest->rejection_reason }}</span>
                        </div>
                    @endif
                </div>

                {{-- Audit Trail Timeline --}}
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-muted-foreground mb-3">
                        Riwayat Jejak Audit Persetujuan (Append-Only)
                    </h4>
                    <div class="space-y-3">
                        @forelse ($selectedRequest->logs as $log)
                            <div class="border-l-2 border-border pl-4 py-1 text-xs space-y-1">
                                <div class="flex items-center justify-between">
                                    <span class="font-semibold text-foreground capitalize">{{ $log->action }}</span>
                                    <span class="text-2xs text-muted-foreground">{{ $log->created_at?->format('d M Y H:i:s') }}</span>
                                </div>
                                <p class="text-muted-foreground text-2xs">
                                    Oleh: <strong class="text-foreground">{{ $log->actor?->name ?? 'Pengguna #' . $log->actor_id }}</strong>
                                    &bull; {{ $log->reason }}
                                </p>
                            </div>
                        @empty
                            <p class="text-xs text-muted-foreground italic">Belum ada riwayat log tercatat.</p>
                        @endforelse
                    </div>
                </div>

                <div class="flex justify-end pt-3 border-t border-border">
                    <button type="button" wire:click="closeDetailModal"
                        class="px-4 py-2 text-xs font-semibold text-foreground bg-muted hover:bg-muted/80 rounded-xl transition-colors">
                        Tutup
                    </button>
                </div>
            </div>
        </x-ui.modal>
    @endif
</div>

