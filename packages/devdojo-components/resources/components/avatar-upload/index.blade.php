@props([
    'src' => null,
    'size' => 14,
    'wireTarget' => null,
    'placeholder' => null,
])

@php
    // Tailwind's numeric size scale → rem (14 → 3.5rem). Applied as an inline
    // dimension so it works even when the host's Tailwind doesn't see the
    // dynamic size-{n} class.
    $dimension = (float) $size * 0.25;
@endphp

@once
    {{-- Croppie (crop UI) + Exif-JS (orientation) load once from a CDN,
         no matter how many avatar pickers are on the page. --}}
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.2/croppie.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exif-js/2.3.0/exif.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.2/croppie.min.js" defer></script>
@endonce

<div
    x-data="(() => {
        // Keep the Croppie instance outside Alpine's reactive proxy so its
        // internal methods (bind, result, destroy) keep working.
        let _cropper = null;

        return {
            previewSrc: @js($src),
            placeholder: @js($placeholder),
            modalOpen: false,
            loadingCrop: true,

            get displaySrc() {
                return this.previewSrc || this.placeholder || null;
            },

            openFilePicker() {
                this.$refs.fileInput.click();
            },

            handleFileChange() {
                const file = this.$refs.fileInput.files[0];
                if (!file) return;

                const ext = file.name.split('.').pop().toLowerCase();
                if (!['jpg', 'jpeg', 'png'].includes(ext)) {
                    alert('Invalid file type. Please select a JPG or PNG file.');
                    this.$refs.fileInput.value = '';
                    return;
                }

                this.modalOpen = true;
                this.loadingCrop = true;

                // Read the file, then init Croppie once the modal has rendered.
                const reader = new FileReader();
                reader.onload = (e) => {
                    const imgDataUrl = e.target.result;

                    setTimeout(() => {
                        this.loadingCrop = false;

                        this.$nextTick(() => {
                            if (_cropper) {
                                _cropper.destroy();
                            }

                            _cropper = new Croppie(this.$refs.cropContainer, {
                                viewport: { width: 190, height: 190, type: 'square' },
                                boundary: { width: 190, height: 190 },
                                enableExif: true,
                            });

                            _cropper.bind({ url: imgDataUrl, orientation: 4 });
                        });
                    }, 800);
                };
                reader.readAsDataURL(file);
            },

            applyCrop() {
                if (!_cropper) return;
                _cropper
                    .result({ type: 'base64', size: 'original', format: 'png', quality: 1 })
                    .then((base64) => {
                        this.previewSrc = base64;
                        this.modalOpen = false;
                        this.$refs.fileInput.value = '';
                        @if ($wireTarget)
                            $wire.set('{{ $wireTarget }}', base64);
                        @endif
                    });
            },

            cancelCrop() {
                this.modalOpen = false;
                this.$refs.fileInput.value = '';
            },
        };
    })()"
    {{ $attributes->twMerge('relative inline-block') }}
>
    {{-- Circle trigger --}}
    <button
        type="button"
        @click="openFilePicker()"
        class="group relative cursor-pointer overflow-hidden rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        style="height: {{ $dimension }}rem; width: {{ $dimension }}rem;"
        aria-label="Upload avatar"
    >
        {{-- Preview image or placeholder --}}
        <template x-if="displaySrc">
            <img :src="displaySrc" alt="Avatar preview" class="size-full object-cover" />
        </template>

        {{-- Empty state --}}
        <template x-if="!displaySrc">
            <span class="flex size-full items-center justify-center bg-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="size-7 text-muted-foreground">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" />
                </svg>
            </span>
        </template>

        {{-- Hover overlay with a camera icon --}}
        <span class="absolute inset-0 flex items-center justify-center rounded-full bg-black/50 opacity-0 transition-opacity group-hover:opacity-100">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="size-6">
                <path d="M3 9a2 2 0 0 1 2-2h.93a2 2 0 0 0 1.664-.89l.812-1.22A2 2 0 0 1 10.07 4h3.86a2 2 0 0 1 1.664.89l.812 1.22A2 2 0 0 0 18.07 7H19a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                <circle cx="12" cy="13" r="3" />
            </svg>
        </span>
    </button>

    {{-- Hidden file input --}}
    <input
        type="file"
        accept="image/jpeg,image/png"
        class="hidden"
        x-ref="fileInput"
        @change="handleFileChange()"
        aria-label="Choose avatar image"
    />

    {{-- Crop modal — teleported to <body> so it is never clipped by an ancestor. --}}
    <template x-teleport="body">
        <div
            x-show="modalOpen"
            x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center"
            @keydown.escape.window="cancelCrop()"
        >
            {{-- Backdrop --}}
            <div
                x-show="modalOpen"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 bg-black/60"
                @click="cancelCrop()"
            ></div>

            {{-- Dialog --}}
            <div
                x-show="modalOpen"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                class="relative z-10 w-full max-w-sm rounded-large border border-foreground/10 bg-popover px-7 py-6 text-popover-foreground shadow-xl dark:border-foreground/15"
            >
                <h3 class="text-center text-base font-semibold text-foreground">
                    Position and resize your photo
                </h3>

                <div class="relative mt-5 flex h-72 items-center justify-center">
                    {{-- Loading spinner --}}
                    <div x-show="loadingCrop" class="flex items-center justify-center">
                        <svg class="size-6 animate-spin text-muted-foreground" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>

                    {{-- Croppie mounts here --}}
                    <div x-show="!loadingCrop" x-ref="cropContainer"></div>
                </div>

                <div class="mt-5 flex gap-3">
                    <button
                        type="button"
                        @click="cancelCrop()"
                        class="flex-1 rounded-medium border border-foreground/10 py-2 text-sm font-medium text-foreground transition-colors hover:bg-secondary dark:border-foreground/15"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        @click="applyCrop()"
                        class="flex-1 rounded-medium bg-primary py-2 text-sm font-medium text-primary-foreground transition hover:bg-primary/90"
                    >
                        Apply
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

@once
    <style>
        /* Croppie — circular crop UX. The viewport mask uses the popover token
           so the dimmed area matches the modal surface in light and dark. */
        .croppie-container .cr-viewport,
        .croppie-container .cr-resizer {
            box-shadow: 0 0 2000px 2000px var(--popover) !important;
            border: none !important;
        }
        .croppie-container .cr-boundary {
            border-radius: 50% !important;
            overflow: hidden !important;
        }
        .croppie-container .cr-slider-wrap {
            margin-top: 12px !important;
            margin-bottom: 0 !important;
        }
        .croppie-container {
            height: auto !important;
        }
    </style>
@endonce
