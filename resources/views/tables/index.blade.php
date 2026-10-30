<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter">
        @php
            $activeStoreId = app(\App\Services\Context\TenantContext::class)->getStoreId();
            $activeStore = $activeStoreId ? \App\Models\Store::find($activeStoreId) : null;
        @endphp

        <!-- Header -->
        <x-erp.page-header
            title="Table Management"
            description="Manage operational floor plans, table layouts, and self-ordering QR codes for your branches.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('dashboard') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Tables</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-3">
                    @if($activeStore)
                        <div class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-200 border border-zinc-200 dark:border-zinc-700">
                            <span class="material-symbols-rounded text-[16px] text-zinc-500">storefront</span>
                            <span>{{ $activeStore->name }}</span>
                        </div>
                    @endif
                    <x-ui.button variant="primary" href="{{ route('tables.create') }}" class="gap-2" wire:navigate>
                        <span class="material-symbols-rounded text-base">add</span> Add Table
                    </x-ui.button>
                </div>
            </x-slot:actions>
        </x-erp.page-header>

        @if(session('success'))
            <x-ui.alert type="success" title="Success" class="rounded-2xl">
                {{ session('success') }}
            </x-ui.alert>
        @endif
        @if(session('error'))
            <x-ui.alert type="danger" title="Error" class="rounded-2xl">
                {{ session('error') }}
            </x-ui.alert>
        @endif

        <!-- Filter & Search Toolbar -->
        <div class="card-surface p-4 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4 border border-[#E5E7EB] dark:border-zinc-800">
            <form method="GET" action="{{ route('tables.index') }}" class="relative w-full sm:w-80">
                <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400 text-lg">search</span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search table code or name..." class="w-full pl-10 pr-4 py-2 text-sm rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 focus:outline-hidden focus:ring-2 focus:ring-apeiron-primary/20 text-zinc-800 dark:text-zinc-100">
            </form>
        </div>

        <div class="card-surface flex flex-col flex-1 min-h-0 rounded-2xl border border-[#E5E7EB] dark:border-zinc-800 overflow-hidden shadow-xs">
        {{-- Desktop/tablet-landscape: existing table --}}
        <x-data-table :hideOnMobile="true">
            <x-slot:head>
                <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Table Code</th>
                <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Name & Area</th>
                <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Operational Status</th>
                <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-left">Self-Order QR Code</th>
                <th scope="col" class="px-6 py-3.5 text-xs font-bold text-gray-500 uppercase tracking-wider text-right">Actions</th>
            </x-slot:head>
            @forelse($tables as $table)
                @php
                    $routeToken = $table->qr_token ?? $table->secure_token;
                @endphp
                <tr class="hover:bg-gray-50/70 transition-colors duration-150">
                    <td class="px-6 py-4 font-mono font-bold text-apeiron-ink text-xs whitespace-nowrap">{{ $table->table_code }}</td>
                    <td class="px-6 py-4">
                        <div class="font-bold text-apeiron-ink text-sm">{{ $table->table_name }}</div>
                        <div class="text-xs text-gray-500 mt-1 flex items-center gap-2">
                            <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">chair</span> {{ $table->capacity }} pax</span>
                            <span class="text-gray-300">&bull;</span>
                            <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[14px]">map</span> {{ $table->area }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex flex-col gap-1.5 items-start">
                            <x-ui.badge :variant="$table->is_active ? 'success' : 'secondary'">
                                {{ $table->is_active ? 'Active' : 'Inactive' }}
                            </x-ui.badge>
                            @php
                                $opLabels = [
                                    'available' => 'Available',
                                    'occupied' => 'Occupied',
                                    'cleaning' => 'Cleaning',
                                    'reserved' => 'Reserved',
                                    'disabled' => 'Disabled',
                                ];
                            @endphp
                            <x-ui.badge :variant="match($table->operational_status) {
                                'available' => 'success', 'occupied' => 'destructive', 'cleaning' => 'warning', 'reserved' => 'primary', default => 'secondary'
                            }">
                                {{ $opLabels[$table->operational_status] ?? $table->operational_status }}
                            </x-ui.badge>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="bg-white p-2 rounded-xl border border-gray-200 inline-block shadow-xs">
                                {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(70)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $routeToken], false))) !!}
                            </div>
                            <div class="text-xs text-gray-500">
                                <div class="font-bold text-apeiron-ink">Version #{{ $table->qr_token_version ?? 1 }}</div>
                                @if($table->qr_rotated_at)
                                    <div class="text-[11px] mt-0.5">Rotated: {{ $table->qr_rotated_at->format('M d, H:i') }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-right space-x-1">
                        @if($table->operational_status === 'occupied')
                            <form action="{{ route('tables.release', $routeToken) }}" method="POST" class="inline-block">
                                @csrf
                                <button type="submit" onclick="return confirm('Release table {{ $table->table_name }}? Please ensure all active orders are settled.')"
                                    x-on:click="window.erpMotion?.pulseHighlight($el)"
                                    class="inline-flex items-center justify-center size-9 rounded-lg text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors active:scale-90" aria-label="Release Table" title="Release Table (Mark Available)">
                                    <span class="material-symbols-rounded text-[20px]">meeting_room</span>
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('tables.edit', $routeToken) }}"
                            x-on:click="window.erpMotion?.pulseHighlight($el)"
                            class="inline-flex items-center justify-center size-9 rounded-lg text-gray-600 hover:text-apeiron-ink hover:bg-gray-100 transition-colors active:scale-90" aria-label="Edit table" wire:navigate>
                            <span class="material-symbols-rounded text-[20px]">edit</span>
                        </a>
                        <form action="{{ route('tables.rotate-qr', $routeToken) }}" method="POST" class="inline-block">
                            @csrf
                            <button type="submit" onclick="return confirm('Rotate QR token for table {{ $table->table_name }}? The physical QR code will need to be reprinted, and any existing customer sessions will be invalidated.')"
                                x-on:click="window.erpMotion?.pulseHighlight($el)"
                                class="inline-flex items-center justify-center size-9 rounded-lg text-gray-600 hover:text-amber-600 hover:bg-amber-50 transition-colors active:scale-90" aria-label="Rotate QR" title="Rotate Table QR Code">
                                <span class="material-symbols-rounded text-[20px]">sync</span>
                            </button>
                        </form>
                        <form id="delete-form-{{ $table->id }}" action="{{ route('tables.destroy', $routeToken) }}" method="POST" class="inline-block">
                            @csrf
                            @method('DELETE')
                            <button type="button" onclick="confirmDelete('delete-form-{{ $table->id }}', '{{ $table->table_name }}')"
                                class="inline-flex items-center justify-center size-9 rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors active:scale-90" aria-label="Delete table">
                                <span class="material-symbols-rounded text-[20px]">delete</span>
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-gray-400 text-sm">
                        <div class="flex flex-col items-center justify-center">
                            <div class="w-14 h-14 rounded-2xl bg-gray-50 flex items-center justify-center mb-3 border border-gray-100">
                                <span class="material-symbols-rounded text-[28px] text-gray-400">table_restaurant</span>
                            </div>
                            <p class="font-bold text-gray-600">No tables registered for this store branch yet.</p>
                            <p class="text-xs text-gray-400 mt-1">Click the Add Table button above to configure a new table.</p>
                        </div>
                    </td>
                </tr>
            @endforelse
        </x-data-table>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($tables as $table)
                @php
                    $routeToken = $table->qr_token ?? $table->secure_token;
                @endphp
                <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-xs" data-gsap="kpi-card">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex flex-col">
                            <span class="font-bold text-apeiron-ink text-sm">{{ $table->table_name }}</span>
                            <span class="text-xs text-gray-500 font-mono">{{ $table->table_code }} &bull; v{{ $table->qr_token_version ?? 1 }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <x-ui.badge :variant="$table->is_active ? 'success' : 'secondary'">
                                {{ $table->is_active ? 'Active' : 'Inactive' }}
                            </x-ui.badge>
                            @php
                                $opLabels = [
                                    'available' => 'Available',
                                    'occupied' => 'Occupied',
                                    'cleaning' => 'Cleaning',
                                    'reserved' => 'Reserved',
                                    'disabled' => 'Disabled',
                                ];
                            @endphp
                            <x-ui.badge :variant="match($table->operational_status) {
                                'available' => 'success', 'occupied' => 'destructive', 'cleaning' => 'warning', 'reserved' => 'primary', default => 'secondary'
                            }">
                                {{ $opLabels[$table->operational_status] ?? $table->operational_status }}
                            </x-ui.badge>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 pt-3 border-t border-gray-100">
                        <div class="bg-white p-2 rounded-xl border border-gray-200 shadow-xs shrink-0">
                            {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(55)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $routeToken], false))) !!}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-semibold text-apeiron-ink flex items-center gap-1.5 mb-1"><span class="material-symbols-rounded text-[16px] text-gray-400">chair</span> {{ $table->capacity }} pax</div>
                            <div class="text-xs font-semibold text-apeiron-ink flex items-center gap-1.5"><span class="material-symbols-rounded text-[16px] text-gray-400">map</span> {{ $table->area }}</div>
                        </div>
                        <div class="flex flex-col gap-1 shrink-0">
                            @if($table->operational_status === 'occupied')
                                <form action="{{ route('tables.release', $routeToken) }}" method="POST">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Release table {{ $table->table_name }}?')" class="size-8 flex items-center justify-center rounded-lg text-emerald-600 hover:text-emerald-700 hover:bg-emerald-50 transition-colors" aria-label="Release Table" title="Release Table">
                                        <span class="material-symbols-rounded text-[18px]">meeting_room</span>
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('tables.edit', $routeToken) }}" class="size-8 flex items-center justify-center rounded-lg text-gray-600 hover:text-apeiron-ink hover:bg-gray-100 transition-colors" aria-label="Edit table" wire:navigate>
                                <span class="material-symbols-rounded text-[18px]">edit</span>
                            </a>
                            <form action="{{ route('tables.rotate-qr', $routeToken) }}" method="POST">
                                @csrf
                                <button type="submit" onclick="return confirm('Rotate QR token for table {{ $table->table_name }}?')" class="size-8 flex items-center justify-center rounded-lg text-gray-600 hover:text-amber-600 hover:bg-amber-50 transition-colors" aria-label="Rotate QR" title="Rotate QR">
                                    <span class="material-symbols-rounded text-[18px]">sync</span>
                                </button>
                            </form>
                            <form id="delete-form-mobile-{{ $table->id }}" action="{{ route('tables.destroy', $routeToken) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-mobile-{{ $table->id }}', '{{ $table->table_name }}')" class="size-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-rose-600 hover:bg-rose-50 transition-colors" aria-label="Delete table">
                                    <span class="material-symbols-rounded text-[18px]">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-gray-400 text-xs flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-gray-50 flex items-center justify-center mb-2 border border-gray-100">
                        <span class="material-symbols-rounded text-[24px] text-gray-400">table_restaurant</span>
                    </div>
                    <p class="font-bold text-gray-600">No tables registered for this store branch yet.</p>
                </div>
            @endforelse
        </div>
        <div class="p-4 border-t border-gray-100 shrink-0">{{ $tables->links() }}</div>
    </div>
</x-app-layout>
