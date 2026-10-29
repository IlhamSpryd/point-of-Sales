<div class="space-y-6" data-gsap="page-enter">
    @include('customers.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Manajemen Batas Kredit (Credit Limits & Terms)"
        description="Kontrol risiko piutang komersial B2B, termin pembayaran tempo (TOP), toleransi jatuh tempo, dan eksposur kredit berjalan.">
        <x-slot:actions>
            <x-ui.button href="{{ route('customers.index') }}" variant="secondary" icon="contacts">
                Data Pelanggan
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert variant="success" icon="check_circle">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert variant="danger" icon="error">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @php
            $totLimit = (float) ($summary?->total_limit ?? 0);
            $totReserved = (float) ($summary?->total_reserved ?? 0);
            $totInvoiced = (float) ($summary?->total_invoiced ?? 0);
            $totAvail = max(0, $totLimit - ($totReserved + $totInvoiced));
        @endphp
        <x-erp.stat-card
            label="Total Plafon Kredit"
            :value="'Rp ' . number_format($totLimit, 2, ',', '.')"
            subtext="Total plafon kredit seluruh pelanggan"
            icon="payments" />
        <x-erp.stat-card
            label="Eksposur Direservasi"
            :value="'Rp ' . number_format($totReserved, 2, ',', '.')"
            subtext="Komitmen Sales Order terkonfirmasi"
            icon="pending_actions" />
        <x-erp.stat-card
            label="Tagihan Terbit (Invoiced)"
            :value="'Rp ' . number_format($totInvoiced, 2, ',', '.')"
            subtext="Faktur piutang belum dilunasi"
            icon="receipt_long" />
        <x-erp.stat-card
            label="Sisa Kredit Tersedia"
            :value="'Rp ' . number_format($totAvail, 2, ',', '.')"
            subtext="Plafon bebas risiko untuk order baru"
            icon="verified_user" />
    </div>

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari kode pelanggan, nama, atau PT..."
                icon="search" />
        </div>
        <div class="flex items-center gap-3">
            <x-ui.select wire:model.live="statusFilter">
                <option value="">Semua Status Kredit</option>
                <option value="ACTIVE">Aktif (Active)</option>
                <option value="ON_HOLD">Ditahan (On Hold)</option>
                <option value="SUSPENDED">Dibekukan (Suspended)</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-zinc-200/80 dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm text-zinc-600 dark:text-zinc-300">
                <thead>
                    <tr class="border-b border-zinc-200/80 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                        <th class="py-3.5 px-4">Pelanggan</th>
                        <th class="py-3.5 px-4 text-right">Plafon Kredit</th>
                        <th class="py-3.5 px-4 text-center">Termin & Toleransi</th>
                        <th class="py-3.5 px-4 text-right">Reserved SO</th>
                        <th class="py-3.5 px-4 text-right">Sisa Plafon</th>
                        <th class="py-3.5 px-4 text-center">Status Kredit</th>
                        <th class="py-3.5 px-4 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200/70 dark:divide-zinc-800/70">
                    @forelse($customers as $cust)
                        @php
                            $acc = $cust->creditAccount;
                            $limitVal = (float) ($acc?->credit_limit ?? 0);
                            $reservedVal = (float) ($acc?->reserved_amount ?? 0);
                            $invoicedVal = (float) ($acc?->invoiced_amount ?? 0);
                            $availVal = max(0, $limitVal - ($reservedVal + $invoicedVal));
                            $statusVal = $acc?->status ?? 'ACTIVE';
                        @endphp
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition">
                            <td class="py-3.5 px-4">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $cust->name }}</div>
                                <div class="text-xs text-zinc-400 font-mono">{{ $cust->customer_code }}</div>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-medium text-zinc-900 dark:text-white">
                                Rp {{ number_format($limitVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-center text-xs">
                                <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $acc?->payment_terms_days ?? 0 }} hari</span>
                                <span class="text-zinc-400 block text-[11px]">(Grace: {{ $acc?->grace_period_days ?? 0 }} hr)</span>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono text-xs text-blue-600 dark:text-blue-400">
                                Rp {{ number_format($reservedVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono text-xs font-semibold {{ $availVal > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600' }}">
                                Rp {{ number_format($availVal, 2, ',', '.') }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $stBadge = match($statusVal) {
                                        'ACTIVE' => 'success',
                                        'ON_HOLD' => 'warning',
                                        'SUSPENDED' => 'danger',
                                        default => 'neutral'
                                    };
                                @endphp
                                <x-ui.badge :variant="$stBadge">
                                    {{ $statusVal }}
                                </x-ui.badge>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <x-ui.button size="icon" variant="ghost" wire:click="openEditModal({{ $cust->id }})" title="Atur Limit & TOP" aria-label="Edit">
                                        <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">tune</span>
                                    </x-ui.button>
                                    <x-ui.button size="icon" variant="ghost" wire:click="openStatusModal({{ $cust->id }})" title="Ubah Status Kredit" aria-label="Status">
                                        <span class="material-symbols-rounded text-base text-amber-500">lock_reset</span>
                                    </x-ui.button>
                                    <x-ui.button size="icon" variant="ghost" wire:click="openLogsModal({{ $cust->id }})" title="Audit Log Kredit" aria-label="Audit">
                                        <span class="material-symbols-rounded text-base text-zinc-500">history</span>
                                    </x-ui.button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">account_balance_wallet</span>
                                Tidak ada data akun kredit pelanggan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($customers->hasPages())
            <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $customers->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Edit Limit & Terms -->
    <x-ui.modal wire:model="showEditModal" maxWidth="lg">
        <x-slot:title>
            Atur Batas Kredit & Termin Pembayaran
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="space-y-4 text-xs">
                <p class="text-xs text-zinc-500">Pelanggan: <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedCustomer->name }}</strong> ({{ $selectedCustomer->customer_code }})</p>

                <div>
                    <x-ui.label for="creditLimit">Plafon Batas Kredit (Rp) *</x-ui.label>
                    <x-ui.input type="number" step="1000" min="0" id="creditLimit" wire:model="creditLimit" class="font-mono text-sm" />
                    @error('creditLimit') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="paymentTermsDays">Termin Pembayaran (Hari) *</x-ui.label>
                        <x-ui.input type="number" min="0" max="365" id="paymentTermsDays" wire:model="paymentTermsDays" class="font-mono text-sm" />
                        <span class="text-[10px] text-zinc-400 mt-0.5 block">0 = Tunai/COD, 30 = Net 30</span>
                        @error('paymentTermsDays') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="gracePeriodDays">Grace Period (Hari) *</x-ui.label>
                        <x-ui.input type="number" min="0" max="90" id="gracePeriodDays" wire:model="gracePeriodDays" class="font-mono text-sm" />
                        <span class="text-[10px] text-zinc-400 mt-0.5 block">Toleransi jatuh tempo sebelum hold</span>
                        @error('gracePeriodDays') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div>
                    <x-ui.label for="limitChangeReason">Alasan Perubahan *</x-ui.label>
                    <textarea id="limitChangeReason" wire:model="limitChangeReason" rows="2" placeholder="Contoh: Penyesuaian hasil evaluasi finansial Q1..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100 text-xs focus:ring-2 focus:ring-apeiron-ink/20"></textarea>
                    @error('limitChangeReason') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditModal">
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="saveLimitAndTerms">
                    Simpan Perubahan
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Status Kredit -->
    <x-ui.modal wire:model="showStatusModal" maxWidth="md">
        <x-slot:title>
            Ubah Status Akun Kredit
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="space-y-4 text-xs">
                <p class="text-xs text-zinc-500">Pelanggan: <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedCustomer->name }}</strong></p>

                <div>
                    <x-ui.label for="targetStatus">Pilih Status Baru *</x-ui.label>
                    <x-ui.select id="targetStatus" wire:model="targetStatus">
                        <option value="ACTIVE">ACTIVE (Kredit Aktif & Dapat Digunakan)</option>
                        <option value="ON_HOLD">ON_HOLD (Ditahan Sementara Karena Risiko / Overdue)</option>
                        <option value="SUSPENDED">SUSPENDED (Dibekukan Total Oleh Kebijakan Manajemen)</option>
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="statusChangeReason">Alasan Perubahan Status *</x-ui.label>
                    <textarea id="statusChangeReason" wire:model="statusChangeReason" rows="3" placeholder="Tulis alasan status diubah..." class="w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100 text-xs focus:ring-2 focus:ring-apeiron-ink/20"></textarea>
                    @error('statusChangeReason') <span class="text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeStatusModal">
                    Batal
                </x-ui.button>
                <x-ui.button type="button" variant="warning" wire:click="saveStatus">
                    Konfirmasi Status
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Riwayat Audit Kredit -->
    <x-ui.modal wire:model="showLogsModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedCustomer)
                Riwayat Audit Kredit: {{ $selectedCustomer->name }}
            @else
                Riwayat Audit Kredit
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedCustomer)
            <div class="p-2 space-y-3 max-h-[60vh] overflow-y-auto">
                @forelse($selectedCustomer->creditLogs as $log)
                    <div class="p-3.5 rounded-xl border border-zinc-200 dark:border-zinc-800 text-xs flex flex-col gap-1.5 bg-zinc-50/50 dark:bg-zinc-800/30">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider font-mono">{{ $log->event_type }}</span>
                            <span class="text-zinc-400 text-[11px]">{{ $log->created_at->format('d/m/Y H:i:s') }}</span>
                        </div>
                        <p class="text-zinc-600 dark:text-zinc-300">{{ $log->reason }}</p>
                        <div class="text-[11px] text-zinc-400">Diproses oleh: <strong>{{ $log->actor?->name ?? 'Sistem' }}</strong></div>
                    </div>
                @empty
                    <div class="py-8 text-center text-zinc-400 text-xs">Belum ada catatan riwayat audit kredit.</div>
                @endforelse
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button type="button" variant="secondary" wire:click="closeLogsModal">
                    Tutup
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
