<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Loads, validates and indexes every navigation domain (ADR-NAV-001).
 *
 * The registry is the ONLY place that knows what destinations exist. It is pure
 * data: no database access, no HTTP, no authorization. Visibility is projected
 * by {@see NavigationVisibilityResolver}.
 *
 * Indexes built once at construction:
 *  - byId        menu id   → node
 *  - byRoute     route name → list<node>  (a route may be declared twice only
 *                                           when listed in the shortcut allow-list)
 *  - domains     ordered list<node> of type domain
 *  - hubs        ordered list<node> of type hub
 *  - pages       ordered list<node> of type page, grouped by owning hub
 *
 * @see docs/architecture/APEIRON_ENTERPRISE_NAVIGATION_ARCHITECTURE_BLUEPRINT.md §16.2
 */
final class NavigationRegistry
{
    /** @var array<string, NavigationNode> */
    private array $byId = [];

    /** @var array<string, list<NavigationNode>> */
    private array $byRoute = [];

    /** @var list<NavigationNode> */
    private array $domains = [];

    /** @var list<NavigationNode> */
    private array $hubs = [];

    /** @var array<string, list<NavigationNode>> hubId → ordered pages */
    private array $pagesByHub = [];

    /** @var list<NavigationNode> */
    private array $pages = [];

    /** @var list<NavigationNode> every declared node */
    private array $all = [];

    private bool $loaded = false;

    public function __construct(private readonly ?string $domainsPath = null) {}

    /**
     * Lazily load the registry. Cheap enough to run per request; the config
     * files are already cached by `config:cache` in production.
     */
    public function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $path = $this->domainsPath ?? config('navigation_engine.domains_path');
        if (! is_string($path) || ! is_dir($path)) {
            throw new RuntimeException('Navigation domains directory is missing: '.var_export($path, true));
        }

        $files = glob($path.'/*.php') ?: [];
        $definitions = [];

        foreach ($files as $file) {
            /** @var mixed $definition */
            $definition = require $file;

            if (! is_array($definition) || ! isset($definition['key'], $definition['hubs'])) {
                throw new RuntimeException("Invalid navigation domain definition: {$file}");
            }

            $definitions[] = $definition;
        }

        usort($definitions, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

        foreach ($definitions as $definition) {
            $this->ingestDomain($definition);
        }

        $this->loaded = true;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function ingestDomain(array $definition): void
    {
        $domainKey = (string) $definition['key'];

        $domain = $this->makeNode($definition, [
            'id' => 'nav.domain.'.$domainKey,
            'label' => (string) $definition['label'],
            'type' => NavigationNode::TYPE_DOMAIN,
            'parent' => null,
            'route' => null,
            'active' => [],
            'roles' => [],
            'abilities' => [],
            'scope' => 'tenant',
            'icon' => $definition['icon'] ?? null,
            'order' => (int) $definition['order'],
            'domain' => $domainKey,
        ]);

        $this->domains[] = $domain;
        $this->register($domain);

        foreach ($definition['hubs'] as $hubDefinition) {
            $this->ingestHub($hubDefinition, $domainKey);
        }
    }

    /**
     * @param  array<string, mixed>  $hub
     */
    private function ingestHub(array $hub, string $domainKey): void
    {
        $hubNode = $this->makeNode($hub, [
            'type' => NavigationNode::TYPE_HUB,
            'parent' => null,
            'domain' => $domainKey,
            'shortcuts' => $hub['shortcuts'] ?? [],
        ]);

        $this->hubs[] = $hubNode;
        $this->register($hubNode);

        $pageDefinitions = $hub['pages'] ?? [];

        // Every hub declares exactly one landing page (registry contract). When a
        // hub authors no page on the hub's own route, derive one so the L1 row has
        // a resolvable destination. A DERIVED landing must never duplicate an
        // authored page: that would surface the same destination twice in the
        // palette and the L2 bar.
        $authoredOnHubRoute = false;
        foreach ($pageDefinitions as $definition) {
            if (($definition['route'] ?? null) === $hubNode->route) {
                $authoredOnHubRoute = true;
                break;
            }
        }

        if (! $authoredOnHubRoute) {
            array_unshift($pageDefinitions, [
                'id' => $hubNode->id.'.landing',
                'label' => $hubNode->label,
                'route' => $hubNode->route,
                // A derived landing claims ONLY its own route. Claiming the hub's
                // full pattern set would make it collide with every sibling tab.
                'active' => [$hubNode->route],
                'roles' => $hubNode->roles,
                'abilities' => $hubNode->abilities,
                'scope' => $hubNode->scope,
                'entitlement' => $hubNode->entitlement,
                'order' => $hubNode->order - 1,
                'landing' => true,
                'aliases' => $hubNode->aliases,
                'icon' => null,
            ]);
        }

        $pages = [];

        foreach ($pageDefinitions as $pageDefinition) {
            // The page that owns the hub's own route IS the landing page.
            if (($pageDefinition['route'] ?? null) === $hubNode->route) {
                $pageDefinition['landing'] = true;
            }

            $page = $this->makeNode($pageDefinition, [
                'type' => NavigationNode::TYPE_PAGE,
                'parent' => $hubNode->id,
                'domain' => $domainKey,
            ]);

            $pages[] = $page;
            $this->pages[] = $page;
            $this->register($page);
        }

        usort($pages, static fn (NavigationNode $a, NavigationNode $b): int => $a->order <=> $b->order);
        $this->pagesByHub[$hubNode->id] = $pages;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $defaults
     */
    private function makeNode(array $definition, array $defaults): NavigationNode
    {
        $payload = array_merge([
            'type' => NavigationNode::TYPE_PAGE,
            'parent' => null,
            'route' => null,
            'active' => [],
            'roles' => [],
            'abilities' => [],
            'scope' => 'tenant',
            'entitlement' => null,
            'visible' => true,
            'icon' => null,
            'order' => 0,
            'aliases' => [],
            'shortcut_of' => null,
            'shortcuts' => [],
            'gate' => null,
            'domain' => null,
            'landing' => false,
            'status' => 'implemented',
            'description' => null,
        ], $definition, $defaults);

        return new NavigationNode(
            id: (string) $payload['id'],
            label: (string) $payload['label'],
            type: (string) $payload['type'],
            parent: $payload['parent'] !== null ? (string) $payload['parent'] : null,
            route: $payload['route'] !== null ? (string) $payload['route'] : null,
            active: array_values(array_map('strval', (array) $payload['active'])),
            roles: array_values(array_map('strval', (array) $payload['roles'])),
            abilities: array_values(array_map('strval', (array) $payload['abilities'])),
            scope: (string) $payload['scope'],
            entitlement: $payload['entitlement'] !== null ? (string) $payload['entitlement'] : null,
            visible: (bool) $payload['visible'],
            icon: $payload['icon'] !== null ? (string) $payload['icon'] : null,
            order: (int) $payload['order'],
            aliases: array_values(array_map('strval', (array) $payload['aliases'])),
            shortcutOf: $payload['shortcut_of'] !== null ? (string) $payload['shortcut_of'] : null,
            shortcuts: array_values(array_map('strval', (array) $payload['shortcuts'])),
            gate: $payload['gate'] !== null ? (string) $payload['gate'] : null,
            domain: $payload['domain'] !== null ? (string) $payload['domain'] : null,
            landing: (bool) $payload['landing'],
            status: (string) $payload['status'],
            description: $payload['description'] !== null ? (string) $payload['description'] : null,
        );
    }

    private function register(NavigationNode $node): void
    {
        if (isset($this->byId[$node->id])) {
            throw new RuntimeException("Duplicate navigation menu id: {$node->id}");
        }

        $this->byId[$node->id] = $node;
        $this->all[] = $node;

        if ($node->route !== null) {
            $this->byRoute[$node->route][] = $node;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Lookups
    // ──────────────────────────────────────────────────────────────

    public function find(string $id): ?NavigationNode
    {
        $this->ensureLoaded();

        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<NavigationNode>
     */
    public function findByRoute(string $routeName): array
    {
        $this->ensureLoaded();

        return $this->byRoute[$routeName] ?? [];
    }

    /**
     * The single canonical node for a route. When a route is delivered through a
     * shortcut (SC-05), the first declared, non-shortcut node wins.
     */
    public function primaryForRoute(string $routeName): ?NavigationNode
    {
        foreach ($this->findByRoute($routeName) as $node) {
            if (! $node->isShortcut()) {
                return $node;
            }
        }

        return $this->findByRoute($routeName)[0] ?? null;
    }

    /**
     * @return list<NavigationNode>
     */
    public function domains(): array
    {
        $this->ensureLoaded();

        return $this->domains;
    }

    /**
     * @return list<NavigationNode>
     */
    public function hubs(): array
    {
        $this->ensureLoaded();

        return $this->hubs;
    }

    /**
     * @return list<NavigationNode>
     */
    public function pages(): array
    {
        $this->ensureLoaded();

        return $this->pages;
    }

    /**
     * @return list<NavigationNode>
     */
    public function pagesForHub(string $hubId): array
    {
        $this->ensureLoaded();

        return $this->pagesByHub[$hubId] ?? [];
    }

    public function hubFor(NavigationNode $node): ?NavigationNode
    {
        $this->ensureLoaded();

        if ($node->isHub()) {
            return $node;
        }

        if ($node->parent === null) {
            return null;
        }

        $parent = $this->byId[$node->parent] ?? null;

        return $parent?->isHub() ? $parent : null;
    }

    /**
     * The hub a page-tab belongs to (walks one extra level).
     */
    public function hubForTab(NavigationNode $tab): ?NavigationNode
    {
        $this->ensureLoaded();

        $page = $tab->parent !== null ? ($this->byId[$tab->parent] ?? null) : null;

        return $page !== null ? $this->hubFor($page) : null;
    }

    public function domainFor(NavigationNode $node): ?NavigationNode
    {
        $this->ensureLoaded();

        $hub = $node->isHub() ? $node : $this->hubFor($node);
        if ($hub === null || $hub->domain === null) {
            return null;
        }

        return $this->byId['nav.domain.'.$hub->domain] ?? null;
    }

    /**
     * @return list<NavigationNode>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->all;
    }

    /**
     * Every node whose route exists in the routing table.
     *
     * @return list<NavigationNode>
     */
    public function resolvable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NavigationNode $node): bool => $node->route === null || Route::has($node->route),
        ));
    }

    /**
     * @return list<string>
     */
    public function permissionsForRoute(string $routeName): array
    {
        $permissions = [];

        foreach ($this->findByRoute($routeName) as $node) {
            foreach ($node->abilities as $ability) {
                $permissions[] = $ability;
            }
        }

        return array_values(array_unique($permissions));
    }
}
