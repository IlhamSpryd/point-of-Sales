<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use App\Core\Context\TenantContext;
use App\Modules\Organization\Models\User;
use App\Modules\Subscription\Contracts\EntitlementGateInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Projects the registry for a given user (ADR-NAV-006 / §12.3).
 *
 * Navigation visibility is ADVISORY. It decides what to *show*, never what is
 * *allowed*: every destination remains independently guarded by route
 * middleware, policies and entitlement middleware.
 *
 * Four consistent projections come out of one algorithm:
 *  - sidebar()               domains → visible hubs (Governance Law G-1/G-5)
 *  - subNavForRoute()        the L2 pills for the hub owning the current route
 *  - breadcrumbsForRoute()   Home → Domain → Hub → Page (Breadcrumb Architecture)
 *  - commandPaletteItems()   every visible page + hub, aliased for ⌘K search
 *
 * Identity is memoised per (user, request) — the container binds this class as a
 * request-scoped singleton, so navigation never issues repeat entitlement or
 * policy lookups during a single render.
 */
final class NavigationVisibilityResolver
{
    /** @var list<string> */
    private const CANONICAL_ROLES = [
        'Owner', 'Admin', 'Manager', 'Finance', 'Sales', 'Kasir', 'Waiter', 'Barista', 'Inventory', 'Supervisor', 'Cook',
    ];

    /** @var array<string, list<array{domain: NavigationNode, hubs: list<NavigationNode>}>> */
    private array $sidebarCache = [];

    /** @var array<string, array{hub: bool, pages: list<NavigationNode>}> */
    private array $visibilityCache = [];

    public function __construct(
        private readonly NavigationRegistry $registry,
        private readonly EntitlementGateInterface $entitlements,
        private readonly TenantContext $tenantContext,
    ) {}

    // ──────────────────────────────────────────────────────────────
    // Core predicate (§12.3)
    // ──────────────────────────────────────────────────────────────

    public function isVisible(NavigationNode $node, ?User $user): bool
    {
        if (! $node->visible || $node->status === 'deprecated' || $node->status === 'planned') {
            return false;
        }

        if ($node->isDomain()) {
            return true;
        }

        if ($node->route === null || ! Route::has($node->route)) {
            return false;
        }

        if (! $this->passesVertical($node)) {
            return false;
        }

        return $this->passesAuthorization($node, $user);
    }

    private function passesVertical(NavigationNode $node): bool
    {
        if ($node->verticals === []) {
            return true;
        }

        $activeVertical = $this->resolveActiveVertical();

        if ($activeVertical === 'hybrid') {
            return true;
        }

        return in_array($activeVertical, $node->verticals, true);
    }

    private function resolveActiveVertical(): string
    {
        $storeId = $this->tenantContext->getStoreId();
        if ($storeId !== null) {
            $storeRow = DB::table('stores')
                ->where('id', $storeId)
                ->first(['store_vertical', 'tenant_id']);

            if ($storeRow !== null && ! empty($storeRow->store_vertical)) {
                return in_array($storeRow->store_vertical, ['fnb', 'retail', 'hybrid'], true)
                    ? $storeRow->store_vertical
                    : 'hybrid';
            }

            if ($storeRow !== null && $storeRow->tenant_id) {
                $tenantIndustry = DB::table('tenants')
                    ->where('id', $storeRow->tenant_id)
                    ->value('primary_industry');

                return in_array($tenantIndustry, ['fnb', 'retail', 'hybrid'], true)
                    ? $tenantIndustry
                    : 'hybrid';
            }
        }

        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId !== null) {
            $tenantIndustry = DB::table('tenants')
                ->where('id', $tenantId)
                ->value('primary_industry');

            return in_array($tenantIndustry, ['fnb', 'retail', 'hybrid'], true)
                ? $tenantIndustry
                : 'hybrid';
        }

        return 'hybrid';
    }

    private function passesAuthorization(NavigationNode $node, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $this->passesRole($node, $user)) {
            return false;
        }

        if ($node->entitlement !== null && ! $this->passesEntitlement($node->entitlement)) {
            return false;
        }

        if ($node->gate !== null && ! $this->passesGate($node->gate, $user)) {
            return false;
        }

        return true;
    }

    /**
     * Owner and Admin bypass the role list (they hold the tenant-wide mandate),
     * except where the node explicitly lists a role set that excludes them
     * (Segregation of Duties — ADR-NAV-009 keeps Admin out of Subscription
     * Billing, which declares `roles: ['Owner']`).
     */
    private function passesRole(NavigationNode $node, User $user): bool
    {
        $role = $user->role;
        $roleName = $role?->name;

        if ($node->roles === []) {
            return $roleName !== null;
        }

        if (in_array($roleName, $node->roles, true)) {
            return true;
        }

        $isCanonical = in_array($roleName, self::CANONICAL_ROLES, true);

        // Custom-role / fine-grained path: an explicit ability grant wins for non-canonical roles.
        if (! $isCanonical && $this->hasAnyAbility($node, $user)) {
            return true;
        }

        $isPrivileged = (bool) ($role?->is_admin) || $roleName === 'Owner';

        if (! $isPrivileged) {
            return false;
        }

        // Privileged roles bypass the list — unless the node is a deliberate
        // Owner-only / SoD-restricted surface (ADR-NAV-009).
        return ! $this->isPrivilegedExcluded($node, (string) $roleName);
    }

    private function isPrivilegedExcluded(NavigationNode $node, string $roleName): bool
    {
        // A node whose explicit role list is a strict subset of privileged roles
        // (e.g. ['Owner']) is intentionally closed to Admin.
        return ! in_array($roleName, $node->roles, true)
            && array_intersect($node->roles, ['Admin']) === []
            && in_array('Owner', $node->roles, true);
    }

    private function hasAnyAbility(NavigationNode $node, User $user): bool
    {
        if ($node->abilities === []) {
            return false;
        }

        foreach ($node->abilities as $ability) {
            if ($user->hasPermission($ability)) {
                return true;
            }
        }

        return false;
    }

    private function passesEntitlement(string $feature): bool
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($tenantId === null) {
            return true;
        }

        try {
            return $this->entitlements->hasFeature((int) $tenantId, $feature);
        } catch (\Throwable) {
            // Fail open on infrastructure failure: a broken entitlement lookup
            // must never blank out the navigation. The route middleware remains
            // the authoritative gate.
            return true;
        }
    }

    private function passesGate(string $gate, User $user): bool
    {
        try {
            return Gate::forUser($user)->allows($gate);
        } catch (\Throwable) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Projection 1 — Sidebar
    // ──────────────────────────────────────────────────────────────

    /**
     * @return list<array{domain: NavigationNode, hubs: list<NavigationNode>}>
     */
    public function sidebar(?User $user = null): array
    {
        $user ??= auth()->user();

        if ($user === null) {
            return [];
        }

        $userIdKey = $user->exists ? (string) $user->id : 'obj_'.spl_object_id($user);
        $cacheKey = $userIdKey.'|'.(string) $this->tenantContext->getStoreId();
        if (isset($this->sidebarCache[$cacheKey])) {
            return $this->sidebarCache[$cacheKey];
        }

        $sections = [];

        foreach ($this->registry->domains() as $domain) {
            if (! $domain->visible) {
                continue;
            }

            $hubs = [];

            foreach ($this->registry->hubs() as $hub) {
                if ($hub->domain !== $domain->domain) {
                    continue;
                }

                if (! $this->hubVisibleFor($hub, $user)) {
                    continue;
                }

                $hubs[] = $hub;
            }

            if ($hubs !== []) {
                $sections[] = ['domain' => $domain, 'hubs' => $hubs];
            }
        }

        return $this->sidebarCache[$cacheKey] = $sections;
    }

    /**
     * Resolves the dynamic landing URL for a hub.
     * If the hub's default route is authorized and visible, returns it.
     * Otherwise returns the first authorized visible child page (e.g. Cook -> kds.index).
     */
    public function landingUrlForHub(NavigationNode $hub, ?User $user = null): string
    {
        $user ??= auth()->user();

        // 1. If hub's own route passes authorization, use it:
        if ($hub->route !== null && Route::has($hub->route) && $this->isVisible($hub, $user)) {
            return route($hub->route);
        }

        // 2. Otherwise find the first visible child page that user has access to:
        $visiblePages = $this->visiblePagesFor($hub, $user);
        foreach ($visiblePages as $page) {
            if ($page->route !== null && Route::has($page->route)) {
                return route($page->route);
            }
        }

        return $hub->route !== null && Route::has($hub->route) ? route($hub->route) : '#';
    }

    /**
     * A hub renders when at least one of its pages is visible (Governance Law
     * R-4). A hub whose single visible page is itself is still rendered as one
     * L1 row with the L2 bar suppressed (R-5).
     */
    private function hubVisibleFor(NavigationNode $hub, User $user): bool
    {
        if ($this->isVisible($hub, $user)) {
            return true;
        }

        return $this->visiblePagesFor($hub, $user) !== [];
    }

    /**
     * @return list<NavigationNode>
     */
    public function visiblePagesFor(NavigationNode $hub, ?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        $userIdKey = $user->exists ? (string) $user->id : 'obj_'.spl_object_id($user);
        $key = $hub->id.'|'.$userIdKey.'|'.(string) $this->tenantContext->getStoreId();
        if (isset($this->visibilityCache[$key])) {
            return $this->visibilityCache[$key]['pages'];
        }

        $pages = $this->registry->pagesForHub($hub->id);
        $visible = array_values(array_filter(
            $pages,
            fn (NavigationNode $page): bool => $this->isVisible($page, $user),
        ));

        $this->visibilityCache[$key] = ['hub' => $visible !== [], 'pages' => $visible];

        return $visible;
    }

    // ──────────────────────────────────────────────────────────────
    // Projection 2 — L2 sub-navigation
    // ──────────────────────────────────────────────────────────────

    /**
     * The L2 pills for the hub that owns the given route name.
     * Returns an empty list when the hub has zero or one visible pages (R-5).
     *
     * @return array{hub: NavigationNode|null, pages: list<NavigationNode>}
     */
    public function subNavForRoute(string $routeName, ?User $user = null): array
    {
        $user ??= auth()->user();

        $node = $this->registry->primaryForRoute($routeName);
        if ($node === null) {
            return ['hub' => null, 'pages' => []];
        }

        $hub = $this->registry->hubFor($node);

        if ($hub === null) {
            return ['hub' => null, 'pages' => []];
        }

        $pages = $this->visiblePagesFor($hub, $user);

        return [
            'hub' => $hub,
            'pages' => count($pages) > 1 ? $pages : [],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Projection 3 — Breadcrumbs
    // ──────────────────────────────────────────────────────────────

    /**
     * Home → Domain → Hub → Page (maximum four fragments).
     *
     * The trail reflects the logical application structure, never the URL
     * (§14). The last fragment is never a link. Intermediate fragments link to
     * their hub landing route — never to `dashboard` (which 403s for 9 of 11
     * canonical roles).
     *
     * @return list<array{label: string, url: string|null}>
     */
    public function breadcrumbsForRoute(string $routeName, ?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        $node = $this->registry->primaryForRoute($routeName);
        if ($node === null) {
            return [];
        }

        $hub = $this->registry->hubFor($node);
        $domain = $this->registry->domainFor($node);

        $trail = [];

        if ($domain !== null) {
            $trail[] = ['label' => $domain->label, 'url' => null];
        }

        if ($hub !== null) {
            $isHubItself = $node->isHub() || ($node->isPage() && $node->id === $hub->id.'.'.$this->tail($hub->id));
            $isLanding = $node->route === $hub->route;

            $trail[] = [
                'label' => $hub->label,
                'url' => $isLanding ? null : $this->safeUrl($hub->route),
            ];

            if ($isHubItself) {
                $isLanding = true;
            }
        }

        if (! $node->isHub() && ($trail === [] || end($trail)['label'] !== $node->label)) {
            $trail[] = ['label' => $node->label, 'url' => null];
        }

        // Terminal fragment is never a link.
        if ($trail !== []) {
            $terminal = array_pop($trail);
            $trail[] = ['label' => $terminal['label'], 'url' => null];
        }

        return $trail;
    }

    private function tail(string $id): string
    {
        $parts = explode('.', $id);

        return (string) end($parts);
    }

    private function safeUrl(?string $routeName): ?string
    {
        if ($routeName === null || ! Route::has($routeName)) {
            return null;
        }

        return route($routeName);
    }

    // ──────────────────────────────────────────────────────────────
    // Projection 4 — Command palette
    // ──────────────────────────────────────────────────────────────

    /**
     * @return list<array{id: string, label: string, route: string, url: string, group: string, icon: string|null, aliases: list<string>, haystack: string, kind: string}>
     */
    public function commandPaletteItems(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        $items = [];

        foreach ($this->registry->hubs() as $hub) {
            $domain = $this->registry->domainFor($hub);
            $pages = $this->visiblePagesFor($hub, $user);

            if ($pages === [] || ! Route::has((string) $hub->route) || $domain === null) {
                continue;
            }

            $group = $domain->label;

            $items[] = [
                'id' => $hub->id,
                'label' => $hub->label,
                'route' => (string) $hub->route,
                'url' => route((string) $hub->route),
                'group' => $group,
                'icon' => $hub->icon,
                'aliases' => $hub->aliases,
                'haystack' => $hub->searchHaystack(),
                'kind' => 'hub',
            ];

            foreach ($pages as $page) {
                if (! Route::has((string) $page->route)) {
                    continue;
                }

                $items[] = [
                    'id' => $page->id,
                    'label' => $page->label,
                    'route' => (string) $page->route,
                    'url' => route((string) $page->route),
                    'group' => $group.' › '.$hub->label,
                    'icon' => null,
                    'aliases' => $page->aliases,
                    'haystack' => $page->searchHaystack(),
                    'kind' => 'page',
                ];
            }
        }

        return $items;
    }

    /**
     * Every node the user may see, flattened. Used by governance tests (G-2).
     *
     * @return list<NavigationNode>
     */
    public function visibleNodes(?User $user = null): array
    {
        $user ??= auth()->user();
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            $this->registry->all(),
            fn (NavigationNode $node): bool => $this->isVisible($node, $user),
        ));
    }
}
