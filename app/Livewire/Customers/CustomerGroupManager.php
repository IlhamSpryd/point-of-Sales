<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Livewire\Concerns\RequiresTenantContext;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PriceList;
use App\Modules\Settings\Services\AuditTrailService;
use App\Services\Context\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerGroupManager extends Component
{
    use RequiresTenantContext;
    use WithPagination;

    #[Url(history: true)]
    public string $search = '';

    public bool $showModal = false;

    public bool $isEditing = false;

    #[Locked]
    public ?int $groupId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public ?int $default_price_list_id = null;

    public string $default_discount_rate = '0.00';

    public bool $is_b2b_default = false;

    public bool $is_active = true;

    protected function rules(): array
    {
        return [
            'code' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'default_price_list_id' => 'nullable|integer',
            'default_discount_rate' => 'required|numeric|min:0|max:100',
            'is_b2b_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        Gate::authorize('manageGroups', Customer::class);

        $this->resetForm();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('manageGroups', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $group = CustomerGroup::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $this->groupId = $group->id;
        $this->code = (string) $group->code;
        $this->name = (string) $group->name;
        $this->description = (string) ($group->description ?? '');
        $this->default_price_list_id = $group->default_price_list_id ? (int) $group->default_price_list_id : null;
        $this->default_discount_rate = (string) $group->default_discount_rate;
        $this->is_b2b_default = (bool) $group->is_b2b_default;
        $this->is_active = (bool) $group->is_active;

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save(AuditTrailService $auditTrailService): void
    {
        Gate::authorize('manageGroups', Customer::class);

        $this->validate();

        $tenantId = app(TenantContext::class)->requireTenantId();
        $code = strtoupper(trim($this->code));

        try {
            if ($this->isEditing && $this->groupId) {
                $group = CustomerGroup::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->findOrFail($this->groupId);

                $codeExists = CustomerGroup::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->where('id', '!=', $group->id)
                    ->exists();

                if ($codeExists) {
                    throw ValidationException::withMessages([
                        'code' => ["Kode grup '{$code}' sudah digunakan pada tenant ini."],
                    ]);
                }

                $oldValues = $group->toArray();

                $group->update([
                    'code' => $code,
                    'name' => trim($this->name),
                    'description' => $this->description ?: null,
                    'default_price_list_id' => $this->default_price_list_id,
                    'default_discount_rate' => $this->default_discount_rate,
                    'is_b2b_default' => $this->is_b2b_default,
                    'is_active' => $this->is_active,
                ]);

                $auditTrailService->log(
                    tenantId: $tenantId,
                    action: 'customer_group.update',
                    userId: auth()->id(),
                    subjectType: CustomerGroup::class,
                    subjectId: $group->id,
                    description: "Memperbarui grup pelanggan: {$group->name} ({$group->code})",
                    oldValues: $oldValues,
                    newValues: $group->toArray()
                );

                session()->flash('message', "Grup pelanggan '{$group->name}' berhasil diperbarui.");
            } else {
                $codeExists = CustomerGroup::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->exists();

                if ($codeExists) {
                    throw ValidationException::withMessages([
                        'code' => ["Kode grup '{$code}' sudah digunakan pada tenant ini."],
                    ]);
                }

                $group = CustomerGroup::create([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'name' => trim($this->name),
                    'description' => $this->description ?: null,
                    'default_price_list_id' => $this->default_price_list_id,
                    'default_discount_rate' => $this->default_discount_rate,
                    'is_b2b_default' => $this->is_b2b_default,
                    'is_active' => $this->is_active,
                ]);

                $auditTrailService->log(
                    tenantId: $tenantId,
                    action: 'customer_group.create',
                    userId: auth()->id(),
                    subjectType: CustomerGroup::class,
                    subjectId: $group->id,
                    description: "Membuat grup pelanggan baru: {$group->name} ({$group->code})",
                    newValues: $group->toArray()
                );

                session()->flash('message', "Grup pelanggan '{$group->name}' berhasil dibuat.");
            }

            $this->showModal = false;
            $this->resetForm();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function delete(int $id, AuditTrailService $auditTrailService): void
    {
        Gate::authorize('manageGroups', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();
        $group = CustomerGroup::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $hasAssignedCustomers = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_group_id', $group->id)
            ->exists();

        if ($hasAssignedCustomers) {
            session()->flash('error', "Grup '{$group->name}' tidak dapat dihapus karena masih digunakan oleh pelanggan aktif.");

            return;
        }

        try {
            $group->delete();

            $auditTrailService->log(
                tenantId: $tenantId,
                action: 'customer_group.delete',
                userId: auth()->id(),
                subjectType: CustomerGroup::class,
                subjectId: $group->id,
                description: "Menghapus grup pelanggan: {$group->name} ({$group->code})"
            );

            session()->flash('message', "Grup pelanggan '{$group->name}' berhasil dihapus.");
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    private function resetForm(): void
    {
        $this->groupId = null;
        $this->code = '';
        $this->name = '';
        $this->description = '';
        $this->default_price_list_id = null;
        $this->default_discount_rate = '0.00';
        $this->is_b2b_default = false;
        $this->is_active = true;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        Gate::authorize('manageGroups', Customer::class);

        $tenantId = app(TenantContext::class)->requireTenantId();

        $query = CustomerGroup::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->withCount('customers');

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term);
            });
        }

        $groups = $query->orderBy('name')->paginate(15);

        $priceLists = PriceList::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.customers.customer-group-manager', [
            'groups' => $groups,
            'priceLists' => $priceLists,
        ]);
    }
}
