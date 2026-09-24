<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center bg-white border border-gray-200 text-gray-700 hover:bg-gray-50 font-medium rounded-xl text-sm px-5 py-2.5 transition-all duration-200 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-yovel-ink']) }}>
    {{ $slot }}
</button>
