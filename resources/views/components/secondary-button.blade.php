<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center bg-white border border-[#E9E9E7] text-[#37352F] hover:bg-[#F7F7F5] font-medium rounded-lg text-sm px-5 py-2.5 transition duration-200 active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm']) }}>
    {{ $slot }}
</button>
