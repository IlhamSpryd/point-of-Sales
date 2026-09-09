<x-app-layout>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Categories</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Manage product categories</p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-3 items-center w-full sm:w-auto">
            <form action="{{ route('categories.index') }}" method="GET" class="w-full sm:w-72 relative">
                <i class="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400"></i>
                <x-form-input type="search" name="search" value="{{ request('search') }}" placeholder="Search categories..." class="pl-10 h-10 w-full" />
            </form>
            
            <div class="flex gap-2 w-full sm:w-auto">
                <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="flex-1 sm:flex-none">
                    <x-button variant="secondary" type="button" class="w-full h-10">
                        <i class="bi bi-download"></i> Export
                    </x-button>
                </a>
                
                <a href="{{ route('categories.create') }}" class="flex-1 sm:flex-none">
                    <x-button variant="primary" type="button" class="w-full h-10">
                        <i class="bi bi-plus-lg"></i> Add Category
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
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">ID</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Category</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider">Created At</th>
                        <th class="px-6 py-4 text-xs font-semibold text-zinc-500 uppercase tracking-wider text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse($categories as $category)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white text-sm">#{{ $category->id }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-zinc-900 dark:text-white text-sm">{{ $category->name }}</div>
                                @if($category->description)
                                <div class="text-xs text-zinc-500 mt-0.5 truncate max-w-xs" title="{{ $category->description }}">{{ $category->description }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if($category->is_active)
                                    <x-badge type="success">Active</x-badge>
                                @else
                                    <x-badge type="secondary">Inactive</x-badge>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-zinc-500">{{ $category->created_at?->format('M d, Y') ?? '—' }}</td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <a href="{{ route('categories.edit', $category->id) }}" class="p-1.5 text-zinc-400 hover:text-blue-600 transition-colors inline-block"><i class="bi bi-pencil-square"></i></a>
                                <form action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this category?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="p-1.5 text-zinc-400 hover:text-rose-600 transition-colors"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-zinc-500 text-sm">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="bi bi-tags text-4xl mb-3 text-zinc-400"></i>
                                    <p>No categories found.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
            {{ $categories->links() }}
        </div>
    </div>
</x-app-layout>
