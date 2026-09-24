@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-white border border-gray-200 text-yovel-ink text-sm rounded-xl focus:ring-2 focus:ring-yovel-ink focus:border-yovel-ink shadow-sm transition-all duration-200 placeholder:text-gray-400 disabled:opacity-50 disabled:bg-gray-50 disabled:cursor-not-allowed']) }}>
