<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\User;
use App\Modules\Customers\Enums\CreditStatusEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CustomerCreditAccount
 *
 * Mengelola batas kredit (credit limit), termin pembayaran (payment terms),
 * grace period, status kredit, dan eksposur berjalan (reserved vs invoiced).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property string $credit_limit
 * @property int $payment_terms_days
 * @property int $grace_period_days
 * @property CreditStatusEnum $credit_status
 * @property string $risk_rating
 * @property string $reserved_exposure
 * @property string $invoiced_exposure
 * @property string $unapplied_credit_balance
 * @property Carbon|null $last_reconciled_at
 * @property int|null $updated_by_user_id
 */
class CustomerCreditAccount extends Model
{
    use AssignsTenant, ScopedToTenant;

    protected $table = 'customer_credit_accounts';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'credit_limit',
        'payment_terms_days',
        'grace_period_days',
        'credit_status',
        'risk_rating',
        'reserved_exposure',
        'invoiced_exposure',
        'unapplied_credit_balance',
        'last_reconciled_at',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'grace_period_days' => 'integer',
            'credit_status' => CreditStatusEnum::class,
            'reserved_exposure' => 'decimal:2',
            'invoiced_exposure' => 'decimal:2',
            'unapplied_credit_balance' => 'decimal:2',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CustomerCreditLog::class, 'customer_id', 'customer_id');
    }

    /**
     * Hitung total eksposur kredit saat ini menggunakan BCMath.
     * Total Eksposur = reserved_exposure + invoiced_exposure - unapplied_credit_balance.
     */
    public function getTotalExposure(): string
    {
        $gross = bcadd((string) $this->reserved_exposure, (string) $this->invoiced_exposure, 2);
        $net = bcsub($gross, (string) $this->unapplied_credit_balance, 2);

        return bccomp($net, '0.00', 2) > 0 ? $net : '0.00';
    }

    /**
     * Hitung sisa plafon kredit yang masih tersedia.
     * Sisa Kredit = credit_limit - total_exposure.
     */
    public function getAvailableCredit(): string
    {
        $avail = bcsub((string) $this->credit_limit, $this->getTotalExposure(), 2);

        return bccomp($avail, '0.00', 2) > 0 ? $avail : '0.00';
    }
}
