<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Exceptions\UnresolvableAccountMappingException;
use App\Modules\Finance\Models\ChartOfAccount;
use App\Modules\Finance\Models\PostingRuleLine;
use Illuminate\Support\Facades\DB;

class AccountMappingResolverService
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * Menyelesaikan PostingRuleLine menjadi ID akun ChartOfAccount konkret.
     *
     * @param  array<string, mixed>  $payload
     */
    public function resolveAccountId(PostingRuleLine $line, int $tenantId, array $payload): int
    {
        // 1. Jika sudah ada account_id terpasang pada baris aturan
        if ($line->account_id !== null) {
            $account = ChartOfAccount::withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->where('id', $line->account_id)
                ->where('is_active', true)
                ->first();

            if ($account && $account->is_postable) {
                return $account->id;
            }
        }

        // 2. Strategi DYNAMIC
        $strategy = $line->account_resolution_strategy;

        switch ($strategy) {
            case 'DYNAMIC_BY_PAYMENT_METHOD':
                // Ambil dari payload: payment_account_id, cash_bank_account_id, atau payment_method_type
                if (! empty($payload['cash_bank_account_id'])) {
                    $cba = DB::table('cash_bank_accounts')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $payload['cash_bank_account_id'])
                        ->first();
                    if ($cba && ! empty($cba->gl_account_id)) {
                        return (int) $cba->gl_account_id;
                    }
                }

                $methodType = $payload['payment_type'] ?? $payload['payment_method'] ?? 'cash';
                if ($methodType === 'cash') {
                    return $this->coaService->getAccountBySystemKey('CASH_DRAWER_CLEARING', $tenantId)->id;
                }

                return $this->coaService->getAccountBySystemKey('PG_CLEARING', $tenantId)->id;

            case 'DYNAMIC_BY_CASH_BANK':
                $cbaId = null;
                if ($line->isDebit() && ! empty($payload['destination_cash_bank_id'])) {
                    $cbaId = $payload['destination_cash_bank_id'];
                } elseif ($line->isCredit() && ! empty($payload['source_cash_bank_id'])) {
                    $cbaId = $payload['source_cash_bank_id'];
                } else {
                    $cbaId = $payload['cash_bank_account_id'] ?? $payload['destination_cash_bank_id'] ?? $payload['source_cash_bank_id'] ?? null;
                }

                if ($cbaId) {
                    $cba = DB::table('cash_bank_accounts')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $cbaId)
                        ->first();
                    if ($cba && ! empty($cba->gl_account_id)) {
                        return (int) $cba->gl_account_id;
                    }
                }
                break;

            case 'DYNAMIC_BY_TAX_CODE':
                $taxCodeId = $payload['tax_code_id'] ?? null;
                if ($taxCodeId && DB::getSchemaBuilder()->hasTable('tax_account_mappings')) {
                    $mapping = DB::table('tax_account_mappings')
                        ->where('tenant_id', $tenantId)
                        ->where('tax_code_id', $taxCodeId)
                        ->first();

                    if ($mapping) {
                        $targetCol = $line->isDebit() ? 'purchase_tax_account_id' : 'sales_tax_account_id';
                        if (! empty($mapping->{$targetCol})) {
                            return (int) $mapping->{$targetCol};
                        }
                    }
                }
                // Fallback default tax key
                $fallbackTaxKey = $line->isDebit() ? 'TAX_INPUT_PPN' : 'TAX_OUTPUT_PPN';
                try {
                    return $this->coaService->getAccountBySystemKey($fallbackTaxKey, $tenantId)->id;
                } catch (\Exception) {
                    // Coba PB1 jika F&B
                    if (! $line->isDebit()) {
                        return $this->coaService->getAccountBySystemKey('TAX_OUTPUT_PB1', $tenantId)->id;
                    }
                }
                break;

            case 'DYNAMIC_BY_EXPENSE_CATEGORY':
                $catId = $payload['expense_category_id'] ?? null;
                if ($catId && DB::getSchemaBuilder()->hasTable('expense_categories')) {
                    $cat = DB::table('expense_categories')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $catId)
                        ->first();
                    if ($cat && ! empty($cat->expense_gl_account_id)) {
                        return (int) $cat->expense_gl_account_id;
                    }
                }
                break;

            case 'DYNAMIC_BY_ASSET_CATEGORY':
                $assetCatId = $payload['fixed_asset_category_id'] ?? null;
                if ($assetCatId && DB::getSchemaBuilder()->hasTable('fixed_asset_categories')) {
                    $fac = DB::table('fixed_asset_categories')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $assetCatId)
                        ->first();
                    if ($fac) {
                        if (in_array($line->amount_source_key, ['accumulated_depreciation', 'depreciation_amount'], true)
                            || $line->system_account_key === 'ACCUM_DEPR_EQUIPMENT') {
                            if (! empty($fac->accum_depr_gl_account_id)) {
                                return (int) $fac->accum_depr_gl_account_id;
                            }
                        }

                        if ($line->amount_source_key === 'acquisition_cost'
                            || $line->system_account_key === 'FIXED_ASSET_EQUIPMENT') {
                            if (! empty($fac->asset_gl_account_id)) {
                                return (int) $fac->asset_gl_account_id;
                            }
                        }

                        if ($line->isDebit() && ! empty($fac->asset_gl_account_id)) {
                            return (int) $fac->asset_gl_account_id;
                        }
                        if ($line->isCredit() && ! empty($fac->accum_depr_gl_account_id)) {
                            return (int) $fac->accum_depr_gl_account_id;
                        }
                    }
                }
                break;
        }

        // 3. Fallback ke system_account_key
        if (! empty($line->system_account_key)) {
            return $this->coaService->getAccountBySystemKey($line->system_account_key, $tenantId)->id;
        }

        throw UnresolvableAccountMappingException::forDynamicStrategy($strategy, $line->amount_source_key);
    }
}
