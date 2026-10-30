@php
    $canManage = auth()->user()?->can('create', \App\Models\Product::class) ?? false;
@endphp

<x-app-layout>
    @include('catalog.partials.sub-nav')

    <div data-gsap="page-enter" x-data="{
        selectedIds: [],
        selectAll: false,
        showImportModal: false,
        showBulkModal: false,
        bulkAction: 'update_status',
        toggleAll() {
            if (this.selectAll) {
                this.selectedIds = Array.from(document.querySelectorAll('.product-checkbox')).map(cb => cb.value);
            } else {
                this.selectedIds = [];
            }
        }
    }">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Products</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Manage master catalog, product types, pricing, and inventory</p>
            </div>

            <x-list-toolbar search-action="{{ route('products.index') }}" search-placeholder="Search by name, SKU, or barcode...">
                <x-slot:actions>
                    <a href="{{ route('products.export') }}" class="flex-1 sm:flex-none">
                        <x-ui.button variant="secondary" type="button" class="w-full h-10" x-on:click="window.erpMotion?.pulseHighlight($el)">
                            <span class="material-symbols-rounded text-[18px]">download</span> Export Excel
                        </x-ui.button>
                    </a>

                    @if($canManage)
                        <x-ui.button variant="secondary" type="button" @click="showImportModal = true" class="flex-1 sm:flex-none h-10" x-on:click="window.erpMotion?.pulseHighlight($el)">
                            <span class="material-symbols-rounded text-[18px]">upload</span> Import CSV
                        </x-ui.button>

                        <button type="button" x-show="selectedIds.length > 0" x-cloak @click="showBulkModal = true"
                            class="inline-flex items-center gap-1.5 px-3.5 h-10 rounded-xl bg-apeiron-ink text-white hover:bg-black text-xs font-semibold shadow-sm transition-all animate-pulse">
                            <span class="material-symbols-rounded text-[18px]">edit_note</span>
                            <span>Bulk Edit (<span x-text="selectedIds.length"></span>)</span>
                        </button>

                        <a href="{{ route('products.create') }}" class="flex-1 sm:flex-none" wire:navigate>
                            <x-ui.button variant="primary" type="button" class="w-full h-10" x-on:click="window.erpMotion?.pulseHighlight($el)">
                                <span class="material-symbols-rounded text-[18px]">add</span> Add Product
                            </x-ui.button>
                        </a>
                    @endif
                </x-slot:actions>
            </x-list-toolbar>
        </div>

        @if(session('success'))
            <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
        @endif
        @if(session('warning'))
            <x-ui.alert variant="warning" class="mb-4">
                <p class="font-bold">{{ session('warning') }}</p>
                @if(session('import_errors'))
                    <ul class="list-disc pl-5 mt-2 space-y-1 text-xs">
                        @foreach(session('import_errors') as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert variant="destructive" class="mb-4">{{ session('error') }}</x-ui.alert>
        @endif

        <div class="card-surface flex flex-col flex-1 min-h-0">
            <x-data-table>
                <x-slot:head>
                    @if($canManage)
                        <th scope="col" class="px-4 py-4 w-10 text-center">
                            <input type="checkbox" x-model="selectAll" @change="toggleAll()"
                                class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink h-4 w-4">
                        </th>
                    @endif
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Product</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Type</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Category & Brand</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Selling Price</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Physical Stock</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                    @if($canManage)
                        <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Actions</th>
                    @endif
                </x-slot:head>

                @forelse($products as $product)
                    @php
                        $typeBadges = [
                            'standard' => ['label' => 'Standard', 'type' => 'secondary'],
                            'service' => ['label' => 'Service', 'type' => 'info'],
                            'non_inventory' => ['label' => 'Non-Inventory', 'type' => 'secondary'],
                            'bundle' => ['label' => 'Bundle / Kit', 'type' => 'warning'],
                        ];
                        $statusBadges = [
                            'draft' => ['label' => 'Draft', 'type' => 'secondary'],
                            'active' => ['label' => 'Active', 'type' => 'success'],
                            'inactive' => ['label' => 'Inactive', 'type' => 'warning'],
                            'discontinued' => ['label' => 'Discontinued', 'type' => 'danger'],
                        ];
                        $prodType = $product->product_type ?? 'standard';
                        $prodStatus = $product->status ?? ($product->is_active ? 'active' : 'inactive');
                    @endphp
                    <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                        @if($canManage)
                            <td class="px-4 py-4 text-center">
                                <input type="checkbox" value="{{ $product->id }}" x-model="selectedIds"
                                    class="product-checkbox rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink h-4 w-4">
                            </td>
                        @endif
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                @if($product->product_photo)
                                    <img src="{{ asset('storage/' . $product->product_photo) }}" alt="{{ $product->product_name }}" loading="lazy" class="w-10 h-10 rounded-xl object-cover border border-[#E5E7EB]">
                                @else
                                    <div class="w-10 h-10 rounded-xl bg-[#F1F1EF] flex items-center justify-center text-[#C4C3C0]">
                                        <span class="material-symbols-rounded">image</span>
                                    </div>
                                @endif
                                <div>
                                    <div class="font-semibold text-apeiron-ink text-sm">{{ $product->product_name }}</div>
                                    <div class="font-mono text-xs text-[#787774]">
                                        {{ $product->product_code }}
                                        @if($product->barcode)
                                             <span class="text-[#9B9A97] ml-1">[{{ $product->barcode }}]</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <x-ui.badge :variant="$typeBadges[$prodType]['type'] ?? 'secondary'">
                                {{ $typeBadges[$prodType]['label'] ?? $prodType }}
                            </x-ui.badge>
                        </td>
                        <td class="px-6 py-4 text-sm text-[#787774]">
                            <div class="font-medium text-apeiron-ink">{{ $product->category?->category_name ?? '—' }}</div>
                            <div class="text-xs text-[#9B9A97]">{{ $product->brand?->name ?? 'No Brand' }}</div>
                        </td>
                        <td class="px-6 py-4 text-sm font-semibold text-apeiron-ink text-right tabular-nums">
                            Rp {{ number_format($product->product_price, 0, ',', '.') }}
                        </td>
                        <td class="px-6 py-4 text-sm text-[#787774] text-right tabular-nums">
                            @if($product->track_inventory)
                                <span class="font-semibold {{ $product->stock <= 0 ? 'text-danger-600' : 'text-apeiron-ink' }}">
                                    {{ $product->stock }}
                                </span>
                                <span class="text-xs text-[#9B9A97] ml-0.5">{{ $product->baseUom?->code ?? 'Pcs' }}</span>
                            @else
                                <span class="text-xs text-[#9B9A97] italic">Untracked</span>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <x-ui.badge :variant="$statusBadges[$prodStatus]['type'] ?? 'secondary'" :dot="true">
                                {{ $statusBadges[$prodStatus]['label'] ?? $prodStatus }}
                            </x-ui.badge>
                        </td>
                        @if($canManage)
                            <td class="px-6 py-4 text-right space-x-1">
                                <a href="{{ route('products.edit', $product->id) }}" class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors" aria-label="Edit product" wire:navigate>
                                    <span class="material-symbols-rounded text-[20px]">edit</span>
                                </a>
                                <form id="delete-form-{{ $product->id }}" action="{{ route('products.destroy', $product->id) }}" method="POST" class="inline-block">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" onclick="confirmDelete('delete-form-{{ $product->id }}', '{{ addslashes($product->product_name) }}')" class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors" aria-label="Delete">
                                        <span class="material-symbols-rounded text-[20px]">delete</span>
                                    </button>
                                </form>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canManage ? 8 : 7 }}" class="px-6 py-12 text-center text-[#9B9A97] text-sm">
                            <div class="flex flex-col items-center justify-center">
                                <div class="w-14 h-14 rounded-2xl bg-[#F1F1EF] flex items-center justify-center mb-3">
                                    <span class="material-symbols-rounded text-[28px] text-[#C4C3C0]">inventory_2</span>
                                </div>
                                <p class="font-medium">No products found.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </x-data-table>

            <div class="p-4 border-t border-[#E5E7EB]">
                {{ $products->links() }}
            </div>
        </div>

        {{-- Modal Impor CSV --}}
        <x-ui.modal x-model="showImportModal" maxWidth="md">
            <x-slot:header>Bulk Product Import (CSV)</x-slot:header>
            <x-slot:content>
                <form action="{{ route('products.import') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <x-form-label for="csv_file">Select CSV File (.csv) <span class="text-danger-600">*</span></x-form-label>
                        <input type="file" id="csv_file" name="file" accept=".csv,text/csv" required
                            class="w-full text-sm text-[#787774] file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#F7F7F5] file:text-apeiron-ink hover:file:bg-[#E9E9E7]">
                        <p class="text-xs text-[#787774] mt-2">Max 1,000 rows per file. Required columns: <code>product_name, category_id, product_price</code>. Includes automated CSV formula sanitization.</p>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <input type="checkbox" id="strict_mode" name="strict_mode" value="1"
                            class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                        <label for="strict_mode" class="text-xs text-apeiron-ink font-medium">Strict Mode (Roll back entire batch if any row fails)</label>
                    </div>

                    <div class="flex justify-end gap-2 pt-4 border-t border-[#E5E7EB]">
                        <x-ui.button variant="secondary" type="button" @click="showImportModal = false">Cancel</x-ui.button>
                        <x-ui.button variant="primary" type="submit" x-on:click="window.erpMotion?.pulseHighlight($el)">Upload & Process</x-ui.button>
                    </div>
                </form>
            </x-slot:content>
        </x-ui.modal>

        {{-- Modal Bulk Edit --}}
        <x-ui.modal x-model="showBulkModal" maxWidth="lg">
            <x-slot:header>Bulk Update (<span x-text="selectedIds.length"></span> Products)</x-slot:header>
            <x-slot:content>
                <form action="{{ route('products.bulk-update') }}" method="POST" class="space-y-4">
                    @csrf
                    <template x-for="id in selectedIds" :key="id">
                        <input type="hidden" name="product_ids[]" :value="id">
                    </template>

                    <div>
                        <x-form-label for="bulk_action">Select Bulk Action <span class="text-danger-600">*</span></x-form-label>
                        <select id="bulk_action" name="action" x-model="bulkAction" required
                            class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                            <option value="update_status">Update Product Lifecycle Status</option>
                            <option value="update_category">Move to Another Category</option>
                            <option value="update_price">Adjust Selling Price</option>
                            <option value="update_assortment">Update Branch Assortment & Availability</option>
                        </select>
                    </div>

                    {{-- Form Dinamis: Status --}}
                    <div x-show="bulkAction === 'update_status'" x-cloak>
                        <x-form-label for="bulk_status">Select New Status</x-form-label>
                        <select id="bulk_status" name="status"
                            class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                            <option value="active">Active (Available in POS & Menu)</option>
                            <option value="inactive">Inactive</option>
                            <option value="draft">Draft</option>
                            <option value="discontinued">Discontinued</option>
                        </select>
                    </div>

                    {{-- Form Dinamis: Kategori --}}
                    <div x-show="bulkAction === 'update_category'" x-cloak>
                        <x-form-label for="bulk_category">Select New Category</x-form-label>
                        <select id="bulk_category" name="category_id"
                            class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                            <option value="">-- No Category --</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->category_name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Form Dinamis: Harga --}}
                    <div x-show="bulkAction === 'update_price'" x-cloak class="grid grid-cols-2 gap-4">
                        <div>
                            <x-form-label for="bulk_price_mode">Adjustment Mode</x-form-label>
                            <select id="bulk_price_mode" name="price_mode"
                                class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                <option value="fixed">Set Fixed Price (Rp)</option>
                                <option value="percentage_increase">Increase by (%)</option>
                                <option value="percentage_decrease">Decrease by (%)</option>
                            </select>
                        </div>
                        <div>
                            <x-form-label for="bulk_price_value">Adjustment Value</x-form-label>
                            <input type="number" id="bulk_price_value" name="price_value" step="0.01" min="0" value="0"
                                class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                        </div>
                    </div>

                    {{-- Form Dinamis: Asortimen Cabang --}}
                    <div x-show="bulkAction === 'update_assortment'" x-cloak class="space-y-4">
                        <div>
                            <x-form-label for="bulk_store_id">Select Branch</x-form-label>
                            <select id="bulk_store_id" name="store_id"
                                class="w-full h-10 px-3 rounded-xl border border-[#E5E7EB] text-sm bg-white focus:border-apeiron-ink focus:ring-1 focus:ring-apeiron-ink">
                                @foreach ($stores as $st)
                                    <option value="{{ $st->id }}">{{ $st->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="bulk_is_available" name="is_available" value="1" checked
                                class="rounded border-gray-300 text-apeiron-ink focus:ring-apeiron-ink">
                            <label for="bulk_is_available" class="text-sm font-medium text-apeiron-ink">Available at this branch</label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 pt-4 border-t border-[#E5E7EB]">
                        <x-ui.button variant="secondary" type="button" @click="showBulkModal = false">Cancel</x-ui.button>
                        <x-ui.button variant="primary" type="submit" x-on:click="window.erpMotion?.pulseHighlight($el)">Apply Bulk Changes</x-ui.button>
                    </div>
                </form>
            </x-slot:content>
        </x-ui.modal>
    </div>
</x-app-layout>
