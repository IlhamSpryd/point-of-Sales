@props([
    'exclusive' => false,
])

{{-- A group of collapsible items. Set `exclusive` to allow only one open at a time. --}}
<div
    x-data="{
        activeAccordions: @js($exclusive ? '' : []),
        toggle(id) {
            if (@js($exclusive)) {
                this.activeAccordions = (this.activeAccordions === id) ? '' : id;
            } else if (this.activeAccordions.includes(id)) {
                this.activeAccordions = this.activeAccordions.filter(i => i !== id);
            } else {
                this.activeAccordions.push(id);
            }
        },
        isOpen(id) {
            return @js($exclusive)
                ? this.activeAccordions === id
                : this.activeAccordions.includes(id);
        }
    }"
    {{ $attributes->twMerge('w-full divide-y divide-foreground/10') }}
>
    {{ $slot }}
</div>
