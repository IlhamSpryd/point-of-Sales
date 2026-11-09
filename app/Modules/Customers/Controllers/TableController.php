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
        $this->authorize('viewAny', Table::class);

        $tables = $this->tableService->getFilteredQuery($request)->paginate(10)->withQueryString();

        return view('tables.index', compact('tables'));
    }

    public function create(): View
    {
        $this->authorize('create', Table::class);

        return view('tables.create');
    }

    public function store(StoreTableRequest $request): RedirectResponse
    {
        $this->authorize('create', Table::class);

        $this->tableService->store($request->validated());

        return redirect()->route('tables.index')->with('success', 'Table created successfully.');
    }

    public function show(Table $table): View
    {
        $this->authorize('view', $table);

        return view('tables.edit', compact('table'));
    }

    public function edit(Table $table): View
    {
        $this->authorize('update', $table);

        return view('tables.edit', compact('table'));
    }

    public function update(UpdateTableRequest $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $this->tableService->update($table, $request->validated());

        return redirect()->route('tables.index')->with('success', 'Table updated successfully.');
    }

    public function destroy(Table $table): RedirectResponse
    {
        $this->authorize('delete', $table);

        try {
            $this->tableService->delete($table);

            return redirect()->route('tables.index')->with('success', 'Table deleted successfully.');
        } catch (ValidationException $e) {
            return redirect()->route('tables.index')->withErrors($e->validator);
        } catch (\Exception $e) {
            if ($e instanceof QueryException) {
                Log::error('Failed to delete table: '.$e->getMessage(), [
                    'table_id' => $table->id,
                    'exception' => $e,
                ]);

                return redirect()->route('tables.index')->with('error', 'A system error occurred while deleting the table. Please try again.');
            }

            return redirect()->route('tables.index')->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Unexpected error deleting table: '.$e->getMessage(), [
                'table_id' => $table->id,
                'exception' => $e,
            ]);

            return redirect()->route('tables.index')->with('error', 'A system error occurred while deleting the table.');
        }
    }

    public function updateStatus(Request $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $validated = $request->validate([
            'operational_status' => 'required|string|in:available,occupied,reserved',
        ]);

        $this->tableService->update($table, $validated);

        return redirect()->route('tables.index')->with('success', 'Table status updated successfully.');
    }

    public function updatePosition(Request $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $validated = $request->validate([
            'area' => 'nullable|string|max:64',
        ]);

        $this->tableService->update($table, $validated);

        return redirect()->route('tables.index')->with('success', 'Table position updated successfully.');
    }

    public function rotateQr(Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $this->tableService->rotateQrToken($table);

        return redirect()->route('tables.index')->with('success', 'Table QR code for "'.$table->table_name.'" was rotated to a new token version successfully.');
    }

    public function release(Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        try {
            $this->tableService->releaseTable($table);

            return redirect()->route('tables.index')->with('success', 'Table "'.$table->table_name.'" has been released and marked available.');
        } catch (ValidationException $e) {
            return redirect()->route('tables.index')->withErrors($e->validator);
        } catch (\Throwable $e) {
            return redirect()->route('tables.index')->with('error', $e->getMessage());
        }
    }

    public function regenerateToken(Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        return $this->rotateQr($table);
    }
}
