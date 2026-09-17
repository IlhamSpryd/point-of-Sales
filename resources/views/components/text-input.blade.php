@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white border-[#E9E9E7] text-[#37352F] text-sm rounded-lg focus:ring-[#37352F] focus:border-[#37352F] shadow-sm transition disabled:opacity-50 disabled:bg-gray-50']) }}>
