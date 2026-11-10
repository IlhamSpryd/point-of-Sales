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

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4">
            <div>
                <h4 class="text-xl font-bold text-foreground tracking-tight">Categories & Subcategories</h4>
                <p class="text-sm font-medium text-muted-foreground mt-1">Manage commodity classification hierarchies and product taxonomy</p>
            </div>
        </div>
        
        <x-erp.filter-bar search-action="{{ route('categories.index') }}" search-placeholder="Search categories...">
            <x-slot:actions>
                <a href="{{ route('categories.export') }}" class="flex-1 sm:flex-none">
                    <x-ui.button variant="secondary" class="w-full h-10">
                        <span class="material-symbols-rounded text-[20px]">download</span> Export Excel
                    </x-ui.button>
                </a>
                @if($canManage)
                    <x-ui.button variant="primary" class="w-full sm:w-auto h-10" @click="openCreate()">
                        <span class="material-symbols-rounded text-[20px]">add</span> Add Category
                    </x-ui.button>
                @endif
            </x-slot:actions>
        </x-erp.filter-bar>

        @if(session('success'))
            <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
        @endif

        @if(session('error'))
            <x-ui.alert variant="destructive" class="mb-4">{{ session('error') }}</x-ui.alert>
        @endif

        <div class="flex flex-col flex-1 min-h-0 space-y-4">
            {{-- Desktop/tablet-landscape: data table --}}
            <x-erp.data-table
                :hide-on-mobile="true"
                :is-empty="$categories->isEmpty()"
                empty-title="No categories found."
                empty-description="Create categories and subcategories to organize products."
                empty-icon="category"
                :colspan="$canManage ? 5 : 4"
            >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Code</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Category Name</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Parent Category</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
                    @endif
                </x-slot:head>
                
                @foreach($categories as $category)
                    <tr class="hover:bg-muted/40 transition-colors duration-200">
                        <td class="px-6 py-4 font-sans font-semibold text-foreground text-xs">{{ $category->category_code }}</td>
                        <td class="px-6 py-4">
                            <div class="font-semibold text-foreground text-sm">{{ $category->category_name }}</div>
                            @if($category->description)
                                <div class="text-xs text-muted-foreground mt-0.5">{{ Str::limit($category->description, 50) }}</div>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-muted-foreground">{{ $category->parent?->category_name ?? '— (Primary Category)' }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ ($category->is_active ?? true) ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ ($category->is_active ?? true) ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right">
                                <x-ui.table-actions>
                                    <x-ui.table-action-item icon="edit" @click="openEdit({{ json_encode($category) }})">
                                        Edit
                                    </x-ui.table-action-item>
                                    <x-ui.table-action-item icon="delete" variant="destructive" onclick="confirmSubmit('delete-form-{{ $category->id }}', '{{ addslashes($category->category_name) }}')">
                                        Delete
                                    </x-ui.table-action-item>
                                </x-ui.table-actions>
                                <form id="delete-form-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="hidden">
                                    @csrf
                                    @method('DELETE')
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
                @if($categories->hasPages())
                    <x-slot:pagination>
                        {{ $categories->links() }}
                    </x-slot:pagination>
                @endif
            </x-erp.data-table>

            {{-- Mobile/tablet-portrait: card list --}}
            <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
                @forelse($categories as $category)
                    <div class="card-surface p-4">
                        <div class="flex justify-between items-start">
                            <div class="flex flex-col">
                                <span class="font-bold text-foreground text-sm">{{ $category->category_name }}</span>
                                <span class="text-xs text-muted-foreground">{{ $category->category_code }}</span>
                            </div>
                            <span class="text-[11px] font-medium text-muted-foreground">{{ $category->created_at?->format('d M Y') ?? '—' }}</span>
                        </div>
                        @if($canManage)
                            <div class="flex items-center justify-end gap-1 mt-3 pt-3 border-t border-border">
                                <button type="button" @click="openEdit({{ json_encode($category) }})" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-primary-700 hover:bg-primary-100 transition-colors duration-200" aria-label="Edit category">
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </button>
                                <form id="delete-form-mobile-{{ $category->id }}" action="{{ route('categories.destroy', $category->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmSubmit('delete-form-mobile-{{ $category->id }}', '{{ addslashes($category->category_name) }}')" class="min-w-[36px] min-h-[36px] flex items-center justify-center rounded-lg text-primary-400 hover:text-danger-600 hover:bg-danger-50 transition-colors duration-200" aria-label="Delete">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="py-8 text-center text-muted-foreground text-sm flex flex-col items-center justify-center">
                        <div class="w-12 h-12 rounded-2xl bg-muted flex items-center justify-center mb-3">
                            <span class="material-symbols-rounded text-[24px] text-muted-foreground">category</span>
                        </div>
                        <p class="font-medium">No categories found.</p>
                    </div>
                @endforelse
            </div>
            @if($categories->hasPages())
                <div class="lg:hidden p-4 border-t border-border shrink-0">
                    {{ $categories->links() }}
                </div>
            @endif
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
                    <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Category Code (Optional)</label>
                    <input type="text" name="category_code" x-model="form.category_code" placeholder="e.g. BEV, FOOD" class="w-full rounded-xl border border-border px-3.5 py-2.5 text-sm text-foreground focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Category Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="category_name" x-model="form.category_name" required placeholder="Enter category name" class="w-full rounded-xl border border-border px-3.5 py-2.5 text-sm text-foreground focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Parent Category</label>
                    <select name="parent_id" x-model="form.parent_id" class="w-full rounded-xl border border-border bg-white px-3.5 py-2.5 text-sm text-foreground focus:border-primary-500 focus:outline-none">
                        <option value="">-- No Parent (Primary Category) --</option>
                        @foreach($parentCategories as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->category_name }} ({{ $parent->category_code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Display Order</label>
                    <input type="number" name="sort_order" x-model="form.sort_order" min="0" class="w-full rounded-xl border border-border px-3.5 py-2.5 text-sm text-foreground focus:border-primary-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">Description</label>
                    <textarea name="description" x-model="form.description" rows="3" placeholder="Optional description..." class="w-full rounded-xl border border-border px-3.5 py-2.5 text-sm text-foreground focus:border-primary-500 focus:outline-none"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-2">
                    <input type="checkbox" id="drawer_is_active" name="is_active" value="1" x-model="form.is_active" class="rounded border-border text-primary-600 focus:ring-primary-500">
                    <label for="drawer_is_active" class="text-sm font-medium text-foreground">Active Status</label>
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
