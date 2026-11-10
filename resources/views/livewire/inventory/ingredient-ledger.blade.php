<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 gap-4">
        <div>
            <h4 class="text-xl font-bold text-foreground tracking-tight">Raw Material Stock History</h4>
            <p class="text-sm font-medium text-muted-foreground mt-1">Historical stock movement ledger and immutable audit trail</p>
        </div>
        <div class="flex items-center gap-3">
            <x-ui.button type="button" variant="secondary" wire:navigate href="{{ route('inventory.ingredients') }}">
                <span class="material-symbols-rounded text-[20px]">arrow_back</span> Back
            </x-ui.button>
        </div>
    </div>

    <x-erp.filter-bar :search-model="false" :reset-method="null" class="mb-4">
        <x-slot:filters>
            <div class="relative w-full sm:w-56">
                <select wire:model.live="ingredient_id" class="h-9 w-full px-3 pr-8 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none appearance-none">
                    <option value="">All Raw Materials</option>
                    @foreach($ingredients as $ing)
                        <option value="{{ $ing->id }}">{{ $ing->name }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-muted-foreground">
                    <span class="material-symbols-rounded text-[20px]">keyboard_arrow_down</span>
                </div>
            </div>
            
            <div class="relative w-full sm:w-56">
                <select wire:model.live="type" class="h-9 w-full px-3 pr-8 rounded-medium border border-input bg-background text-foreground text-xs font-semibold focus-visible:ring-1 focus-visible:ring-ring outline-none appearance-none">
                    <option value="">All Movement Types</option>
                    <option value="sale_deduction">Sales Deduction (Out)</option>
                    <option value="purchase_receipt">Purchase Receipt (In)</option>
                    <option value="waste">Waste / Damaged (Out)</option>
                    <option value="adjustment">Manual Adjustment</option>
                    <option value="restore_compensation">Order Cancellation (In)</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-muted-foreground">
                    <span class="material-symbols-rounded text-[20px]">keyboard_arrow_down</span>
                </div>
            </div>
        </x-slot:filters>
    </x-erp.filter-bar>

    <x-erp.data-table
        loading-target="filterIngredient, filterType, startDate, endDate"
        :is-empty="$movements->isEmpty()"
        empty-title="No Movement History"
        empty-description="Stock movement history will appear automatically when transactions, goods receipts, or adjustments occur."
        empty-icon="history"
        :colspan="5"
    >
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Timestamp</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Raw Material</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Type</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider text-right font-sans tabular-nums numeric">Quantity</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Reason / Notes</th>
                </x-slot:head>
                    @foreach ($movements as $movement)
                        <tr class="hover:bg-secondary transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-foreground">{{ $movement->created_at->format('d M Y') }}</div>
                                <div class="text-xs text-muted-foreground">{{ $movement->created_at->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-semibold text-foreground">{{ $movement->ingredient->name ?? '-' }}</span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                @php
                                    $typeVal = $movement->type instanceof \App\Enums\IngredientStockMovementTypeEnum ? $movement->type->value : $movement->type;
                                    $typeStr = match($typeVal) {
                                        'sale_deduction' => 'Sales Deduction',
                                        'purchase_receipt' => 'Purchase Receipt',
                                        'waste' => 'Waste / Damaged',
                                        'adjustment' => 'Manual Adjustment',
                                        'restore_compensation' => 'Order Reversal',
                                        default => ucwords(str_replace('_', ' ', $typeVal))
                                    };
                                    $typeVariant = match($typeVal) {
                                        'purchase_receipt', 'restore_compensation' => 'success',
                                        'sale_deduction', 'waste' => 'danger',
                                        'adjustment' => 'warning',
                                        default => 'secondary'
                                    };
                                @endphp
                                <x-ui.badge :variant="$typeVariant">
                                    {{ $typeStr }}
                                </x-ui.badge>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right tabular-nums">
                                @if($movement->quantity > 0)
                                    <span class="text-sm font-semibold text-emerald-600">+{{ number_format($movement->quantity, 2) }} {{ $movement->ingredient->unit ?? '' }}</span>
                                @else
                                    <span class="text-sm font-semibold text-rose-600">{{ number_format($movement->quantity, 2) }} {{ $movement->ingredient->unit ?? '' }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm text-muted-foreground truncate max-w-xs block" title="{{ $movement->reason }}">
                                    {{ $movement->reason ?: '-' }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                    @if($movements->hasPages())
                        <x-slot:pagination>
                            {{ $movements->links() }}
                        </x-slot:pagination>
                    @endif
            </x-erp.data-table>
</div>
