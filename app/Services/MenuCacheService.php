<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class MenuCacheService
{
    private const TTL_SECONDS = 3600;

    private const KEY_CATALOG = 'pos:menu:catalog:v1';

    private const KEY_CATEGORIES = 'pos:menu:categories:v1';

    private const KEY_ACTIVE_PRODUCTS = 'pos:menu:active-products:v1';

    private const KEY_MENU_DISPLAY = 'pos:menu:display:v1';

    public function getCatalog()
    {
        return Cache::remember(self::KEY_CATALOG, self::TTL_SECONDS, function () {
            return Category::with([
                'products' => fn ($q) => $q->availableForOrder()->with('modifierGroups.modifiers'),
            ])->get();
        });
    }

    public function getAllCategories()
    {
        return Cache::remember(self::KEY_CATEGORIES, self::TTL_SECONDS, function () {
            return Category::all();
        });
    }

    public function getActiveProductsWithCategory()
    {
        return Cache::remember(self::KEY_ACTIVE_PRODUCTS, self::TTL_SECONDS, function () {
            return Product::where('is_active', true)->with('category')->get();
        });
    }

    public function getMenuDisplayData(): array
    {
        return Cache::remember(self::KEY_MENU_DISPLAY, self::TTL_SECONDS, function () {
            return [
                'categories' => Category::all(),
                'products' => Product::where('is_active', true)
                    ->with(['category', 'modifierGroups.modifiers'])
                    ->get(),
            ];
        });
    }

    public function flush(): void
    {
        Cache::forget(self::KEY_CATALOG);
        Cache::forget(self::KEY_CATEGORIES);
        Cache::forget(self::KEY_ACTIVE_PRODUCTS);
        Cache::forget(self::KEY_MENU_DISPLAY);
    }
}
