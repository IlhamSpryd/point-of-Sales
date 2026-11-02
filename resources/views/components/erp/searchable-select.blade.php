@props([
    'options' => [],
    'placeholder' => 'Pilih opsi...',
    'searchPlaceholder' => 'Ketik untuk mencari...',
    'emptyMessage' => 'Tidak ada hasil yang cocok',
    'clearable' => false,
    'multiple' => false,
    'searchMethod' => null,
    'disabled' => false,
    'name' => null,
    'id' => null,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
    $attributesClean = $attributes->whereDoesntStartWith('wire:model');
    
    // Normalisasi opsi ke format array uniform: [['value' => '...', 'label' => '...'], ...]
    $normalizedOptions = [];
    if (!empty($options)) {
        foreach ($options as $key => $opt) {
            if (is_array($opt)) {
                $val = $opt['value'] ?? $opt['id'] ?? $key;
                $lbl = $opt['label'] ?? $opt['name'] ?? $opt['title'] ?? (string) $val;
            } elseif (is_object($opt)) {
                $val = $opt->value ?? $opt->id ?? $key;
                $lbl = $opt->label ?? $opt->name ?? $opt->title ?? (string) $val;
            } else {
                $val = is_numeric($key) ? $opt : $key;
                $lbl = (string) $opt;
            }
            $normalizedOptions[] = [
                'value' => (string) $val,
                'label' => (string) $lbl,
            ];
        }
    }

    $elementId = $id ?? $name ?? ($wireModel ? str_replace('.', '_', $wireModel) : uniqid('erp-select-'));
    $listboxId = $elementId . '-listbox';
    $valueExpression = $wireModel ? "\$wire.entangle('{$wireModel}')" : 'null';
@endphp

<div
    x-data="{
        open: false,
        search: '',
        value: {!! $valueExpression !!},
        options: @js($normalizedOptions),
        focusedIndex: -1,
        searchMethod: @js($searchMethod),
        loading: false,
        placeholder: @js($placeholder),
        clearable: @js($clearable),
        multiple: @js($multiple),
        disabled: @js($disabled),
        init() {
            if (this.multiple && !Array.isArray(this.value)) {
                this.value = this.value ? [String(this.value)] : [];
            }
            this.$watch('search', (query) => {
                this.focusedIndex = -1;
                if (this.searchMethod && typeof $wire !== 'undefined' && typeof $wire[this.searchMethod] === 'function') {
                    this.loading = true;
                    $wire.call(this.searchMethod, query).then((res) => {
                        if (Array.isArray(res)) {
                            this.options = res.map(item => ({
                                value: String(item.value ?? item.id ?? item),
                                label: String(item.label ?? item.name ?? item.title ?? item)
                            }));
                        }
                        this.loading = false;
                    }).catch(() => { this.loading = false; });
                }
            });
        },
        get filteredOptions() {
            if (this.searchMethod || !this.search) {
                return this.options;
            }
            const q = this.search.toLowerCase().trim();
            return this.options.filter(o => o.label.toLowerCase().includes(q) || o.value.toLowerCase().includes(q));
        },
        get selectedLabel() {
            if (this.multiple) {
                if (!Array.isArray(this.value) || this.value.length === 0) return this.placeholder;
                const labels = this.options
                    .filter(o => this.value.includes(o.value))
                    .map(o => o.label);
                return labels.length > 0 ? labels.join(', ') : `${this.value.length} opsi terpilih`;
            }
            const found = this.options.find(o => String(o.value) === String(this.value));
            return found ? found.label : (this.value ? String(this.value) : this.placeholder);
        },
        get hasValue() {
            if (this.multiple) {
                return Array.isArray(this.value) && this.value.length > 0;
            }
            return this.value !== null && this.value !== '' && typeof this.value !== 'undefined';
        },
        isSelected(val) {
            if (this.multiple) {
                return Array.isArray(this.value) && this.value.includes(String(val));
            }
            return String(this.value) === String(val);
        },
        select(opt) {
            if (this.multiple) {
                const strVal = String(opt.value);
                const idx = this.value.indexOf(strVal);
                if (idx > -1) {
                    this.value.splice(idx, 1);
                } else {
                    this.value.push(strVal);
                }
            } else {
                this.value = String(opt.value);
                this.open = false;
                this.search = '';
            }
            this.$dispatch('change', this.value);
        },
        clear() {
            this.value = this.multiple ? [] : '';
            this.search = '';
            this.$dispatch('change', this.value);
        },
        toggle() {
            if (this.disabled) return;
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.focusedIndex = -1;
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                });
            }
        },
        onKeyDown(e) {
            if (this.disabled) return;
            if (!this.open) {
                if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
                    e.preventDefault();
                    this.toggle();
                }
                return;
            }
            const opts = this.filteredOptions;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.focusedIndex = (this.focusedIndex + 1) % Math.max(1, opts.length);
                this.scrollToFocused();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.focusedIndex = (this.focusedIndex - 1 + opts.length) % Math.max(1, opts.length);
                this.scrollToFocused();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.focusedIndex >= 0 && this.focusedIndex < opts.length) {
                    this.select(opts[this.focusedIndex]);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.open = false;
                this.$refs.trigger?.focus();
            } else if (e.key === 'Tab') {
                this.open = false;
            }
        },
        scrollToFocused() {
            this.$nextTick(() => {
                const el = this.$refs.listbox?.querySelector(`[data-index='${this.focusedIndex}']`);
                if (el) el.scrollIntoView({ block: 'nearest' });
            });
        }
    }"
    @keydown="onKeyDown($event)"
    @click.outside="open = false"
    class="relative w-full"
    {{ $attributesClean }}
>
    @if ($name)
        <template x-if="multiple">
            <template x-for="v in value" :key="v">
                <input type="hidden" name="{{ $name }}[]" :value="v">
            </template>
        </template>
        <template x-if="!multiple">
            <input type="hidden" name="{{ $name }}" :value="value">
        </template>
    @endif

    {{-- Trigger Button --}}
    <button
        type="button"
        role="combobox"
        x-ref="trigger"
        @click="toggle()"
        :disabled="disabled"
        :aria-expanded="open"
        aria-haspopup="listbox"
        aria-controls="{{ $listboxId }}"
        class="h-9 w-full min-w-0 px-3 py-1.5 bg-background border border-input rounded-control text-xs sm:text-sm flex items-center justify-between gap-2 shadow-xs transition-colors hover:border-border-strong focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:border-ring disabled:cursor-not-allowed disabled:opacity-50 select-none cursor-pointer"
        :class="{ 'border-ring ring-1 ring-ring': open }"
    >
        <span
            class="truncate"
            :class="{ 'text-muted-foreground': !hasValue, 'text-foreground font-medium': hasValue }"
            x-text="selectedLabel"
        ></span>

        <div class="flex items-center gap-1 shrink-0 text-muted-foreground">
            <template x-if="clearable && hasValue && !disabled">
                <span
                    role="button"
                    tabindex="0"
                    aria-label="Bersihkan pilihan"
                    @click.stop="clear()"
                    @keydown.enter.stop="clear()"
                    class="p-0.5 hover:text-foreground rounded cursor-pointer transition-colors"
                >
                    <x-ui.icon name="close" size="xs" />
                </span>
            </template>

            <span class="transition-transform duration-150" :class="{ 'rotate-180': open }">
                <x-ui.icon name="expand_more" size="xs" />
            </span>
        </div>
    </button>

    {{-- Dropdown Listbox Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        id="{{ $listboxId }}"
        role="listbox"
        x-ref="listbox"
        class="absolute left-0 right-0 z-50 mt-1 rounded-card bg-popover text-popover-foreground border border-border shadow-md overflow-hidden p-1 focus:outline-none"
    >
        {{-- Search input inside popover --}}
        <div class="relative p-1 border-b border-border mb-1">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground pointer-events-none">
                <x-ui.icon name="search" size="xs" />
            </span>
            <input
                type="text"
                x-ref="searchInput"
                x-model.debounce.300ms="search"
                placeholder="{{ $searchPlaceholder }}"
                aria-label="{{ $searchPlaceholder }}"
                class="w-full h-8 pl-8 pr-3 text-xs bg-muted/40 border border-border rounded-control text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-1 focus:ring-ring"
            />
            <template x-if="loading">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground animate-spin">
                    <x-ui.icon name="sync" size="xs" />
                </span>
            </template>
        </div>

        {{-- Options list --}}
        <div class="max-h-56 overflow-y-auto space-y-0.5 pr-0.5" role="presentation">
            <template x-for="(opt, idx) in filteredOptions" :key="opt.value">
                <div
                    role="option"
                    :id="'{{ $elementId }}-opt-' + idx"
                    :data-index="idx"
                    :aria-selected="isSelected(opt.value)"
                    @click="select(opt)"
                    @mouseenter="focusedIndex = idx"
                    class="px-2.5 py-1.5 rounded-control text-xs sm:text-sm cursor-pointer flex items-center justify-between gap-2 text-foreground transition-colors select-none"
                    :class="{
                        'bg-muted font-medium': focusedIndex === idx,
                        'text-primary font-semibold': isSelected(opt.value)
                    }"
                >
                    <span class="truncate" x-text="opt.label"></span>

                    <template x-if="isSelected(opt.value)">
                        <span class="shrink-0 text-primary">
                            <x-ui.icon name="check" size="xs" />
                        </span>
                    </template>
                </div>
            </template>

            {{-- Empty State --}}
            <template x-if="filteredOptions.length === 0 && !loading">
                <div class="py-4 px-3 text-center text-xs text-muted-foreground">
                    {{ $emptyMessage }}
                </div>
            </template>
        </div>
    </div>
</div>
