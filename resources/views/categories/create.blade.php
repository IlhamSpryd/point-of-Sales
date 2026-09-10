<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Create Category</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Add a new product category</p>
        </div>
        <a href="{{ route('categories.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <span class="material-symbols-rounded">arrow_back</span> Back
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('categories.store') }}" method="POST" class="p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <x-form-label for="category_name">Category Name</x-form-label>
                <x-form-input type="text" id="category_name" name="category_name" value="{{ old('category_name') }}" 
                        
                       placeholder="Enter category name" required class="@error('category_name') input-error @enderror" />
                @error('category_name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                <x-button type="submit" variant="primary">
                    <span class="material-symbols-rounded">save</span> Save Category
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
