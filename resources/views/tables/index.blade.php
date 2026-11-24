<x-app-layout>
    <div class="space-y-6" data-gsap="page-enter"
        x-data="{
            moveModalOpen: false,
            sourceTableId: null,
            sourceTableName: '',
            mergeModalOpen: false,
            primaryTableId: null,
            primaryTableName: '',
            openMove(id, name) {
                this.sourceTableId = id;
                this.sourceTableName = name;
                this.moveModalOpen = true;
            },
            openMerge(id, name) {
                this.primaryTableId = id;
                this.primaryTableName = name;
                this.mergeModalOpen = true;
            }
        }">
        @php
            $activeStoreId = app(\App\Core\Context\TenantContext::class)->getStoreId();
            $activeStore = $activeStoreId ? \App\Modules\Organization\Models\Store::find($activeStoreId) : null;
        @endphp

        <!-- Header -->
        <x-erp.page-header
            title="Table Management"
            description="Manage operational floor plans, table layouts, and self-ordering QR codes for your branches.">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs.item href="{{ route('orders.index') }}">Dashboard</x-ui.breadcrumbs.item>
                <x-ui.breadcrumbs.item active>Tables</x-ui.breadcrumbs.item>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <div class="flex items-center gap-3">
                    @if($activeStore)
                        <div class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold bg-secondary text-foreground border border-border">
                            <span class="material-symbols-rounded text-[16px] text-muted-foreground">storefront</span>
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
        <x-erp.filter-bar
            search-action="{{ route('tables.index') }}"
            search-placeholder="Search table code or name..."
        />

        <div class="flex flex-col flex-1 min-h-0 space-y-4">
        {{-- Desktop/tablet-landscape: existing table --}}
        <x-erp.data-table
            :hideOnMobile="true"
            :is-empty="$tables->isEmpty()"
            empty-title="No tables registered for this store branch yet."
            empty-description="Click the Add Table button above to configure a new table."
            empty-icon="table_restaurant"
            :colspan="5"
        >
            <x-slot:head>
                <th scope="col" class="h-8 px-3 py-2 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider text-left">Table Code</th>
                <th scope="col" class="h-8 px-3 py-2 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider text-left">Name & Area</th>
                <th scope="col" class="h-8 px-3 py-2 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider text-left">Operational Status</th>
                <th scope="col" class="h-8 px-3 py-2 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider text-left">Self-Order QR Code</th>
                <th scope="col" class="h-8 px-3 py-2 text-[10.5px] font-semibold text-muted-foreground uppercase tracking-wider text-right">Actions</th>
            </x-slot:head>
            @foreach($tables as $table)
                @php
                    $routeToken = $table->qr_token ?? $table->secure_token;
                @endphp
                <tr class="hover:bg-secondary/70 transition-colors duration-150">
                    <td class="px-3 py-2 font-sans font-bold text-foreground text-[11px] whitespace-nowrap">{{ $table->table_code }}</td>
                    <td class="px-3 py-2">
                        <div class="font-medium text-foreground text-[12px]">{{ $table->table_name }}</div>
                        <div class="text-[11px] text-muted-foreground mt-0.5 flex items-center gap-2">
                            <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[13px]">chair</span> {{ $table->capacity }} pax</span>
                            <span class="text-foreground">&bull;</span>
                            <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-[13px]">map</span> {{ $table->area }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex flex-col gap-0.5 items-start">
                            <span class="inline-flex items-center gap-1.5 text-[11px] text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $table->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ $table->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            @php
                                $opLabels = [
                                    'available' => 'Available',
                                    'occupied' => 'Occupied',
                                    'cleaning' => 'Cleaning',
                                    'reserved' => 'Reserved',
                                    'disabled' => 'Disabled',
                                ];
                                $opDot = match($table->operational_status) {
                                    'available' => 'bg-success',
                                    'occupied' => 'bg-destructive',
                                    'cleaning' => 'bg-warning',
                                    'reserved' => 'bg-info',
                                    default => 'bg-neutral',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-[11px] text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $opDot }}"></span>
                                {{ $opLabels[$table->operational_status] ?? $table->operational_status }}
                            </span>
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <div class="flex items-center gap-2.5">
                            <div class="bg-card p-1.5 rounded-[6px] border border-border inline-block shadow-2xs">
                                {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(54)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $routeToken], false))) !!}
                            </div>
                            <div class="text-[11px] text-muted-foreground">
                                <div class="font-bold text-foreground">Version #{{ $table->qr_token_version ?? 1 }}</div>
                                @if($table->qr_rotated_at)
                                    <div class="text-[10px] mt-0.5">Rotated: {{ $table->qr_rotated_at->format('M d, H:i') }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-3 py-2 text-right whitespace-nowrap">
                        <x-ui.table-actions>
                            @if($table->operational_status === 'occupied')
                                <x-ui.table-action-item icon="drive_file_move" @click="openMove({{ $table->id }}, '{{ addslashes($table->table_name) }}')" aria-label="Move Table">
                                    Pindah Meja (Move)
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="merge_type" @click="openMerge({{ $table->id }}, '{{ addslashes($table->table_name) }}')" aria-label="Merge Tables">
                                    Gabung Meja (Merge)
                                </x-ui.table-action-item>
                                <x-ui.table-action-item icon="meeting_room" onclick="confirmSubmit('release-form-{{ $table->id }}', '{{ addslashes($table->table_name) }}', { title: 'Kosongkan Meja', message: 'Pastikan semua pesanan aktif di meja ini sudah diselesaikan.', confirmText: 'Kosongkan Meja' })" aria-label="Release Table">
                                    Release Table
                                </x-ui.table-action-item>
                            @endif
                            <x-ui.table-action-item icon="edit" href="{{ route('tables.edit', $routeToken) }}" wire:navigate aria-label="Edit Table">
                                Edit Table
                            </x-ui.table-action-item>
                            <x-ui.table-action-item icon="sync" onclick="confirmSubmit('rotate-qr-form-{{ $table->id }}', '{{ addslashes($table->table_name) }}', { title: 'Rotasi QR Code', message: 'QR code fisik perlu dicetak ulang, dan sesi pelanggan aktif akan dihentikan.', confirmText: 'Rotasi QR' })" aria-label="Rotate QR">
                                Rotate QR Code
                            </x-ui.table-action-item>
                            <x-ui.table-action-item icon="delete" variant="destructive" onclick="confirmSubmit('delete-form-{{ $table->id }}', '{{ addslashes($table->table_name) }}')" aria-label="Delete Table">
                                Delete Table
                            </x-ui.table-action-item>
                        </x-ui.table-actions>

                        @if($table->operational_status === 'occupied')
                            <form id="release-form-{{ $table->id }}" action="{{ route('tables.release', $routeToken) }}" method="POST" class="hidden">
                                @csrf
                            </form>
                        @endif
                        <form id="rotate-qr-form-{{ $table->id }}" action="{{ route('tables.rotate-qr', $routeToken) }}" method="POST" class="hidden">
                            @csrf
                        </form>
                        <form id="delete-form-{{ $table->id }}" action="{{ route('tables.destroy', $routeToken) }}" method="POST" class="hidden">
                            @csrf
                            @method('DELETE')
                        </form>
                    </td>
                </tr>
            @endforeach
            @if($tables->hasPages())
                <x-slot:pagination>
                    {{ $tables->links() }}
                </x-slot:pagination>
            @endif
        </x-erp.data-table>

        {{-- Mobile/tablet-portrait: card list --}}
        <div class="lg:hidden flex flex-col gap-3 p-4 overflow-y-auto flex-1">
            @forelse($tables as $table)
                @php
                    $routeToken = $table->qr_token ?? $table->secure_token;
                @endphp
                <div class="bg-card rounded-2xl border border-border p-4 shadow-xs" data-gsap="kpi-card">
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex flex-col">
                            <span class="font-bold text-foreground text-sm">{{ $table->table_name }}</span>
                            <span class="text-xs text-muted-foreground font-sans">{{ $table->table_code }} &bull; v{{ $table->qr_token_version ?? 1 }}</span>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $table->is_active ? 'bg-success' : 'bg-neutral' }}"></span>
                                {{ $table->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            @php
                                $opLabels = [
                                    'available' => 'Available',
                                    'occupied' => 'Occupied',
                                    'cleaning' => 'Cleaning',
                                    'reserved' => 'Reserved',
                                    'disabled' => 'Disabled',
                                ];
                                $opDot = match($table->operational_status) {
                                    'available' => 'bg-success',
                                    'occupied' => 'bg-destructive',
                                    'cleaning' => 'bg-warning',
                                    'reserved' => 'bg-primary',
                                    default => 'bg-neutral',
                                };
                            @endphp
                            <span class="inline-flex items-center gap-1.5 text-xs text-foreground font-medium">
                                <span class="w-1.5 h-1.5 rounded-full {{ $opDot }}"></span>
                                {{ $opLabels[$table->operational_status] ?? $table->operational_status }}
                            </span>
                        </div>
                    </div>
                    
                    <div class="flex items-center gap-4 pt-3 border-t border-border">
                        <div class="bg-card p-2 rounded-xl border border-border shadow-xs shrink-0">
                            {!! Cache::remember('pos:qr:table:'.$table->id, 86400, fn() => (string) QrCode::size(55)->generate(rtrim(config('app.url'), '/') . route('customer.menu.index', ['token' => $routeToken], false))) !!}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-semibold text-foreground flex items-center gap-1.5 mb-1"><span class="material-symbols-rounded text-[16px] text-muted-foreground">chair</span> {{ $table->capacity }} pax</div>
                            <div class="text-xs font-semibold text-foreground flex items-center gap-1.5"><span class="material-symbols-rounded text-[16px] text-muted-foreground">map</span> {{ $table->area }}</div>
                        </div>
                        <div class="flex flex-col gap-1 shrink-0">
                            @if($table->operational_status === 'occupied')
                                <form id="release-form-mobile-{{ $table->id }}" action="{{ route('tables.release', $routeToken) }}" method="POST">
                                    @csrf
                                    <button type="button" onclick="confirmSubmit('release-form-mobile-{{ $table->id }}', '{{ addslashes($table->table_name) }}', { title: 'Kosongkan Meja', message: 'Pastikan semua pesanan aktif di meja ini sudah diselesaikan.', confirmText: 'Kosongkan Meja' })" class="size-8 flex items-center justify-center rounded-lg text-positive hover:text-positive hover:bg-positive-muted transition-colors" aria-label="Release Table" title="Release Table">
                                        <span class="material-symbols-rounded text-[20px]">meeting_room</span>
                                    </button>
                                </form>
                            @endif
                            <a href="{{ route('tables.edit', $routeToken) }}" class="size-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors" aria-label="Edit table" wire:navigate>
                                <span class="material-symbols-rounded text-[20px]">edit</span>
                            </a>
                            <form id="rotate-qr-form-mobile-{{ $table->id }}" action="{{ route('tables.rotate-qr', $routeToken) }}" method="POST">
                                @csrf
                                <button type="button" onclick="confirmSubmit('rotate-qr-form-mobile-{{ $table->id }}', '{{ addslashes($table->table_name) }}', { title: 'Rotasi QR Code', message: 'QR code fisik perlu dicetak ulang, dan sesi pelanggan aktif akan dihentikan.', confirmText: 'Rotasi QR' })" class="size-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-warning hover:bg-warning-muted transition-colors" aria-label="Rotate QR" title="Rotate QR">
                                    <span class="material-symbols-rounded text-[20px]">sync</span>
                                </button>
                            </form>
                            <form id="delete-form-mobile-{{ $table->id }}" action="{{ route('tables.destroy', $routeToken) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="button" onclick="confirmSubmit('delete-form-mobile-{{ $table->id }}', '{{ $table->table_name }}')" class="size-8 flex items-center justify-center rounded-lg text-muted-foreground hover:text-destructive hover:bg-destructive-muted transition-colors" aria-label="Delete table">
                                    <span class="material-symbols-rounded text-[20px]">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-muted-foreground text-xs flex flex-col items-center justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-secondary flex items-center justify-center mb-2 border border-border">
                        <span class="material-symbols-rounded text-[24px] text-muted-foreground">table_restaurant</span>
                    </div>
                    <p class="font-bold text-muted-foreground">No tables registered for this store branch yet.</p>
                </div>
            @endforelse
        </div>
        @if($tables->hasPages())
            <div class="lg:hidden p-4 border-t border-border shrink-0">{{ $tables->links() }}</div>
        @endif

        {{-- MODAL MOVE TABLE (TASK-P1-01) --}}
        <div x-show="moveModalOpen" x-cloak
            class="fixed inset-0 z-50 overflow-y-auto bg-overlay-scrim backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-2xl max-w-md w-full p-6 shadow-xl"
                @click.outside="moveModalOpen = false">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-muted border border-border flex items-center justify-center text-foreground">
                        <span class="material-symbols-rounded text-[24px]">drive_file_move</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">Pindah Meja (Move Table)</h3>
                        <p class="text-xs text-muted-foreground">Pindahkan seluruh pesanan aktif dari meja ini</p>
                    </div>
                </div>

                <form :action="'{{ url('tables') }}/' + sourceTableId + '/move'" method="POST">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">Meja Asal</label>
                            <input type="text" :value="sourceTableName" readonly class="form-input w-full p-2.5 rounded-xl border border-border bg-muted text-xs font-bold text-foreground">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">Pilih Meja Tujuan (Kosong / Available)</label>
                            <select name="target_table_id" required class="form-select w-full p-2.5 rounded-xl border border-border bg-background text-xs font-semibold text-foreground">
                                <option value="">-- Pilih Meja Tujuan --</option>
                                @foreach($allStoreTables ?? [] as $t)
                                    <option value="{{ $t->id }}" x-show="sourceTableId != {{ $t->id }}" {{ $t->operational_status !== 'available' ? 'disabled' : '' }}>
                                        {{ $t->table_name }} ({{ $t->area ?? 'Utama' }}) - {{ ucfirst($t->operational_status) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 mt-6 pt-4 border-t border-border">
                        <x-ui.button type="button" variant="secondary" @click="moveModalOpen = false">
                            Batal
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary">
                            Pindahkan Pesanan
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>

        {{-- MODAL MERGE TABLES (TASK-P1-01) --}}
        <div x-show="mergeModalOpen" x-cloak
            class="fixed inset-0 z-50 overflow-y-auto bg-overlay-scrim backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-card border border-border rounded-2xl max-w-md w-full p-6 shadow-xl"
                @click.outside="mergeModalOpen = false">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-xl bg-muted border border-border flex items-center justify-center text-foreground">
                        <span class="material-symbols-rounded text-[24px]">merge_type</span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">Gabung Meja (Merge Tables)</h3>
                        <p class="text-xs text-muted-foreground">Gabungkan meja-meja lain ke dalam meja utama ini</p>
                    </div>
                </div>

                <form :action="'{{ url('tables') }}/' + primaryTableId + '/merge'" method="POST">
                    @csrf
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-1">Meja Utama (Primary Table)</label>
                            <input type="text" :value="primaryTableName" readonly class="form-input w-full p-2.5 rounded-xl border border-border bg-muted text-xs font-bold text-foreground">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-foreground uppercase tracking-wider mb-2">Pilih Meja Sekunder yang Digabungkan</label>
                            <div class="space-y-2 max-h-48 overflow-y-auto p-2 bg-muted/40 rounded-xl border border-border">
                                @foreach($allStoreTables ?? [] as $t)
                                    <label class="flex items-center gap-2.5 p-2 rounded-lg hover:bg-muted text-xs font-medium text-foreground cursor-pointer"
                                        x-show="primaryTableId != {{ $t->id }}">
                                        <input type="checkbox" name="secondary_table_ids[]" value="{{ $t->id }}"
                                            class="rounded border-border text-primary focus:ring-ring">
                                        <span>{{ $t->table_name }} ({{ $t->area ?? 'Utama' }}) - <span class="capitalize text-muted-foreground">{{ $t->operational_status }}</span></span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2.5 mt-6 pt-4 border-t border-border">
                        <x-ui.button type="button" variant="secondary" @click="mergeModalOpen = false">
                            Batal
                        </x-ui.button>
                        <x-ui.button type="submit" variant="primary">
                            Gabungkan Meja
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
