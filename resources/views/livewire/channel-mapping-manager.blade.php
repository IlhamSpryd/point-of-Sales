<div>
    <div class="mb-6 flex flex-col items-start gap-1">
        <h1 class="text-xl font-bold text-yovel-ink tracking-tight">Integrasi Channel</h1>
        <p class="text-sm font-medium text-yovel-muted">Kelola pemetaan SKU dari platform eksternal ke produk internal.</p>
    </div>

    @if (session()->has('message'))
        <x-alert type="success" class="mb-6">{{ session('message') }}</x-alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Form mapping --}}
        <div class="card-surface p-6 lg:col-span-1 h-fit">
            <h2 class="text-sm font-semibold text-yovel-ink mb-5 tracking-tight border-b border-yovel-border pb-3">Tambah Pemetaan Baru</h2>

            <form wire:submit="saveMapping" class="flex flex-col gap-4">
                <div>
                    <x-form-label for="mapping-provider">Provider</x-form-label>
                    <select id="mapping-provider" wire:model="provider"
                        class="mt-1 block w-full border border-yovel-border bg-white rounded-xl text-sm text-yovel-ink px-3 py-2.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-700 focus:border-primary-700 transition-all">
                        <option value="grabfood">GrabFood</option>
                        <option value="gofood">GoFood</option>
                    </select>
                </div>

                <div>
                    <x-form-label for="mapping-external-id">SKU Provider (External ID)</x-form-label>
                    <x-form-input id="mapping-external-id" type="text" wire:model="externalProductId" placeholder="Misal: GF-1234" class="mt-1" name="externalProductId" />
                    @error('externalProductId') <x-input-error :messages="$message" class="mt-1" /> @enderror
                </div>

                <div>
                    <x-form-label for="mapping-product">Produk Internal</x-form-label>
                    <select id="mapping-product" wire:model="productId"
                        class="mt-1 block w-full border border-yovel-border bg-white rounded-xl text-sm text-yovel-ink px-3 py-2.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-700 focus:border-primary-700 transition-all">
                        <option value="">-- Pilih Produk --</option>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->product_name }}</option>
                        @endforeach
                    </select>
                    @error('productId') <x-input-error :messages="$message" class="mt-1" /> @enderror
                </div>

                <x-button type="submit" variant="primary" class="mt-2 w-full"
                    wire:loading.attr="disabled" wire:target="saveMapping">
                    <span wire:loading wire:target="saveMapping" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    Simpan Pemetaan
                </x-button>
            </form>
        </div>

        {{-- Tabel mapping --}}
        <div class="card-surface overflow-hidden lg:col-span-2 flex flex-col">
            <div class="px-6 py-4 border-b border-yovel-border bg-yovel-surface">
                <h2 class="text-sm font-semibold text-yovel-ink tracking-tight">Daftar Pemetaan Aktif</h2>
            </div>
            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-yovel-surface border-b border-yovel-border">
                            <th class="px-6 py-3 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Provider</th>
                            <th class="px-6 py-3 text-xs font-semibold text-yovel-muted uppercase tracking-wider">SKU Eksternal</th>
                            <th class="px-6 py-3 text-xs font-semibold text-yovel-muted uppercase tracking-wider">Produk Internal</th>
                            <th class="px-6 py-3 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-yovel-border">
                        @forelse($mappings as $mapping)
                            <tr class="hover:bg-yovel-surface transition-colors">
                                <td class="px-6 py-4">
                                    <x-badge type="secondary">{{ $mapping->provider }}</x-badge>
                                </td>
                                <td class="px-6 py-4 text-sm font-semibold text-yovel-ink">
                                    {{ $mapping->external_product_id }}
                                </td>
                                <td class="px-6 py-4 text-sm text-yovel-muted">
                                    {{ $mapping->product->product_name ?? 'Produk Dihapus' }}
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button type="button"
                                        data-swal-delete
                                        data-swal-title="Hapus Pemetaan?"
                                        data-swal-text="SKU {{ $mapping->external_product_id }} akan dihapus. Order baru dengan SKU ini akan gagal sampai dipetakan ulang."
                                        data-wire-action="deleteMapping({{ $mapping->id }})"
                                        title="Hapus Pemetaan"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-yovel-muted hover:bg-danger-50 hover:text-danger-700 transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-600">
                                        <span class="material-symbols-rounded text-[18px]">delete</span>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center text-yovel-muted">
                                        <span class="material-symbols-rounded text-4xl mb-3 opacity-40">link_off</span>
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
