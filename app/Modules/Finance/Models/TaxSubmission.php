<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Models\Concerns\AssignsTenant;
use App\Models\Concerns\ScopedToTenant;
use App\Models\Tenant;
use App\Modules\Core\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int|null $company_id
 * @property string $provider_name
 * @property string $idempotency_key
 * @property string $submission_reference
 * @property int|null $invoice_id
 * @property string $invoice_number
 * @property string|null $tax_invoice_serial_number
 * @property string $status
 * @property string $raw_payload_hash
 * @property array<string, mixed>|null $request_payload_masked
 * @property array<string, mixed>|null $response_payload_masked
 * @property int $retry_count
 * @property string|null $last_error
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class TaxSubmission extends Model
{
    use AssignsTenant;
    use ScopedToTenant;

    protected $table = 'tax_submissions';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'provider_name',
        'idempotency_key',
        'submission_reference',
        'invoice_id',
        'invoice_number',
        'tax_invoice_serial_number',
        'status',
        'raw_payload_hash',
        'request_payload_masked',
        'response_payload_masked',
        'retry_count',
        'last_error',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload_masked' => 'array',
            'response_payload_masked' => 'array',
            'retry_count' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
