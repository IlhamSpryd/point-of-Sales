<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Ubah Produk <!-- Standarisasi bahasa UjiKom --></h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Perbarui detail produk #{{ $product->id }} <!-- Standarisasi bahasa UjiKom --></p>
        </div>
        <a href="{{ route('products.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Kembali <!-- Standarisasi bahasa UjiKom -->
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('products.update', $product->id) }}" method="POST" enctype="multipart/form-data" x-data="{ submitting: false }" @submit="submitting = true" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <!-- Name & Category Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_name">Nama Produk <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    {{-- Menggunakan <x-form-input> alih-alih <input> manual, supaya style Create & Edit selalu seragam dan mudah dirawat dari satu sumber (komponen). --}}
                    <x-form-input type="text" id="product_name" name="product_name" 
                           value="{{ old('product_name', $product->product_name) }}" 
                           placeholder="Masukkan nama produk" required 
                           class="@error('product_name') input-error @enderror" />
                    @error('product_name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="category_id">Kategori <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    <div class="relative">
                        <select id="category_id" name="category_id" 
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 appearance-none focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('category_id') input-error @enderror" required>
                            <option value="">Pilih Kategori <!-- Standarisasi bahasa UjiKom --></option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id', $product->category_id) == $category->id ? 'selected' : '' }}>
                                    {{ $category->category_name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-500">
                            <span class="material-symbols-rounded text-xs">keyboard_arrow_down</span>
                        </div>
                    </div>
                    @error('category_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Price & Stock Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="product_price">Harga (Rp) <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    {{-- Menggunakan <x-form-input> alih-alih <input> manual, supaya style Create & Edit selalu seragam dan mudah dirawat dari satu sumber (komponen). --}}
                    <x-form-input type="number" id="product_price" name="product_price" 
                           value="{{ old('product_price', $product->product_price) }}" min="0" step="1" 
                           placeholder="0" required 
                           class="@error('product_price') input-error @enderror" />
                    @error('product_price')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="stock">Kuantitas Stok <!-- Standarisasi bahasa UjiKom --></x-form-label>
                    {{-- Menggunakan <x-form-input> alih-alih <input> manual, supaya style Create & Edit selalu seragam dan mudah dirawat dari satu sumber (komponen). --}}
                    <x-form-input type="number" id="stock" name="stock" 
                           value="{{ old('stock', $product->stock) }}" min="0" step="1" 
                           placeholder="0" required 
                           class="@error('stock') input-error @enderror" />
                    @error('stock')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Status -->
            <div class="flex items-center">
                <label class="flex items-center cursor-pointer">
                    <div class="relative">
                        <input type="checkbox" name="is_active" value="1" class="sr-only" {{ old('is_active', $product->is_active) ? 'checked' : '' }}>
                        <div class="block bg-zinc-200 dark:bg-zinc-700 w-10 h-6 rounded-full transition-colors duration-300 peer-checked:bg-zinc-1000"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 peer-checked:translate-x-full"></div>
                    </div>
                    <style>
                        input:checked ~ .block { background-color: #10b981; }
                        input:checked ~ .dot { transform: translateX(100%); }
                    </style>
                    <div class="ml-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        Produk Aktif <!-- Standarisasi bahasa UjiKom -->
                    </div>
                </label>
            </div>
            
            <!-- Description -->
            <div>
                <x-form-label for="product_description">Deskripsi <!-- Standarisasi bahasa UjiKom --></x-form-label>
                <textarea id="product_description" name="product_description" rows="3"
                          class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('product_description') input-error @enderror" 
                          placeholder="Masukkan deskripsi produk (opsional)">{{ old('product_description', $product->product_description) }}</textarea> <!-- Standarisasi bahasa UjiKom -->
                @error('product_description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <!-- Photo Upload -->
            <div>
                <x-form-label for="product_photo">Foto Produk <!-- Standarisasi bahasa UjiKom --></x-form-label>
                @if($product->product_photo)
                    <div class="mb-3">
                        <p class="text-xs text-zinc-500 mb-2">Foto Saat Ini: <!-- Standarisasi bahasa UjiKom --></p>
                        <img src="{{ asset('storage/' . $product->product_photo) }}" class="h-24 w-24 object-cover rounded-xl border border-zinc-100 dark:border-zinc-700 shadow-[0_2px_8px_rgba(0,0,0,0.04)]" alt="Foto yang ada"> <!-- Standarisasi bahasa UjiKom -->
                    </div>
                @endif
                <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-zinc-200 dark:border-zinc-700 border-dashed rounded-xl form-input transition-colors hover:border-zinc-1000 dark:hover:border-zinc-1000 bg-zinc-50/50 dark:bg-zinc-800/50">
                    <div class="space-y-1 text-center">
                        <span class="material-symbols-rounded text-3xl text-zinc-400">image</span>
                        <div class="flex flex-col sm:flex-row text-sm text-zinc-500 dark:text-zinc-400 justify-center gap-1 mt-3">
                            <label for="product_photo" class="relative cursor-pointer bg-white dark:bg-zinc-900 rounded-md font-medium text-zinc-900 hover:text-zinc-900 focus-within:outline-none px-2 py-0.5 shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-700 transition-colors">
                                <span>Unggah untuk mengganti <!-- Standarisasi bahasa UjiKom --></span>
                                <input id="product_photo" name="product_photo" type="file" class="sr-only" accept="image/jpeg,image/png,image/jpg" onchange="document.getElementById('file-name').textContent = this.files[0].name">
                            </label>
                            <p class="pl-1 shrink-0 pt-0.5">atau seret dan lepas <!-- Standarisasi bahasa UjiKom --></p>
                        </div>
                        <p class="text-xs text-zinc-500 mt-2" id="file-name">Opsional. PNG, JPG hingga 2MB <!-- Standarisasi bahasa UjiKom --></p>
                    </div>
                </div>
                @error('product_photo')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                {{-- submitting mencegah user klik tombol dua kali saat form sedang diproses server, supaya tidak ada data duplikat --}}
                <x-button type="submit" variant="primary" :disabled="false" x-bind:disabled="submitting" x-bind:class="submitting ? 'opacity-60 cursor-not-allowed' : ''">
                    <span x-show="!submitting">
                        <span class="material-symbols-rounded">save</span> Perbarui Produk <!-- Standarisasi bahasa UjiKom -->
                    </span>
                    <span x-show="submitting" x-cloak>Menyimpan...</span>
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
