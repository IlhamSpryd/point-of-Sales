<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="flex items-center justify-between mb-6" data-gsap="page-enter">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Tambah Produk</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambahkan produk baru ke dalam inventaris</p>
        </div>
        <a href="{{ route('products.index') }}" wire:navigate>
            <x-ui.button variant="outline" type="button" class="h-10">
                <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
            </x-ui.button>
        </a>
    </div>

    <div class="bg-white border border-[#E9E9E7] rounded-xl shadow-sm overflow-hidden w-full shrink-0">
        <form action="{{ route('products.store') }}" method="POST" enctype="multipart/form-data" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf

            <!-- Name & Category Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_name">Nama Produk</x-form-label>
                    <x-form-input type="text" id="product_name" name="product_name" value="{{ old('product_name') }}" 
                           placeholder="Masukkan nama produk" required class="{{ $errors->has('product_name') ? 'input-error' : '' }}" />
                    @error('product_name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>

                <div>
                    <x-form-label for="category_id">Kategori</x-form-label>
                    <div class="relative">
                        <select id="category_id" name="category_id" 
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('category_id') ? 'input-error' : '' }}" required>
                            <option value="">Pilih Kategori</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                    {{ $category->category_name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('category_id')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>
            </div>

            <!-- Brand & Unit (UoM) Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="brand_id">Merek / Brand (Opsional)</x-form-label>
                    <div class="relative">
                        <select id="brand_id" name="brand_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('brand_id') ? 'input-error' : '' }}">
                            <option value="">-- Tanpa Brand --</option>
                            @foreach($brands as $brand)
                                <option value="{{ $brand->id }}" {{ old('brand_id') == $brand->id ? 'selected' : '' }}>
                                    {{ $brand->brand_name ?? $brand->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('brand_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="base_uom_id">Satuan Ukur Dasar (UoM)</x-form-label>
                    <div class="relative">
                        <select id="base_uom_id" name="base_uom_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('base_uom_id') ? 'input-error' : '' }}">
                            <option value="">-- Pilih Satuan Ukur --</option>
                            @foreach($uoms as $uom)
                                <option value="{{ $uom->id }}" {{ old('base_uom_id') == $uom->id ? 'selected' : '' }}>
                                    {{ $uom->uom_name ?? $uom->name }} ({{ $uom->code }})
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('base_uom_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- SKU / Barcode Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_code">Kode SKU / Kode Produk (Opsional)</x-form-label>
                    <x-form-input type="text" id="product_code" name="product_code" value="{{ old('product_code') }}"
                           placeholder="Dibuat otomatis jika dikosongkan" class="{{ $errors->has('product_code') ? 'input-error' : '' }}" />
                    @error('product_code')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="barcode">Barcode (Opsional)</x-form-label>
                    <x-form-input type="text" id="barcode" name="barcode" value="{{ old('barcode') }}"
                           placeholder="Contoh: 899123456789" class="{{ $errors->has('barcode') ? 'input-error' : '' }}" />
                    @error('barcode')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Product Type & Status Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_type">Tipe Produk</x-form-label>
                    <div class="relative">
                        <select id="product_type" name="product_type"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('product_type') ? 'input-error' : '' }}">
                            <option value="standard" {{ old('product_type', 'standard') == 'standard' ? 'selected' : '' }}>Fisik Standar (Standard)</option>
                            <option value="service" {{ old('product_type') == 'service' ? 'selected' : '' }}>Layanan / Jasa (Service)</option>
                            <option value="non_inventory" {{ old('product_type') == 'non_inventory' ? 'selected' : '' }}>Non-Inventori (Non-Inventory)</option>
                            <option value="bundle" {{ old('product_type') == 'bundle' ? 'selected' : '' }}>Paket / Bundel (Bundle)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('product_type')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="status">Status Lifecycle</x-form-label>
                    <div class="relative">
                        <select id="status" name="status"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('status') ? 'input-error' : '' }}">
                            <option value="active" {{ old('status', 'active') == 'active' ? 'selected' : '' }}>Aktif (Siap Dijual)</option>
                            <option value="draft" {{ old('status') == 'draft' ? 'selected' : '' }}>Draf (Draft)</option>
                            <option value="inactive" {{ old('status') == 'inactive' ? 'selected' : '' }}>Non-Aktif (Inactive)</option>
                            <option value="discontinued" {{ old('status') == 'discontinued' ? 'selected' : '' }}>Diskualifikasi / Tidak Diproduksi Lagi (Discontinued)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('status')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Costing Method & Standard Cost Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="costing_method">Metode Costing</x-form-label>
                    <div class="relative">
                        <select id="costing_method" name="costing_method"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('costing_method') ? 'input-error' : '' }}">
                            <option value="standard" {{ old('costing_method', 'standard') == 'standard' ? 'selected' : '' }}>Standard Cost</option>
                            <option value="fifo" {{ old('costing_method') == 'fifo' ? 'selected' : '' }}>FIFO</option>
                            <option value="moving_average" {{ old('costing_method') == 'moving_average' ? 'selected' : '' }}>Moving Average</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('costing_method')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="standard_cost">Biaya Pokok Standar / HPP (Rp)</x-form-label>
                    <x-form-input type="number" id="standard_cost" name="standard_cost" value="{{ old('standard_cost', 0) }}" min="0" step="0.01"
                           placeholder="0.00" class="{{ $errors->has('standard_cost') ? 'input-error' : '' }}" />
                    @error('standard_cost')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Price & Stock Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_price">Harga (Rp)</x-form-label>
                    <x-form-input type="number" id="product_price" name="product_price" value="{{ old('product_price') }}" min="0" step="1"
                           placeholder="0" required class="{{ $errors->has('product_price') ? 'input-error' : '' }}" />
                    @error('product_price')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>

                <div>
                    <x-form-label for="stock">Kuantitas Stok</x-form-label>
                    <x-form-input type="number" id="stock" name="stock" value="{{ old('stock') }}" min="0" step="1"
                           placeholder="0" required class="{{ $errors->has('stock') ? 'input-error' : '' }}" />
                    @error('stock')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Tax Class & Tax Inclusivity Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="tax_class_id">Kelas Pajak (Opsional)</x-form-label>
                    <div class="relative">
                        <select id="tax_class_id" name="tax_class_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('tax_class_id') ? 'input-error' : '' }}">
                            <option value="">-- Tanpa Pajak Khusus --</option>
                            @if(isset($taxClasses))
                                @foreach($taxClasses as $tc)
                                    <option value="{{ $tc->id }}" {{ old('tax_class_id') == $tc->id ? 'selected' : '' }}>
                                        {{ $tc->name }} ({{ number_format((float) $tc->rate_percent, 2) }}%)
                                    </option>
                                @endforeach
                            @endif
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('tax_class_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col justify-center">
                    <x-form-label for="is_tax_inclusive">Perlakuan Pajak</x-form-label>
                    <div class="flex items-center gap-3 mt-2">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="is_tax_inclusive" name="is_tax_inclusive" value="1" class="rounded border-[#E9E9E7] text-apeiron-ink focus:ring-[#37352F] h-4 w-4" {{ old('is_tax_inclusive') ? 'checked' : '' }}>
                            <span class="ml-2 text-sm font-medium text-apeiron-ink">Harga Sudah Termasuk Pajak (Tax Inclusive)</span>
                        </label>
                    </div>
                    @error('is_tax_inclusive')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Status -->
            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="is_active" value="1" class="sr-only" checked>
                        <div class="block bg-primary-200 w-10 h-6 rounded-full transition-colors duration-300"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                    </div>
                    <style>
                        input:checked ~ .block { background-color: #10b981; }
                        input:checked ~ .dot { transform: translateX(100%); }
                    </style>
                    <div class="ml-3 text-sm font-semibold text-apeiron-ink">
                        Produk Aktif
                    </div>
                </label>
            </div>
            
            <!-- Description -->
            <div>
                <x-form-label for="product_description">Deskripsi</x-form-label>
                <textarea id="product_description" name="product_description" rows="3"
                          class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink placeholder-primary-400 focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('product_description') ? 'input-error' : '' }}" 
                          placeholder="Masukkan deskripsi produk (opsional)">{{ old('product_description') }}</textarea>
                @error('product_description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Bill of Materials (BOM) Section -->
            <div x-data="{
                items: {{ json_encode(old('ingredients', [])) }},
                ingredients: {{ json_encode($ingredients->map(fn($i) => ['id' => $i->id, 'name' => $i->name, 'unit' => $i->unit])) }},
                addItem() {
                    this.items.push({ id: '', quantity: '' });
                },
                removeItem(index) {
                    this.items.splice(index, 1);
                }
            }" class="pt-4 border-t border-[#E9E9E7]">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h5 class="text-sm font-bold text-apeiron-ink">Bahan Baku (Bill of Materials)</h5>
                        <p class="text-xs text-[#787774] mt-0.5">Tentukan bahan baku yang akan memotong stok otomatis saat produk ini terjual.</p>
                    </div>
                    <button type="button" @click="addItem" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-500 bg-white border border-[#E9E9E7] rounded-xl hover:bg-[#F7F7F5] transition-colors shadow-sm">
                        <span class="material-symbols-rounded text-[16px]">add</span> Tambah Bahan
                    </button>
                </div>

                <div class="space-y-3">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <select x-model="item.id" :name="'ingredients['+index+'][id]'" class="form-select w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink shadow-sm" required>
                                    <option value="">-- Pilih Bahan --</option>
                                    <template x-for="ing in ingredients" :key="ing.id">
                                        <option :value="ing.id" x-text="ing.name + ' (' + ing.unit + ')'" :selected="item.id == ing.id"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="w-32">
                                <input type="number" step="0.0001" x-model="item.quantity" :name="'ingredients['+index+'][quantity]'" placeholder="Kuantitas" class="form-input w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink shadow-sm" required>
                            </div>
                            <button type="button" @click="removeItem(index)" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-colors" title="Hapus baris">
                                <span class="material-symbols-rounded text-[18px]">close</span>
                            </button>
                        </div>
                    </template>
                    
                    <div x-show="items.length === 0" class="text-center py-6 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7] border-dashed">
                        <p class="text-sm text-primary-400">Belum ada bahan baku. Klik "Tambah Bahan" untuk menyusun resep.</p>
                    </div>
                </div>
            </div>

            <!-- Modifier Groups Section -->
            <div x-data="{
                selectedGroups: {{ json_encode(old('modifier_groups', [])) }},
                groups: {{ json_encode($modifierGroups->map(fn($g) => ['id' => $g->id, 'name' => $g->name])) }},
                addGroup() {
                    this.selectedGroups.push('');
                },
                removeGroup(index) {
                    this.selectedGroups.splice(index, 1);
                }
            }" class="pt-4 border-t border-[#E9E9E7]">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h5 class="text-sm font-bold text-apeiron-ink">Grup Varian & Modifier</h5>
                        <p class="text-xs text-[#787774] mt-0.5">Tautkan grup modifier (seperti Pilihan Susu, Suhu) yang tersedia untuk produk ini.</p>
                    </div>
                    <button type="button" @click="addGroup" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-primary-500 bg-white border border-[#E9E9E7] rounded-xl hover:bg-[#F7F7F5] transition-colors shadow-sm">
                        <span class="material-symbols-rounded text-[16px]">add</span> Tambah Grup Varian
                    </button>
                </div>

                <div class="space-y-3">
                    <template x-for="(groupId, index) in selectedGroups" :key="index">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <select x-model="selectedGroups[index]" :name="'modifier_groups['+index+']'" class="form-select w-full px-3 py-2 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink text-sm focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink shadow-sm" required>
                                    <option value="">-- Pilih Grup Varian --</option>
                                    <template x-for="g in groups" :key="g.id">
                                        <option :value="g.id" x-text="g.name" :selected="groupId == g.id"></option>
                                    </template>
                                </select>
                            </div>
                            <button type="button" @click="removeGroup(index)" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-colors" title="Hapus baris">
                                <span class="material-symbols-rounded text-[18px]">close</span>
                            </button>
                        </div>
                    </template>
                    
                    <div x-show="selectedGroups.length === 0" class="text-center py-6 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7] border-dashed">
                        <p class="text-sm text-primary-400">Belum ada grup varian. Klik "Tambah Grup Varian" jika diperlukan.</p>
                    </div>
                </div>
            </div>

            <!-- Photo Upload -->
            <div>
                <x-form-label for="product_photo">Foto Produk (Opsional)</x-form-label>
                <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-[#E9E9E7] border-dashed rounded-xl transition-colors duration-200 hover:border-[#C4C3C0] bg-[#F7F7F5]">
                    <div class="space-y-1 text-center">
                        <span class="material-symbols-rounded text-[32px] text-primary-300">image</span>
                        <div class="flex flex-col sm:flex-row text-sm text-[#787774] justify-center gap-1 mt-3">
                            <label for="product_photo" class="relative cursor-pointer bg-white rounded-xl font-medium text-apeiron-ink hover:text-apeiron-ink focus-within:outline-none px-3 py-1 shadow-sm border border-[#E9E9E7] transition-all duration-200 active:scale-95">
                                <span>Unggah file</span>
                                <input id="product_photo" name="product_photo" type="file" class="sr-only" accept="image/jpeg,image/png,image/jpg,image/webp" onchange="document.getElementById('file-name').textContent = this.files[0].name">
                            </label>
                            <p class="pl-1 shrink-0 pt-1">atau seret dan lepas</p>
                        </div>
                        <p class="text-xs text-primary-400 mt-2" id="file-name">PNG, JPG hingga 2MB</p>
                    </div>
                </div>
                @error('product_photo')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-ui.button type="submit" variant="primary" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    <span x-show="!submitting" class="flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Produk
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-ui.button>
            </div>
        </form>
    </div>
</x-app-layout>
