@props([
    'label' => null,
    'placeholder' => 'Select a date',
    'format' => 'M d, Y',
    'value' => null,
    'name' => null,
])

@php
    $wireModel = $attributes->get('wire:model')
        ?? $attributes->get('wire:model.live')
        ?? $attributes->get('wire:model.blur')
        ?? $attributes->get('wire:model.lazy');
@endphp

<div
    x-data="{
        open: false,
        value: @js($value ?? ''),
        format: @js($format),
        month: 0,
        year: 0,
        days: [],
        blankDays: [],
        triggerRect: { top: 0, left: 0, width: 0, height: 0 },
        monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        dayNames: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],

        initCalendar() {
            let date = new Date();
            if (this.value) {
                const parsed = new Date(Date.parse(this.value));
                if (! isNaN(parsed)) date = parsed;
            }
            this.month = date.getMonth();
            this.year = date.getFullYear();
            this.calculateDays();
        },
        openCalendar() {
            this.position();
            this.open = true;
        },
        position() {
            const rect = this.$refs.trigger.getBoundingClientRect();
            const top = window.pageYOffset || document.documentElement.scrollTop;
            const left = window.pageXOffset || document.documentElement.scrollLeft;
            this.triggerRect = { top: rect.top + top, left: rect.left + left, width: rect.width, height: rect.height };
        },
        styles() {
            return {
                position: 'absolute',
                top: (this.triggerRect.top + this.triggerRect.height + 8) + 'px',
                left: this.triggerRect.left + 'px',
                width: Math.max(this.triggerRect.width, 272) + 'px',
                zIndex: 50,
            };
        },
        previousMonth() {
            if (this.month === 0) { this.year--; this.month = 11; } else { this.month--; }
            this.calculateDays();
        },
        nextMonth() {
            if (this.month === 11) { this.year++; this.month = 0; } else { this.month++; }
            this.calculateDays();
        },
        calculateDays() {
            const daysInMonth = new Date(this.year, this.month + 1, 0).getDate();
            const dayOfWeek = new Date(this.year, this.month, 1).getDay();
            this.blankDays = Array.from({ length: dayOfWeek }, (_, i) => i);
            this.days = Array.from({ length: daysInMonth }, (_, i) => i + 1);
        },
        isToday(day) {
            const today = new Date();
            const d = new Date(this.year, this.month, day);
            return today.toDateString() === d.toDateString();
        },
        isSelected(day) {
            return this.value === this.formatDate(new Date(this.year, this.month, day));
        },
        selectDay(day) {
            this.value = this.formatDate(new Date(this.year, this.month, day));
            this.$refs.hidden.value = this.value;
            this.$refs.hidden.dispatchEvent(new Event('input', { bubbles: true }));
            @if ($wireModel) $wire.set(@js($wireModel), this.value); @endif
            this.open = false;
        },
        formatDate(date) {
            const day = ('0' + date.getDate()).slice(-2);
            const monthShort = this.monthNames[date.getMonth()].substring(0, 3);
            const monthFull = this.monthNames[date.getMonth()];
            const monthNum = ('0' + (date.getMonth() + 1)).slice(-2);
            const weekday = this.dayNames[date.getDay()];
            const year = date.getFullYear();

            switch (this.format) {
                case 'MM-DD-YYYY': return `${monthNum}-${day}-${year}`;
                case 'DD-MM-YYYY': return `${day}-${monthNum}-${year}`;
                case 'YYYY-MM-DD': return `${year}-${monthNum}-${day}`;
                case 'D d M, Y': return `${weekday} ${day} ${monthShort} ${year}`;
                case 'M d, Y': return `${monthShort} ${day}, ${year}`;
                default: return `${monthFull} ${day}, ${year}`;
            }
        }
    }"
    x-init="initCalendar()"
    @resize.window="if (open) position()"
    @scroll.window="if (open) position()"
    {{ $attributes->whereDoesntStartWith('wire:model')->twMerge('w-full') }}
>
    @if ($label)
        <x-components.label class="mb-1.5">{{ $label }}</x-components.label>
    @endif

    <input type="hidden" @if ($name) name="{{ $name }}" @endif x-ref="hidden" :value="value" />

    <div class="relative" x-ref="trigger">
        <input
            type="text"
            readonly
            x-model="value"
            @click="openCalendar()"
            placeholder="{{ $placeholder }}"
            class="h-9 w-full cursor-pointer rounded-medium border border-input bg-background pl-3 pr-9 text-base text-foreground shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/10 md:text-sm dark:bg-input/30"
        />
        <button type="button" @click="openCalendar()" tabindex="-1" class="absolute right-0 top-0 flex h-9 w-9 items-center justify-center text-foreground/50 transition-colors hover:text-foreground">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4M16 2v4M3 10h18" /><rect x="3" y="4" width="18" height="18" rx="2" /></svg>
        </button>
    </div>

    {{-- Calendar, teleported so it is never clipped by an ancestor. --}}
    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            x-transition:enter="ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            :style="styles()"
            class="rounded-large border border-foreground/10 bg-popover p-4 text-popover-foreground shadow-lg dark:border-foreground/15"
        >
            <div class="mb-3 flex items-center justify-between">
                <div class="text-sm font-semibold text-foreground">
                    <span x-text="monthNames[month]"></span>
                    <span class="text-foreground/50" x-text="year"></span>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" @click="previousMonth()" class="inline-flex size-7 items-center justify-center rounded-medium text-foreground/60 transition-colors hover:bg-secondary hover:text-foreground">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6" /></svg>
                    </button>
                    <button type="button" @click="nextMonth()" class="inline-flex size-7 items-center justify-center rounded-medium text-foreground/60 transition-colors hover:bg-secondary hover:text-foreground">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6" /></svg>
                    </button>
                </div>
            </div>

            <div class="mb-1 grid grid-cols-7">
                <template x-for="day in dayNames" :key="day">
                    <div class="flex h-8 items-center justify-center text-xs font-medium text-foreground/40" x-text="day"></div>
                </template>
            </div>

            <div class="grid grid-cols-7">
                <template x-for="blank in blankDays" :key="'blank-' + blank">
                    <div></div>
                </template>
                <template x-for="day in days" :key="day">
                    <div class="flex items-center justify-center p-0.5">
                        <button
                            type="button"
                            @click="selectDay(day)"
                            x-text="day"
                            class="flex size-9 items-center justify-center rounded-medium text-sm transition-colors"
                            :class="{
                                'bg-primary text-primary-foreground hover:bg-primary/90': isSelected(day),
                                'bg-secondary text-foreground': isToday(day) && ! isSelected(day),
                                'text-foreground/70 hover:bg-secondary': ! isToday(day) && ! isSelected(day),
                            }"
                        ></button>
                    </div>
                </template>
            </div>
        </div>
    </template>
</div>
