<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * CategoryController: Kontroler minimalis pemantau Kategori tanpa beban logika tinggi.
 * Memastikan payload aman tersaring sebelum diserahkan pada CategoryService.
 */
class CategoryController extends Controller
{
    /**
     * Mengikat class dengan Service menggunakan mekanisme Dependency Injection.
     * Ini memastikan bahwa logika domain diletakkan pada layer servis (Service Layer).
     */
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    /**
     * Menampilkan list daftar indeks dari resource kategori.
     */
    public function index(Request $request): View|BinaryFileResponse
    {
        // Bila diinstruksikan oleh parameter url untuk ekspor csv, alihkan kontrol ke Service
        if ($request->has('export') && $request->export === 'csv') {
            return $this->categoryService->exportCsv($request);
        }

        // Dapatkan query berisi filter dari Service Layer, paginasikan hasilnya.
        $categories = $this->categoryService->getFilteredQuery($request)->paginate(10)->withQueryString();

        // Render view HTML serta kirim variabel "categories"
        return view('categories.index', compact('categories'));
    }

    /**
     * Menampilkan form untuk membuat resource baru.
     */
    public function create(): View
    {
        return view('categories.create');
    }

    /**
     * Persistensi objek baru yang belum ada memori lalu menyimpannya.
     */
    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        // Parameter HTTP masuk ke kelas Request untuk divalidasi.
        // Array associative tervalidasi yang dihasilkan diteruskan ke objek servis.
        $this->categoryService->store($request->validated());

        return redirect()->route('categories.index')->with('success', 'Category created successfully.');
    }

    /**
     * Menampilkan form untuk menyunting resource yang dipilih.
     */
    public function edit(Category $category): View
    {
        return view('categories.edit', compact('category'));
    }

    /**
     * Perbarui properti resource yang sudah ada berdasarkan kuncinya.
     */
    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        // Meneruskan instance Model ke service agar memori data tervalidasi di atasnya diperbarui.
        $this->categoryService->update($category, $request->validated());

        return redirect()->route('categories.index')->with('success', 'Category updated successfully.');
    }

    /**
     * Hapus permanen instance dari resource database.
     */
    public function destroy(Category $category): RedirectResponse
    {
        try {
            // Meneruskan data spesifik (Model) agar terhapus ke dalam Service.
            $this->categoryService->delete($category);

            return redirect()->route('categories.index')->with('success', 'Category deleted successfully.');
        } catch (QueryException $e) {
            return redirect()->route('categories.index')->with('error', 'Data ini masih terhubung dengan data lain dan tidak dapat dihapus.');
        } catch (\Throwable $e) {
            // Jika eksekusi Exception memunculkan galat (ex. kendala asing, Foreign Key) kembalikan galat.
            return redirect()->route('categories.index')->with('error', $e->getMessage());
        }
    }
}
