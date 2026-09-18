@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white border border-gray-200 text-[#37352F] text-sm rounded-xl focus:ring-2 focus:ring-[#37352F] focus:border-[#37352F] shadow-sm transition-all duration-200 placeholder:text-gray-400 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed']) }}>
