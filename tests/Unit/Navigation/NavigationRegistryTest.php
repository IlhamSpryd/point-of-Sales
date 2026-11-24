<?php

declare(strict_types=1);

namespace Tests\Unit\Navigation;

use App\Platform\Navigation\NavigationNode;
use App\Platform\Navigation\NavigationRegistry;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Governance Law G-1 — Single Canonical Home.
 *
 * Every non-shortcut page route appears exactly once; every menu id is unique;
 * every route resolves; every hub has exactly one landing page.
 */
class NavigationRegistryTest extends TestCase
{
    private NavigationRegistry $registry;

    protected function afterSubclassSetUp(): void
    {
        $this->registry = app(NavigationRegistry::class);
    }

    public function test_registry_parses_all_four_domains(): void
    {
        $keys = array_map(
            static fn (NavigationNode $domain): string => (string) $domain->domain,
            $this->registry->domains(),
        );

        $this->assertSame([
            'core',
            'operations',
            'finance_insights',
            'settings',
        ], $keys);
    }

    public function test_registry_contains_ten_hubs(): void
    {
        $this->assertCount(10, $this->registry->hubs());
    }

    public function test_registry_contains_forty_five_pages(): void
    {
        // 45 declared application destinations + derived hub landing pages for
        // hubs whose author omitted an explicit landing. Category hubs (POS,
        // BI, Manufacturing, Settings, Subscription, Users) are browses-only and
        // get their landing derived from the hub row.
        $this->assertGreaterThanOrEqual(45, count($this->registry->pages()));
        $this->assertGreaterThanOrEqual(
            45,
            count(array_filter($this->registry->pages(), static fn (NavigationNode $p): bool => $p->landing === false)),
            'At least 45 authored non-landing pages must exist.',
        );
    }

    public function test_every_page_route_is_registered_in_the_routing_table(): void
    {
        $missing = [];

        foreach ([...$this->registry->hubs(), ...$this->registry->pages()] as $node) {
            if ($node->route !== null && ! Route::has($node->route)) {
                $missing[] = "{$node->id} → {$node->route}";
            }
        }

        $this->assertSame([], $missing, 'Registry references unregistered routes: '.implode(', ', $missing));
    }

    public function test_every_page_belongs_to_an_existing_hub(): void
    {
        foreach ($this->registry->pages() as $page) {
            $this->assertNotNull($page->parent, "Page [{$page->id}] has no parent hub.");
            $this->assertNotNull($this->registry->find($page->parent), "Page [{$page->id}] points to an unknown hub.");
        }
    }

    public function test_every_hub_has_exactly_one_landing_page(): void
    {
        foreach ($this->registry->hubs() as $hub) {
            $landings = array_filter(
                $this->registry->pagesForHub($hub->id),
                static fn (NavigationNode $page): bool => $page->landing,
            );

            $this->assertCount(1, $landings, "Hub [{$hub->id}] must declare exactly one landing page.");
            $landing = array_values($landings)[0];
            $this->assertSame($hub->route, $landing->route, "Hub [{$hub->id}] landing route must match the hub route.");
        }
    }

    public function test_menu_ids_are_unique(): void
    {
        $ids = array_map(static fn (NavigationNode $node): string => $node->id, $this->registry->all());

        $this->assertSame(count($ids), count(array_unique($ids)), 'Duplicate navigation menu id detected.');
    }

    public function test_pages_are_declared_at_most_once_except_shortcuts(): void
    {
        $allowlist = (array) config('navigation_engine.shortcut_allowlist', []);
        $counts = [];

        foreach ($this->registry->pages() as $page) {
            if ($page->route === null) {
                continue;
            }
            $counts[$page->route] = ($counts[$page->route] ?? 0) + 1;
        }

        foreach ($counts as $route => $count) {
            if ($count <= 1) {
                continue;
            }

            $duplicateIds = array_map(
                static fn (NavigationNode $n): string => $n->id,
                array_filter($this->registry->pages(), static fn (NavigationNode $n): bool => $n->route === $route),
            );

            $permitted = array_intersect($duplicateIds, $allowlist) !== [];
            $this->assertTrue($permitted, "Route [{$route}] is declared {$count} times without a shortcut allowlist entry.");
        }
    }

    public function test_hub_icons_are_unique(): void
    {
        $icons = array_map(static fn (NavigationNode $hub): ?string => $hub->icon, $this->registry->hubs());

        $this->assertSame(count($icons), count(array_unique($icons)), 'Two hubs share the same icon.');
    }

    public function test_no_node_exceeds_three_rendered_levels_for_pages(): void
    {
        foreach ($this->registry->pages() as $page) {
            $this->assertSame(3, $page->depth(), "Page [{$page->id}] is not at depth 3.");
        }
    }

    public function test_active_patterns_do_not_collide_across_pages(): void
    {
        $allowlist = (array) config('navigation_engine.shortcut_allowlist', []);
        $owners = [];

        foreach ($this->registry->pages() as $page) {
            foreach ($page->active as $pattern) {
                $owners[$pattern][] = $page->id;
            }
        }

        $collisions = [];
        foreach ($owners as $pattern => $ids) {
            // A documented shortcut (SC-05) is allowed to share a pattern with its
            // canonical node. Every other overlap is an active-state defect.
            $canonical = array_values(array_filter($ids, static fn (string $id): bool => ! in_array($id, $allowlist, true)));
            if (count($canonical) > 1) {
                $collisions[$pattern] = $ids;
            }
        }

        $this->assertSame([], $collisions, 'Overlapping active patterns detected: '.json_encode($collisions));
    }

    public function test_fixed_assets_page_is_rescued_from_orphan_status(): void
    {
        $node = $this->registry->primaryForRoute('finance.fixed-assets.index');

        $this->assertNotNull($node, 'finance.fixed-assets.index must be registered (closes INT-01).');
        $this->assertSame('nav.finance.gl.fixed_assets', $node->id);
        $this->assertTrue($node->visible);
    }

    public function test_tax_classes_does_not_collide_with_settings(): void
    {
        $owners = array_filter(
            $this->registry->pages(),
            static fn (NavigationNode $page): bool => in_array('tax-classes.*', $page->active, true),
        );

        $this->assertCount(1, $owners, 'tax-classes.* must be owned by exactly one page (INT-02).');
        $this->assertSame('nav.catalog.master.tax_classes', array_values($owners)[0]->id);

        // And the Settings hub must not claim it.
        $settings = $this->registry->find('nav.settings');
        $this->assertNotNull($settings);
        $this->assertNotContains('tax-classes.*', $settings->active);
    }

    public function test_channel_mapping_does_not_activate_table_management(): void
    {
        $tableNode = $this->registry->find('nav.frontline.desk.tables');

        $this->assertNotNull($tableNode);
        $this->assertNotContains('integrations.channel-mapping', $tableNode->active, 'Channel mapping must not highlight Table & QR (INT-04).');
    }
}
