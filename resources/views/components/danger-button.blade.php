<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center bg-rose-500 text-white hover:bg-rose-600 font-medium rounded-lg text-sm px-5 py-2.5 transition duration-200 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed']) }}>
    {{ $slot }}
</button>
