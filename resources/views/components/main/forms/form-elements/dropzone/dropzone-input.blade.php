@props([
    'name' => 'file',
    'label' => 'Drop File Here',
    'hint' => 'Drag and drop your PNG, JPG, WebP, SVG images here or browse',
    'accept' => '.png,.jpg,.jpeg,.webp,.svg,.pdf',
    'multiple' => false,
    'maxSizeMb' => 5,
])

<x-erp.file-dropzone
    :name="$name"
    :label="$label"
    :hint="$hint"
    :accept="$accept"
    :multiple="$multiple"
    :max-size-mb="$maxSizeMb"
    {{ $attributes }}
/>
