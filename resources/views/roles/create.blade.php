<x-app-layout>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h4 class="text-xl font-bold text-zinc-900 dark:text-zinc-100 tracking-tight">Create Role</h4>
            <p class="text-sm font-medium text-zinc-500 mt-1">Add a new user role</p>
        </div>
        <a href="{{ route('roles.index') }}" class="flex items-center gap-2 px-4 py-2 bg-zinc-50/50 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700 text-zinc-700 dark:text-zinc-300 text-sm font-medium rounded-lg transition-colors shadow-[0_2px_8px_rgba(0,0,0,0.04)]">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl shadow-[0_2px_8px_rgba(0,0,0,0.04)] border border-zinc-100 dark:border-zinc-800 overflow-hidden w-full">
        <form action="{{ route('roles.store') }}" method="POST" class="p-6 md:p-8 space-y-6">
            @csrf

            <div>
                <x-form-label for="name">Role Name</x-form-label>
                <x-form-input type="text" id="name" name="name" value="{{ old('name') }}" 
                        
                       placeholder="Enter role name (e.g. Administrator)" required class="@error('name') input-error @enderror" />
                @error('name')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <x-form-label for="description">Description</x-form-label>
                <textarea id="description" name="description" rows="3"
                          class="form-input w-full px-4 py-2.5 rounded-xl border border-zinc-100 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-zinc-100 focus:outline-none focus:ring-2 focus:ring-zinc-1000/20 focus:border-zinc-1000 transition-all @error('description') input-error @enderror" 
                          placeholder="Optional role description">{{ old('description') }}</textarea>
                @error('description')
                    <p class="text-sm text-rose-500 mt-1.5 font-medium label-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 flex justify-end">
                <x-button type="submit" variant="primary">
                    <i class="bi bi-save"></i> Save Role
                </x-button>
            </div>
        </form>
    </div>
</x-app-layout>
