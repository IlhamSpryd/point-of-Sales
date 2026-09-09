<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Products</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Manage your product inventory</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('products.index') }}" method="GET" class="w-full sm:w-72 relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"></i>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Search products..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <i class="bi bi-download"></i> Export
                    </x-button>
                </a>
                
                <a href="{{ route('products.create') }}" class="flex-1 sm:flex-none">
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <i class="bi bi-plus-lg"></i> Add Product
                    </x-button>
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/20 text-emerald-700 dark:text-emerald-400 text-sm font-medium flex items-center justify-between" x-data="{ show: true }" x-show="show">
            <span><i class="bi bi-check-circle-fill mr-2"></i> {{ session('success') }}</span>
            <button @click="show = false" class="text-emerald-500 hover:text-emerald-700"><i class="bi bi-x-lg"></i></button>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 p-4 rounded-lg bg-rose-50 dark:bg-rose-500/10 border border-rose-200 dark:border-rose-500/20 text-rose-700 dark:text-rose-400 text-sm font-medium flex items-center justify-between" x-data="{ show: true }" x-show="show">
            <span><i class="bi bi-exclamation-triangle-fill mr-2"></i> {{ session('error') }}</span>
            <button @click="show = false" class="text-rose-500 hover:text-rose-700"><i class="bi bi-x-lg"></i></button>
        </div>
    @endif

    <div class="bg-white dark:bg-zinc-900 rounded-2xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-zinc-50/50 dark:bg-zinc-800/50">
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Photo</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Product Info</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Price (Sell/Cost)</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Stock</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($products as $product)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="w-12 h-12 rounded-xl bg-zinc-50/50 dark:bg-zinc-800 flex items-center justify-center shrink-0">
                                    <img src="{{ $product->photo ? asset('storage/' . $product->photo) : 'https://via.placeholder.com/64' }}" alt="{{ $product->name }}" class="w-8 h-8 object-cover rounded-md">
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white text-sm">{{ $product->name }}</div>
                                <div class="text-xs text-zinc-500 mt-0.5">{{ $product->sku ?? 'No SKU' }}</div>
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $product->category->name ?? '-' }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white text-sm">Rp {{ number_format($product->price, 0, ',', '.') }}</div>
                                @if($product->cost_price)
                                <div class="text-xs text-zinc-500 mt-0.5" title="Cost Price">Rp {{ number_format($product->cost_price, 0, ',', '.') }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium {{ $product->stock > 10 ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 border-emerald-100 dark:border-emerald-500/20' : 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 border-rose-100 dark:border-rose-500/20' }} border">
                                    {{ $product->stock }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @if($product->is_active)
                                    <x-badge type="success">Active</x-badge>
                                @else
                                    <x-badge type="secondary">Inactive</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('products.edit', $product->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 transition-colors inline-block"><i class="bi bi-pencil-square"></i></a>
                                <form action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-zinc-400 hover:text-rose-600 transition-colors"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-zinc-500 text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="bi bi-box text-4xl mb-3 text-zinc-400"></i>
                                    <p>No products found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $products->links() }}
        </div>
    </div>
</x-app-layout>
