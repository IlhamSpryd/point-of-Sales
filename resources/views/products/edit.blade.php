<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div class="flex items-center justify-between mb-6" data-gsap="page-enter">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Ubah Produk</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Perbarui detail produk #{{ $product->id }}</p>
        </div>
        <a href="{{ route('products.index') }}" wire:navigate>
            <x-ui.button variant="outline" type="button" class="h-10">
                <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
            </x-ui.button>
        </a>
    </div>

    <div class="bg-white border border-[#E9E9E7] rounded-xl shadow-sm overflow-hidden w-full shrink-0">
        <form action="{{ route('products.update', $product->id) }}" method="POST" enctype="multipart/form-data" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <!-- Name Row -->
            <div>
                <x-form-label for="product_name">Nama Produk</x-form-label>
                <x-form-input type="text" id="product_name" name="product_name"
                       value="{{ old('product_name', $product->product_name) }}"
                       placeholder="Masukkan nama produk" required
                       class="{{ $errors->has('product_name') ? 'input-error' : '' }}" />
                @error('product_name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- SKU & Barcode Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_code">Kode SKU / Kode Produk</x-form-label>
                    <x-form-input type="text" id="product_code" name="product_code"
                           value="{{ old('product_code', $product->product_code) }}"
                           placeholder="Contoh: PRD-001"
                           class="{{ $errors->has('product_code') ? 'input-error' : '' }}" />
                    @error('product_code')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="barcode">Barcode / EAN / UPC</x-form-label>
                    <x-form-input type="text" id="barcode" name="barcode"
                           value="{{ old('barcode', $product->barcode) }}"
                           placeholder="Barcode produk (opsional)"
                           class="{{ $errors->has('barcode') ? 'input-error' : '' }}" />
                    @error('barcode')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Category & Brand Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="category_id">Kategori</x-form-label>
                    <div class="relative">
                        <select id="category_id" name="category_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('category_id') ? 'input-error' : '' }}" required>
                            <option value="">Pilih Kategori</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id', $product->category_id) == $category->id ? 'selected' : '' }}>
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

                <div>
                    <x-form-label for="brand_id">Merek (Brand)</x-form-label>
                    <div class="relative">
                        <select id="brand_id" name="brand_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('brand_id') ? 'input-error' : '' }}">
                            <option value="">Tanpa Merek</option>
                            @if(isset($brands))
                                @foreach($brands as $brand)
                                    <option value="{{ $brand->id }}" {{ old('brand_id', $product->brand_id) == $brand->id ? 'selected' : '' }}>
                                        {{ $brand->brand_name ?? $brand->name }}
                                    </option>
                                @endforeach
                            @endif
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('brand_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Type & Status Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_type">Tipe Produk</x-form-label>
                    <div class="relative">
                        <select id="product_type" name="product_type"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('product_type') ? 'input-error' : '' }}">
                            <option value="standard" {{ old('product_type', $product->product_type ?? 'standard') === 'standard' ? 'selected' : '' }}>Standard (Barang Jadi)</option>
                            <option value="service" {{ old('product_type', $product->product_type) === 'service' ? 'selected' : '' }}>Jasa / Layanan</option>
                            <option value="non_inventory" {{ old('product_type', $product->product_type) === 'non_inventory' ? 'selected' : '' }}>Non-Inventory</option>
                            <option value="bundle" {{ old('product_type', $product->product_type) === 'bundle' ? 'selected' : '' }}>Paket (Bundle)</option>
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
                            <option value="active" {{ old('status', $product->status ?? 'active') === 'active' ? 'selected' : '' }}>Aktif (Siap Dijual)</option>
                            <option value="draft" {{ old('status', $product->status) === 'draft' ? 'selected' : '' }}>Draf (Draft)</option>
                            <option value="inactive" {{ old('status', $product->status) === 'inactive' ? 'selected' : '' }}>Non-Aktif (Inactive)</option>
                            <option value="discontinued" {{ old('status', $product->status) === 'discontinued' ? 'selected' : '' }}>Diskualifikasi (Discontinued)</option>
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

            <!-- UoM & Tax Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="base_uom_id">Satuan Dasar (Base UoM)</x-form-label>
                    <div class="relative">
                        <select id="base_uom_id" name="base_uom_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('base_uom_id') ? 'input-error' : '' }}">
                            <option value="">Pilih Satuan</option>
                            @if(isset($uoms))
                                @foreach($uoms as $uom)
                                    <option value="{{ $uom->id }}" {{ old('base_uom_id', $product->base_uom_id) == $uom->id ? 'selected' : '' }}>
                                        {{ $uom->uom_name ?? $uom->name }} ({{ $uom->code ?? $uom->symbol ?? '' }})
                                    </option>
                                @endforeach
                            @endif
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('base_uom_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="tax_class_id">Kelas Pajak (Opsional)</x-form-label>
                    <div class="relative">
                        <select id="tax_class_id" name="tax_class_id"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('tax_class_id') ? 'input-error' : '' }}">
                            <option value="">-- Tanpa Pajak Khusus --</option>
                            @if(isset($taxClasses))
                                @foreach($taxClasses as $tc)
                                    <option value="{{ $tc->id }}" {{ old('tax_class_id', $product->tax_class_id) == $tc->id ? 'selected' : '' }}>
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
            </div>

            <!-- Cost & Costing Method Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="standard_cost">Biaya Standar / HPP (Rp)</x-form-label>
                    <x-form-input type="number" id="standard_cost" name="standard_cost"
                           value="{{ old('standard_cost', $product->standard_cost ?? 0) }}" min="0" step="0.01"
                           placeholder="0.00"
                           class="{{ $errors->has('standard_cost') ? 'input-error' : '' }}" />
                    @error('standard_cost')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>

                <div>
                    <x-form-label for="costing_method">Metode Biaya (Costing Method)</x-form-label>
                    <div class="relative">
                        <select id="costing_method" name="costing_method"
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-apeiron-ink appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-apeiron-ink transition-all duration-200 shadow-sm {{ $errors->has('costing_method') ? 'input-error' : '' }}">
                            <option value="fifo" {{ old('costing_method', $product->costing_method ?? 'fifo') === 'fifo' ? 'selected' : '' }}>FIFO (First In, First Out)</option>
                            <option value="average" {{ old('costing_method', $product->costing_method) === 'average' ? 'selected' : '' }}>Rata-Rata (Average)</option>
                            <option value="standard" {{ old('costing_method', $product->costing_method) === 'standard' ? 'selected' : '' }}>Standar (Standard Cost)</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-primary-400">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('costing_method')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>
            </div>

            <!-- Price & Stock Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_price">Harga (Rp)</x-form-label>
                    <x-form-input type="number" id="product_price" name="product_price" 
                           value="{{ old('product_price', $product->product_price) }}" min="0" step="1" 
                           placeholder="0" required 
                           class="{{ $errors->has('product_price') ? 'input-error' : '' }}" />
                    @error('product_price')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>

                <div>
                    <x-form-label for="stock">Kuantitas Stok</x-form-label>
                    <x-form-input type="number" id="stock" name="stock" 
                           value="{{ old('stock', $product->stock) }}" min="0" step="1" 
                           placeholder="0" required 
                           class="{{ $errors->has('stock') ? 'input-error' : '' }}" />
                    @error('stock')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
                </div>
            </div>

            <!-- Tax Inclusive & Cost Change Reason Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="cost_change_reason">Alasan Perubahan Biaya Pokok (Jika HPP berubah)</x-form-label>
                    <x-form-input type="text" id="cost_change_reason" name="cost_change_reason"
                           value="{{ old('cost_change_reason') }}"
                           placeholder="Contoh: Penyesuaian harga bahan baku supplier"
                           class="{{ $errors->has('cost_change_reason') ? 'input-error' : '' }}" />
                    @error('cost_change_reason')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col justify-center">
                    <x-form-label for="is_tax_inclusive">Perlakuan Pajak</x-form-label>
                    <div class="flex items-center gap-3 mt-2">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="is_tax_inclusive" name="is_tax_inclusive" value="1" class="rounded border-[#E9E9E7] text-apeiron-ink focus:ring-[#37352F] h-4 w-4" {{ old('is_tax_inclusive', $product->is_tax_inclusive) ? 'checked' : '' }}>
                            <span class="ml-2 text-sm font-medium text-apeiron-ink">Harga Jual Sudah Termasuk Pajak (Tax Inclusive)</span>
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
                        <input type="checkbox" name="is_active" value="1" class="sr-only" {{ old('is_active', $product->is_active) ? 'checked' : '' }}>
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
                          placeholder="Masukkan deskripsi produk (opsional)">{{ old('product_description', $product->product_description) }}</textarea>
                @error('product_description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <!-- Bill of Materials (BOM) Section -->
            @php
                $existingBom = old('ingredients') ? old('ingredients') : $product->ingredients->map(function($ing) {
                    return [
                        'id' => $ing->id,
                        'quantity' => (float) $ing->pivot->quantity_required
                    ];
                })->toArray();
            @endphp
            <div x-data="{
                items: {{ json_encode($existingBom) }},
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
            @php
                $existingGroups = old('modifier_groups') ? old('modifier_groups') : $product->modifierGroups->pluck('id')->toArray();
            @endphp
            <div x-data="{
                selectedGroups: {{ json_encode($existingGroups) }},
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
                <x-form-label for="product_photo">Foto Produk</x-form-label>
                @if($product->product_photo)
                    <div class="mb-3">
                        <p class="text-xs text-[#787774] mb-2">Foto Saat Ini:</p>
                        <img src="{{ asset('storage/' . $product->product_photo) }}" class="h-24 w-24 object-cover rounded-xl border border-[#E9E9E7] shadow-sm" alt="Foto yang ada">
                    </div>
                @endif
                <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-[#E9E9E7] border-dashed rounded-xl transition-colors duration-200 hover:border-[#C4C3C0] bg-[#F7F7F5]">
                    <div class="space-y-1 text-center">
                        <span class="material-symbols-rounded text-[32px] text-primary-300">image</span>
                        <div class="flex flex-col sm:flex-row text-sm text-[#787774] justify-center gap-1 mt-3">
                            <label for="product_photo" class="relative cursor-pointer bg-white rounded-xl font-medium text-apeiron-ink hover:text-apeiron-ink focus-within:outline-none px-3 py-1 shadow-sm border border-[#E9E9E7] transition-all duration-200 active:scale-95">
                                <span>Unggah untuk mengganti</span>
                                <input id="product_photo" name="product_photo" type="file" class="sr-only" accept="image/jpeg,image/png,image/jpg,image/webp" onchange="document.getElementById('file-name').textContent = this.files[0].name">
                            </label>
                            <p class="pl-1 shrink-0 pt-1">atau seret dan lepas</p>
                        </div>
                        <p class="text-xs text-primary-400 mt-2" id="file-name">Opsional. PNG, JPG hingga 2MB</p>
                    </div>
                </div>
                @error('product_photo')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-ui.button type="submit" variant="primary" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''" x-on:click="window.erpMotion?.pulseHighlight($el)">
                    <span x-show="!submitting" class="flex items-center gap-1.5">
                        <span class="material-symbols-rounded text-[18px]">save</span> Perbarui Produk
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-ui.button>
            </div>
        </form>
    </div>

    <!-- Media Gallery Section -->
    <div class="mt-6 bg-white border border-[#E9E9E7] rounded-xl shadow-sm overflow-hidden p-6">
        <h5 class="text-base font-bold text-apeiron-ink mb-1">Galeri Media Produk</h5>
        <p class="text-xs text-[#787774] mb-4">Kelola foto dan aset visual pendukung untuk produk ini.</p>

        @if($product->media->isNotEmpty())
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
                @foreach($product->media as $m)
                    <div class="relative group rounded-xl border border-[#E9E9E7] overflow-hidden bg-[#F7F7F5] aspect-square flex flex-col justify-between p-2">
                        <img src="{{ asset('storage/' . $m->file_path) }}" alt="{{ $m->alt_text ?? $product->product_name }}" class="w-full h-full object-cover rounded-lg absolute inset-0">
                        <div class="relative z-10 flex justify-between items-start">
                            @if($m->is_primary)
                                <span class="px-2 py-0.5 text-[10px] font-bold uppercase rounded-md bg-emerald-500 text-white shadow-sm">Utama</span>
                            @else
                                <form action="{{ route('products.media.set-primary', [$product->id, $m->id]) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="px-2 py-0.5 text-[10px] font-medium rounded-md bg-black/60 hover:bg-black text-white transition-colors" title="Jadikan foto utama">
                                        Set Utama
                                    </button>
                                </form>
                            @endif
                            <form action="{{ route('products.media.destroy', [$product->id, $m->id]) }}" method="POST" onsubmit="return confirm('Hapus media ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="p-1 rounded-md bg-rose-500/80 hover:bg-rose-600 text-white transition-colors shadow-sm" title="Hapus foto">
                                    <span class="material-symbols-rounded text-[14px]">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-6 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7] border-dashed">
                <p class="text-sm text-primary-400">Belum ada media tersimpan dalam galeri produk ini.</p>
            </div>
        @endif
    </div>

    <!-- Cost History Section -->
    <div class="mt-6 bg-white border border-[#E9E9E7] rounded-xl shadow-sm overflow-hidden p-6">
        <h5 class="text-base font-bold text-apeiron-ink mb-1">Riwayat Perubahan Biaya Pokok (HPP)</h5>
        <p class="text-xs text-[#787774] mb-4">Audit log pergeseran biaya standar dan metode kalkulasi produk ini.</p>

        @if($product->costHistories->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-apeiron-ink">
                    <thead class="bg-[#F7F7F5] text-xs uppercase font-semibold text-[#787774] border-b border-[#E9E9E7]">
                        <tr>
                            <th class="px-4 py-2.5">Waktu Efektif</th>
                            <th class="px-4 py-2.5">Metode</th>
                            <th class="px-4 py-2.5">Biaya Lama</th>
                            <th class="px-4 py-2.5">Biaya Baru</th>
                            <th class="px-4 py-2.5">Alasan Perubahan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#E9E9E7]">
                        @foreach($product->costHistories as $ch)
                            <tr class="hover:bg-[#F7F7F5]/50 transition-colors">
                                <td class="px-4 py-2.5 text-xs text-[#787774]">{{ $ch->effective_at ? $ch->effective_at->format('d M Y H:i') : $ch->created_at->format('d M Y H:i') }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs uppercase">{{ $ch->costing_method }}</td>
                                <td class="px-4 py-2.5 font-mono">Rp {{ number_format((float) $ch->old_cost, 2, ',', '.') }}</td>
                                <td class="px-4 py-2.5 font-mono font-semibold text-emerald-600">Rp {{ number_format((float) $ch->new_cost, 2, ',', '.') }}</td>
                                <td class="px-4 py-2.5 text-xs text-[#787774]">{{ $ch->reason ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center py-6 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7] border-dashed">
                <p class="text-sm text-primary-400">Belum ada catatan riwayat perubahan biaya untuk produk ini.</p>
            </div>
        @endif
    </div>
</x-app-layout>
