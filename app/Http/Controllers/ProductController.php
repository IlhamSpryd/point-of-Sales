<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Category;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Services\ProductService;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Exception;

/**
 * ProductController: Pengatur alur pertukaran formulir entitas Produk seraya mendelegasikan
 * kerumitan transaksi (termasuk penyimpanan gambar) sepenuhnya kepada ProductService.
 */
class ProductController extends Controller
{
    /**
     * Dependency Injection untuk ProductService.
     * Logika bisnis dipisah ke Service Pattern agar Controller tetap tipis (Thin Controller).
     */
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * Menampilkan daftar semua kueri produk.
     */
    public function index(\Illuminate\Http\Request $request): \Illuminate\View\View|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        // Cek request untuk ekspor CSV, lalu delegasikan ke Service.
        if ($request->has('export') && $request->export === 'csv') {
            return $this->productService->exportCsv($request);
        }

        // Mengambil data produk dengan filter dari Service berserta paginasinya
        $products = $this->productService->getFilteredQuery($request)->paginate(10)->withQueryString();
        // Mengembalikan View dengan membawa data produk
        return view('products.index', compact('products'));
    }

    /**
     * Menampilkan form untuk membuat resource baru.
     */
    public function create(): View
    {
        // Mengambil semua data kategori untuk kebutuhan dropdown pilihan
        $categories = Category::all();
        return view('products.create', compact('categories'));
    }

    /**
     * Menyimpan data resource baru ke database.
     */
    public function store(StoreProductRequest $request): RedirectResponse
    {
        // Parameter Request telah divalidasi oleh Form Request (StoreProductRequest). 
        // Lakukan pemanggilan logika penyimpanan utama yang berada di dalam ProductService.
        $this->productService->store($request->validated(), $request->file('product_photo'));
        
        // Redirect kembali ke halaman list produk beserta pesan sukses. 
        return redirect()->route('products.index')->with('success', 'Product created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource yang dipilih.
     */
    public function edit(Product $product): View
    {
        $categories = Category::all();
        return view('products.edit', compact('product', 'categories'));
    }

    /**
     * Memperbarui resource tertentu pada database.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        // Parameter input masuk melalui Form Request yang memegang aturan validasi.
        // Data yang tervalidasi kemudian diteruskan ke ProductService.
        $this->productService->update($product, $request->validated(), $request->file('product_photo'));
        
        return redirect()->route('products.index')->with('success', 'Product updated successfully.');
    }

    /**
     * Menghapus resource dari database.
     */
    public function destroy(Product $product): RedirectResponse
    {
        try {
            // Meneruskan model untuk dihapus oleh ProductService.
            $this->productService->delete($product);
            return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
        } catch (Exception $e) {
            // Menangkap semua pengecualian yang dilemparkan oleh Service
            return redirect()->route('products.index')->with('error', $e->getMessage());
        }
    }
}
