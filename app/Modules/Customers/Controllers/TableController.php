<?php

namespace App\Modules\Customers\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTableRequest;
use App\Http\Requests\UpdateTableRequest;
use App\Models\Table;
use App\Services\TableService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * TableController: Thin Controller untuk manajemen Meja (khusus Owner/Manager).
 */
class TableController extends Controller
{
    public function __construct(protected TableService $tableService) {}

    public function index(Request $request): View
    {
        $tables = $this->tableService->getFilteredQuery($request)->paginate(10)->withQueryString();

        return view('tables.index', compact('tables'));
    }

    public function create(): View
    {
        return view('tables.create');
    }

    public function store(StoreTableRequest $request): RedirectResponse
    {
        $this->tableService->store($request->validated());

        return redirect()->route('tables.index')->with('success', 'Meja berhasil ditambahkan.');
    }

    public function edit(Table $table): View
    {
        return view('tables.edit', compact('table'));
    }

    public function update(UpdateTableRequest $request, Table $table): RedirectResponse
    {
        $this->tableService->update($table, $request->validated());

        return redirect()->route('tables.index')->with('success', 'Meja berhasil diperbarui.');
    }

    public function destroy(Table $table): RedirectResponse
    {
        try {
            $this->tableService->delete($table);

            return redirect()->route('tables.index')->with('success', 'Meja berhasil dihapus.');
        } catch (ValidationException $e) {
            return redirect()->route('tables.index')->withErrors($e->validator);
        } catch (\Exception $e) {
            if ($e instanceof QueryException) {
                Log::error('Failed to delete table: '.$e->getMessage(), [
                    'table_id' => $table->id,
                    'exception' => $e,
                ]);

                return redirect()->route('tables.index')->with('error', 'Terjadi kesalahan sistem saat menghapus meja. Silakan coba lagi.');
            }

            return redirect()->route('tables.index')->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Unexpected error deleting table: '.$e->getMessage(), [
                'table_id' => $table->id,
                'exception' => $e,
            ]);

            return redirect()->route('tables.index')->with('error', 'Terjadi kesalahan sistem saat menghapus meja.');
        }
    }

    public function rotateQr(Table $table): RedirectResponse
    {
        $this->tableService->rotateQrToken($table);

        return redirect()->route('tables.index')->with('success', 'QR Code meja "'.$table->table_name.'" berhasil dirotasi ke versi baru.');
    }

    public function release(Table $table): RedirectResponse
    {
        try {
            $this->tableService->releaseTable($table);

            return redirect()->route('tables.index')->with('success', 'Meja "'.$table->table_name.'" berhasil dibebaskan (tersedia kembali).');
        } catch (ValidationException $e) {
            return redirect()->route('tables.index')->withErrors($e->validator);
        } catch (\Throwable $e) {
            return redirect()->route('tables.index')->with('error', $e->getMessage());
        }
    }

    public function regenerateToken(Table $table): RedirectResponse
    {
        return $this->rotateQr($table);
    }
}
