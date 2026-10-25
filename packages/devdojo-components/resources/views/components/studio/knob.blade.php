@props(['prop'])

@php
    $name = $prop['name'];
    $type = $prop['type'] ?? 'text';
    $default = $prop['default'] ?? '';
@endphp

<div class="grid grid-cols-1 items-center gap-2 border-b border-foreground/10 px-4 py-3 last:border-0 sm:grid-cols-[10rem_1fr_14rem]">
    <div class="flex items-center gap-2">
        <code class="rounded-small bg-secondary px-1.5 py-0.5 font-mono text-[12px] text-foreground/80">{{ $name }}</code>
        <span class="font-mono text-[10px] text-foreground/35">{{ $type }}</span>
    </div>
    <p class="text-xs leading-5 text-foreground/50">{{ $prop['description'] ?? '' }}</p>
    <div>
        @if ($type === 'select')
            <div class="relative">
                <select x-model="attrs['{{ $name }}']" @change="update()"
                    class="w-full appearance-none rounded-medium border border-input bg-card py-1.5 pl-2.5 pr-8 text-[13px] text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring">
                    @foreach ($prop['options'] ?? [] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <svg class="pointer-events-none absolute right-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-foreground/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6" /></svg>
            </div>
        @elseif ($type === 'boolean')
            <button type="button" role="switch" x-bind:aria-checked="!!attrs['{{ $name }}']"
                data-knob="{{ $name }}"
                @click="attrs['{{ $name }}'] = ! attrs['{{ $name }}']; update()"
                :class="attrs['{{ $name }}'] ? 'bg-primary' : 'bg-foreground/15'"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full outline-none transition-colors duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                <span :class="attrs['{{ $name }}'] ? 'translate-x-[22px]' : 'translate-x-0.5'"
                    class="pointer-events-none block h-5 w-5 rounded-full bg-white shadow-sm transition-transform duration-200"></span>
            </button>
        @elseif ($type === 'textarea' || $type === 'array')
            <textarea x-model="attrs['{{ $name }}']" @input.debounce.300ms="update()" rows="2"
                placeholder="{{ is_array($default) ? json_encode($default) : $default }}"
                class="w-full resize-y rounded-medium border border-input bg-card px-2.5 py-1.5 font-mono text-xs text-foreground transition-colors placeholder:text-foreground/30 focus:outline-none focus:ring-2 focus:ring-ring"></textarea>
        @elseif ($type === 'number' || $type === 'integer')
            <input type="number" x-model="attrs['{{ $name }}']" @input.debounce.300ms="update()"
                class="w-full rounded-medium border border-input bg-card px-2.5 py-1.5 text-[13px] text-foreground transition-colors focus:outline-none focus:ring-2 focus:ring-ring" />
        @else
            <input type="text" x-model="attrs['{{ $name }}']" @input.debounce.300ms="update()"
                placeholder="{{ is_array($default) ? json_encode($default) : $default }}"
                class="w-full rounded-medium border border-input bg-card px-2.5 py-1.5 text-[13px] text-foreground transition-colors placeholder:text-foreground/30 focus:outline-none focus:ring-2 focus:ring-ring" />
        @endif
    </div>
</div>
