<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TaxClass: Master klasifikasi dan tarif persentase pajak produk per tenant.
 *
 * @property array<int, array<string, mixed>>|null $composite_rates
 */
class TaxClass extends Model
{
    use AssignsTenant;
    use HasFactory;
    use ScopedToTenant;

    protected $table = 'tax_classes';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'rate',
        'rate_percent',
        'is_inclusive',
        'composite_rates',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_inclusive' => 'boolean',
            'composite_rates' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getRatePercentAttribute(): ?string
    {
        return isset($this->attributes['rate']) ? (string) $this->attributes['rate'] : null;
    }

    public function setRatePercentAttribute(mixed $value): void
    {
        $this->attributes['rate'] = $value;
    }

    public function isComposite(): bool
    {
        return ! empty($this->composite_rates);
    }

    /**
     * Menghitung total tarif efektif (menjumlahkan tarif komposit bila ada).
     */
    public function effectiveRate(): string
    {
        if (! $this->isComposite()) {
            return (string) ($this->rate ?? '0.0000');
        }

        $total = '0.0000';
        $rates = $this->composite_rates;
        if (is_array($rates)) {
            foreach ($rates as $component) {
                $rate = (string) ($component['rate'] ?? $component['rate_percent'] ?? '0');
                if (is_numeric($rate)) {
                    /** @var numeric-string $total */
                    $total = bcadd($total, $rate, 4);
                }
            }
        }

        return $total;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'tax_class_id');
    }
}
