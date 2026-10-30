@php
    // Izin kelola katalog (tambah/ubah/hapus) melalui CategoryPolicy (mendukung is_admin & RBAC).
    $canManage = auth()->user()?->can('create', \App\Models\Category::class) ?? false;
@endphp
<x-app-layout>
    <div x-data="{
        drawerOpen: {{ $errors->any() ? 'true' : 'false' }},
        drawerMode: '{{ old('_method') === 'PUT' ? 'edit' : 'create' }}',
        drawerTitle: '{{ old('_method') === 'PUT' ? 'Edit Category' : 'Add Category' }}',
        actionUrl: '{{ old('_form_action', route('categories.store')) }}',
        form: {
            id: '{{ old('id', '') }}',
            category_code: '{{ old('category_code', '') }}',
            category_name: '{{ old('category_name', '') }}',
            parent_id: '{{ old('parent_id', '') }}',
            sort_order: '{{ old('sort_order', 0) }}',
            description: '{{ old('description', '') }}',
            is_active: {{ old('is_active', '1') ? 'true' : 'false' }}
        },
        openCreate() {
            this.drawerMode = 'create';
            this.drawerTitle = 'Add Category';
            this.actionUrl = '{{ route('categories.store') }}';
            this.form = { id: '', category_code: '', category_name: '', parent_id: '', sort_order: 0, description: '', is_active: true };
            this.drawerOpen = true;
        },
        openEdit(cat) {
            this.drawerMode = 'edit';
            this.drawerTitle = 'Edit Category: ' + cat.category_name;
            this.actionUrl = '/categories/' + cat.id;
            this.form = {
                id: cat.id,
                category_code: cat.category_code || '',
                category_name: cat.category_name || '',
                parent_id: cat.parent_id || '',
                sort_order: cat.sort_order || 0,
                description: cat.description || '',
                is_active: cat.is_active ? true : false
            };
            this.drawerOpen = true;
        }
    }">
        @include('catalog.partials.sub-nav')

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Categories & Subcategories</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Manage commodity classification hierarchies and product taxonomy</p>
            </div>
            
            <x-list-toolbar search-action="{{ route('categories.index') }}" search-placeholder="Search categories...">
                <x-slot:actions>
                    <a href="{{ route('categories.export') }}" class="flex-1 sm:flex-none">
                        <x-ui.button variant="secondary" class="w-full h-10">
                            <span class="material-symbols-rounded text-[18px]">download</span> Export Excel
                        </x-ui.button>
                    </a>
                    @if($canManage)
                        <x-ui.button variant="primary" class="w-full sm:w-auto h-10" @click="openCreate()">
                            <span class="material-symbols-rounded text-[18px]">add</span> Add Category
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-list-toolbar>
        </div>

        @if(session('success'))
            <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
        @endif

        @if(session('error'))
            <x-ui.alert variant="destructive" class="mb-4">{{ session('error') }}</x-ui.alert>
        @endif

        <div class="card-surface flex flex-col flex-1 min-h-0">
            {{-- Desktop/tablet-landscape: existing table --}}
            <x-data-table :hideOnMobile="true">
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Code</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Category Name</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Parent Category</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Actions</th>
                    @endif
                </x-slot:head>
                
                @forelse($categories as $category)
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        <td class="px-6 py-4 font-mono font-semibold text-apeiron-ink text-xs">{{ $category->category_code }}</td>
                        <td class="px-6 py-4">
                            <div class="font-semibold text-apeiron-ink text-sm">{{ $category->category_name }}</div>
                            @if($category->description)
                                <div class="text-xs text-[#787774] mt-0.5">{{ Str::limit($category->description, 50) }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-[#787774]">{{ $category->parent?->category_name ?? '— (Primary Category)' }}</td>
                        <td class="px-6 py-4">
                            @if($category->is_active ?? true)
                                <x-ui.badge variant="success" :dot="true">Active</x-ui.badge>
                            @else
                                <x-ui.badge variant="secondary" :dot="true">Inactive</x-ui.badge>
                            @endif
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right space-x-2">
                                <button type="button" @click="openEdit({{ json_encode($category) }})" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200 active:scale-90" aria-label="Edit category">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <form id="delete-form-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $category->id }}', '{{ addslashes($category->category_name) }}')" class="inline-flex items-center justify-center min-w-11 min-h-11 rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200 active:scale-90" aria-label="Delete">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canManage ? 5 : 4 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                    <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">category</span>
                                </div>
                                <p class="font-medium">No categories found.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            {{-- Mobile/tablet-portrait: card list --}}
            <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
                @forelse($categories as $category)
                    <div class="card-surface p-4">
                        <div class="flex justify-between items-start">
                            <div class="flex flex-col">
                                <span class="font-bold text-apeiron-ink text-sm">{{ $category->category_name }}</span>
                                <span class="text-xs text-[#787774]">{{ $category->category_code }}</span>
                            </div>
                            <span class="text-[11px] font-medium text-[#9B9A97]">{{ $category->created_at?->format('d M Y') ?? '—' }}</span>
                        </div>
                        @if($canManage)
                            <div class="flex items-center justify-end gap-1 mt-3 pt-3 border-t border-[#E5E7EB]">
                                <button type="button" @click="openEdit({{ json_encode($category) }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Edit category">
                                    <span class="material-symbols-rounded text-[18px]">edit</span>
                                </button>
                                <form id="delete-form-mobile-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $category->id }}', '{{ addslashes($category->category_name) }}')" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200" aria-label="Delete">
                                        <span class="material-symbols-rounded text-[18px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-8 text-center text-[#9B9A97] text-sm flex flex-col items-center justify-center">
                        <div class="w-12 h-12 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-[24px] text-[#C4C3C0]">category</span>
                        </div>
                        <p class="font-medium">No categories found.</p>
                    </div>
                @endforelse
            </div>
            <div class="p-4 border-t border-apeiron-border shrink-0">
                {{ $categories->links() }}
            </div>
        </div>

        {{-- Contextual Drawer for PAGE-029 & PAGE-030 --}}
        <x-ui.drawer x-model="drawerOpen" maxWidth="lg">
            <x-slot:header>
                <span x-text="drawerTitle"></span>
            </x-slot:header>

            <form :action="actionUrl" method="POST" id="categoryDrawerForm" class="space-y-4">
                @csrf
                <template x-if="drawerMode === 'edit'">
                    <input type="hidden" name="_method" value="PUT">
                </template>
                <input type="hidden" name="_form_action" :value="actionUrl">

                <div>
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Category Code (Optional)</label>
                    <input type="text" name="category_code" x-model="form.category_code" placeholder="e.g. BEV, FOOD" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Category Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="category_name" x-model="form.category_name" required placeholder="Enter category name" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Parent Category</label>
                    <select name="parent_id" x-model="form.parent_id" class="w-full rounded-xl border border-[#E5E7EB] bg-white px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none">
                        <option value="">-- No Parent (Primary Category) --</option>
                        @foreach($parentCategories as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->category_name }} ({{ $parent->category_code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Display Order</label>
                    <input type="number" name="sort_order" x-model="form.sort_order" min="0" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Description</label>
                    <textarea name="description" x-model="form.description" rows="3" placeholder="Optional description..." class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2.5 text-sm text-apeiron-ink focus:border-primary-500 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-2">
                    <input type="checkbox" id="drawer_is_active" name="is_active" value="1" x-model="form.is_active" class="rounded border-[#E5E7EB] text-primary-600 focus:ring-primary-500">
                    <label for="drawer_is_active" class="text-sm font-medium text-apeiron-ink">Active Status</label>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="drawerOpen = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="categoryDrawerForm">
                    Save Changes
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
