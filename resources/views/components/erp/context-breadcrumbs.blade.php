{{--
    SHELL BREADCRUMB — registry derived (Breadcrumb Architecture, §14)

    Answers "where am I in the Domain -> Hub -> Page hierarchy". It is generated
    from App\Platform\Navigation\NavigationVisibilityResolver::breadcrumbsForRoute()
    and can therefore never disagree with the sidebar or the L2 pills.

    Critically, it never hardcodes route('dashboard'): that route is guarded by
    `role:Owner,Manager`, so a Kasir/Finance/Sales breadcrumb root previously
    produced a guaranteed 403 dead-end (INT-06).

    Semantic markup: <nav><ol> with aria-current on the terminal crumb (B-10).
    Maximum four fragments; a one-fragment trail is hidden (B-4).
--}}
@php
    /** @var \App\Platform\Navigation\NavigationVisibilityResolver $navResolver */
    $navResolver = app(\App\Platform\Navigation\NavigationVisibilityResolver::class);

    $currentRouteName = request()->route()?->getName();
    $trail = $currentRouteName !== null
        ? $navResolver->breadcrumbsForRoute($currentRouteName, auth()->user())
        : [];

    $landingRoute = \App\Modules\Organization\Controllers\HomeController::landingRouteFor(auth()->user());
    $homeUrl = ($landingRoute !== 'home' && \Illuminate\Support\Facades\Route::has($landingRoute))
        ? route($landingRoute)
        : (\Illuminate\Support\Facades\Route::has('home') ? route('home') : url('/'));
@endphp

@if (count($trail) > 0)
    <nav aria-label="Breadcrumb" class="hidden md:flex items-center gap-1.5 text-xs select-none min-w-0">
        <ol class="flex items-center gap-1.5 min-w-0">
            <li class="flex items-center">
                <a href="{{ $homeUrl }}" wire:navigate
                    aria-label="Home"
                    class="text-shell-foreground hover:text-shell-foreground-active transition-colors flex items-center motion-reduce:transition-none">
                    <span class="material-symbols-rounded text-[16px]" aria-hidden="true">home</span>
                </a>
            </li>

            @foreach ($trail as $crumb)
                <li class="flex items-center gap-1.5 min-w-0">
                    <span class="text-shell-foreground/40 font-sans text-[11px] select-none" aria-hidden="true">/</span>

                    @if ($loop->last)
                        <span class="text-shell-foreground-active font-semibold truncate max-w-[120px] md:max-w-[180px] lg:max-w-[240px]"
                            aria-current="page">{{ $crumb['label'] }}</span>
                    @elseif (! empty($crumb['url']))
                        <a href="{{ $crumb['url'] }}" wire:navigate
                            class="text-shell-foreground hover:text-shell-foreground-active transition-colors font-medium truncate max-w-[120px] lg:max-w-[180px] motion-reduce:transition-none">
                            {{ $crumb['label'] }}
                        </a>
                    @else
                        <span class="text-shell-foreground font-medium truncate max-w-[120px] lg:max-w-[180px]">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
