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
            @foreach ($trail as $crumb)
                @if (!$loop->first)
                    <li aria-hidden="true" class="text-muted-foreground/75 font-sans text-[11px] select-none">/</li>
                @endif
                <li class="flex items-center min-w-0">
                    @if ($loop->last)
                        <span class="text-foreground font-medium truncate max-w-[140px] md:max-w-[200px]"
                            aria-current="page">{{ $crumb['label'] }}</span>
                    @elseif (! empty($crumb['url']))
                        <a href="{{ $crumb['url'] }}" wire:navigate
                            class="text-muted-foreground hover:text-foreground transition-colors font-normal truncate max-w-[120px] lg:max-w-[160px] motion-reduce:transition-none">
                            {{ $crumb['label'] }}
                        </a>
                    @else
                        <span class="text-muted-foreground font-normal truncate max-w-[120px] lg:max-w-[160px]">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
