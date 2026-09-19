<?php

namespace App\Services;

use App\Models\Table;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TableService mengisolasi seluruh logika CRUD Meja dari Controller,
 * mengikuti pola Service Pattern yang sama seperti CategoryService/RoleService.
 */
class TableService
{
    public function getFilteredQuery(Request $request): Builder
    {
        $query = Table::query()->latest();
        if ($request->has('search') && $request->search) {
            $query->where('table_name', 'like', '%'.$request->search.'%');
        }

        return $query;
    }

    /**
     * Membuat meja baru. secure_token TIDAK perlu diisi di sini —
     * otomatis ter-generate oleh model event Table::booted().
     */
    public function store(array $data): Table
    {
        return DB::transaction(function () use ($data) {
            return Table::create($data);
        });
    }

    public function update(Table $table, array $data): Table
    {
        return DB::transaction(function () use ($table, $data) {
            $table->update($data);

            return $table;
        });
    }

    /**
     * Menghapus meja. Ditolak jika meja masih memiliki riwayat pesanan,
     * demi menjaga integritas audit trail (sama seperti aturan Category/Role).
     */
    public function delete(Table $table): bool
    {
        return DB::transaction(function () use ($table) {
            if ($table->orders()->exists()) {
                throw new Exception('Tidak dapat menghapus meja "'.$table->table_name.'" karena masih memiliki riwayat pesanan.');
            }

            return $table->delete();
        });
    }
}
