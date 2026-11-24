<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Immutable navigation node — Domain, Hub, Page or Page-Tab (ADR-NAV-001 / ADR-NAV-003).
 *
 * A node is a pure value object. It never touches the database, never resolves
 * authorization, and never renders. Visibility is delegated to
 * {@see NavigationVisibilityResolver}; the node only declares its contract.
 *
 * Field semantics
 * ---------------
 * - `id`            Stable permanent dot-ID (e.g. `nav.finance.gl.fixed_assets`).
 *                   Decoupled from route names and labels so it survives renames.
 * - `type`          domain | hub | page | tab.
 * - `parent`        Menu ID of the owning hub (null for domains and hubs).
 * - `route`         Laravel route name. `null` only for `type = domain`.
 * - `active`        Route patterns for `request()->routeIs()`. Non-overlapping by contract.
 * - `roles`         MUST mirror the `role:` middleware on the target route (G-2).
 * - `abilities`     Permission codes honoured for custom roles + policy refinement.
 * - `entitlement`   Feature key enforced by `EntitlementGateInterface` (G-4).
 * - `visible`       false = declared for zero-orphan governance but never rendered.
 * - `shortcutOf`    Canonical menu ID when this node is a documented duplicate (SC-01..SC-05).
 * - `shortcuts`     Additional hub placements rendered in navigation (SC-05 only).
 * - `aliases`       Bilingual search keywords for the command palette (ADR-NAV-008).
 */
final readonly class NavigationNode
{
    public const TYPE_DOMAIN = 'domain';

    public const TYPE_HUB = 'hub';

    public const TYPE_PAGE = 'page';

    public const TYPE_TAB = 'tab';

    /**
     * @param  list<string>  $active
     * @param  list<string>  $roles
     * @param  list<string>  $abilities
     * @param  list<string>  $aliases
     * @param  list<string>  $shortcuts
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public ?string $parent,
        public ?string $route,
        public array $active,
        public array $roles,
        public array $abilities,
        public string $scope,
        public ?string $entitlement,
        public bool $visible,
        public ?string $icon,
        public int $order,
        public array $aliases = [],
        public ?string $shortcutOf = null,
        public array $shortcuts = [],
        public ?string $gate = null,
        public ?string $domain = null,
        public bool $landing = false,
        public string $status = 'implemented',
        public ?string $description = null,
        public array $verticals = [],
    ) {
        $this->guard();
    }

    private function guard(): void
    {
        if ($this->id === '') {
            throw new InvalidArgumentException('Navigation node requires a non-empty id.');
        }

        if (! in_array($this->type, [self::TYPE_DOMAIN, self::TYPE_HUB, self::TYPE_PAGE, self::TYPE_TAB], true)) {
            throw new InvalidArgumentException("Navigation node [{$this->id}] has invalid type [{$this->type}].");
        }

        if ($this->type !== self::TYPE_DOMAIN && ($this->route === null || $this->route === '')) {
            throw new InvalidArgumentException("Navigation node [{$this->id}] requires a route name.");
        }

        if ($this->type === self::TYPE_PAGE && $this->parent === null) {
            throw new InvalidArgumentException("Navigation page [{$this->id}] requires a parent hub.");
        }

        if (! in_array($this->scope, ['store', 'tenant'], true)) {
            throw new InvalidArgumentException("Navigation node [{$this->id}] has invalid scope [{$this->scope}].");
        }

        if ($this->active === [] && $this->route !== null) {
            throw new InvalidArgumentException("Navigation node [{$this->id}] requires at least one active pattern.");
        }
    }

    public function isDomain(): bool
    {
        return $this->type === self::TYPE_DOMAIN;
    }

    public function isHub(): bool
    {
        return $this->type === self::TYPE_HUB;
    }

    public function isPage(): bool
    {
        return $this->type === self::TYPE_PAGE;
    }

    public function isTab(): bool
    {
        return $this->type === self::TYPE_TAB;
    }

    /**
     * Rendered depth: domain = 1, hub = 2, page = 3, tab = 4 (in-page only).
     */
    public function depth(): int
    {
        return match ($this->type) {
            self::TYPE_DOMAIN => 1,
            self::TYPE_HUB => 2,
            self::TYPE_PAGE => 3,
            default => 4,
        };
    }

    public function isShortcut(): bool
    {
        return $this->shortcutOf !== null;
    }

    /**
     * Searchable haystack for the command palette — label, id tail, aliases and
     * the owning domain. Lower-cased so the client-side scorer stays cheap.
     */
    public function searchHaystack(): string
    {
        return Str::lower(implode(' ', array_filter([
            $this->label,
            Str::afterLast($this->id, '.'),
            $this->domain,
            ...$this->aliases,
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'parent' => $this->parent,
            'domain' => $this->domain,
            'route' => $this->route,
            'active' => $this->active,
            'roles' => $this->roles,
            'abilities' => $this->abilities,
            'scope' => $this->scope,
            'entitlement' => $this->entitlement,
            'visible' => $this->visible,
            'icon' => $this->icon,
            'order' => $this->order,
            'aliases' => $this->aliases,
            'shortcut_of' => $this->shortcutOf,
            'shortcuts' => $this->shortcuts,
            'gate' => $this->gate,
            'landing' => $this->landing,
            'status' => $this->status,
            'description' => $this->description,
        ];
    }
}
