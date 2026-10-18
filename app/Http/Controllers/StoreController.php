<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreStoreRequest;
use App\Http\Requests\UpdateStoreRequest;
use App\Models\Store;
use App\Services\StoreService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function __construct(
        protected StoreService $storeService
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Store::class);

        $stores = $this->storeService
            ->getFilteredQuery($request)
            ->withCount(['devices', 'tables'])
            ->paginate(15)
            ->withQueryString();

        return view('stores.index', compact('stores'));
    }

    public function create(): View
    {
        $this->authorize('create', Store::class);

        return view('stores.create');
    }

    public function store(StoreStoreRequest $request): RedirectResponse
    {
        try {
            $store = $this->storeService->store($request->validated());

            return redirect()
                ->route('stores.index')
                ->with('success', "Cabang '{$store->name}' berhasil ditambahkan.");
        } catch (DomainException $e) {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    public function edit(Store $store): View
    {
        $this->authorize('update', $store);

        return view('stores.edit', compact('store'));
    }

    public function update(UpdateStoreRequest $request, Store $store): RedirectResponse
    {
        $this->storeService->update($store, $request->validated());

        return redirect()
            ->route('stores.index')
            ->with('success', "Informasi cabang '{$store->name}' berhasil diperbarui.");
    }

    public function destroy(Store $store): RedirectResponse
    {
        $this->authorize('delete', $store);

        try {
            $this->storeService->delete($store);

            return redirect()
                ->route('stores.index')
                ->with('success', "Cabang '{$store->name}' berhasil dihapus.");
        } catch (DomainException $e) {
            return redirect()
                ->route('stores.index')
                ->with('error', $e->getMessage());
        }
    }
}
