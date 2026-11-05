@props([
    'name' => 'file',
    'label' => 'Unggah Berkas',
    'hint' => 'Seret dan lepas gambar atau dokumen (PNG, JPG, PDF maks 10MB) di sini atau telusuri',
    'accept' => '.png,.jpg,.jpeg,.webp,.svg,.pdf',
    'multiple' => false,
    'maxSizeMb' => 10,
])

@php
    $wireModel = $attributes->whereStartsWith('wire:model')->first();
@endphp

<div
    class="w-full"
    x-data="{
        files: [],
        isDragActive: false,
        isDragReject: false,
        isFocused: false,
        maxBytes: {{ $maxSizeMb }} * 1024 * 1024,
        get borderColor() {
            if (this.isDragReject) return 'border-destructive bg-destructive-muted text-destructive';
            if (this.isDragActive) return 'border-primary bg-secondary text-primary';
            if (this.isFocused) return 'border-primary ring-2 ring-ring bg-card';
            return 'border-border bg-muted hover:border-border-strong hover:bg-muted';
        },
        addFiles(incoming) {
            const accepted = incoming.filter(f => f.size <= this.maxBytes);
            const mapped = accepted.map(file => ({
                file,
                name: file.name,
                size: this.formatFileSize(file.size),
                preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                isPdf: file.type === 'application/pdf'
            }));
            @if($multiple)
                this.files = [...this.files, ...mapped];
            @else
                this.files = mapped.slice(0, 1);
            @endif
        },
        removeFile(index) {
            if (this.files[index]?.preview) {
                URL.revokeObjectURL(this.files[index].preview);
            }
            this.files.splice(index, 1);
        },
        clearAll() {
            this.files.forEach(f => { if (f.preview) URL.revokeObjectURL(f.preview); });
            this.files = [];
        },
        openPicker() {
            this.$refs.fileInput.click();
        },
        onInputChange(e) {
            if (e.target.files) {
                this.addFiles(Array.from(e.target.files));
            }
        },
        formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }
    }"
    {{ $attributes->whereDoesntStartWith('wire:model') }}>

    {{-- Dropzone Area (TailAdmin Dashed Box) --}}
    <div
        role="button"
        tabindex="0"
        class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed p-6 text-center transition-all duration-200 outline-hidden lg:p-8 select-none"
        :class="borderColor"
        @click="openPicker()"
        @keydown.enter.prevent="openPicker()"
        @keydown.space.prevent="openPicker()"
        @focus="isFocused = true"
        @blur="isFocused = false"
        @dragenter.prevent="isDragActive = true"
        @dragover.prevent="isDragActive = true"
        @dragleave.prevent="isDragActive = false"
        @drop.prevent="isDragActive = false; if ($event.dataTransfer?.files) addFiles(Array.from($event.dataTransfer.files))">

        {{-- Native Hidden Input for Livewire and HTML Forms --}}
        <input
            x-ref="fileInput"
            type="file"
            name="{{ $name }}"
            accept="{{ $accept }}"
            @if($multiple) multiple @endif
            @if($wireModel) wire:model="{{ $wireModel }}" @endif
            class="hidden"
            tabindex="-1"
            @click.stop
            @change="onInputChange($event)" />

        <div class="flex flex-col items-center">
            {{-- Circular Upload Icon --}}
            <div class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-secondary text-foreground border border-border shadow-xs transition-transform duration-200 group-hover:scale-105">
                <span class="material-symbols-rounded text-2xl text-foreground">cloud_upload</span>
            </div>

            {{-- Heading --}}
            <h4 class="mb-1 text-base font-semibold text-foreground tracking-tight"
                x-text="isDragReject ? 'Format berkas tidak didukung' : isDragActive ? 'Lepaskan berkas di sini' : '{{ $label }}'">
                {{ $label }}
            </h4>

            {{-- Subtitle / Hint --}}
            <p class="mb-3 max-w-sm text-xs text-muted-foreground leading-normal"
                x-text="isDragReject ? 'Ukuran berkas melebihi batas {{ $maxSizeMb }}MB' : '{{ $hint }}'">
                {{ $hint }}
            </p>

            {{-- Browse Button Pill --}}
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold bg-card border border-border text-foreground hover:bg-muted shadow-xs transition-colors">
                <span class="material-symbols-rounded text-sm">folder_open</span>
                <span>Pilih Berkas</span>
            </span>
        </div>
    </div>

    {{-- Uploaded Files Preview Gallery (TailAdmin Style) --}}
    <div x-show="files.length > 0" x-cloak class="mt-4 space-y-3">
        <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-foreground">
                Berkas Terpilih (<span x-text="files.length"></span>)
            </span>
            <button type="button" @click="clearAll()" class="text-xs font-medium text-destructive hover:underline cursor-pointer">
                Hapus Semua
            </button>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
            <template x-for="(item, index) in files" :key="index">
                <div class="group relative overflow-hidden rounded-xl border border-border bg-card shadow-xs transition hover:shadow-sm">
                    {{-- Thumbnail or Document Icon --}}
                    <div class="relative aspect-4/3 w-full overflow-hidden bg-muted flex items-center justify-center">
                        <template x-if="item.preview">
                            <img :src="item.preview" :alt="item.name" class="size-full object-cover transition-transform duration-300 group-hover:scale-105" />
                        </template>
                        <template x-if="!item.preview">
                            <span class="material-symbols-rounded text-3xl text-muted-foreground" x-text="item.isPdf ? 'picture_as_pdf' : 'description'"></span>
                        </template>

                        {{-- Action Overlay --}}
                        <div class="absolute inset-0 flex items-center justify-center bg-overlay-scrim opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                            <button type="button"
                                @click="removeFile(index)"
                                title="Hapus berkas"
                                class="flex size-8 items-center justify-center rounded-full bg-destructive text-destructive-foreground hover:bg-destructive transition-colors cursor-pointer shadow-xs">
                                <span class="material-symbols-rounded text-base">delete</span>
                            </button>
                        </div>
                    </div>

                    {{-- Metadata --}}
                    <div class="p-2.5">
                        <p class="truncate text-xs font-medium text-foreground" :title="item.name" x-text="item.name"></p>
                        <p class="text-[10px] text-muted-foreground mt-0.5" x-text="item.size"></p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
