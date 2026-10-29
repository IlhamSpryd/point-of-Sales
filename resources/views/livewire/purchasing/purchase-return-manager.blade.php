<div class="space-y-6" data-gsap="page-enter">
    @include('purchasing.partials.sub-nav')

    <!-- Header -->
    <x-erp.page-header
        title="Retur Pembelian & Nota Debit (Purchase Returns)"
        description="Kelola pengembalian barang fisik rusak atau tidak sesuai ke vendor, kurangi stok inventori, dan terbitkan Nota Debit (Debit Note) untuk memotong tagihan faktur pemasok.">
        <x-slot:actions>
            @can('create', \App\Modules\Purchasing\Models\PurchaseReturn::class)
            <x-ui.button wire:click="openCreateModal" icon="assignment_return" variant="primary">
                Buat Retur Pembelian
            </x-ui.button>
            @endcan
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

    <!-- Filter & Toolbar -->
    <div class="card-surface p-4 rounded-2xl flex flex-col md:flex-row md:items-center justify-between gap-4 border border-zinc-200/80 dark:border-zinc-800">
        <div class="flex-1 max-w-md">
            <x-ui.input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Cari nomor retur, GRN, atau pemasok..."
                icon="search" />
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.select wire:model.live="supplierFilter">
                <option value="">Semua Pemasok</option>
                @foreach($suppliers as $s)
                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                @endforeach
            </x-ui.select>

            <x-ui.select wire:model.live="statusFilter">
                <option value="">Semua Status</option>
                <option value="DRAFT">Draf</option>
                <option value="POSTED">Diposting (Stok Keluar)</option>
                <option value="COMPLETED">Selesai (Debit Note Dialokasikan)</option>
                <option value="CANCELLED">Dibatalkan</option>
            </x-ui.select>
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface rounded-2xl overflow-hidden border border-zinc-200/80 dark:border-zinc-800 shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="bg-zinc-50 dark:bg-zinc-900/50 text-xs font-semibold uppercase text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                    <tr>
                        <th class="px-6 py-4">Nomor & Tanggal Retur</th>
                        <th class="px-6 py-4">Pemasok & Gudang</th>
                        <th class="px-6 py-4">Referensi GRN</th>
                        <th class="px-6 py-4">Alasan Retur</th>
                        <th class="px-6 py-4 text-center">Nota Debit (DN)</th>
                        <th class="px-6 py-4 text-center">Status Retur</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($returns as $ret)
                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition-colors">
                        <td class="px-6 py-4">
                            <div class="font-bold text-zinc-900 dark:text-white font-mono">{{ $ret->return_number }}</div>
                            <div class="text-xs text-zinc-500">{{ \Carbon\Carbon::parse($ret->created_at)->format('d M Y') }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $ret->supplier?->name ?? 'Vendor' }}</div>
                            <div class="text-xs text-zinc-400">{{ $ret->store?->name ?? 'Semua Cabang' }}</div>
                        </td>
                        <td class="px-6 py-4 font-mono text-xs">
                            <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $ret->goodsReceivedNote?->grn_number ?? 'GRN #' . $ret->goods_received_note_id }}</div>
                            <div class="text-zinc-400">SJ: {{ $ret->goodsReceivedNote?->delivery_order_number }}</div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ $ret->reason?->label() ?? $ret->reason ?? '-' }}</span>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($ret->debitNote)
                                <div class="font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">{{ $ret->debitNote->debit_note_number }}</div>
                                <div class="text-[11px] text-zinc-500">Rp {{ number_format((float) $ret->debitNote->total_amount, 2) }}</div>
                            @else
                                <span class="text-xs text-zinc-400 italic">Belum terbit</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            @php
                                $retStatusVal = $ret->status?->value ?? (string) $ret->status;
                                $statusVariant = match($retStatusVal) {
                                    'POSTED', 'posted' => 'info',
                                    'COMPLETED', 'completed' => 'success',
                                    'CANCELLED', 'cancelled' => 'danger',
                                    default => 'neutral'
                                };
                            @endphp
                            <x-ui.badge :variant="$statusVariant">
                                {{ $ret->status?->label() ?? $retStatusVal }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <x-ui.button
                                    size="icon"
                                    variant="ghost"
                                    wire:click="viewReturn({{ $ret->id }})"
                                    title="Lihat Detail Retur"
                                    aria-label="Detail">
                                    <span class="material-symbols-rounded text-base text-zinc-600 dark:text-zinc-400">visibility</span>
                                </x-ui.button>
                                @if($ret->isDraft())
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="postReturn({{ $ret->id }})"
                                        title="Posting Retur (Keluarkan Stok & Terbitkan Nota Debit)"
                                        aria-label="Posting">
                                        <span class="material-symbols-rounded text-base text-emerald-600">publish</span>
                                    </x-ui.button>
                                @endif
                                @if($ret->debitNote && $ret->debitNote->getRemainingUnappliedAmount() > 0)
                                    <x-ui.button
                                        size="icon"
                                        variant="ghost"
                                        wire:click="openApplyModal({{ $ret->debitNote->id }})"
                                        title="Potongkan Nota Debit ke Faktur Pemasok"
                                        aria-label="Potong Faktur">
                                        <span class="material-symbols-rounded text-base text-amber-600">credit_card</span>
                                    </x-ui.button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center justify-center">
                                <span class="material-symbols-rounded text-5xl text-zinc-300 dark:text-zinc-700">keyboard_return</span>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">Belum ada retur pembelian</h3>
                                <p class="mt-1 text-xs text-zinc-500">Buat retur barang fisik ke vendor untuk pengadaan yang rusak atau bermasalah.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($returns->hasPages())
        <div class="px-6 py-4 border-t border-zinc-200 dark:border-zinc-800">
            {{ $returns->links() }}
        </div>
        @endif
    </div>

    <!-- Modal Buat Retur Baru -->
    <x-ui.modal wire:model="showCreateModal" maxWidth="4xl">
        <x-slot:title>
            Penerbitan Retur Pembelian (Purchase Return)
        </x-slot:title>

        <x-slot:content>
            <form id="createReturnForm" wire:submit="saveDraftReturn" class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/40 border border-zinc-200 dark:border-zinc-800">
                    <div class="md:col-span-2">
                        <x-ui.label for="selectedGrnId">Pilih Penerimaan Barang (GRN) Asal Retur *</x-ui.label>
                        <x-ui.select id="selectedGrnId" wire:model.live="selectedGrnId" required>
                            <option value="">-- Pilih Dokumen GRN Terposting --</option>
                            @foreach($availableGrns as $grn)
                                <option value="{{ $grn->id }}">{{ $grn->grn_number }} &bull; {{ $grn->purchaseOrder?->supplier?->name }} (SJ: {{ $grn->delivery_order_number }})</option>
                            @endforeach
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('selectedGrnId')" class="mt-1" />
                    </div>

                    <div>
                        <x-ui.label for="reason">Alasan Retur *</x-ui.label>
                        <x-ui.select id="reason" wire:model="reason" required>
                            <option value="DAMAGED">Barang Rusak (Damaged)</option>
                            <option value="DEFECTIVE">Cacat Kualitas (Defective)</option>
                            <option value="OVER_SHIPMENT">Kelebihan Kirim (Over-Shipment)</option>
                            <option value="INCORRECT_ITEM">Salah Barang (Incorrect Item)</option>
                            <option value="OTHER">Lainnya</option>
                        </x-ui.select>
                        <x-input-error :messages="$errors->get('reason')" class="mt-1" />
                    </div>
                </div>

                <!-- Lines Table -->
                @if(count($returnLines) > 0)
                <div class="space-y-3">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Tentukan Kuantitas Barang yang Dikembalikan</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-100 dark:bg-zinc-800/80 font-semibold text-zinc-600 dark:text-zinc-300">
                                <tr>
                                    <th class="px-3 py-2">Nama Barang</th>
                                    <th class="px-3 py-2 w-20">Satuan</th>
                                    <th class="px-3 py-2 w-28 text-right">Diterima GRN</th>
                                    <th class="px-3 py-2 w-32 text-right">Kuantitas Retur *</th>
                                    <th class="px-3 py-2 w-32 text-right">Biaya Satuan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($returnLines as $i => $line)
                                <tr>
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-zinc-800 dark:text-zinc-200">{{ $line['item_name'] }}</div>
                                        <div class="text-[10px] text-zinc-400 uppercase">{{ $line['item_type'] }}</div>
                                    </td>
                                    <td class="px-3 py-2 font-mono uppercase">{{ $line['purchase_uom'] }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-emerald-600">{{ (float) $line['accepted_qty'] }}</td>
                                    <td class="px-3 py-2">
                                        <input type="number" step="0.0001" min="0" max="{{ $line['accepted_qty'] }}" wire:model="returnLines.{{ $i }}.return_quantity" class="w-full text-xs rounded-lg border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 py-1 text-right font-bold text-rose-600" />
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-zinc-500">
                                        Rp {{ number_format((float) $line['unit_cost'], 2) }}
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @elseif($selectedGrnId)
                <div class="p-6 text-center text-xs text-zinc-400 border border-dashed rounded-xl">
                    Dokumen GRN ini tidak memiliki barang yang dapat diretur.
                </div>
                @endif

                <div>
                    <x-ui.label for="notes">Catatan & Keterangan Retur</x-ui.label>
                    <textarea id="notes" wire:model="notes" rows="2" placeholder="Nomor resi pengiriman kembali, berita acara kerusakan..." class="mt-1 w-full rounded-xl border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-3 py-2 text-sm"></textarea>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showCreateModal', false)">Batal</x-ui.button>
                <x-ui.button type="submit" form="createReturnForm" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove>Simpan Draf Retur</span>
                    <span wire:loading>Menyimpan...</span>
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Detail Retur & Nota Debit -->
    <x-ui.modal wire:model="showDetailModal" maxWidth="3xl">
        <x-slot:title>
            @if($selectedReturn)
                <div>
                    <span class="font-mono">{{ $selectedReturn->return_number }}</span>
                    <span class="ml-2">
                        <x-ui.badge variant="primary">{{ $selectedReturn->status?->label() ?? $selectedReturn->status }}</x-ui.badge>
                    </span>
                </div>
            @else
                Detail Retur Pembelian
            @endif
        </x-slot:title>

        <x-slot:content>
            @if($selectedReturn)
            <div class="space-y-6">
                <!-- Info Header -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 rounded-xl bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 text-xs">
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Pemasok</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedReturn->supplier?->name }}</div>
                        <div class="text-zinc-500 font-mono">{{ $selectedReturn->supplier?->code }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Dokumen GRN</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5 font-mono">{{ $selectedReturn->goodsReceivedNote?->grn_number }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Alasan</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedReturn->reason?->label() ?? $selectedReturn->reason }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-400 uppercase font-bold text-[10px]">Cabang / Gudang</div>
                        <div class="font-semibold text-zinc-800 dark:text-zinc-200 mt-0.5">{{ $selectedReturn->store?->name }}</div>
                    </div>
                </div>

                <!-- Lines Table -->
                <div class="space-y-2">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500">Rincian Barang yang Dikembalikan</h4>
                    <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-800 rounded-xl">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-zinc-50 dark:bg-zinc-900/50 font-semibold text-zinc-500 border-b border-zinc-200 dark:border-zinc-800">
                                <tr>
                                    <th class="px-4 py-2.5">Item</th>
                                    <th class="px-4 py-2.5">Satuan</th>
                                    <th class="px-4 py-2.5 text-right">Kuantitas Retur</th>
                                    <th class="px-4 py-2.5 text-right">Biaya Satuan</th>
                                    <th class="px-4 py-2.5 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach($selectedReturn->lines as $line)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                        {{ $line->purchasable?->product_name ?? $line->purchasable?->name ?? 'Item #' . $line->purchasable_id }}
                                    </td>
                                    <td class="px-4 py-2.5 font-mono uppercase">{{ $line->purchase_uom }}</td>
                                    <td class="px-4 py-2.5 text-right font-bold text-rose-600">{{ (float) $line->return_quantity }}</td>
                                    <td class="px-4 py-2.5 text-right">Rp {{ number_format((float) $line->unit_cost, 2) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold">Rp {{ number_format((float) $line->subtotal, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Debit Note Section -->
                @if($selectedReturn->debitNote)
                <div class="p-4 rounded-xl bg-emerald-50/50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold uppercase text-emerald-800 dark:text-emerald-300">Nota Debit (Debit Note) Diterbitkan</span>
                            <div class="font-mono text-sm font-bold text-emerald-900 dark:text-emerald-200">{{ $selectedReturn->debitNote->debit_note_number }}</div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-zinc-500">Total Kredit Tagihan:</span>
                            <div class="text-base font-bold text-emerald-700 dark:text-emerald-400">Rp {{ number_format((float) $selectedReturn->debitNote->total_amount, 2) }}</div>
                        </div>
                    </div>

                    <div class="text-xs text-emerald-700 dark:text-emerald-400">
                        Sisa Belum Dipotongkan: <strong>Rp {{ number_format((float) $selectedReturn->debitNote->getRemainingUnappliedAmount(), 2) }}</strong>
                    </div>

                    @if($selectedReturn->debitNote->getRemainingUnappliedAmount() > 0)
                        <div class="pt-2">
                            <x-ui.button type="button" size="sm" variant="primary" wire:click="openApplyModal({{ $selectedReturn->debitNote->id }})">
                                Potongkan ke Faktur Pemasok
                            </x-ui.button>
                        </div>
                    @endif
                </div>
                @endif
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex items-center justify-between">
                <div>
                    @if($selectedReturn && $selectedReturn->isDraft())
                        <x-ui.button type="button" variant="primary" wire:click="postReturn({{ $selectedReturn->id }})">
                            Posting Retur (Keluarkan Stok & Terbitkan Nota Debit)
                        </x-ui.button>
                    @endif
                </div>
                <x-ui.button type="button" variant="secondary" wire:click="$set('showDetailModal', false)">Tutup</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>

    <!-- Modal Alokasi Debit Note ke Faktur Pemasok -->
    <x-ui.modal wire:model="showApplyModal" maxWidth="md">
        <x-slot:title>
            Alokasi Nota Debit ke Faktur Pemasok
        </x-slot:title>

        <x-slot:content>
            @if($selectedDebitNote)
            <div class="space-y-4">
                <p class="text-xs text-zinc-600 dark:text-zinc-300">
                    Pilih faktur tagihan vendor yang akan dipotong pembayarannya oleh Nota Debit <strong>{{ $selectedDebitNote->debit_note_number }}</strong>.
                </p>

                <div>
                    <x-ui.label for="applyInvoiceId">Pilih Faktur Pemasok *</x-ui.label>
                    <x-ui.select id="applyInvoiceId" wire:model="applyInvoiceId" required>
                        <option value="">-- Pilih Faktur --</option>
                        @foreach($availableInvoices as $inv)
                            <option value="{{ $inv->id }}">{{ $inv->supplier_invoice_number }} (Total: Rp {{ number_format((float) $inv->total_amount, 0) }})</option>
                        @endforeach
                    </x-ui.select>
                    <x-input-error :messages="$errors->get('applyInvoiceId')" class="mt-1" />
                </div>

                <div>
                    <x-ui.label for="applyAmount">Nominal Alokasi (Rp) *</x-ui.label>
                    <x-ui.input type="number" step="0.01" min="0.01" id="applyAmount" wire:model="applyAmount" required />
                    <x-input-error :messages="$errors->get('applyAmount')" class="mt-1" />
                </div>

                <div>
                    <x-ui.label for="applyNotes">Catatan Alokasi</x-ui.label>
                    <x-ui.input type="text" id="applyNotes" wire:model="applyNotes" placeholder="Potongan retur atas barang rusak" />
                </div>
            </div>
            @endif
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showApplyModal', false)">Batal</x-ui.button>
                <x-ui.button type="button" variant="primary" wire:click="applyDebitNote">Simpan Alokasi</x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
