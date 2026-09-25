<div>
    <div class="mb-6 flex flex-col items-start gap-1">
        <h1 class="text-2xl font-bold text-[#37352F] tracking-tight">Integrasi Channel</h1>
        <p class="text-sm font-medium text-[#787774]">Kelola pemetaan SKU dari platform eksternal ke produk internal.</p>
    </div>

    @if (session()->has('message'))
        <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-2xl text-sm shadow-sm flex items-center gap-2">
            <span class="material-symbols-rounded text-[18px]">check_circle</span>
            {{ session('message') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form mapping -->
        <div class="card-surface p-6 lg:col-span-1 h-fit">
            <h2 class="text-sm font-semibold text-[#37352F] mb-5 tracking-tight border-b border-yovel-surface pb-3">Tambah Pemetaan Baru</h2>
            
            <form wire:submit="saveMapping" class="flex flex-col gap-4">
                <div>
                    <label class="block text-xs font-medium text-[#787774] mb-1.5 uppercase tracking-wide">Provider</label>
                    <select wire:model="provider" class="w-full border-[#C4C3C0] focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink rounded-xl text-sm shadow-sm">
                        <option value="grabfood">GrabFood</option>
                        <option value="gofood">GoFood</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-xs font-medium text-[#787774] mb-1.5 uppercase tracking-wide">SKU Provider (External ID)</label>
                    <input type="text" wire:model="externalProductId" placeholder="Misal: GF-1234" class="w-full border-[#C4C3C0] focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink rounded-xl text-sm shadow-sm">
                    @error('externalProductId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                
                <div>
                    <label class="block text-xs font-medium text-[#787774] mb-1.5 uppercase tracking-wide">Produk Internal</label>
                    <select wire:model="productId" class="w-full border-[#C4C3C0] focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink rounded-xl text-sm shadow-sm">
                        <option value="">-- Pilih Produk --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                        @endforeach
                    </select>
                    @error('productId') <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span> @enderror
                </div>
                
                <button type="submit" class="mt-4 w-full bg-yovel-ink text-white hover:bg-gray-800 rounded-xl px-4 py-2.5 text-sm font-medium transition-colors shadow-sm">
                    Simpan Pemetaan
                </button>
            </form>
        </div>

        <!-- Tabel mapping -->
        <div class="card-surface overflow-hidden lg:col-span-2 flex flex-col">
            <div class="px-6 py-4 border-b border-[#E9E9E7] bg-[#F1F1EF]">
                <h2 class="text-sm font-semibold text-[#37352F] tracking-tight">Daftar Pemetaan Aktif</h2>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-[#F1F1EF] border-b border-[#E9E9E7]">
                            <th class="px-6 py-3 text-xs font-medium text-[#787774] uppercase tracking-wider">Provider</th>
                            <th class="px-6 py-3 text-xs font-medium text-[#787774] uppercase tracking-wider">SKU Eksternal</th>
                            <th class="px-6 py-3 text-xs font-medium text-[#787774] uppercase tracking-wider">Produk Internal</th>
                            <th class="px-6 py-3 text-xs font-medium text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-yovel-surface">
                        @forelse($mappings as $mapping)
                            <tr class="hover:bg-[#F1F1EF] transition-colors">
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[#F7F7F5] text-[#787774] capitalize border border-[#E9E9E7]">
                                        {{ $mapping->provider }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm font-medium text-[#37352F]">
                                    {{ $mapping->external_product_id }}
                                </td>
                                <td class="px-6 py-4 text-sm text-[#787774]">
                                    {{ $mapping->product->product_name ?? 'Produk Dihapus' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button wire:click="deleteMapping({{ $mapping->id }})"
                                            wire:confirm="Yakin hapus pemetaan SKU {{ $mapping->external_product_id }}? Order baru dengan SKU ini akan gagal sampai dipetakan ulang."
                                            class="text-red-600 hover:text-red-800 text-sm font-medium transition-colors">
                                        Hapus
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center text-[#787774]">
                                        <span class="material-symbols-rounded text-4xl text-primary-300 mb-3">link_off</span>
                                        <p class="text-sm font-medium">Belum ada pemetaan sku channel.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-yovel-border shrink-0">
                {{ $mappings->links() }}
            </div>
        </div>
    </div>
</div>
