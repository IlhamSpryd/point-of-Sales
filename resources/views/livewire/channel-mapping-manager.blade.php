<div class="space-y-6" data-gsap="page-enter">
    <!-- Header -->
    <x-erp.page-header
        title="Integrasi Saluran (Channel Mapping)"
        description="Kelola pemetaan SKU dari platform pesan-antar eksternal (GrabFood, GoFood) ke data produk internal.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Katalog</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Integrasi Saluran</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>
    </x-erp.page-header>

    @if (session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form Pemetaan Baru -->
        <div class="card-surface p-6 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 lg:col-span-1 h-fit shadow-xs">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white mb-5 tracking-tight border-b border-[#E5E7EB] dark:border-zinc-800 pb-3 flex items-center gap-2">
                <span class="material-symbols-rounded text-apeiron-primary text-lg">add_link</span>
                Tambah Pemetaan Baru
            </h2>

            <form wire:submit="saveMapping" class="flex flex-col gap-4">
                <div>
                    <x-ui.label for="mapping-provider">Provider Saluran *</x-ui.label>
                    <x-ui.select id="mapping-provider" wire:model="provider">
                        <option value="grabfood">GrabFood</option>
                        <option value="gofood">GoFood</option>
                    </x-ui.select>
                </div>

                <div>
                    <x-ui.label for="mapping-external-id">SKU Provider (External Product ID) *</x-ui.label>
                    <x-ui.input id="mapping-external-id" type="text" wire:model="externalProductId" placeholder="Misal: GF-1234 atau SKU-GF-AYAM" class="font-mono text-sm" />
                    @error('externalProductId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <x-ui.label for="mapping-product">Produk Internal *</x-ui.label>
                    <x-ui.select id="mapping-product" wire:model="productId">
                        <option value="">-- Pilih Produk Internal --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                        @endforeach
                    </x-ui.select>
                    @error('productId') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <x-ui.button type="submit" variant="primary" class="mt-2 w-full justify-center" wire:loading.attr="disabled" wire:target="saveMapping">
                    <span wire:loading wire:target="saveMapping" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white mr-1.5"></span>
                    Simpan Pemetaan
                </x-ui.button>
            </form>
        </div>

        <!-- Tabel Daftar Pemetaan -->
        <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 lg:col-span-2 flex flex-col shadow-xs">
            <div class="px-6 py-4 border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex items-center justify-between">
                <h2 class="text-sm font-bold text-zinc-900 dark:text-white tracking-tight flex items-center gap-2">
                    <span class="material-symbols-rounded text-apeiron-primary text-lg">sync_alt</span>
                    Daftar Pemetaan Aktif
                </h2>
                <span class="text-xs text-zinc-400">Total: {{ $mappings->total() }} SKU</span>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/30 dark:bg-zinc-900/30 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                            <th class="px-6 py-3.5">Provider</th>
                            <th class="px-6 py-3.5">SKU Eksternal</th>
                            <th class="px-6 py-3.5">Produk Internal</th>
                            <th class="px-6 py-3.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                        @forelse($mappings as $mapping)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                                <td class="px-6 py-4">
                                    <x-ui.badge variant="{{ $mapping->provider === 'grabfood' ? 'success' : 'danger' }}" size="sm">
                                        {{ strtoupper($mapping->provider) }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-6 py-4 font-mono font-semibold text-zinc-900 dark:text-white text-xs">
                                    {{ $mapping->external_product_id }}
                                </td>
                                <td class="px-6 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $mapping->product->product_name ?? 'Produk Dihapus' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button type="button"
                                        wire:click="deleteMapping({{ $mapping->id }})"
                                        wire:confirm="SKU {{ $mapping->external_product_id }} akan dihapus. Pesanan baru dengan SKU ini akan gagal diproses sampai dipetakan ulang. Lanjutkan?"
                                        title="Hapus Pemetaan"
                                        class="p-1.5 rounded-lg text-zinc-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition">
                                        <span class="material-symbols-rounded text-lg">delete</span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-zinc-400">
                                    <span class="material-symbols-rounded text-4xl block mb-2">link_off</span>
                                    Belum ada pemetaan SKU kanal eksternal.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($mappings->hasPages())
                <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                    {{ $mappings->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
