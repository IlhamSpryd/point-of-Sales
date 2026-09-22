<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-[#37352F] tracking-tight">Tambah Produk</h4>
            <p class="text-sm font-medium text-[#787774] mt-1">Tambahkan produk baru ke dalam inventaris</p>
        </div>
        <a href="{{ route('products.index') }}" class="flex items-center gap-2 px-4 py-2 bg-white hover:bg-[#F7F7F5] border border-[#E9E9E7] text-[#37352F] text-sm font-medium rounded-xl transition-all duration-200 shadow-sm active:scale-95" wire:navigate>
            <span class="material-symbols-rounded text-[18px]">arrow_back</span> Kembali
        </a>
    </div>

    <div class="card-surface overflow-hidden w-full">
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
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-[#37352F] appearance-none focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all duration-200 shadow-sm {{ $errors->has('category_id') ? 'input-error' : '' }}" required>
                            <option value="">Pilih Kategori</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>
                                    {{ $category->category_name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-[#9B9A97]">
                            <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('category_id')
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

            <!-- Status -->
            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="is_active" value="1" class="sr-only" checked>
                        <div class="block bg-[#E9E9E7] w-10 h-6 rounded-full transition-colors duration-300"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 shadow-sm"></div>
                    </div>
                    <style>
                        input:checked ~ .block { background-color: #10b981; }
                        input:checked ~ .dot { transform: translateX(100%); }
                    </style>
                    <div class="ml-3 text-sm font-semibold text-[#37352F]">
                        Produk Aktif
                    </div>
                </label>
            </div>
            
            <!-- Description -->
            <div>
                <x-form-label for="product_description">Deskripsi</x-form-label>
                <textarea id="product_description" name="product_description" rows="3"
                          class="form-input w-full px-4 py-2.5 rounded-xl border border-[#E9E9E7] bg-white text-[#37352F] placeholder-[#9B9A97] focus:outline-none focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] transition-all duration-200 shadow-sm {{ $errors->has('product_description') ? 'input-error' : '' }}" 
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
                        <h5 class="text-sm font-bold text-[#37352F]">Bahan Baku (Bill of Materials)</h5>
                        <p class="text-xs text-[#787774] mt-0.5">Tentukan bahan baku yang akan memotong stok otomatis saat produk ini terjual.</p>
                    </div>
                    <button type="button" @click="addItem" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors shadow-sm">
                        <span class="material-symbols-rounded text-[16px]">add</span> Tambah Bahan
                    </button>
                </div>

                <div class="space-y-3">
                    <template x-for="(item, index) in items" :key="index">
                        <div class="flex items-start gap-3">
                            <div class="flex-1">
                                <select x-model="item.id" :name="'ingredients['+index+'][id]'" class="form-select w-full px-3 py-2 rounded-lg border border-[#E9E9E7] bg-white text-[#37352F] text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 shadow-sm" required>
                                    <option value="">-- Pilih Bahan --</option>
                                    <template x-for="ing in ingredients" :key="ing.id">
                                        <option :value="ing.id" x-text="ing.name + ' (' + ing.unit + ')'" :selected="item.id == ing.id"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="w-32">
                                <input type="number" step="0.0001" x-model="item.quantity" :name="'ingredients['+index+'][quantity]'" placeholder="Kuantitas" class="form-input w-full px-3 py-2 rounded-lg border border-[#E9E9E7] bg-white text-[#37352F] text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 shadow-sm" required>
                            </div>
                            <button type="button" @click="removeItem(index)" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-rose-500 hover:bg-rose-50 border border-transparent hover:border-rose-200 transition-colors" title="Hapus baris">
                                <span class="material-symbols-rounded text-[18px]">close</span>
                            </button>
                        </div>
                    </template>
                    
                    <div x-show="items.length === 0" class="text-center py-6 bg-[#F7F7F5] rounded-xl border border-[#E9E9E7] border-dashed">
                        <p class="text-sm text-[#9B9A97]">Belum ada bahan baku. Klik "Tambah Bahan" untuk menyusun resep.</p>
                    </div>
                </div>
            </div>

            <!-- Photo Upload -->
            <div>
                <x-form-label for="product_photo">Foto Produk (Opsional)</x-form-label>
                <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-[#E9E9E7] border-dashed rounded-xl transition-colors duration-200 hover:border-[#C4C3C0] bg-[#F7F7F5]">
                    <div class="space-y-1 text-center">
                        <span class="material-symbols-rounded text-[32px] text-[#C4C3C0]">image</span>
                        <div class="flex flex-col sm:flex-row text-sm text-[#787774] justify-center gap-1 mt-3">
                            <label for="product_photo" class="relative cursor-pointer bg-white rounded-lg font-medium text-[#37352F] hover:text-black focus-within:outline-none px-3 py-1 shadow-sm border border-[#E9E9E7] transition-all duration-200 active:scale-95">
                                <span>Unggah file</span>
                                <input id="product_photo" name="product_photo" type="file" class="sr-only" accept="image/jpeg,image/png,image/jpg" onchange="document.getElementById('file-name').textContent = this.files[0].name">
                            </label>
                            <p class="pl-1 shrink-0 pt-1">atau seret dan lepas</p>
                        </div>
                        <p class="text-xs text-[#9B9A97] mt-2" id="file-name">PNG, JPG hingga 2MB</p>
                    </div>
                </div>
                @error('product_photo')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-[#E9E9E7] flex justify-end">
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded text-[18px]">save</span> Simpan Produk
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
