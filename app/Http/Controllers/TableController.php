<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTableRequest;
use App\Http\Requests\UpdateTableRequest;
use App\Models\Table;
use App\Services\TableService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        } catch (\Throwable $e) {
            return redirect()->route('tables.index')->with('error', $e->getMessage());
        }
    }
}
