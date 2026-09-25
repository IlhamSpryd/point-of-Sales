<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center bg-yovel-ink text-white hover:bg-yovel-ink font-medium rounded-xl text-sm px-5 py-2.5 transition-all duration-200 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm hover:shadow-md focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-yovel-ink']) }}>
    {{ $slot }}
</button>
