<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Core\Support\EmailNormalizer;
use App\Enums\SupplierStatusEnum;
use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Context\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $name
 * @property string $normalized_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $tax_id
 * @property string|null $address
 * @property int $payment_terms_days
 * @property string $currency_code
 * @property SupplierStatusEnum $status
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property-read SupplierContact|null $primaryContact
 * @property-read Collection<int, SupplierContact> $contacts
 * @property-read Collection<int, SupplierProduct> $supplierProducts
 */
class Supplier extends Model
{
    use AssignsTenant;
    use ScopedToTenant;
    use SoftDeletes;

    protected $table = 'suppliers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SupplierStatusEnum::class,
            'payment_terms_days' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $supplier): void {
            if (! empty($supplier->name) && empty($supplier->normalized_name)) {
                $supplier->normalized_name = mb_strtolower(trim($supplier->name));
            }

            if (! empty($supplier->email)) {
                $supplier->email = EmailNormalizer::normalize($supplier->email);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * @return HasMany<SupplierContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class, 'supplier_id');
    }

    /**
     * @return HasOne<SupplierContact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(SupplierContact::class, 'supplier_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<SupplierProduct, $this>
     */
    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class, 'supplier_id');
    }

    public function getStatusEnum(): SupplierStatusEnum
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->getStatusEnum() === SupplierStatusEnum::Active;
    }

    public function canAcceptPurchaseOrder(): bool
    {
        return $this->getStatusEnum() === SupplierStatusEnum::Active;
    }

    public function canProcessFulfillment(): bool
    {
        return in_array($this->getStatusEnum(), [SupplierStatusEnum::Active, SupplierStatusEnum::OnHold], true);
    }

    /**
     * Route binding di-scope ke tenant pada konteks aktif (404 bila lintas tenant).
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return static::query()->where($field ?? $this->getRouteKeyName(), $value)->first();
        }

        if ($context->hasTenant()) {
            return static::query()
                ->where('tenant_id', $context->requireTenantId())
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->first();
        }

        return null;
    }
}
