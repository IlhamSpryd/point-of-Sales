@php
    $paletteItems = [];
    $seenRoutes = [];
    $authUser = auth()->user();
    $userRole = $authUser?->role?->name;
    $canonicalRoles = ['Owner', 'Admin', 'Manager', 'Finance', 'Sales', 'Kasir', 'Waiter', 'Barista', 'Inventory', 'Supervisor', 'Cook'];
    $isCustomRole = $userRole !== null && ! in_array($userRole, $canonicalRoles, true);

    foreach (config('navigation.sections', []) as $groupName => $items) {
        foreach ($items as $item) {
            $route = (string) ($item['route'] ?? '');
            if ($route === '' || isset($seenRoutes[$route]) || ! \Illuminate\Support\Facades\Route::has($route)) {
                continue;
            }

            $allowed = ($userRole && in_array($userRole, $item['roles'] ?? [], true))
                || ($isCustomRole && ! empty($item['permission']) && $authUser?->hasPermission((string) $item['permission']));

            if (! $allowed) {
                continue;
            }

            $seenRoutes[$route] = true;
            $paletteItems[] = [
                'title' => $item['label'],
                'value' => $route,
                'url' => route($route),
                'group' => $groupName,
                'icon' => '<span class="material-symbols-rounded text-base">' . ($item['icon'] ?? 'chevron_right') . '</span>',
            ];
        }
    }

    foreach (config('navigation.sub_nav', []) as $moduleKey => $moduleConfig) {
        $groupLabel = (string) ($moduleConfig['label'] ?? ucfirst((string) $moduleKey));
        foreach ($moduleConfig['items'] ?? [] as $subItem) {
            $route = (string) ($subItem['route'] ?? '');
            if ($route === '' || isset($seenRoutes[$route]) || ! \Illuminate\Support\Facades\Route::has($route)) {
                continue;
            }

            $allowed = ($userRole && in_array($userRole, $subItem['roles'] ?? [], true))
                || ($isCustomRole && ! empty($subItem['permission']) && $authUser?->hasPermission((string) $subItem['permission']));

            if (! $allowed) {
                continue;
            }

            $seenRoutes[$route] = true;
            $paletteItems[] = [
                'title' => $subItem['label'],
                'value' => $route,
                'url' => route($route),
                'group' => $groupLabel,
                'icon' => '<span class="material-symbols-rounded text-base">' . ($subItem['icon'] ?? 'chevron_right') . '</span>',
            ];
        }
    }
@endphp

<x-ui.command :items="$paletteItems" :headless="true" />
