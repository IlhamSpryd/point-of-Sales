<div class="space-y-6" data-gsap="page-enter">
    @include('sales.partials.sub-nav')

    <!-- Header Page -->
    <x-erp.page-header
        title="Piutang Usaha (Accounts Receivable)"
        description="Kelola faktur penjualan kredit (AR Invoice), alokasi pelunasan pembayaran, analisis umur piutang, dan rekening koran pelanggan.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Penjualan</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Piutang Usaha (AR)</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="primary" wire:click="openPaymentModal" class="gap-2">
                <span class="material-symbols-rounded text-base">payments</span>
                Terima Pembayaran
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Feedback Flash Messages -->
    @if(session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if(session()->has('error'))
        <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- AR Aging Summary Widgets -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <x-erp.stat-card
            label="Belum Jatuh Tempo"
            value="Rp {{ number_format((float) ($agingBuckets['current'] ?? 0), 2, ',', '.') }}"
            variant="default"
        />
        <x-erp.stat-card
            label="1 – 30 Hari"
            value="Rp {{ number_format((float) ($agingBuckets['1_30'] ?? 0), 2, ',', '.') }}"
            variant="warning"
        />
        <x-erp.stat-card
            label="31 – 60 Hari"
            value="Rp {{ number_format((float) ($agingBuckets['31_60'] ?? 0), 2, ',', '.') }}"
            variant="warning"
        />
        <x-erp.stat-card
            label="61 – 90 Hari"
            value="Rp {{ number_format((float) ($agingBuckets['61_90'] ?? 0), 2, ',', '.') }}"
            variant="danger"
        />
        <x-erp.stat-card
            label="> 90 Hari (Macet)"
            value="Rp {{ number_format((float) ($agingBuckets['over_90'] ?? 0), 2, ',', '.') }}"
            variant="danger"
        />
        <x-erp.stat-card
            label="Total Piutang Aktif"
            value="Rp {{ number_format((float) ($agingBuckets['total'] ?? 0), 2, ',', '.') }}"
            variant="success"
        />
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center border-b border-zinc-200 dark:border-zinc-800 gap-6">
        <button wire:click="$set('activeTab', 'invoices')" class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'invoices' ? 'border-apeiron-primary text-apeiron-primary dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}">
            <span class="material-symbols-rounded text-lg">receipt_long</span>
            Faktur Piutang
        </button>
        <button wire:click="$set('activeTab', 'statement')" class="pb-3 text-sm font-semibold border-b-2 transition flex items-center gap-2 {{ $activeTab === 'statement' ? 'border-apeiron-primary text-apeiron-primary dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-300' }}">
            <span class="material-symbols-rounded text-lg">list_alt</span>
            Rekening Koran (Statement)
        </button>
    </div>

    <!-- TAB 1: DAFTAR INVOICE -->
    @if($activeTab === 'invoices')
        <!-- Filter Bar -->
        <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <div class="flex-1 max-w-md">
                <div class="relative">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor faktur, PO customer, atau nama PT..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                    <option value="">Semua Status Faktur</option>
                    <option value="DRAFT">Konsep (Draft)</option>
                    <option value="POSTED">Terbit (Belum Lunas)</option>
                    <option value="PARTIALLY_PAID">Dibayar Sebagian</option>
                    <option value="PAID">Lunas (Paid)</option>
                    <option value="VOIDED">Dibatalkan (Voided)</option>
                </x-ui.select>
            </div>
        </div>

        <!-- Table Invoices -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="py-3.5 px-6">Nomor Faktur</th>
                            <th class="py-3.5 px-6">Pelanggan</th>
                            <th class="py-3.5 px-6">Tgl Terbit / Tempo</th>
                            <th class="py-3.5 px-6 text-right">Total Nilai</th>
                            <th class="py-3.5 px-6 text-right">Sudah Dibayar</th>
                            <th class="py-3.5 px-6 text-right">Sisa Tagihan</th>
                            <th class="py-3.5 px-6 text-center">Status</th>
                            <th class="py-3.5 px-6 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($invoices as $inv)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-900/40 transition">
                                <td class="py-4 px-6 font-mono font-bold text-xs text-apeiron-ink dark:text-white">
                                    {{ $inv->invoice_number }}
                                    @if($inv->customer_po_number)
                                        <div class="text-[11px] font-sans font-normal text-zinc-400">PO: {{ $inv->customer_po_number }}</div>
                                    @endif
                                </td>
                                <td class="py-4 px-6">
                                    <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $inv->customer?->name }}</div>
                                    @if($inv->customer?->company_name)
                                        <div class="text-xs text-zinc-400">{{ $inv->customer->company_name }}</div>
                                    @endif
                                </td>
                                <td class="py-4 px-6 text-xs text-zinc-600 dark:text-zinc-400">
                                    <div>{{ $inv->invoice_date?->format('d/m/Y') }}</div>
                                    <div class="font-semibold {{ $inv->isOverdue() ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-500' }}">
                                        Tempo: {{ $inv->due_date?->format('d/m/Y') }}
                                        @if($inv->isOverdue())
                                            <span class="inline-block ml-1 px-1.5 py-0.5 rounded bg-rose-100 text-rose-800 text-[10px]">Lewat {{ $inv->daysOverdue() }}h</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-right font-mono font-medium text-xs text-zinc-900 dark:text-white">
                                    Rp {{ number_format((float) $inv->total_amount, 2, ',', '.') }}
                                </td>
                                <td class="py-4 px-6 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">
                                    Rp {{ number_format((float) $inv->paid_amount, 2, ',', '.') }}
                                </td>
                                <td class="py-4 px-6 text-right font-mono font-bold text-xs {{ (float) $inv->balance_due > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-400' }}">
                                    Rp {{ number_format((float) $inv->balance_due, 2, ',', '.') }}
                                </td>
                                <td class="py-4 px-6 text-center">
                                    @php
                                        $invVariant = match($inv->status->value) {
                                            'DRAFT' => 'neutral',
                                            'POSTED' => 'warning',
                                            'PARTIALLY_PAID' => 'info',
                                            'PAID' => 'success',
                                            'VOIDED' => 'danger',
                                            default => 'neutral',
                                        };
                                    @endphp
                                    <x-ui.badge variant="{{ $invVariant }}" size="sm">
                                        {{ $inv->status->label() }}
                                    </x-ui.badge>
                                </td>
                                <td class="py-4 px-6 text-right">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button wire:click="viewInvoice({{ $inv->id }})" class="p-1.5 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" title="Lihat Detail Faktur">
                                            <span class="material-symbols-rounded text-lg">visibility</span>
                                        </button>
                                        @if(in_array($inv->status->value, ['POSTED', 'PARTIALLY_PAID']))
                                            <button wire:click="openPaymentModal({{ $inv->customer_id }})" class="p-1.5 text-emerald-600 hover:text-emerald-700 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition" title="Alokasikan Pembayaran">
                                                <span class="material-symbols-rounded text-lg">add_card</span>
                                            </button>
                                        @endif
                                        @if($inv->canBeVoided())
                                            <button wire:click="openVoidModal({{ $inv->id }})" class="p-1.5 text-rose-600 hover:text-rose-700 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-950/40 transition" title="Batalkan Faktur (Void)">
                                                <span class="material-symbols-rounded text-lg">cancel</span>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl mb-2 text-zinc-300 block">receipt_long</span>
                                    <div>Belum ada faktur penjualan tercatat.</div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($invoices->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>
    @endif

    <!-- TAB 2: REKENING KORAN (STATEMENT) -->
    @if($activeTab === 'statement')
        <div class="card-surface p-6 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 space-y-6">
            <h2 class="text-base font-bold text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="material-symbols-rounded text-apeiron-primary">tune</span>
                Pilih Parameter Rekening Koran
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <x-ui.label for="statementCustomerId">Pelanggan *</x-ui.label>
                    <x-ui.select id="statementCustomerId" wire:model="statementCustomerId">
                        <option value="">-- Pilih Pelanggan --</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->customer_code }})</option>
                        @endforeach
                    </x-ui.select>
                    @error('statementCustomerId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-ui.label for="statementStartDate">Tanggal Mulai</x-ui.label>
                    <x-ui.input type="date" id="statementStartDate" wire:model="statementStartDate" />
                    @error('statementStartDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <x-ui.label for="statementEndDate">Tanggal Akhir</x-ui.label>
                    <x-ui.input type="date" id="statementEndDate" wire:model="statementEndDate" />
                    @error('statementEndDate') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex justify-end">
                <x-ui.button variant="primary" wire:click="generateStatement" class="gap-2">
                    <span class="material-symbols-rounded text-base">play_arrow</span>
                    Tampilkan Rekening Koran
                </x-ui.button>
            </div>

            @if($statementData)
                <div class="pt-6 border-t border-[#E5E7EB] dark:border-zinc-800 space-y-4">
                    <div class="flex flex-col md:flex-row md:items-center justify-between p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/60 border border-zinc-200 dark:border-zinc-800 gap-4">
                        <div>
                            <span class="text-xs font-medium text-zinc-500">Saldo Awal (Opening Balance):</span>
                            <div class="text-base font-mono font-bold text-zinc-800 dark:text-zinc-200">
                                Rp {{ number_format((float) $statementData['opening_balance'], 2, ',', '.') }}
                            </div>
                        </div>
                        <div>
                            <span class="text-xs font-medium text-zinc-500">Saldo Akhir (Closing Balance):</span>
                            <div class="text-lg font-mono font-extrabold text-emerald-600 dark:text-emerald-400">
                                Rp {{ number_format((float) $statementData['closing_balance'], 2, ',', '.') }}
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left text-sm border-collapse">
                            <thead>
                                <tr class="bg-zinc-50/80 dark:bg-zinc-900 text-xs font-semibold text-zinc-500 uppercase tracking-wider border-b border-zinc-200 dark:border-zinc-800">
                                    <th class="py-3 px-4">Tanggal</th>
                                    <th class="py-3 px-4">Jenis Transaksi</th>
                                    <th class="py-3 px-4">No. Dokumen / Referensi</th>
                                    <th class="py-3 px-4 text-right">Debet (Tagihan)</th>
                                    <th class="py-3 px-4 text-right">Kredit (Pelunasan)</th>
                                    <th class="py-3 px-4 text-right">Saldo Berjalan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @forelse($statementData['transactions'] as $tx)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-900/30">
                                        <td class="py-2.5 px-4 font-mono text-xs">{{ \Carbon\Carbon::parse($tx['entry_date'])->format('d/m/Y H:i') }}</td>
                                        <td class="py-2.5 px-4 text-xs font-medium">
                                            <x-ui.badge variant="neutral" size="xs">{{ $tx['entry_type_label'] }}</x-ui.badge>
                                        </td>
                                        <td class="py-2.5 px-4 font-mono text-xs text-zinc-600 dark:text-zinc-300">{{ $tx['reference_number'] }}</td>
                                        <td class="py-2.5 px-4 text-right font-mono text-xs text-rose-600 font-semibold">
                                            {{ (float) $tx['debit_amount'] > 0 ? 'Rp '.number_format((float) $tx['debit_amount'], 2, ',', '.') : '-' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono text-xs text-emerald-600 font-semibold">
                                            {{ (float) $tx['credit_amount'] > 0 ? 'Rp '.number_format((float) $tx['credit_amount'], 2, ',', '.') : '-' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right font-mono font-bold text-xs text-zinc-900 dark:text-white">
                                            Rp {{ number_format((float) $tx['running_balance'], 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-zinc-400">Tidak ada mutasi piutang dalam periode ini.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- MODAL 1: DETAIL FAKTUR -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:title>
            @if($selectedInvoice)
                <div class="flex items-center gap-2">
                    <span>{{ $selectedInvoice->invoice_number }}</span>
                    <x-ui.badge variant="{{ $selectedInvoice->status->value === 'PAID' ? 'success' : 'warning' }}" size="sm">
                        {{ $selectedInvoice->status->label() }}
                    </x-ui.badge>
                </div>
            @else
                Detail Faktur Piutang
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedInvoice)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                    <div>
                        <span class="text-zinc-500">Pelanggan:</span>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedInvoice->customer?->name }}</div>
                        <div class="text-[11px] text-zinc-400">{{ $selectedInvoice->customer?->company_name }}</div>
                    </div>
                    <div>
                        <span class="text-zinc-500">Tgl Terbit / Tempo:</span>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedInvoice->invoice_date?->format('d M Y') }}</div>
                        <div class="text-zinc-500">Tempo: {{ $selectedInvoice->due_date?->format('d M Y') }} ({{ $selectedInvoice->payment_terms_days }} hari)</div>
                    </div>
                    <div>
                        <span class="text-zinc-500">Customer PO Number:</span>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedInvoice->customer_po_number ?: '-' }}</div>
                    </div>
                    <div>
                        <span class="text-zinc-500">Diposting Oleh:</span>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedInvoice->postedBy?->name ?: '-' }}</div>
                        <div class="text-[11px] text-zinc-400">{{ $selectedInvoice->posted_at?->format('d/m/Y H:i') }}</div>
                    </div>
                </div>

                <!-- Items Table -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">Rincian Item Tagihan</h4>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500">
                                    <th class="py-2.5 px-3">#</th>
                                    <th class="py-2.5 px-3">Item Deskripsi</th>
                                    <th class="py-2.5 px-3 text-right">Kuantitas</th>
                                    <th class="py-2.5 px-3 text-right">Harga Satuan</th>
                                    <th class="py-2.5 px-3 text-right">Diskon</th>
                                    <th class="py-2.5 px-3 text-right">Pajak</th>
                                    <th class="py-2.5 px-3 text-right">Total Baris</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedInvoice->lines as $line)
                                    <tr>
                                        <td class="py-2.5 px-3 font-mono text-zinc-400">{{ $line->line_number }}</td>
                                        <td class="py-2.5 px-3 font-medium text-zinc-800 dark:text-zinc-200">{{ $line->description }}</td>
                                        <td class="py-2.5 px-3 text-right font-mono">{{ number_format((float) $line->quantity, 2, ',', '.') }}</td>
                                        <td class="py-2.5 px-3 text-right font-mono">Rp {{ number_format((float) $line->unit_price, 2, ',', '.') }}</td>
                                        <td class="py-2.5 px-3 text-right font-mono text-amber-600">Rp {{ number_format((float) $line->discount_amount, 2, ',', '.') }}</td>
                                        <td class="py-2.5 px-3 text-right font-mono text-zinc-500">Rp {{ number_format((float) $line->tax_amount, 2, ',', '.') }}</td>
                                        <td class="py-2.5 px-3 text-right font-mono font-bold text-zinc-900 dark:text-zinc-100">Rp {{ number_format((float) $line->line_total, 2, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="flex justify-end">
                    <div class="w-full md:w-80 space-y-1.5 text-xs">
                        <div class="flex justify-between py-1 text-zinc-600 dark:text-zinc-400">
                            <span>Subtotal:</span>
                            <span class="font-mono">Rp {{ number_format((float) $selectedInvoice->subtotal, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between py-1 text-zinc-600 dark:text-zinc-400">
                            <span>Diskon:</span>
                            <span class="font-mono text-amber-600">- Rp {{ number_format((float) $selectedInvoice->discount_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between py-1 text-zinc-600 dark:text-zinc-400">
                            <span>Pajak (PPN):</span>
                            <span class="font-mono">Rp {{ number_format((float) $selectedInvoice->tax_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-t border-zinc-200 dark:border-zinc-800 font-bold text-sm text-zinc-900 dark:text-zinc-100">
                            <span>Total Tagihan:</span>
                            <span class="font-mono">Rp {{ number_format((float) $selectedInvoice->total_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between py-1 text-emerald-600 dark:text-emerald-400 font-semibold">
                            <span>Sudah Dibayar:</span>
                            <span class="font-mono">Rp {{ number_format((float) $selectedInvoice->paid_amount, 2, ',', '.') }}</span>
                        </div>
                        <div class="flex justify-between py-1.5 border-t border-zinc-200 dark:border-zinc-800 font-extrabold text-sm text-rose-600 dark:text-rose-400">
                            <span>Sisa Piutang (Balance Due):</span>
                            <span class="font-mono">Rp {{ number_format((float) $selectedInvoice->balance_due, 2, ',', '.') }}</span>
                        </div>
                    </div>
                </div>

                <!-- Riwayat Alokasi Pembayaran -->
                @if($selectedInvoice->allocations->count() > 0)
                    <div class="border-t border-[#E5E7EB] dark:border-zinc-800 pt-4">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">Riwayat Alokasi Pelunasan</h4>
                        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500">
                                        <th class="py-2 px-3">Tanggal</th>
                                        <th class="py-2 px-3">Referensi Pembayaran</th>
                                        <th class="py-2 px-3">Dicatat Oleh</th>
                                        <th class="py-2 px-3 text-right">Nilai Alokasi</th>
                                        <th class="py-2 px-3 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @foreach($selectedInvoice->allocations as $alloc)
                                        <tr>
                                            <td class="py-2 px-3 font-mono">{{ $alloc->allocated_at?->format('d/m/Y H:i') }}</td>
                                            <td class="py-2 px-3 font-mono text-zinc-600 dark:text-zinc-300">{{ $alloc->payment_reference ?: '-' }}</td>
                                            <td class="py-2 px-3">{{ $alloc->allocatedBy?->name }}</td>
                                            <td class="py-2 px-3 text-right font-mono font-bold text-emerald-600">Rp {{ number_format((float) $alloc->allocated_amount, 2, ',', '.') }}</td>
                                            <td class="py-2 px-3 text-center">
                                                <x-ui.badge variant="{{ $alloc->is_reversed ? 'danger' : 'success' }}" size="xs">
                                                    {{ $alloc->is_reversed ? 'Reversed' : 'Active' }}
                                                </x-ui.badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end">
                <x-ui.button variant="secondary" wire:click="closeDetailModal">
                    Tutup
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- MODAL 2: TERIMA & ALOKASIKAN PEMBAYARAN -->
    <x-ui.modal wire:model="showPaymentModal" maxWidth="3xl">
        <x-slot:title>
            Penerimaan & Alokasi Pembayaran Piutang
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-6">
                <p class="text-xs text-zinc-500 -mt-2">Catat pembayaran masuk dan alokasikan ke faktur-faktur yang belum lunas.</p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <x-ui.label for="paymentCustomerId">Pelanggan *</x-ui.label>
                        <x-ui.select id="paymentCustomerId" wire:model.live="paymentCustomerId">
                            <option value="">-- Pilih Pelanggan --</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->customer_code }})</option>
                            @endforeach
                        </x-ui.select>
                        @error('paymentCustomerId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="paymentAmount">Total Pembayaran Diterima (Rp) *</x-ui.label>
                        <x-ui.input type="number" step="0.01" id="paymentAmount" wire:model="paymentAmount" placeholder="0.00" class="font-mono font-bold" />
                        @error('paymentAmount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <x-ui.label for="paymentMethod">Metode Pembayaran</x-ui.label>
                        <x-ui.select id="paymentMethod" wire:model="paymentMethod">
                            <option value="bank_transfer">Transfer Bank (BCA / Mandiri / BNI / BRI)</option>
                            <option value="giro_cek">Bilyet Giro / Cek Tunai</option>
                            <option value="cash">Tunai / Kasir</option>
                            <option value="qris">QRIS Statis/Dinamis</option>
                        </x-ui.select>
                    </div>
                    <div class="md:col-span-2">
                        <x-ui.label for="paymentReference">Nomor Referensi / Bukti Transfer *</x-ui.label>
                        <x-ui.input type="text" id="paymentReference" wire:model="paymentReference" placeholder="mis. TRF-BCA-20270105-0982" class="font-mono" />
                        @error('paymentReference') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <!-- Daftar Faktur Terbuka Milik Pelanggan -->
                @if($paymentCustomerId && count($openCustomerInvoices) > 0)
                    <div class="space-y-2">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Pilih & Alokasikan ke Faktur Terbuka</h4>
                        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full text-left text-xs border-collapse">
                                <thead>
                                    <tr class="bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500">
                                        <th class="py-2.5 px-3">Faktur</th>
                                        <th class="py-2.5 px-3">Jatuh Tempo</th>
                                        <th class="py-2.5 px-3 text-right">Sisa Tagihan</th>
                                        <th class="py-2.5 px-3 text-right w-44">Alokasi Bayar (Rp)</th>
                                        <th class="py-2.5 px-3 text-center w-24">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                    @foreach($openCustomerInvoices as $openInv)
                                        <tr>
                                            <td class="py-2 px-3 font-mono font-bold">{{ $openInv->invoice_number }}</td>
                                            <td class="py-2 px-3 text-zinc-600 dark:text-zinc-400">{{ $openInv->due_date?->format('d/m/Y') }}</td>
                                            <td class="py-2 px-3 text-right font-mono font-semibold text-rose-600">
                                                Rp {{ number_format((float) $openInv->balance_due, 2, ',', '.') }}
                                            </td>
                                            <td class="py-2 px-3 text-right">
                                                <x-ui.input type="number" step="0.01" wire:model="allocatedAmounts.{{ $openInv->id }}" placeholder="0.00" class="text-right text-xs font-mono font-bold" />
                                            </td>
                                            <td class="py-2 px-3 text-center">
                                                <x-ui.button type="button" variant="secondary" size="xs" wire:click="allocateFull({{ $openInv->id }}, '{{ $openInv->balance_due }}')">
                                                    Lunas Penuh
                                                </x-ui.button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @elseif($paymentCustomerId)
                    <div class="p-6 text-center text-xs text-zinc-400 bg-zinc-50 dark:bg-zinc-900 rounded-xl">
                        Pelanggan ini tidak memiliki faktur belum lunas. Pembayaran akan dicatat sebagai Unapplied Credit.
                    </div>
                @endif
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closePaymentModal">
                    Batal
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="submitPayment">
                    Simpan & Alokasikan Pembayaran
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- MODAL 3: VOID INVOICE -->
    <x-ui.modal wire:model="showVoidModal" maxWidth="md">
        <x-slot:title>
            Batalkan Faktur (VOID)
        </x-slot:title>

        <x-slot:content>
            @if($selectedInvoice)
            <div class="space-y-4">
                <p class="text-xs text-zinc-500">
                    Anda akan membatalkan faktur <strong class="font-mono text-zinc-800 dark:text-zinc-200">{{ $selectedInvoice->invoice_number }}</strong> senilai <strong class="font-mono text-zinc-800 dark:text-zinc-200">Rp {{ number_format((float) $selectedInvoice->total_amount, 2, ',', '.') }}</strong>. Tindakan ini membalik mutasi piutang dan mengembalikan eksposur kredit ke Sales Order asal.
                </p>
                <div>
                    <x-ui.label for="voidReason">Alasan Pembatalan *</x-ui.label>
                    <textarea id="voidReason" wire:model="voidReason" rows="3" placeholder="Masukkan alasan pembatalan faktur..." class="w-full text-xs rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2.5 text-zinc-800 dark:text-zinc-100"></textarea>
                    @error('voidReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeVoidModal">
                    Batal
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="submitVoid">
                    Konfirmasi VOID
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
