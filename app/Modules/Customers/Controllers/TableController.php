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
        $allStoreTables = Table::where('is_active', true)->orderBy('table_name')->get();

        return view('tables.index', compact('tables', 'allStoreTables'));
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

    /**
     * Pindah meja aktif (TASK-P1-01).
     */
    public function move(Request $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $validated = $request->validate([
            'target_table_id' => 'required|integer',
        ]);

        $tenantId = (int) app(\App\Services\Context\TenantContext::class)->requireTenantId();
        $storeId = (int) (app(\App\Services\Context\TenantContext::class)->getStoreId() ?? $table->store_id);
        $userId = (int) auth()->id();

        try {
            $result = app(\App\Modules\FNB\Services\TableManagementService::class)->moveTable(
                tenantId: $tenantId,
                storeId: $storeId,
                sourceTableId: (int) $table->id,
                targetTableId: (int) $validated['target_table_id'],
                userId: $userId
            );

            return redirect()->route('tables.index')
                ->with('success', "Meja {$table->table_name} berhasil dipindahkan ke meja {$result['target_table']->table_name}. ({$result['moved_orders_count']} pesanan dipindahkan)");
        } catch (\Throwable $e) {
            return redirect()->route('tables.index')->with('error', $e->getMessage());
        }
    }

    /**
     * Gabung meja (TASK-P1-01).
     */
    public function merge(Request $request, Table $table): RedirectResponse
    {
        $this->authorize('update', $table);

        $validated = $request->validate([
            'secondary_table_ids' => 'required|array|min:1',
            'secondary_table_ids.*' => 'integer',
        ]);

        $tenantId = (int) app(\App\Services\Context\TenantContext::class)->requireTenantId();
        $storeId = (int) (app(\App\Services\Context\TenantContext::class)->getStoreId() ?? $table->store_id);
        $userId = (int) auth()->id();

        try {
            $result = app(\App\Modules\FNB\Services\TableManagementService::class)->mergeTables(
                tenantId: $tenantId,
                storeId: $storeId,
                primaryTableId: (int) $table->id,
                secondaryTableIds: array_map('intval', $validated['secondary_table_ids']),
                userId: $userId
            );

            return redirect()->route('tables.index')
                ->with('success', "Meja {$table->table_name} berhasil digabungkan dengan ".count($result['merged_table_ids'])." meja lainnya ({$result['orders_consolidated']} pesanan dikonsolidasikan).");
        } catch (\Throwable $e) {
            return redirect()->route('tables.index')->with('error', $e->getMessage());
        }
    }
}
