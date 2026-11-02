# 09 — Component Architecture Proposal (Blade + Livewire 4 + Alpine)

## 1. Keputusan teknologi

| Kebutuhan | Teknologi | Alasan |
|---|---|---|
| Visual primitives | **Blade anonymous components** (`components/ui`) | render murah, testable via `Blade::render()` (sudah terbukti di DevDojoComponentsIntegrationTest), tidak butuh state |
| State interaktif lokal | **Alpine.js** (sudah dibundel Livewire) | dropdown/modal/tabs — hindari network |
| State server + reaktivitas | **Livewire 4** full-page + concerns | sudah pola repo; manager besar |
| Animasi | motion tokens + Alpine transition + GSAP (ada) — dibatasi | konsisten & reduced-motion safe |
| **TIDAK** | React/SPA/komponen lib baru | dilarang brief; stack cukup |

## 2. Penamaan & struktur

```
resources/views/components/
├── ui/            # L1 primitive (shadcn-style, tanpa domain)
│   └── button/index.blade.php  →  <x-ui.button>
├── erp/           # L2 composed + L3 domain
│   ├── form-field.blade.php    →  <x-erp.form-field>
│   ├── searchable-select/
│   ├── data-table.blade.php    →  <x-erp.data-table>
│   └── pos/product-selector.blade.php
└── shells/        # L4 page scaffolding
    └── manager.blade.php
```

## 3. Livewire concerns (bukan paket tabel eksternal)

Manager tabel berbagi trait di `app/Livewire/Concerns/`:

```php
trait WithDataTable {
    public string $sort = '-created_at';
    public array $selected = [];
    public function sortBy(string $key): void {}      // toggle dir, reset page
    public function getRowsProperty(): LengthAwarePaginator {} // kontrak scope tenant WAJIB
}
```

- Isolasi tenant TETAP di query service/model scope — trait hanya UI-state; **tidak** menaruh otorisasi di frontend.

## 4. SearchableSelect — spesifikasi kunci (menggantikan 136 native select)

- Props: `wire:model`, `options` (array statis ≤100) ATAU `search-method` (Livewire method untuk lazy), `placeholder`, `clearable`, `multiple`.
- Perilaku: input filter → debounce 300ms → (lazy) `$wire.searchOptions()` → listbox; arrow/enter/ESC; aria-combobox pattern; x-trap tidak perlu (non-modal) tapi roving focus ya.
- Null-state: tampilkan "Ketik untuk mencari…" bukan select kosong.

## 5. Confirm dialog tunggal (menggantikan 3 sistem)

```blade
<x-erp.confirm-dialog />  {{-- sekali di layout --}}
{{-- pemakaian: --}}
<button x-on:click="$dispatch('erp-confirm', {title, message, confirmText,
        variant:'destructive', onConfirm: () => $wire.delete({{ $id }})})">
{{-- mode form klasik (menggantikan confirmDelete swal): --}}
<x-erp.confirm-dialog-submit form-id="delete-{{ $id }}" item-name="{{ $name }}" />
```

Migration path: tulis `confirmSubmit()` di confirm-dialog → sweep 9 file pemakai confirmDelete → hapus partial swal → swal-bridge tetap dipakai internal oleh kode JS lama sampai sweep selesai, lalu dihapus.

## 6. Manager shell (L4) — mengurangi boilerplate 30 manager

```blade
<x-erp.shell :title="$title" :breadcrumb="... ">
  <x-slot:actions> ... </x-slot:actions>
  <x-slot:filters> <x-erp.filter-bar ... /> </x-slot:filters>
  <x-erp.data-table :columns="..." :rows="..." />
</x-erp.shell>
```

## 7. Aturan mutlak komponen

1. Tidak ada query DB / kalkulasi uang di komponen (Iron Law repo).
2. Tidak ada `dd()/dump()` (Rule D).
3. Semua komponen baru wajib punya test render minimal (pola existing).
4. A11y bawaan komponen — pemakai tidak perlu menulis aria manual.
5. Setiap variant baru harus dipetakan ke token, bukan hex.
