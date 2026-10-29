<div class="space-y-6" data-gsap="page-enter">
    @include('sales.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Penawaran Harga (Quotations)"
        description="Kelola penawaran komersial B2B, persetujuan diskon/margin, masa berlaku, dan konversi satu-kali ke Sales Order.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('sales.orders.index') }}">Penjualan</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Penawaran Harga</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            @can('create', \App\Models\Quotation::class)
            <x-ui.button variant="primary" wire:click="openCreateModal" class="gap-2">
                <span class="material-symbols-rounded text-base">add_circle</span>
                Buat Penawaran Baru
            </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <!-- Flash Messages -->
    @if (session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    @if (session()->has('error'))
        <x-ui.alert type="danger" title="Terjadi Kesalahan" class="rounded-2xl">
            {{ session('error') }}
        </x-ui.alert>
    @endif

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <div class="relative">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari nomor penawaran, nama pelanggan, kode..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="statusFilter" class="w-auto text-sm">
                <option value="">Semua Status</option>
                <option value="DRAFT">Draft</option>
                <option value="PENDING_APPROVAL">Pending Approval</option>
                <option value="APPROVED">Approved</option>
                <option value="SENT">Sent</option>
                <option value="ACCEPTED">Accepted</option>
                <option value="CONVERTED">Converted (SO)</option>
                <option value="REJECTED">Rejected</option>
                <option value="EXPIRED">Expired</option>
                <option value="SUPERSEDED">Superseded</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                        <th class="py-3.5 px-6">No. Penawaran</th>
                        <th class="py-3.5 px-6">Pelanggan</th>
                        <th class="py-3.5 px-6">Tanggal / Berlaku</th>
                        <th class="py-3.5 px-6 text-right">Total Nilai</th>
                        <th class="py-3.5 px-6 text-center">Status</th>
                        <th class="py-3.5 px-6 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                    @forelse($quotations as $qtn)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                            <td class="py-4 px-6">
                                <div class="font-medium text-apeiron-ink dark:text-white flex items-center gap-1.5 font-mono">
                                    <span>{{ $qtn->quotation_number }}</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-mono">v{{ $qtn->version_number }}</span>
                                </div>
                                <div class="text-xs text-zinc-400 mt-0.5">Cabang: {{ $qtn->store?->name ?? 'Pusat' }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $qtn->customer?->name }}</div>
                                <div class="text-xs text-zinc-400 font-mono">{{ $qtn->customer?->customer_code }}</div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-zinc-800 dark:text-zinc-200">{{ $qtn->issue_date->format('d/m/Y') }}</div>
                                <div class="text-xs text-zinc-500">s/d {{ $qtn->valid_until->format('d/m/Y') }}</div>
                            </td>
                            <td class="py-4 px-6 text-right font-medium text-zinc-900 dark:text-white">
                                Rp {{ number_format((float) $qtn->total_amount, 2, ',', '.') }}
                            </td>
                            <td class="py-4 px-6 text-center">
                                @php
                                    $statusVariant = match($qtn->status->value) {
                                        'DRAFT' => 'neutral',
                                        'PENDING_APPROVAL' => 'warning',
                                        'APPROVED' => 'info',
                                        'SENT' => 'info',
                                        'ACCEPTED' => 'success',
                                        'CONVERTED' => 'neutral',
                                        'REJECTED' => 'danger',
                                        'EXPIRED' => 'warning',
                                        'SUPERSEDED' => 'neutral',
                                        default => 'neutral',
                                    };
                                @endphp
                                <x-ui.badge variant="{{ $statusVariant }}" size="sm">
                                    {{ $qtn->status->value }}
                                </x-ui.badge>
                            </td>
                            <td class="py-4 px-6 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button wire:click="viewQuotation({{ $qtn->id }})" class="p-1.5 text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200 rounded-lg hover:bg-zinc-100 dark:hover:bg-zinc-800 transition" title="Lihat Detail">
                                        <span class="material-symbols-rounded text-lg">visibility</span>
                                    </button>

                                    @if($qtn->status->value === 'ACCEPTED' && $qtn->converted_sales_order_id === null)
                                        @can('convert', $qtn)
                                        <button wire:click="convertToSalesOrder({{ $qtn->id }})" class="p-1.5 text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 rounded-lg hover:bg-emerald-50 dark:hover:bg-emerald-950/40 transition" title="Konversi ke Sales Order">
                                            <span class="material-symbols-rounded text-lg">shopping_bag</span>
                                        </button>
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">request_quote</span>
                                Belum ada data penawaran harga (Quotation).
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($quotations->hasPages())
            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                {{ $quotations->links() }}
            </div>
        @endif
    </div>

    <!-- Modal 1: Create Quotation -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Buat Penawaran Harga Baru
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-ui.label for="quotation_customer_id">Pelanggan *</x-ui.label>
                        <x-ui.select id="quotation_customer_id" wire:model="customer_id">
                            <option value="">-- Pilih Pelanggan --</option>
                            @foreach($customers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->customer_code }}){{ $c->company_name ? ' - ' . $c->company_name : '' }}</option>
                            @endforeach
                        </x-ui.select>
                        @error('customer_id') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <x-ui.label for="issue_date">Tgl Penawaran *</x-ui.label>
                            <x-ui.input type="date" id="issue_date" wire:model="issue_date" />
                        </div>
                        <div>
                            <x-ui.label for="valid_until">Masa Berlaku *</x-ui.label>
                            <x-ui.input type="date" id="valid_until" wire:model="valid_until" />
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <x-ui.label for="payment_terms_days">Termin Pembayaran (Hari)</x-ui.label>
                        <x-ui.input type="number" id="payment_terms_days" wire:model="payment_terms_days" min="0" placeholder="0 = Cash / Langsung" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-ui.label for="notes">Catatan Tambahan</x-ui.label>
                        <x-ui.input type="text" id="notes" wire:model="notes" placeholder="Catatan internal atau pengiriman..." />
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800/60 px-4 py-2.5 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300 uppercase tracking-wider">Item Penawaran</span>
                        <x-ui.button type="button" variant="secondary" size="xs" wire:click="addLine" class="gap-1">
                            <span class="material-symbols-rounded text-sm">add</span> Tambah Baris
                        </x-ui.button>
                    </div>

                    <div class="p-3 space-y-3">
                        @foreach($lines as $idx => $line)
                            <div class="flex flex-col sm:flex-row items-center gap-2 p-2 rounded-lg bg-zinc-50/50 dark:bg-zinc-800/30 border border-zinc-200/50 dark:border-zinc-700/50">
                                <div class="flex-1 w-full sm:w-auto">
                                    <x-ui.select wire:model="lines.{{ $idx }}.product_id" class="text-xs">
                                        <option value="">-- Pilih Produk --</option>
                                        @foreach($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->product_name ?? $p->name }} ({{ $p->product_code ?? $p->sku }})</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                                <div class="w-24">
                                    <x-ui.input type="number" step="0.0001" wire:model="lines.{{ $idx }}.quantity" placeholder="Qty" class="text-right text-xs" />
                                </div>
                                <div class="w-28">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.unit_price" placeholder="Harga" class="text-right text-xs" />
                                </div>
                                <div class="w-24">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.discount_amount" placeholder="Diskon" class="text-right text-xs" />
                                </div>
                                <div class="w-20">
                                    <x-ui.input type="number" step="0.01" wire:model="lines.{{ $idx }}.tax_rate" placeholder="Pajak %" class="text-right text-xs" />
                                </div>
                                <div>
                                    <button type="button" wire:click="removeLine({{ $idx }})" class="p-1 text-zinc-400 hover:text-rose-500 rounded">
                                        <span class="material-symbols-rounded text-lg">delete</span>
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeCreateModal">
                    Batal
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="createQuotation" wire:loading.attr="disabled">
                    Simpan Penawaran
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal 2: Detail Quotation -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="4xl">
        <x-slot:title>
            @if($selectedQuotation)
                <div class="flex items-center gap-2">
                    <span>{{ $selectedQuotation->quotation_number }}</span>
                    <x-ui.badge variant="neutral" size="xs">Versi {{ $selectedQuotation->version_number }}</x-ui.badge>
                </div>
            @else
                Detail Penawaran Harga
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedQuotation)
            <div class="space-y-6">
                <div class="text-xs text-zinc-500 -mt-2">
                    Dibuat oleh: {{ $selectedQuotation->createdBy?->name }} pada {{ $selectedQuotation->created_at->format('d/m/Y H:i') }}
                </div>

                <!-- Status & Action Bar -->
                <div class="p-4 rounded-xl bg-zinc-50 dark:bg-zinc-800/40 border border-[#E5E7EB] dark:border-zinc-700/80 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-zinc-500 uppercase">Status:</span>
                        <span class="font-bold text-zinc-900 dark:text-white">{{ $selectedQuotation->status->value }}</span>
                        @if($selectedQuotation->requires_approval)
                            <x-ui.badge variant="warning" size="xs">Butuh Persetujuan Diskon</x-ui.badge>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if($selectedQuotation->status->value === 'DRAFT')
                            <x-ui.button variant="primary" size="sm" wire:click="submitForApprovalOrSend({{ $selectedQuotation->id }})">
                                Ajukan / Kirim ke Pelanggan
                            </x-ui.button>
                        @endif

                        @if($selectedQuotation->status->value === 'PENDING_APPROVAL')
                            @can('approve', $selectedQuotation)
                            <x-ui.button variant="success" size="sm" wire:click="approve({{ $selectedQuotation->id }})">
                                Setujui (Approve)
                            </x-ui.button>
                            <x-ui.button variant="danger" size="sm" wire:click="openRejectModal({{ $selectedQuotation->id }})">
                                Tolak
                            </x-ui.button>
                            @endcan
                        @endif

                        @if(in_array($selectedQuotation->status->value, ['APPROVED', 'SENT']))
                            <x-ui.button variant="success" size="sm" wire:click="accept({{ $selectedQuotation->id }})">
                                Pelanggan Menerima (Accept)
                            </x-ui.button>
                        @endif

                        @if($selectedQuotation->status->value === 'ACCEPTED' && $selectedQuotation->converted_sales_order_id === null)
                            @can('convert', $selectedQuotation)
                            <x-ui.button variant="primary" size="sm" wire:click="convertToSalesOrder({{ $selectedQuotation->id }})" class="gap-1.5 shadow-xs">
                                <span class="material-symbols-rounded text-sm">shopping_bag</span>
                                Konversi ke Sales Order (1x)
                            </x-ui.button>
                            @endcan
                        @endif

                        @if($selectedQuotation->convertedSalesOrder)
                            <div class="text-xs font-medium text-purple-700 dark:text-purple-400 bg-purple-50 dark:bg-purple-950/40 px-3 py-1.5 rounded-lg border border-purple-200 dark:border-purple-800">
                                Ditautkan ke SO: {{ $selectedQuotation->convertedSalesOrder->so_number }}
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <h4 class="font-bold text-zinc-900 dark:text-white mb-2 text-xs uppercase tracking-wider">Informasi Pelanggan</h4>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-zinc-500">Nama:</span> <strong class="text-zinc-800 dark:text-zinc-200">{{ $selectedQuotation->customer?->name }}</strong></p>
                            <p><span class="text-zinc-500">Kode:</span> <span class="font-mono">{{ $selectedQuotation->customer?->customer_code }}</span></p>
                            <p><span class="text-zinc-500">Telepon:</span> {{ $selectedQuotation->customer?->phone ?? '-' }}</p>
                            <p><span class="text-zinc-500">Email:</span> {{ $selectedQuotation->customer?->email ?? '-' }}</p>
                        </div>
                    </div>
                    <div class="p-4 rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <h4 class="font-bold text-zinc-900 dark:text-white mb-2 text-xs uppercase tracking-wider">Ketentuan Komersial</h4>
                        <div class="space-y-1 text-xs">
                            <p><span class="text-zinc-500">Tgl Penawaran:</span> {{ $selectedQuotation->issue_date->format('d/m/Y') }}</p>
                            <p><span class="text-zinc-500">Masa Berlaku:</span> {{ $selectedQuotation->valid_until->format('d/m/Y') }}</p>
                            <p><span class="text-zinc-500">Termin Pembayaran:</span> {{ $selectedQuotation->payment_terms_days }} Hari</p>
                            <p><span class="text-zinc-500">Mata Uang:</span> {{ $selectedQuotation->currency_code }}</p>
                        </div>
                    </div>
                </div>

                <!-- Lines -->
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden">
                    <table class="w-full text-left text-xs">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 text-zinc-500 uppercase font-semibold">
                            <tr>
                                <th class="p-3">#</th>
                                <th class="p-3">Produk</th>
                                <th class="p-3 text-right">Kuantitas</th>
                                <th class="p-3 text-right">Harga Satuan</th>
                                <th class="p-3 text-right">Diskon</th>
                                <th class="p-3 text-right">Pajak</th>
                                <th class="p-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                            @foreach($selectedQuotation->lines as $line)
                                <tr>
                                    <td class="p-3 font-mono text-zinc-400">{{ $line->line_number }}</td>
                                    <td class="p-3">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $line->description ?: ($line->product?->product_name ?? $line->product?->name) }}</div>
                                        <div class="text-[10px] text-zinc-400 font-mono">{{ $line->product?->product_code ?? $line->product?->sku }} | Sumber: {{ $line->pricing_source }}</div>
                                    </td>
                                    <td class="p-3 text-right font-mono">{{ number_format((float) $line->quantity, 2) }}</td>
                                    <td class="p-3 text-right font-mono">Rp {{ number_format((float) $line->unit_price, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-mono text-rose-600">-Rp {{ number_format((float) $line->discount_amount, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-mono">Rp {{ number_format((float) $line->tax_amount, 2, ',', '.') }}</td>
                                    <td class="p-3 text-right font-mono font-bold text-zinc-900 dark:text-white">Rp {{ number_format((float) $line->line_total, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-zinc-50/50 dark:bg-zinc-800/50 border-t border-zinc-200 dark:border-zinc-700 font-semibold text-xs">
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-zinc-500">Subtotal:</td>
                                <td class="p-2.5 text-right font-mono">Rp {{ number_format((float) $selectedQuotation->subtotal, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-zinc-500">Total Diskon:</td>
                                <td class="p-2.5 text-right font-mono text-rose-600">-Rp {{ number_format((float) $selectedQuotation->discount_amount, 2, ',', '.') }}</td>
                            </tr>
                            <tr>
                                <td colspan="6" class="p-2.5 text-right text-zinc-500">Total Pajak:</td>
                                <td class="p-2.5 text-right font-mono">Rp {{ number_format((float) $selectedQuotation->tax_amount, 2, ',', '.') }}</td>
                            </tr>
                            <tr class="text-sm font-bold border-t border-zinc-300 dark:border-zinc-600">
                                <td colspan="6" class="p-3 text-right text-zinc-900 dark:text-white">TOTAL PENAWARAN:</td>
                                <td class="p-3 text-right font-mono text-emerald-600 dark:text-emerald-400">Rp {{ number_format((float) $selectedQuotation->total_amount, 2, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
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

    <!-- Modal 3: Reject -->
    <x-ui.modal wire:model="showRejectModal" maxWidth="md">
        <x-slot:title>
            Tolak Penawaran Harga
        </x-slot:title>

        <x-slot:content>
            <div class="space-y-4">
                <div>
                    <x-ui.label for="rejectReason">Alasan Penolakan *</x-ui.label>
                    <textarea id="rejectReason" wire:model="rejectReason" rows="3" placeholder="Tulis alasan penolakan penawaran ini..." class="w-full text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 p-2.5 text-zinc-800 dark:text-zinc-100"></textarea>
                    @error('rejectReason') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="$set('showRejectModal', false)">
                    Batal
                </x-ui.button>
                <x-ui.button variant="danger" wire:click="reject">
                    Konfirmasi Tolak
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
