<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="mb-6" data-gsap="page-enter">
        <div class="flex items-center gap-2 text-sm text-[#787774] mb-2">
            <a href="{{ route('price-lists.index') }}" class="hover:text-apeiron-ink" wire:navigate>Daftar Harga</a>
            <span>/</span>
            <span class="text-apeiron-ink font-medium">Buat Baru</span>
        </div>
        <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Buat Daftar Harga & Aturan Grosir</h4>
    </div>

    @if($errors->any())
        <x-ui.alert variant="destructive" class="mb-6">
            <ul class="list-disc pl-5 space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <div class="card-surface p-6" x-data="{
        type: 'standard',
        items: [
            { product_id: '', min_quantity: 1, price: 0 }
        ],
        addItem() {
            this.items.push({ product_id: '', min_quantity: 1, price: 0 });
        },
        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
            }
        }
    }">
        <form action="{{ route('price-lists.store') }}" method="POST" class="space-y-6">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pb-6 border-b border-[#E5E7EB]">
                <div>
                    <x-form-label for="code">Kode Daftar Harga <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" required maxlength="64"
                        placeholder="misal: PL-GROSIR-01"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm uppercase focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    <x-input-error :messages="$errors->get('code')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="name">Nama Aturan Harga <span class="text-danger-600">*</span></x-form-label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="150"
                        placeholder="misal: Harga Grosir Min. 10 Pcs"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-form-label for="type">Tipe Aturan / Sasaran <span class="text-danger-600">*</span></x-form-label>
                    <select id="type" name="type" x-model="type" required
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="standard">Standar (Global / Umum)</option>
                        <option value="wholesale">Grosir Bertingkat (Volume Qty)</option>
                        <option value="customer_tier">Tier Anggota Loyalitas (Loyalty Tier)</option>
                        <option value="customer_specific">Kontrak Khusus Pelanggan Spesifik</option>
                        <option value="branch">Khusus Cabang Tertentu</option>
                        <option value="channel">Khusus Kanal Penjualan (Delivery Online, dll.)</option>
                    </select>
                </div>

                <div>
                    <x-form-label for="priority">Prioritas Evaluasi (Angka Lebih Kecil = Lebih Didahulukan)</x-form-label>
                    <input type="number" id="priority" name="priority" value="{{ old('priority', 10) }}" min="0" required
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                {{-- Target Dinamis --}}
                <div x-show="type === 'customer_specific'" x-cloak>
                    <x-form-label for="customer_id">Pilih Pelanggan Kontrak</x-form-label>
                    <select id="customer_id" name="customer_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Pilih Pelanggan --</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->phone ?? 'Tanpa Telp' }})</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'customer_tier'" x-cloak>
                    <x-form-label for="loyalty_tier_id">Pilih Tier Loyalitas</x-form-label>
                    <select id="loyalty_tier_id" name="loyalty_tier_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Pilih Tier --</option>
                        @foreach ($loyaltyTiers as $t)
                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'branch'" x-cloak>
                    <x-form-label for="store_id">Pilih Cabang Sasaran</x-form-label>
                    <select id="store_id" name="store_id"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        <option value="">-- Pilih Cabang --</option>
                        @foreach ($stores as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'channel'" x-cloak>
                    <x-form-label for="channel">Kanal Penjualan (misal: online_delivery, pos, grabfood)</x-form-label>
                    <input type="text" id="channel" name="channel" placeholder="online_delivery"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div>
                    <x-form-label for="valid_from">Mulai Berlaku (Opsional)</x-form-label>
                    <input type="date" id="valid_from" name="valid_from" value="{{ old('valid_from') }}"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div>
                    <x-form-label for="valid_until">Berakhir Pada (Opsional)</x-form-label>
                    <input type="date" id="valid_until" name="valid_until" value="{{ old('valid_until') }}"
                        class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                </div>

                <div class="flex items-center gap-2 pt-2 md:col-span-2">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" id="is_active" name="is_active" value="1" checked
                        class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                    <label for="is_active" class="text-sm font-medium text-apeiron-ink">Daftar harga ini aktif</label>
                </div>
            </div>

            {{-- Tabel Rincian Harga Produk --}}
            <div>
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h5 class="text-base font-bold text-apeiron-ink">Rincian Komoditas & Tangga Harga</h5>
                        <p class="text-xs text-[#787774]">Tentukan produk, batas minimal kuantitas pembelian (Tier Grosir), dan harga per unit.</p>
                    </div>
                    <button type="button" @click="addItem()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[#F7F7F5] hover:bg-[#E9E9E7] text-xs font-semibold text-apeiron-ink transition-colors">
                        <span class="material-symbols-rounded text-[18px]">add</span> Tambah Baris
                    </button>
                </div>

                <div class="overflow-x-auto border border-[#E5E7EB] rounded-2xl">
                    <table class="table-standard w-full min-w-full text-left text-sm">
                        <thead class="bg-[#F7F7F5] border-b border-[#E5E7EB] text-xs text-[#787774] uppercase">
                            <tr>
                                <th class="px-4 py-3">Produk</th>
                                <th class="px-4 py-3 w-40 text-right">Min. Beli (Qty)</th>
                                <th class="px-4 py-3 w-48 text-right">Harga Satuan (Rp)</th>
                                <th class="px-4 py-3 w-16 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#E5E7EB]">
                            <template x-for="(item, index) in items" :key="index">
                                <tr>
                                    <td class="px-4 py-2.5">
                                        <select :name="'items[' + index + '][product_id]'" x-model="item.product_id" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                            <option value="">-- Pilih Produk --</option>
                                            @foreach ($products as $p)
                                                <option value="{{ $p->id }}">{{ $p->product_name }} (Rp {{ number_format($p->product_price, 0, ',', '.') }})</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][min_quantity]'" x-model="item.min_quantity" min="1" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm text-right tabular-nums focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <input type="number" :name="'items[' + index + '][price]'" x-model="item.price" min="0" step="0.01" required
                                            class="w-full h-9 px-3 rounded-lg border border-[#E5E7EB] text-sm text-right tabular-nums focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                    </td>
                                    <td class="px-4 py-2.5 text-center">
                                        <button type="button" @click="removeItem(index)"
                                            class="text-danger-500 hover:text-danger-700 p-1 rounded-lg hover:bg-danger-50 transition-colors">
                                            <span class="material-symbols-rounded text-[20px]">delete</span>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-6 border-t border-[#E5E7EB]">
                <a href="{{ route('price-lists.index') }}" wire:navigate>
                    <x-ui.button variant="secondary" type="button">Batal</x-ui.button>
                </a>
                <x-ui.button variant="primary" type="submit" x-on:click="window.erpMotion?.pulseHighlight($el)">Simpan Daftar Harga</x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
