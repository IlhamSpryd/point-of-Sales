<div data-gsap="page-enter">
    @include('inventory.partials.sub-nav')
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-6 gap-4">
        <div>
            <h4 class="text-xl font-bold text-apeiron-ink tracking-tight">Raw Material Stock History</h4>
            <p class="text-sm font-medium text-apeiron-muted mt-1">Historical stock movement ledger and immutable audit trail</p>
        </div>
        <div class="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
            <x-ui.button type="button" variant="secondary" wire:navigate href="{{ route('inventory.ingredients') }}">
                <span class="material-symbols-rounded text-[18px]">arrow_back</span> Back
            </x-ui.button>

            <div class="relative w-full sm:w-48">
                <select wire:model.live="ingredient_id" class="form-input w-full pl-3.5 pr-8 py-2 rounded-lg border border-[#E5E7EB] bg-white text-xs font-medium text-apeiron-ink focus:outline-none focus:ring-1 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all shadow-xs appearance-none">
                    <option value="">All Raw Materials</option>
                    @foreach($ingredients as $ing)
                        <option value="{{ $ing->id }}">{{ $ing->name }}</option>
                    @endforeach
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
            
            <div class="relative w-full sm:w-48">
                <select wire:model.live="type" class="form-input w-full pl-3.5 pr-8 py-2 rounded-lg border border-[#E5E7EB] bg-white text-xs font-medium text-apeiron-ink focus:outline-none focus:ring-1 focus:ring-apeiron-ink focus:border-apeiron-ink transition-all shadow-xs appearance-none">
                    <option value="">All Movement Types</option>
                    <option value="sale_deduction">Sales Deduction (Out)</option>
                    <option value="purchase_receipt">Purchase Receipt (In)</option>
                    <option value="waste">Waste / Damaged (Out)</option>
                    <option value="adjustment">Manual Adjustment</option>
                    <option value="restore_compensation">Order Cancellation (In)</option>
                </select>
                <div class="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none text-apeiron-muted">
                    <span class="material-symbols-rounded text-[18px]">keyboard_arrow_down</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card-surface overflow-hidden w-full shrink-0">
        <div class="overflow-x-auto">
            <x-data-table>
                <x-slot:head>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Timestamp</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Raw Material</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Type</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider text-right">Quantity</th>
                    <th scope="col" class="px-6 py-4 text-xs font-semibold text-[#787774] uppercase tracking-wider">Reason / Notes</th>
                </x-slot:head>
                    @forelse ($movements as $movement)
                        <tr class="hover:bg-apeiron-surface transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-apeiron-ink">{{ $movement->created_at->format('d M Y') }}</div>
                                <div class="text-xs text-apeiron-muted">{{ $movement->created_at->format('H:i') }}</div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-semibold text-apeiron-ink">{{ $movement->ingredient->name ?? '-' }}</span>
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
                                <span class="text-sm text-apeiron-muted truncate max-w-xs block" title="{{ $movement->reason }}">
                                    {{ $movement->reason ?: '-' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <x-empty-state icon="history" title="No Movement History" description="Stock movement history will appear automatically when transactions, goods receipts, or adjustments occur." />
                            </td>
                        </tr>
                    @endforelse
            </x-data-table>
        </div>
        @if($movements->hasPages())
            <div class="p-4 border-t border-apeiron-border shrink-0">
                {{ $movements->links() }}
            </div>
        @endif
    </div>
</div>
