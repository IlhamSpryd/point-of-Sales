@props(['messages'])

@if ($messages)
    <ul role="alert" aria-live="assertive" {{ $attributes->merge(['class' => 'text-sm text-rose-600 space-y-1 mt-1 font-medium']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
