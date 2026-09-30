<div>
    <div class="mb-6 flex flex-col items-start gap-1">
        <h1 class="text-xl font-bold text-apeiron-ink tracking-tight">Integrasi Channel</h1>
        <p class="text-sm font-medium text-apeiron-muted">Kelola pemetaan SKU dari platform eksternal ke produk internal.</p>
    </div>

    @if (session()->has('message'))
        <x-alert type="success" class="mb-6">{{ session('message') }}</x-alert>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Form mapping --}}
        <div class="card-surface p-6 lg:col-span-1 h-fit">
            <h2 class="text-sm font-semibold text-apeiron-ink mb-5 tracking-tight border-b border-apeiron-border pb-3">Tambah Pemetaan Baru</h2>

            <form wire:submit="saveMapping" class="flex flex-col gap-4">
                <div>
                    <x-form-label for="mapping-provider">Provider</x-form-label>
                    <select id="mapping-provider" wire:model="provider"
                        class="mt-1 block w-full border border-apeiron-border bg-white rounded-xl text-sm text-apeiron-ink px-3 py-2.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-700 focus:border-primary-700 transition-all">
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
                        class="mt-1 block w-full border border-apeiron-border bg-white rounded-xl text-sm text-apeiron-ink px-3 py-2.5 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-700 focus:border-primary-700 transition-all">
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
            <div class="px-6 py-4 border-b border-apeiron-border bg-apeiron-surface">
                <h2 class="text-sm font-semibold text-apeiron-ink tracking-tight">Daftar Pemetaan Aktif</h2>
            </div>
            <div class="overflow-x-auto flex-1">
                <x-data-table>
                    <x-slot:head>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Provider</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">SKU Eksternal</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Produk Internal</th>
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Aksi</th>
                    </x-slot:head>
                    
                    @forelse($mappings as $mapping)
                        <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                            <td class="px-6 py-4">
                                <x-badge type="secondary">{{ $mapping->provider }}</x-badge>
                            </td>
                            <td class="px-6 py-4 text-sm font-semibold text-apeiron-ink">
                                {{ $mapping->external_product_id }}
                            </td>
                            <td class="px-6 py-4 text-sm text-[#787774]">
                                {{ $mapping->product->product_name ?? 'Produk Dihapus' }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button type="button"
                                    data-swal-delete
                                    data-swal-title="Hapus Pemetaan?"
                                    data-swal-text="SKU {{ $mapping->external_product_id }} akan dihapus. Order baru dengan SKU ini akan gagal sampai dipetakan ulang."
                                    data-wire-action="deleteMapping({{ $mapping->id }})"
                                    title="Hapus Pemetaan"
                                    class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                        <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">link_off</span>
                                    </div>
                                    <p class="font-medium">Belum ada pemetaan sku channel.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </x-data-table>
            </div>
            <div class="p-4 border-t border-apeiron-border shrink-0">
                {{ $mappings->links() }}
            </div>
        </div>
    </div>
</div>
