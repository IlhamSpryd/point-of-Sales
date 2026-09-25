<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-yovel-ink tracking-tight">Manajemen Diskon &amp; Promo</h4>
            <p class="text-sm font-medium text-yovel-muted mt-1">Kelola diskon dan kode promo sistem</p>
        </div>

        <div class="flex items-center gap-3 w-full sm:w-auto">
            <div class="w-full sm:w-64">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Cari Diskon..." />
            </div>
            <x-button variant="primary" type="button" wire:click="create" class="w-full sm:w-auto">
                <span class="material-symbols-rounded text-[18px]">add</span> Tambah Diskon
            </x-button>
        </div>
    </div>

    @if (session()->has('message'))
        <x-alert type="success">{{ session('message') }}</x-alert>
    @endif

    <div class="card-surface flex flex-col flex-1 min-h-0">

        <div class="hidden lg:block overflow-auto flex-1 table-scroll-shadow">
            <table class="data-table relative w-full">
                <thead class="sticky top-0 z-10 shadow-sm">
                    <tr class="bg-yovel-surface">
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-left">Nama &amp; Kode</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-left">Tipe &amp; Nilai</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-left">Syarat</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-left">Status &amp; Masa Aktif</th>
                        <th class="px-6 py-4 text-xs font-semibold text-yovel-muted uppercase tracking-wider text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-yovel-border bg-white">
                    @forelse($discounts as $discount)
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-yovel-ink">{{ $discount->name }}</div>
                                <div class="text-xs text-yovel-muted font-mono mt-0.5">{{ $discount->code ?? 'Tanpa Kode' }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-yovel-ink">
                                    {{ $discount->type === 'percentage' ? $discount->value . '%' : 'Rp ' . number_format($discount->value, 0, ',', '.') }}
                                </div>
                                <div class="text-xs text-yovel-muted mt-0.5">
                                    {{ $discount->type === 'percentage' && $discount->max_discount_amount ? 'Maks: Rp ' . number_format($discount->max_discount_amount, 0, ',', '.') : '' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-yovel-ink">Min. Beli: Rp {{ number_format($discount->min_purchase_amount, 0, ',', '.') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-badge :type="$discount->is_active ? 'success' : 'secondary'">
                                    {{ $discount->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-badge>
                                @if($discount->valid_from || $discount->valid_until)
                                    <div class="text-xs text-yovel-muted mt-1.5">
                                        {{ $discount->valid_from ? $discount->valid_from->format('d M y') : 'Seterusnya' }} – {{ $discount->valid_until ? $discount->valid_until->format('d M y') : 'Seterusnya' }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button"
                                        wire:click="edit({{ $discount->id }})"
                                        title="Edit Diskon"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-yovel-muted hover:bg-primary-50 hover:text-yovel-ink transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-700">
                                        <span class="material-symbols-rounded text-[18px]">edit</span>
                                    </button>
                                    <button type="button"
                                        data-swal-delete
                                        data-swal-title="Hapus Diskon?"
                                        data-swal-text="Diskon &quot;{{ $discount->name }}&quot; akan dihapus secara permanen."
                                        data-wire-action="delete({{ $discount->id }})"
                                        title="Hapus Diskon"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-yovel-muted hover:bg-danger-50 hover:text-danger-700 transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger-600">
                                        <span class="material-symbols-rounded text-[18px]">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-empty-state icon="local_offer" title="Belum ada diskon." description="Tambahkan diskon atau kode promo untuk mulai mengelola program diskon." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-yovel-border shrink-0">
            {{ $discounts->links() }}
        </div>
    </div>

    {{-- Modal Form menggunakan x-modal (focus-trap + bottom-sheet mobile gratis) --}}
    <x-modal name="discount-form" :show="$isModalOpen" max-width="2xl" sheet focusable>
        <form wire:submit.prevent="store">
            {{-- Header --}}
            <div class="px-6 py-5 border-b border-yovel-border flex items-center justify-between bg-white">
                <h3 class="text-lg font-bold text-yovel-ink tracking-tight" id="discount-modal-title">
                    {{ $discountId ? 'Edit Diskon' : 'Tambah Diskon Baru' }}
                </h3>
                <button type="button" x-on:click="$dispatch('close')" aria-label="Tutup"
                    class="min-w-[44px] min-h-[44px] flex items-center justify-center rounded-xl text-yovel-muted hover:bg-yovel-surface hover:text-yovel-ink transition-all duration-200 active:scale-90">
                    <span class="material-symbols-rounded text-[22px]">close</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="px-6 py-5 bg-yovel-bg overflow-y-auto max-h-[70vh]">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    {{-- Nama Diskon --}}
                    <div class="col-span-1 md:col-span-2">
                        <x-form-label for="discount-name">Nama Diskon <span class="text-danger-600">*</span></x-form-label>
                        <x-form-input id="discount-name" type="text" wire:model="name" class="mt-1" />
                        @error('name') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Kode Diskon --}}
                    <div>
                        <x-form-label for="discount-code">Kode Voucher</x-form-label>
                        <x-form-input id="discount-code" type="text" wire:model="code" placeholder="Kosongkan jika bukan voucher" class="mt-1" />
                        @error('code') <x-input-error :messages="$message" class="mt-1" /> @enderror
                        <p class="text-xs text-yovel-muted mt-1.5">Biarkan kosong untuk diskon manual.</p>
                    </div>

                    {{-- Tipe Diskon --}}
                    <div>
                        <x-form-label for="discount-type">Tipe Diskon <span class="text-danger-600">*</span></x-form-label>
                        <select id="discount-type" wire:model.live="type"
                            class="mt-1 block w-full border border-yovel-border bg-white rounded-xl shadow-sm focus:outline-none focus:ring-2 focus:ring-primary-700 focus:border-primary-700 text-sm text-yovel-ink px-3 py-2.5 transition-all">
                            <option value="percentage">Persentase (%)</option>
                            <option value="fixed">Nominal Tetap (Rp)</option>
                        </select>
                        @error('type') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Nilai Diskon --}}
                    <div>
                        <x-form-label for="discount-value">Nilai Diskon <span class="text-danger-600">*</span></x-form-label>
                        <x-form-input id="discount-value" type="number" wire:model="value" class="mt-1" />
                        @error('value') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Maksimal Potongan --}}
                    @if($type === 'percentage')
                    <div>
                        <x-form-label for="discount-max">Maksimal Potongan (Rp)</x-form-label>
                        <x-form-input id="discount-max" type="number" wire:model="max_discount_amount" class="mt-1" />
                        @error('max_discount_amount') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>
                    @endif

                    {{-- Minimal Pembelian --}}
                    <div>
                        <x-form-label for="discount-min">Minimal Pembelian (Rp)</x-form-label>
                        <x-form-input id="discount-min" type="number" wire:model="min_purchase_amount" class="mt-1" />
                        @error('min_purchase_amount') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Tanggal Mulai --}}
                    <div>
                        <x-form-label for="discount-from">Berlaku Mulai</x-form-label>
                        <x-form-input id="discount-from" type="datetime-local" wire:model="valid_from" class="mt-1" />
                        @error('valid_from') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Tanggal Selesai --}}
                    <div>
                        <x-form-label for="discount-until">Berlaku Sampai</x-form-label>
                        <x-form-input id="discount-until" type="datetime-local" wire:model="valid_until" class="mt-1" />
                        @error('valid_until') <x-input-error :messages="$message" class="mt-1" /> @enderror
                    </div>

                    {{-- Status Aktif --}}
                    <div class="col-span-1 md:col-span-2">
                        <label class="flex items-center gap-3 p-4 bg-white rounded-xl border border-yovel-border cursor-pointer hover:bg-yovel-surface transition-colors">
                            <input wire:model="is_active" id="is_active" type="checkbox"
                                class="w-4 h-4 rounded border-yovel-border text-primary-700 focus:ring-primary-700">
                            <div>
                                <span class="block text-sm font-semibold text-yovel-ink">Diskon Aktif</span>
                                <span class="block text-xs text-yovel-muted mt-0.5">Diskon ini dapat digunakan di kasir.</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 border-t border-yovel-border bg-white flex flex-row-reverse gap-3">
                <x-button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="store">
                    <span wire:loading wire:target="store" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    Simpan
                </x-button>
                <x-button type="button" variant="secondary" x-on:click="$dispatch('close')">
                    Batal
                </x-button>
            </div>
        </form>
    </x-modal>
</div>
