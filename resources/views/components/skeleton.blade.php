@props([
    'type' => 'card', // card, text, list, table-row
    'rows' => 1
])

@if($type === 'card')
    <div {{ $attributes->merge(['class' => 'animate-pulse p-4 rounded-2xl border border-apeiron-border bg-white']) }}>
        <div class="h-4 bg-apeiron-border rounded w-3/4 mb-3"></div>
        <div class="h-3 bg-apeiron-border rounded w-1/2"></div>
    </div>
@elseif($type === 'text')
    <div {{ $attributes->merge(['class' => 'animate-pulse h-4 bg-apeiron-border rounded w-3/4']) }}></div>
@elseif($type === 'table-row')
    @for($i=0; $i<$rows; $i++)
        <tr class="animate-pulse border-b border-apeiron-border last:border-0">
            <td class="px-6 py-4"><div class="h-4 bg-apeiron-border rounded w-3/4"></div></td>
            <td class="px-6 py-4"><div class="h-4 bg-apeiron-border rounded w-1/2"></div></td>
            <td class="px-6 py-4"><div class="h-4 bg-apeiron-border rounded w-1/4"></div></td>
            <td class="px-6 py-4"><div class="h-8 w-8 bg-apeiron-border rounded-xl ml-auto"></div></td>
        </tr>
    @endfor
@endif
