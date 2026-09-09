<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Edit Product</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Update details for #{{ $product->id }}</p>
        </div>
        <a href="{{ route('products.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden max-w-3xl">
        <form action="{{ route('products.update', $product->id) }}" method="POST" enctype="multipart/form-data" class="p-6 md:p-8 space-y-6">
            @csrf
            @method('PUT')

            <!-- Name & Category Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="name">Product Name</x-form-label>
                    <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" 
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('name') input-error @enderror" 
                           placeholder="Enter product name" required>
                    @error('name')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="category_id">Category</x-form-label>
                    <div class="relative">
                        <select id="category_id" name="category_id" 
                                class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 appearance-none focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('category_id') input-error @enderror" required>
                            <option value="">Select Category</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" {{ old('category_id', $product->category_id) == $category->id ? 'selected' : '' }}>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="absolute inset-y-0 right-0 flex items-center px-4 pointer-events-none text-zinc-500">
                            <i class="bi bi-chevron-down text-xs"></i>
                        </div>
                    </div>
                    @error('category_id')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- SKU & Barcode Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="sku">SKU</x-form-label>
                    <input type="text" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" 
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('sku') input-error @enderror" 
                           placeholder="e.g. PRD-00001">
                    @error('sku')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="barcode">Barcode</x-form-label>
                    <input type="text" id="barcode" name="barcode" value="{{ old('barcode', $product->barcode) }}" 
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('barcode') input-error @enderror" 
                           placeholder="Scan or enter barcode">
                    @error('barcode')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Cost Price & Selling Price Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="cost_price">Cost Price (Rp)</x-form-label>
                    <input type="number" id="cost_price" name="cost_price" value="{{ old('cost_price', $product->cost_price ?? 0) }}" min="0" step="1"
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('cost_price') input-error @enderror" 
                           placeholder="0">
                    @error('cost_price')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <x-form-label for="price">Selling Price (Rp)</x-form-label>
                    <input type="number" id="price" name="price" value="{{ old('price', $product->price) }}" min="0" step="1"
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('price') input-error @enderror" 
                           placeholder="0" required>
                    @error('price')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Stock & Status Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <x-form-label for="stock">Stock Quantity</x-form-label>
                    <input type="number" id="stock" name="stock" value="{{ old('stock', $product->stock) }}" min="0" step="1"
                           class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('stock') input-error @enderror" 
                           placeholder="0" required>
                    @error('stock')
                        <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                    @enderror
                </div>
                
                <div class="flex items-center mt-8">
                    <label class="flex items-center cursor-pointer">
                        <div class="relative">
                            <input type="checkbox" name="is_active" class="sr-only" {{ old('is_active', $product->is_active) ? 'checked' : '' }}>
                            <div class="block bg-zinc-200 dark:bg-zinc-700 w-10 h-6 rounded-full transition-colors duration-300 peer-checked:bg-zinc-1000"></div>
                            <div class="dot absolute left-1 top-1 bg-white w-4 h-4 rounded-full transition-transform duration-300 peer-checked:translate-x-full"></div>
                        </div>
                        <style>
                            input:checked ~ .block { background-color: #10b981; }
                            input:checked ~ .dot { transform: translateX(100%); }
                        </style>
                        <div class="ml-3 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            Active Product
                        </div>
                    </label>
                </div>
            </div>
            
            <!-- Description -->
            <div>
                <x-form-label for="description">Description</x-form-label>
                <textarea id="description" name="description" rows="3"
                          class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('description') input-error @enderror" 
                          placeholder="Enter product description (optional)">{{ old('description', $product->description) }}</textarea>
                @error('description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <!-- Photo Upload -->
            <div>
                <x-form-label for="photo">Product Photo</x-form-label>
                @if($product->photo)
                    <div class="mb-3">
                        <p class="text-xs text-zinc-500 mb-2">Current Photo:</p>
                        <img src="{{ asset('storage/' . $product->photo) }}" class="h-24 w-24 object-cover rounded-xl border border-zinc-100 dark:border-zinc-700 shadow-[0_2px_8px_rgba(0,0,0,0.04)]" alt="Existing photo">
                    </div>
                @endif
                <div class="mt-1 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-zinc-200 dark:border-zinc-700 border-dashed rounded-xl form-input transition-colors hover:border-zinc-1000 dark:hover:border-zinc-1000 bg-zinc-50/50 dark:bg-zinc-800/50">
                    <div class="space-y-1 text-center">
                        <i class="bi bi-image text-3xl text-zinc-400"></i>
                        <div class="flex flex-col sm:flex-row text-sm text-zinc-500 dark:text-zinc-400 justify-center gap-1 mt-3">
                            <label for="photo" class="relative cursor-pointer bg-white dark:bg-zinc-900 rounded-md font-medium text-zinc-900 hover:text-zinc-900 focus-within:outline-none px-2 py-0.5 shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-700 transition-colors">
                                <span>Upload to replace</span>
                                <input id="photo" name="photo" type="file" class="sr-only" accept="image/jpeg,image/png,image/jpg" onchange="document.getElementById('file-name').textContent = this.files[0].name">
                            </label>
                            <p class="pl-1 shrink-0 pt-0.5">or drag and drop</p>
                        </div>
                        <p class="text-xs text-zinc-500 mt-2" id="file-name">Optional. PNG, JPG up to 2MB</p>
                    </div>
                </div>
                @error('photo')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                <x-button type="submit" variant="primary">
                    <i class="bi bi-save"></i> Update Product
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
