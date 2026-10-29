<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\PostingRule;
use App\Modules\Finance\Models\PostingRuleVersion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;

class PostingRuleResolverService
{
    /**
     * Menyelesaikan versi aturan posting aktif untuk tenant, event_code, dan tanggal transaksi.
     */
    public function resolveActiveRuleVersion(int $tenantId, string $eventCode, string|CarbonInterface $date): PostingRuleVersion
    {
        $dateStr = $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();

        $rule = PostingRule::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('event_code', $eventCode)
            ->where('is_active', true)
            ->where('approval_status', 'APPROVED')
            ->first();

        if (! $rule) {
            throw new DomainException("FIN-M7-07: Tidak ditemukan aturan posting aktif dan disetujui untuk event '{$eventCode}' pada tenant #{$tenantId}.");
        }

        $version = PostingRuleVersion::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('posting_rule_id', $rule->id)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($q) use ($dateStr) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $dateStr);
            })
            ->with(['lines' => fn ($q) => $q->orderBy('line_sequence')])
            ->latest('version_number')
            ->first();

        if (! $version) {
            throw new DomainException("FIN-M7-07: Tidak ditemukan versi aktif aturan posting '{$eventCode}' untuk tanggal {$dateStr}.");
        }

        return $version;
    }
}
