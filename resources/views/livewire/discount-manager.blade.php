<div class="space-y-6" data-gsap="page-enter">
    <!-- Header -->
    <x-erp.page-header
        title="Manajemen Diskon & Promo"
        description="Kelola diskon produk, kuota promosi, voucher kasir, dan penumpukan diskon (stackable).">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item href="{{ route('products.index') }}">Katalog</x-ui.breadcrumbs.item>
            <x-ui.breadcrumbs.item active>Diskon & Promo</x-ui.breadcrumbs.item>
        </x-slot:breadcrumbs>

        <x-slot:actions>
            <x-ui.button variant="primary" type="button" wire:click="create" class="gap-2">
                <span class="material-symbols-rounded text-base">add</span>
                Tambah Diskon
            </x-ui.button>
        </x-slot:actions>
    </x-erp.page-header>

    @if (session()->has('message'))
        <x-ui.alert type="success" title="Berhasil" class="rounded-2xl">
            {{ session('message') }}
        </x-ui.alert>
    @endif

    <!-- Toolbar & Search -->
    <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
        <div class="relative w-full sm:w-80">
            <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Cari Diskon..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
        </div>
    </div>

    <!-- Data Table -->
    <div class="card-surface overflow-hidden rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[#E5E7EB] dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 text-xs font-semibold text-zinc-500 uppercase tracking-wider">
                        <th class="px-6 py-4">Nama & Kode</th>
                        <th class="px-6 py-4 text-right">Tipe & Nilai</th>
                        <th class="px-6 py-4 text-right">Syarat Min. Belanja</th>
                        <th class="px-6 py-4 text-center">Kuota & Stackable</th>
                        <th class="px-6 py-4 text-center">Status & Masa Aktif</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#E5E7EB] dark:divide-zinc-800/70">
                    @forelse($discounts as $discount)
                        <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-apeiron-ink dark:text-white">{{ $discount->name }}</div>
                                <div class="text-xs text-zinc-400 font-mono mt-0.5">{{ $discount->code ?? 'Tanpa Kode' }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="font-semibold text-zinc-900 dark:text-white font-mono">
                                    {{ $discount->type === 'percentage' ? $discount->value . '%' : 'Rp ' . number_format((float) $discount->value, 0, ',', '.') }}
                                </div>
                                @if($discount->type === 'percentage' && $discount->max_discount_amount)
                                    <div class="text-[11px] text-zinc-400 font-mono mt-0.5">
                                        Maks: Rp {{ number_format((float) $discount->max_discount_amount, 0, ',', '.') }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right text-xs text-zinc-600 dark:text-zinc-400 font-mono">
                                Rp {{ number_format((float) $discount->min_purchase_amount, 0, ',', '.') }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="text-xs font-mono font-bold text-zinc-800 dark:text-zinc-200">
                                    {{ $discount->redemptions_count }} / {{ $discount->usage_limit ?? '∞' }}
                                </div>
                                <div class="mt-1">
                                    <x-ui.badge variant="{{ $discount->is_stackable ? 'info' : 'neutral' }}" size="xs">
                                        {{ $discount->is_stackable ? 'Stackable' : 'Tunggal' }}
                                    </x-ui.badge>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <x-ui.badge variant="{{ $discount->is_active ? 'success' : 'neutral' }}" size="sm">
                                    {{ $discount->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-ui.badge>
                                @if($discount->valid_from || $discount->valid_until)
                                    <div class="text-[11px] text-zinc-400 mt-1">
                                        {{ $discount->valid_from ? $discount->valid_from->format('d/m/y') : '∞' }} – {{ $discount->valid_until ? $discount->valid_until->format('d/m/y') : '∞' }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button"
                                        wire:click="edit({{ $discount->id }})"
                                        title="Edit Diskon"
                                        class="p-1.5 rounded-lg text-zinc-500 hover:text-blue-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                        <span class="material-symbols-rounded text-lg">edit</span>
                                    </button>
                                    <button type="button"
                                        wire:click="delete({{ $discount->id }})"
                                        wire:confirm="Diskon &quot;{{ $discount->name }}&quot; akan dihapus secara permanen. Lanjutkan?"
                                        title="Hapus Diskon"
                                        class="p-1.5 rounded-lg text-zinc-500 hover:text-rose-600 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                                        <span class="material-symbols-rounded text-lg">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-zinc-400">
                                <span class="material-symbols-rounded text-4xl block mb-2">local_offer</span>
                                Belum ada diskon atau kode promo terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($discounts->hasPages())
            <div class="p-4 border-t border-[#E5E7EB] dark:border-zinc-800">
                {{ $discounts->links() }}
            </div>
        @endif
    </div>

    <!-- Modal Form Diskon -->
    <x-ui.modal wire:model="isModalOpen" name="discount-form" maxWidth="2xl">
        <x-slot:title>
            {{ $discountId ? 'Edit Diskon' : 'Tambah Diskon Baru' }}
        </x-slot:title>

        <x-slot:content>
            <form id="discountForm" wire:submit.prevent="store" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-1 md:col-span-2">
                        <x-ui.label for="discount-name">Nama Diskon *</x-ui.label>
                        <x-ui.input id="discount-name" type="text" wire:model="name" placeholder="Contoh: Diskon Pelanggan Setia" />
                        @error('name') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="discount-code">Kode Voucher</x-ui.label>
                        <x-ui.input id="discount-code" type="text" wire:model="code" placeholder="Kosongkan jika bukan voucher" class="uppercase font-mono" />
                        @error('code') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                        <p class="text-[11px] text-zinc-400 mt-1">Biarkan kosong untuk diskon otomatis/manual.</p>
                    </div>

                    <div>
                        <x-ui.label for="discount-type">Tipe Diskon *</x-ui.label>
                        <x-ui.select id="discount-type" wire:model.live="type">
                            <option value="percentage">Persentase (%)</option>
                            <option value="fixed">Nominal Tetap (Rp)</option>
                        </x-ui.select>
                        @error('type') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="discount-value">Nilai Diskon *</x-ui.label>
                        <x-ui.input id="discount-value" type="number" wire:model="value" />
                        @error('value') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    @if($type === 'percentage')
                    <div>
                        <x-ui.label for="discount-max">Maksimal Potongan (Rp)</x-ui.label>
                        <x-ui.input id="discount-max" type="number" wire:model="max_discount_amount" placeholder="Kosongkan jika tak terbatas" />
                        @error('max_discount_amount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                    @endif

                    <div>
                        <x-ui.label for="discount-min">Minimal Pembelian (Rp)</x-ui.label>
                        <x-ui.input id="discount-min" type="number" wire:model="min_purchase_amount" />
                        @error('min_purchase_amount') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="discount-usage-limit">Kuota Penggunaan (Usage Limit)</x-ui.label>
                        <x-ui.input id="discount-usage-limit" type="number" min="1" wire:model="usage_limit" placeholder="Kosongkan jika tak terbatas" />
                        @error('usage_limit') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="discount-from">Berlaku Mulai</x-ui.label>
                        <x-ui.input id="discount-from" type="datetime-local" wire:model="valid_from" />
                        @error('valid_from') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <x-ui.label for="discount-until">Berlaku Sampai</x-ui.label>
                        <x-ui.input id="discount-until" type="datetime-local" wire:model="valid_until" />
                        @error('valid_until') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="col-span-1 md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                        <label class="flex items-center gap-3 p-3.5 bg-zinc-50 dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 cursor-pointer">
                            <input wire:model="is_stackable" id="is_stackable" type="checkbox"
                                class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                            <div>
                                <span class="block text-xs font-semibold text-zinc-900 dark:text-zinc-100">Dapat Digabung (Stackable)</span>
                                <span class="block text-[11px] text-zinc-500">Izinkan voucher digabung dengan promo lain.</span>
                            </div>
                        </label>

                        <label class="flex items-center gap-3 p-3.5 bg-zinc-50 dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-800 cursor-pointer">
                            <input wire:model="is_active" id="is_active" type="checkbox"
                                class="rounded border-zinc-300 text-apeiron-primary shadow-xs focus:ring-apeiron-primary">
                            <div>
                                <span class="block text-xs font-semibold text-zinc-900 dark:text-zinc-100">Diskon Aktif</span>
                                <span class="block text-[11px] text-zinc-500">Diskon dapat digunakan saat transaksi.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </form>
        </x-slot:content>

        <x-slot:footer>
            <div class="flex justify-end gap-3">
                <x-ui.button variant="secondary" wire:click="closeModal">
                    Batal
                </x-ui.button>
                <x-ui.button variant="primary" wire:click="store" wire:loading.attr="disabled" wire:target="store">
                    <span wire:loading wire:target="store" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white mr-1.5"></span>
                    Simpan Diskon
                </x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
</div>
