<div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">Riwayat Stok Bahan Baku</h4>
            <p class="text-sm font-medium text-yovel-muted mt-1">Laporan mutasi pergerakan stok secara historis (Ledger Immutable)</p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <div class="relative w-full sm:w-48">
                <select wire:model.live="ingredient_id" class="form-select w-full pl-4 pr-8 py-2 rounded-2xl border border-yovel-border bg-white text-yovel-ink focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm appearance-none">
                    <option value="">Semua Bahan Baku</option>
                    @foreach($ingredients as $ing)
                        <option value="{{ $ing->id }}">{{ $ing->name }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-primary-400">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
            
            <div class="relative w-full sm:w-48">
                <select wire:model.live="type" class="form-select w-full pl-4 pr-8 py-2 rounded-2xl border border-yovel-border bg-white text-yovel-ink focus:outline-none focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink transition-all duration-200 shadow-sm appearance-none">
                    <option value="">Semua Tipe Mutasi</option>
                    <option value="sale_deduction">Penjualan (Keluar)</option>
                    <option value="purchase_receipt">Pembelian (Masuk)</option>
                    <option value="waste">Waste / Rusak (Keluar)</option>
                    <option value="adjustment">Koreksi Manual</option>
                    <option value="restore_compensation">Pembatalan (Masuk)</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-primary-400">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-surface overflow-hidden w-full">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-yovel-border bg-yovel-bg">
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Waktu</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Bahan Baku</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Tipe</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-right">Kuantitas</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Keterangan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-yovel-border bg-white">
                    @forelse ($movements as $movement)
                        <tr class="hover:bg-yovel-bg transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-yovel-ink">{{ $movement->created_at->format('d M Y') }}</div>
                                <div class="text-xs text-primary-400">{{ $movement->created_at->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-semibold text-yovel-ink">{{ $movement->ingredient->name ?? '-' }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $typeVal = $movement->type instanceof \App\Enums\IngredientStockMovementTypeEnum ? $movement->type->value : $movement->type;
                                    $typeStr = match($typeVal) {
                                        'sale_deduction' => 'Penjualan',
                                        'purchase_receipt' => 'Pembelian Masuk',
                                        'waste' => 'Barang Rusak',
                                        'adjustment' => 'Koreksi Manual',
                                        'restore_compensation' => 'Pembatalan Transaksi',
                                        default => ucwords(str_replace('_', ' ', $typeVal))
                                    };
                                    $typeColor = match($typeVal) {
                                        'purchase_receipt', 'restore_compensation' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'sale_deduction', 'waste' => 'bg-rose-50 text-rose-700 border-rose-200',
                                        'adjustment' => 'bg-amber-50 text-amber-700 border-amber-200',
                                        default => 'bg-yovel-bg text-gray-700 border-yovel-border'
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-xs font-medium border {{ $typeColor }}">
                                    {{ $typeStr }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                @if($movement->quantity > 0)
                                    <span class="text-sm font-semibold text-emerald-600">+{{ number_format($movement->quantity, 2) }} {{ $movement->ingredient->unit ?? '' }}</span>
                                @else
                                    <span class="text-sm font-semibold text-rose-600">{{ number_format($movement->quantity, 2) }} {{ $movement->ingredient->unit ?? '' }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm text-yovel-muted truncate max-w-xs block" title="{{ $movement->reason }}">
                                    {{ $movement->reason ?: '-' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-16 h-16 mb-4 rounded-full bg-yovel-bg flex items-center justify-center border border-yovel-border">
                                        <span class="material-symbols-rounded text-3xl text-primary-400">history</span>
                                    </div>
                                    <h3 class="text-base font-semibold text-yovel-ink mb-1">Belum Ada Riwayat</h3>
                                    <p class="text-sm text-yovel-muted max-w-sm mx-auto">Riwayat pergerakan stok akan muncul secara otomatis saat terjadi transaksi atau penyesuaian stok.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($movements->hasPages())
            <div class="px-6 py-4 border-t border-yovel-border">
                {{ $movements->links() }}
            </div>
        @endif
    </div>
</div>
