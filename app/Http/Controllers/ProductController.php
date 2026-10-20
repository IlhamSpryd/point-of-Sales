<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BulkUpdateProductsRequest;
use App\Http\Requests\ImportProductsRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Ingredient;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\Store;
use App\Models\TaxClass;
use App\Models\UnitOfMeasure;
use App\Services\AuditTrailService;
use App\Services\CatalogBulkEditService;
use App\Services\Context\TenantContext;
use App\Services\ProductImportService;
use App\Services\ProductService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ProductController: Pengatur alur pertukaran formulir entitas Produk seraya mendelegasikan
 * kerumitan transaksi sepenuhnya kepada ProductService.
 */
class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected ?AuditTrailService $auditTrail = null
    ) {
        $this->auditTrail ??= app(AuditTrailService::class);
    }

    /**
     * Menampilkan daftar semua kueri produk.
     */
    public function index(Request $request): View|BinaryFileResponse
    {
        $this->authorize('viewAny', Product::class);

        // Cek request untuk ekspor CSV, lalu delegasikan ke Service.
        if ($request->has('export') && $request->export === 'csv') {
            return $this->productService->exportCsv($request);
        }

        // Mengambil data produk dengan filter dari Service berserta paginasinya
        $products = $this->productService->getFilteredQuery($request)->paginate(15)->withQueryString();
        $categories = Category::orderBy('category_name')->get();
        $stores = Store::where('is_active', true)->orderBy('name')->get();

        return view('products.index', compact('products', 'categories', 'stores'));
    }

    /**
     * Endpoint terdedikasi untuk ekspor katalog produk.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $this->authorize('viewAny', Product::class);

        return $this->productService->exportCsv($request);
    }

    /**
     * Menampilkan form untuk membuat resource baru.
     */
    public function create(): View
    {
        $this->authorize('create', Product::class);

        $categories = Category::all();
        $brands = Brand::where('is_active', true)->orderBy('name')->get();
        $uoms = UnitOfMeasure::where('is_active', true)->orderBy('name')->get();
        $taxClasses = TaxClass::where('is_active', true)->orderBy('name')->get();
        $ingredients = Ingredient::where('is_active', true)->orderBy('name')->get();
        $modifierGroups = ModifierGroup::orderBy('name')->get();
        $allProducts = Product::where('is_active', true)->orderBy('product_name')->get();

        return view('products.create', compact('categories', 'brands', 'uoms', 'taxClasses', 'ingredients', 'modifierGroups', 'allProducts'));
    }

    /**
     * Menyimpan data resource baru ke database.
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $product = $this->productService->store($request->validated(), $request->file('product_photo'));

        $tenantId = app(TenantContext::class)->requireTenantId();
        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'product.created',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Product::class,
            subjectId: $product->id,
            description: "Produk '{$product->product_name}' ({$product->product_code}) berhasil dibuat.",
            oldValues: null,
            newValues: $product->toArray()
        );

        return redirect()->route('products.index')->with('success', 'Produk berhasil ditambahkan.');
    }

    /**
     * Menampilkan form untuk menyunting resource yang dipilih.
     */
    public function edit(Product $product): View
    {
        $this->authorize('update', $product);

        $categories = Category::all();
        $brands = Brand::where('is_active', true)->orderBy('name')->get();
        $uoms = UnitOfMeasure::where('is_active', true)->orderBy('name')->get();
        $taxClasses = TaxClass::where('is_active', true)->orderBy('name')->get();
        $ingredients = Ingredient::where('is_active', true)->orderBy('name')->get();
        $modifierGroups = ModifierGroup::orderBy('name')->get();
        $allProducts = Product::where('id', '!=', $product->id)->where('is_active', true)->orderBy('product_name')->get();

        $product->load([
            'ingredients',
            'modifierGroups',
            'media',
            'costHistories',
            'variants.attributeValues.attribute',
            'bundleItems.componentProduct',
        ]);

        return view('products.edit', compact('product', 'categories', 'brands', 'uoms', 'taxClasses', 'ingredients', 'modifierGroups', 'allProducts'));
    }

    /**
     * Memperbarui resource tertentu pada database.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $oldValues = $product->toArray();
        $updated = $this->productService->update($product, $request->validated(), $request->file('product_photo'));

        $tenantId = app(TenantContext::class)->requireTenantId();
        $this->auditTrail->log(
            tenantId: $tenantId,
            action: 'product.updated',
            userId: auth()->id(),
            storeId: app(TenantContext::class)->getStoreId(),
            subjectType: Product::class,
            subjectId: $product->id,
            description: "Produk '{$product->product_name}' berhasil diperbarui.",
            oldValues: $oldValues,
            newValues: $updated->toArray()
        );

        return redirect()->route('products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    /**
     * Menghapus resource dari database.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        try {
            $tenantId = app(TenantContext::class)->requireTenantId();
            $pName = $product->product_name;
            $pId = $product->id;

            $this->productService->delete($product);

            $this->auditTrail->log(
                tenantId: $tenantId,
                action: 'product.deleted',
                userId: auth()->id(),
                storeId: app(TenantContext::class)->getStoreId(),
                subjectType: Product::class,
                subjectId: $pId,
                description: "Produk '{$pName}' berhasil dihapus."
            );

            return redirect()->route('products.index')->with('success', 'Produk berhasil dihapus.');
        } catch (QueryException) {
            return redirect()->route('products.index')->with('error', 'Data ini masih terhubung dengan data lain dan tidak dapat dihapus.');
        } catch (\Throwable $e) {
            return redirect()->route('products.index')->with('error', $e->getMessage());
        }
    }

    /**
     * Menghapus foto/media spesifik milik produk.
     */
    public function destroyMedia(Product $product, ProductMedia $media): RedirectResponse
    {
        $this->authorize('update', $product);

        if ((int) $media->product_id !== (int) $product->id) {
            abort(404, 'Media tidak ditemukan pada produk ini.');
        }

        if ($media->file_path && Storage::disk('public')->exists($media->file_path)) {
            Storage::disk('public')->delete($media->file_path);
        }

        $media->delete();

        return back()->with('success', 'Media produk berhasil dihapus.');
    }

    /**
     * Mengatur foto utama (primary media) produk.
     */
    public function setPrimaryMedia(Product $product, ProductMedia $media): RedirectResponse
    {
        $this->authorize('update', $product);

        if ((int) $media->product_id !== (int) $product->id) {
            abort(404, 'Media tidak ditemukan pada produk ini.');
        }

        ProductMedia::where('product_id', $product->id)->update(['is_primary' => false]);
        $media->update(['is_primary' => true]);
        $product->update(['product_photo' => $media->file_path]);

        return back()->with('success', 'Foto utama produk berhasil diperbarui.');
    }

    /**
     * Mengimpor katalog produk massal dari file CSV.
     */
    public function import(ImportProductsRequest $request, ProductImportService $importService): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $file = $request->file('file');
        $strictMode = (bool) $request->boolean('strict_mode', false);

        $result = $importService->importCsv($file, null, $strictMode);

        if ($result['failed_count'] > 0) {
            $msg = "Impor selesai: {$result['imported_count']} berhasil, {$result['failed_count']} gagal.";

            return redirect()->route('products.index')->with('warning', $msg)->with('import_errors', $result['errors']);
        }

        return redirect()->route('products.index')->with('success', "Berhasil mengimpor {$result['imported_count']} produk.");
    }

    /**
     * Memperbarui banyak produk sekaligus (Bulk Edit).
     */
    public function bulkUpdate(BulkUpdateProductsRequest $request, CatalogBulkEditService $bulkService): RedirectResponse
    {
        $this->authorize('update', Product::class);

        $validated = $request->validated();
        $productIds = $validated['product_ids'];
        $action = $validated['action'];
        $tenantId = app(TenantContext::class)->requireTenantId();

        $count = match ($action) {
            'update_status' => $bulkService->bulkUpdateStatus($productIds, $validated['status'], $tenantId),
            'update_category' => $bulkService->bulkUpdateCategory($productIds, $validated['category_id'] ? (int) $validated['category_id'] : null, $tenantId),
            'update_price' => $bulkService->bulkUpdatePrice($productIds, (float) $validated['price_value'], $validated['price_mode'], $tenantId),
            'update_assortment' => $bulkService->bulkUpdateStoreAssortment($productIds, (int) $validated['store_id'], array_filter([
                'is_available' => $request->has('is_available') ? $request->boolean('is_available') : null,
                'store_price_override' => $validated['store_price_override'] ?? null,
            ], fn ($v) => $v !== null), $tenantId),
            default => 0,
        };

        return redirect()->route('products.index')->with('success', "Berhasil memperbarui {$count} produk terpilih.");
    }
}
