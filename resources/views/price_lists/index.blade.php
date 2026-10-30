@php
    $canManage = in_array(auth()->user()?->role?->name, ['Owner', 'Manager'], true);
@endphp

<x-app-layout>
    <div x-data="{
        createDrawerOpen: {{ $errors->any() ? 'true' : 'false' }},
        type: 'standard',
        items: [
            { product_id: '', min_quantity: 1, price: 0 }
        ],
        addItem() {
            this.items.push({ product_id: '', min_quantity: 1, price: 0 });
        },
        removeItem(index) {
            if (this.items.length > 1) {
                this.items.splice(index, 1);
            }
        }
    }">
        @include('catalog.partials.sub-nav')

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6" data-gsap="page-enter">
            <div>
                <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Price Lists & Tiered Pricing</h4>
                <p class="text-sm font-medium text-[#787774] mt-1">Manage volume-tiered wholesale rules, loyalty tier pricing, and customer contracts</p>
            </div>

            <x-list-toolbar search-action="{{ route('price-lists.index') }}" search-placeholder="Search price lists...">
                <x-slot:actions>
                    @if($canManage)
                        <x-ui.button variant="primary" type="button" class="w-full sm:w-auto h-10" @click="createDrawerOpen = true">
                            <span class="material-symbols-rounded text-[18px]">add</span> Create Price List
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
        <x-data-table>
            <x-slot:head>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-center">Priority</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Code & Name</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Rule Type</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Scope / Target</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Items</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Validity Period</th>
                <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Status</th>
                @if($canManage)
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Actions</th>
                @endif
            </x-slot:head>

            @forelse($priceLists as $pl)
                @php
                    $typeLabels = [
                        'standard' => 'Standard',
                        'branch' => 'Branch',
                        'wholesale' => 'Tiered Wholesale',
                        'customer_tier' => 'Loyalty Tier',
                        'customer_specific' => 'Custom Contract',
                        'channel' => 'Sales Channel',
                    ];
                    $typeBadgeTypes = [
                        'standard' => 'secondary',
                        'branch' => 'info',
                        'wholesale' => 'warning',
                        'customer_tier' => 'info',
                        'customer_specific' => 'success',
                        'channel' => 'secondary',
                    ];
                @endphp
                <tr class="hover:bg-[#F7F7F5] transition-colors duration-200">
                    <td class="px-6 py-4 text-center font-bold text-sm text-apeiron-ink tabular-nums">#{{ $pl->priority }}</td>
                    <td class="px-6 py-4">
                        <div class="font-semibold text-sm text-apeiron-ink">{{ $pl->name }}</div>
                        <div class="font-mono text-xs text-[#787774]">{{ $pl->code }}</div>
                    </td>
                    <td class="px-6 py-4">
                        <x-ui.badge :variant="$typeBadgeTypes[$pl->type] ?? 'secondary'">
                            {{ $typeLabels[$pl->type] ?? $pl->type }}
                        </x-ui.badge>
                    </td>
                    <td class="px-6 py-4 text-sm text-[#787774]">
                        @if($pl->customer)
                            <div class="font-medium text-apeiron-ink">{{ $pl->customer->name }}</div>
                            <div class="text-xs">Custom Customer</div>
                        @elseif($pl->loyaltyTier)
                            <div class="font-medium text-apeiron-ink">{{ $pl->loyaltyTier->name }}</div>
                            <div class="text-xs">Member Tier</div>
                        @elseif($pl->store)
                            <div class="font-medium text-apeiron-ink">{{ $pl->store->name }}</div>
                            <div class="text-xs">Specific Branch</div>
                        @elseif($pl->channel)
                            <div class="font-medium text-apeiron-ink">{{ strtoupper($pl->channel) }}</div>
                            <div class="text-xs">Channel</div>
                        @else
                            <span class="text-xs italic text-[#9B9A97]">All Customers / Global</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm font-semibold text-right text-apeiron-ink tabular-nums">{{ $pl->items_count }} items</td>
                    <td class="px-6 py-4 text-xs text-[#787774]">
                        @if($pl->valid_from || $pl->valid_until)
                            {{ $pl->valid_from?->format('d/m/Y') ?? 'Start' }} to {{ $pl->valid_until?->format('d/m/Y') ?? 'Indefinite' }}
                        @else
                            <span class="text-xs text-[#9B9A97]">Permanent</span>
                        @endif
                    </td>
                    <td class="px-6 py-4">
                        @if($pl->is_active)
                            <x-ui.badge variant="success" :dot="true">Active</x-ui.badge>
                        @else
                            <x-ui.badge variant="secondary" :dot="true">Inactive</x-ui.badge>
                        @endif
                    </td>
                    @if($canManage)
                        <td class="px-6 py-4 text-right space-x-1">
                            <a href="{{ route('price-lists.edit', $pl->id) }}" wire:navigate
                                class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-primary-500 hover:text-primary-700 hover:bg-primary-100 transition-colors"
                                aria-label="Edit price list">
                                <span class="material-symbols-rounded text-[20px]">edit</span>
                            </a>
                            <form id="delete-pl-{{ $pl->id }}" action="{{ route('price-lists.destroy', $pl->id) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-pl-{{ $pl->id }}', '{{ addslashes($pl->name) }}')"
                                    class="inline-flex items-center justify-center min-w-9 min-h-9 rounded-lg text-danger-500 hover:text-danger-700 hover:bg-danger-50 transition-colors"
                                    aria-label="Delete price list">
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
                            <span class="material-symbols-rounded text-4xl text-[#C4C3C0] mb-2">price_change</span>
                            <p class="font-medium">No custom price lists or wholesale rules registered.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        <div class="p-4 border-t border-[#E5E7EB]">
            {{ $priceLists->links() }}
        </div>
    </div>

        {{-- Contextual Drawer for PAGE-035 (Create Price List) --}}
        <x-ui.drawer x-model="createDrawerOpen" maxWidth="xl">
            <x-slot:header>
                <span>Create Price List & Wholesale Rule</span>
            </x-slot:header>

            <form action="{{ route('price-lists.store') }}" method="POST" id="priceListDrawerForm" class="space-y-5">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Price List Code <span class="text-rose-500">*</span></label>
                        <input type="text" name="code" value="{{ old('code') }}" required maxlength="64" placeholder="e.g. PL-WHOLESALE-01" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm uppercase focus:border-primary-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Price Rule Name <span class="text-rose-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="150" placeholder="e.g. Wholesale Price Min. 10 Pcs" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm focus:border-primary-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Rule Type / Scope <span class="text-rose-500">*</span></label>
                        <select name="type" x-model="type" required class="w-full rounded-xl border border-[#E5E7EB] bg-white px-3.5 py-2 text-sm focus:border-primary-500 focus:outline-none">
                            <option value="standard">Standard (Global / General)</option>
                            <option value="wholesale">Tiered Wholesale (Volume Qty)</option>
                            <option value="customer_tier">Loyalty Member Tier</option>
                            <option value="customer_specific">Custom Customer Contract</option>
                            <option value="branch">Branch Specific</option>
                            <option value="channel">Sales Channel Specific (Online Delivery, etc.)</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Execution Priority</label>
                        <input type="number" name="priority" value="{{ old('priority', 0) }}" min="0" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm focus:border-primary-500 focus:outline-none">
                    </div>
                </div>

                {{-- Target Fields based on Type --}}
                <div x-show="type === 'branch'" class="p-3 bg-zinc-50 rounded-xl border border-zinc-200">
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Target Branch</label>
                    <select name="store_id" class="w-full rounded-xl border border-[#E5E7EB] bg-white px-3.5 py-2 text-sm">
                        <option value="">Select Branch</option>
                        @foreach($stores as $st)
                            <option value="{{ $st->id }}">{{ $st->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'customer_specific'" class="p-3 bg-zinc-50 rounded-xl border border-zinc-200">
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Target Customer</label>
                    <select name="customer_id" class="w-full rounded-xl border border-[#E5E7EB] bg-white px-3.5 py-2 text-sm">
                        <option value="">Select Customer</option>
                        @foreach($customers as $cust)
                            <option value="{{ $cust->id }}">{{ $cust->name }} ({{ $cust->phone ?? '-' }})</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'customer_tier'" class="p-3 bg-zinc-50 rounded-xl border border-zinc-200">
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Target Loyalty Tier</label>
                    <select name="loyalty_tier_id" class="w-full rounded-xl border border-[#E5E7EB] bg-white px-3.5 py-2 text-sm">
                        <option value="">Select Tier</option>
                        @foreach($loyaltyTiers as $tier)
                            <option value="{{ $tier->id }}">{{ $tier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="type === 'channel'" class="p-3 bg-zinc-50 rounded-xl border border-zinc-200">
                    <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Channel Name</label>
                    <input type="text" name="channel" placeholder="e.g. GoFood, GrabFood, ShopeeFood" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm">
                </div>

                {{-- Date range & status --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Valid From</label>
                        <input type="date" name="valid_from" value="{{ old('valid_from') }}" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-[#787774] uppercase tracking-wider mb-1">Valid Until</label>
                        <input type="date" name="valid_until" value="{{ old('valid_until') }}" class="w-full rounded-xl border border-[#E5E7EB] px-3.5 py-2 text-sm">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" id="pl_is_active" name="is_active" value="1" checked class="rounded border-[#E5E7EB] text-primary-600 focus:ring-primary-500">
                    <label for="pl_is_active" class="text-sm font-medium text-apeiron-ink">Activate this price rule immediately</label>
                </div>

                {{-- Item Pricing Repeater --}}
                <div class="pt-3 border-t border-[#E5E7EB]">
                    <div class="flex items-center justify-between mb-2">
                        <h5 class="text-sm font-bold text-apeiron-ink">Product Pricing Rules</h5>
                        <button type="button" @click="addItem()" class="inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-800 font-semibold">
                            <span class="material-symbols-rounded text-sm">add</span> Add Row
                        </button>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(item, idx) in items" :key="idx">
                            <div class="flex items-center gap-2 p-2 bg-zinc-50 rounded-lg border border-zinc-200">
                                <select :name="`items[${idx}][product_id]`" x-model="item.product_id" required class="flex-1 rounded-lg border border-[#E5E7EB] bg-white px-2 py-1.5 text-xs">
                                    <option value="">Select Product</option>
                                    @foreach($products as $prod)
                                        <option value="{{ $prod->id }}">{{ $prod->product_name }}</option>
                                    @endforeach
                                </select>
                                <input type="number" :name="`items[${idx}][min_quantity]`" x-model="item.min_quantity" placeholder="Min. Qty" min="1" required class="w-20 rounded-lg border border-[#E5E7EB] px-2 py-1.5 text-xs text-right">
                                <input type="number" :name="`items[${idx}][price]`" x-model="item.price" placeholder="Price (Rp)" min="0" step="100" required class="w-28 rounded-lg border border-[#E5E7EB] px-2 py-1.5 text-xs text-right">
                                <button type="button" @click="removeItem(idx)" class="text-rose-500 hover:text-rose-700 p-1" title="Remove Row">
                                    <span class="material-symbols-rounded text-sm">delete</span>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" @click="createDrawerOpen = false">
                    Cancel
                </x-ui.button>
                <x-ui.button variant="primary" type="submit" form="priceListDrawerForm">
                    Save Price Rule
                </x-ui.button>
            </x-slot:footer>
        </x-ui.drawer>
    </div>
</x-app-layout>
