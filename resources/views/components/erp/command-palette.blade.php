@php
    $paletteItems = [];
    $userRole = auth()->user()?->role?->name;

    foreach (config('navigation.sections', []) as $groupName => $items) {
        foreach ($items as $item) {
            if ($userRole && !empty($item['roles']) && !in_array($userRole, $item['roles'], true)) {
                continue;
            }
            if (\Illuminate\Support\Facades\Route::has($item['route'])) {
                $paletteItems[] = [
                    'title' => $item['label'],
                    'value' => $item['route'],
                    'url' => route($item['route']),
                    'group' => $groupName,
                    'icon' => '<span class="material-symbols-rounded text-base">' . ($item['icon'] ?? 'chevron_right') . '</span>',
                ];
            }
        }
    }
@endphp

<x-ui.command :items="$paletteItems" :headless="true" />
