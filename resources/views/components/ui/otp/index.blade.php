@props([
    'length' => 6,
    'name' => null,
    'value' => null,
    'autofocus' => false,
    'eventCallback' => null,
])

@php
    $wireModel = $attributes->get('wire:model')
        ?? $attributes->get('wire:model.live')
        ?? $attributes->get('wire:model.blur')
        ?? $attributes->get('wire:model.lazy');
    $len = max(1, (int) $length);
@endphp

<div
    x-data="{
        total: {{ $len }},
        eventCallback: @js($eventCallback),
        completed: false,

        code() {
            let value = '';
            for (let i = 1; i <= this.total; i++) {
                value += (this.$refs['input' + i] ? this.$refs['input' + i].value : '');
            }
            return value;
        },

        focusInput(i) {
            const el = this.$refs['input' + i];
            if (el) { el.focus(); el.select(); }
        },

        handleKey(index, e) {
            const key = e.key;

            // Let copy/paste, tab and modifier combos through untouched.
            if (key === 'Tab' || e.metaKey || e.ctrlKey) { return; }

            if (key === 'ArrowLeft') {
                e.preventDefault();
                if (index > 1) { this.focusInput(index - 1); }
                return;
            }
            if (key === 'ArrowRight') {
                e.preventDefault();
                if (index < this.total) { this.focusInput(index + 1); }
                return;
            }
            if (key === 'Backspace') {
                e.preventDefault();
                if (this.$refs['input' + index].value !== '') {
                    this.$refs['input' + index].value = '';
                } else if (index > 1) {
                    this.$refs['input' + (index - 1)].value = '';
                    this.focusInput(index - 1);
                }
                this.sync();
                return;
            }
            if (key === 'Delete') {
                e.preventDefault();
                this.$refs['input' + index].value = '';
                this.sync();
                return;
            }
            // Accept a single digit, fill this box and advance.
            if (/^[0-9]$/.test(key)) {
                e.preventDefault();
                this.$refs['input' + index].value = key;
                if (index < this.total) { this.focusInput(index + 1); }
                this.sync();
                return;
            }
            // Block any other printable character.
            if (key.length === 1) { e.preventDefault(); }
        },

        handlePaste(e) {
            e.preventDefault();
            const pasted = ((e.clipboardData || window.clipboardData).getData('text') || '')
                .replace(/[^0-9]/g, '')
                .slice(0, this.total);
            if (! pasted) { return; }
            for (let i = 0; i < this.total; i++) {
                this.$refs['input' + (i + 1)].value = pasted[i] || '';
            }
            this.focusInput(Math.min(pasted.length, this.total));
            this.sync();
        },

        sync() {
            const value = this.code();
            this.$refs.hidden.value = value;
            this.$refs.hidden.dispatchEvent(new Event('input', { bubbles: true }));
            @if ($wireModel) $wire.set(@js($wireModel), value); @endif

            if (value.length === this.total) {
                if (this.eventCallback && ! this.completed) {
                    this.completed = true;
                    window.dispatchEvent(new CustomEvent(this.eventCallback, { detail: { code: value } }));
                }
            } else {
                this.completed = false;
            }
        }
    }"
    x-init="$nextTick(() => {
        @if ($value)
            const initial = @js((string) $value).replace(/[^0-9]/g, '');
            for (let i = 0; i < total; i++) {
                if ($refs['input' + (i + 1)]) { $refs['input' + (i + 1)].value = initial[i] || ''; }
            }
            sync();
        @endif
        @if ($autofocus)
            if ($refs.input1) { $refs.input1.focus(); }
        @endif
    })"
    role="group"
    {{ $attributes->whereDoesntStartWith('wire:model')->twMerge('inline-flex items-center gap-2') }}
>
    <input type="hidden" @if ($name) name="{{ $name }}" @endif x-ref="hidden" />

    @for ($i = 1; $i <= $len; $i++)
        <input
            x-ref="input{{ $i }}"
            type="text"
            inputmode="numeric"
            autocomplete="{{ $i === 1 ? 'one-time-code' : 'off' }}"
            maxlength="1"
            aria-label="Digit {{ $i }}"
            x-on:keydown="handleKey({{ $i }}, $event)"
            x-on:paste="handlePaste($event)"
            x-on:focus="$el.select()"
            class="size-11 rounded-medium border border-input bg-background text-center text-lg font-medium text-foreground shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-primary/10 dark:bg-input/30"
        />
    @endfor
</div>
