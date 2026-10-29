<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Modules\Finance\Models\PostingRule;
use App\Modules\Finance\Models\PostingRuleLine;
use App\Modules\Finance\Models\PostingRuleVersion;
use Illuminate\Database\Seeder;

class DefaultPostingRulesSeeder extends Seeder
{
    public function run(?int $targetTenantId = null): void
    {
        $tenants = $targetTenantId
            ? Tenant::where('id', $targetTenantId)->get()
            : Tenant::all();

        foreach ($tenants as $tenant) {
            $this->seedForTenant($tenant->id);
        }
    }

    public function seedForTenant(int $tenantId): void
    {
        // 1. pos.order.paid
        $posRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'pos.order.paid'],
            [
                'rule_name' => 'Penjualan Kasir POS Standar',
                'source_domain' => 'POS',
                'description' => 'Posting otomatis penerimaan kas, non-tunai, pendapatan penjualan, PPN, dan HPP',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $posVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $posRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        // Lines for pos.order.paid
        $posLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'cash_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Kas Kasir POS',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'pg_clearing_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PG_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Kliring Pembayaran Non-Tunai POS',
            ],
            [
                'line_sequence' => 3,
                'side' => 'DEBIT',
                'amount_source_key' => 'discount_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_DISCOUNT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Diskon Penjualan POS',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'subtotal',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_RETAIL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pendapatan Penjualan POS',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'tax_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_OUTPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'PPN Keluaran Kasir POS',
            ],
            [
                'line_sequence' => 6,
                'side' => 'DEBIT',
                'amount_source_key' => 'cogs_retail',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'COGS_RETAIL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Pokok Penjualan Retail POS',
            ],
            [
                'line_sequence' => 7,
                'side' => 'CREDIT',
                'amount_source_key' => 'cogs_retail',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Persediaan Retail POS',
            ],
            [
                'line_sequence' => 8,
                'side' => 'DEBIT',
                'amount_source_key' => 'cogs_food',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'COGS_FNB',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Pokok Penjualan F&B POS',
            ],
            [
                'line_sequence' => 9,
                'side' => 'CREDIT',
                'amount_source_key' => 'cogs_food',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Konsumsi Bahan Baku F&B POS',
            ],
        ];

        foreach ($posLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $posVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 2. sales.return.refunded
        $retRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'sales.return.refunded'],
            [
                'rule_name' => 'Retur & Refund Penjualan Kasir POS',
                'source_domain' => 'SALES',
                'description' => 'Posting jurnal pembatalan pendapatan, pengembalian kas, dan perlakuan restock vs waste',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $retVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $retRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $retLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'subtotal_returned',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_RETURN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Retur Penjualan POS',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'tax_returned',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_OUTPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pembatalan PPN Keluaran Retur',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'total_refund_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengeluaran Kas Refund Pelanggan',
            ],
            [
                'line_sequence' => 4,
                'side' => 'DEBIT',
                'amount_source_key' => 'restock_cogs',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Restock Persediaan Retail dari Retur',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'restock_cogs',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'COGS_RETAIL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Koreksi HPP Retail atas Restock Retur',
            ],
            [
                'line_sequence' => 6,
                'side' => 'DEBIT',
                'amount_source_key' => 'waste_cogs',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_WASTE_EXPENSE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Kerugian/Waste Barang Retur Rusak/F&B',
            ],
            [
                'line_sequence' => 7,
                'side' => 'CREDIT',
                'amount_source_key' => 'waste_cogs',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'COGS_FNB',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengalihan HPP F&B ke Beban Waste',
            ],
        ];

        foreach ($retLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $retVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 3. pos.shift.closed
        $shiftRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'pos.shift.closed'],
            [
                'rule_name' => 'Penutupan Shift & Selisih Kas Kasir POS',
                'source_domain' => 'POS',
                'description' => 'Posting jurnal otomatis selisih kas fisik laci kasir vs sistem (kelebihan kas / kekurangan kas)',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $shiftVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $shiftRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $shiftLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'overage_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Selisih Lebih Kas Kasir',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'overage_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_OVERAGE_INCOME',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pendapatan Selisih Lebih Kas Kasir',
            ],
            [
                'line_sequence' => 3,
                'side' => 'DEBIT',
                'amount_source_key' => 'shortage_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_SHORTAGE_EXPENSE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Selisih Kurang Kas Kasir',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'shortage_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Kas atas Selisih Kurang Kasir',
            ],
        ];

        foreach ($shiftLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $shiftVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 4. purchasing.grn.posted (PR-PUR-01)
        $grnPostedRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'purchasing.grn.posted'],
            [
                'rule_name' => 'Penerimaan Barang PO (GRN)',
                'source_domain' => 'PURCHASING',
                'description' => 'Posting jurnal otomatis penerimaan barang masuk persediaan dan akrual GR/IR clearing',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $grnPostedVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $grnPostedRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $grnPostedLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'retail_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Persediaan Produk Retail Masuk (GRN)',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'ingredient_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Persediaan Bahan Baku F&B Masuk (GRN)',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'gr_ir_clearing_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'GR_IR_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Akrual Penerimaan Barang Belum Ditagih (GR/IR Clearing)',
            ],
        ];

        foreach ($grnPostedLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $grnPostedVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 5. purchasing.grn.reversed (PR-PUR-02)
        $grnReversedRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'purchasing.grn.reversed'],
            [
                'rule_name' => 'Pembalikan Penerimaan Barang PO (GRN Reversal)',
                'source_domain' => 'PURCHASING',
                'description' => 'Posting jurnal otomatis pembatalan/pembalik GRN terhadap GR/IR clearing dan persediaan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $grnReversedVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $grnReversedRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $grnReversedLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'gr_ir_clearing_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'GR_IR_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pembalikan Akrual GR/IR Clearing',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'retail_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pembalikan Persediaan Produk Retail',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'ingredient_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pembalikan Persediaan Bahan Baku F&B',
            ],
        ];

        foreach ($grnReversedLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $grnReversedVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 6. inventory.adjustment.posted (PR-INV-01)
        $adjRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'inventory.adjustment.posted'],
            [
                'rule_name' => 'Penyesuaian Stok Persediaan',
                'source_domain' => 'INVENTORY',
                'description' => 'Posting jurnal otomatis penyesuaian stok persediaan (stok bertambah atau berkurang)',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $adjVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $adjRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $adjLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'positive_retail_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penambahan Persediaan Produk Retail',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'positive_ingredient_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penambahan Persediaan Bahan Baku F&B',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'positive_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_WASTE_EXPENSE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Koreksi Keuntungan/Penyesuaian Persediaan Masuk',
            ],
            [
                'line_sequence' => 4,
                'side' => 'DEBIT',
                'amount_source_key' => 'negative_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_WASTE_EXPENSE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Kerugian/Waste/Selisih Pengurangan Persediaan',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'negative_retail_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Persediaan Produk Retail',
            ],
            [
                'line_sequence' => 6,
                'side' => 'CREDIT',
                'amount_source_key' => 'negative_ingredient_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Persediaan Bahan Baku F&B',
            ],
        ];

        foreach ($adjLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $adjVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 7. inventory.transfer.shipped (PR-INV-02)
        $shipRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'inventory.transfer.shipped'],
            [
                'rule_name' => 'Pengiriman Transfer Stok Antar-Cabang',
                'source_domain' => 'INVENTORY',
                'description' => 'Posting jurnal otomatis pengiriman stok dari cabang asal ke akun barang dalam perjalanan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $shipVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $shipRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $shipLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'transfer_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_IN_TRANSIT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Persediaan Dalam Perjalanan (In-Transit)',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'retail_transfer_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengeluaran Persediaan Retail Cabang Asal',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'ingredient_transfer_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengeluaran Persediaan Bahan Baku Cabang Asal',
            ],
        ];

        foreach ($shipLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $shipVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 8. inventory.transfer.received (PR-INV-02)
        $recRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'inventory.transfer.received'],
            [
                'rule_name' => 'Penerimaan Transfer Stok Antar-Cabang',
                'source_domain' => 'INVENTORY',
                'description' => 'Posting jurnal otomatis penerimaan stok di cabang tujuan dan penutupan akun in-transit',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $recVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $recRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $recLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'retail_received_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Persediaan Retail Cabang Tujuan',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'ingredient_received_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_FNB_INGREDIENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Persediaan Bahan Baku Cabang Tujuan',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'received_value',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_IN_TRANSIT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penyelesaian Persediaan Dalam Perjalanan (In-Transit)',
            ],
        ];

        foreach ($recLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $recVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 9. purchasing.invoice.approved_payable (PR-PUR-03)
        $invRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'purchasing.invoice.approved_payable'],
            [
                'rule_name' => 'Faktur Pemasok Disetujui Siap-Bayar',
                'source_domain' => 'PURCHASING',
                'description' => 'Posting jurnal pengakuan hutang dagang, penutupan GR/IR clearing, selisih harga, dan PPN masukan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $invVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $invRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $invLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'grn_clearing_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'GR_IR_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penyelesaian Akrual Penerimaan Barang (GR/IR)',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'direct_inventory_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengakuan Langsung Persediaan Faktur',
            ],
            [
                'line_sequence' => 3,
                'side' => 'DEBIT',
                'amount_source_key' => 'ppv_debit_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PURCHASE_PRICE_VARIANCE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Selisih Harga Beli Faktur Lebih Tinggi (PPV)',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'ppv_credit_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PURCHASE_PRICE_VARIANCE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Selisih Harga Beli Faktur Lebih Rendah (PPV)',
            ],
            [
                'line_sequence' => 5,
                'side' => 'DEBIT',
                'amount_source_key' => 'tax_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_INPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'PPN Masukan Faktur Pembelian',
            ],
            [
                'line_sequence' => 6,
                'side' => 'CREDIT',
                'amount_source_key' => 'discount_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PURCHASE_DISCOUNT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Diskon Pembelian Faktur Pemasok',
            ],
            [
                'line_sequence' => 7,
                'side' => 'CREDIT',
                'amount_source_key' => 'total_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AP_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Kewajiban Hutang Usaha Pemasok (AP)',
            ],
        ];

        foreach ($invLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $invVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 10. purchasing.debit_note.applied (PR-PUR-05)
        $dnRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'purchasing.debit_note.applied'],
            [
                'rule_name' => 'Aplikasi Nota Debit Pembelian ke Faktur',
                'source_domain' => 'PURCHASING',
                'description' => 'Posting pengurangan hutang usaha atas aplikasi nota debit pembelian',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $dnVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $dnRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $dnLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'applied_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AP_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Hutang Usaha atas Nota Debit',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'grn_reversal_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'GR_IR_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Akrual GR/IR Retur Pembelian',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'inventory_reversal_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INVENTORY_RETAIL_PRODUCT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Nilai Persediaan atas Retur Pembelian',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'tax_correction_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_INPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Koreksi PPN Masukan Retur Pembelian',
            ],
        ];

        foreach ($dnLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $dnVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 11. ap.payment.posted (PR-AP-01)
        $apPayRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'ap.payment.posted'],
            [
                'rule_name' => 'Pembayaran Hutang Usaha Pemasok',
                'source_domain' => 'FINANCE',
                'description' => 'Posting pelunasan hutang usaha, pengeluaran kas/bank, potongan diskon, dan biaya admin',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $apPayVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $apPayRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $apPayLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'allocated_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AP_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pelunasan Hutang Usaha Pemasok',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'unapplied_prepayment_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SUPPLIER_PREPAYMENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Uang Muka / Kelebihan Bayar ke Pemasok',
            ],
            [
                'line_sequence' => 3,
                'side' => 'DEBIT',
                'amount_source_key' => 'bank_fee_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'BANK_ADMIN_FEE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Administrasi Bank Pembayaran Supplier',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'early_discount_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PURCHASE_DISCOUNT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Potongan Pelunasan Dini Supplier',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'total_payment_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_PAYMENT_METHOD',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengeluaran Dana Kas / Bank',
            ],
        ];

        foreach ($apPayLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $apPayVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 12. ar.invoice.posted (PR-AR-01)
        $arInvRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'ar.invoice.posted'],
            [
                'rule_name' => 'Faktur Penjualan Piutang B2B',
                'source_domain' => 'SALES',
                'description' => 'Pengakuan piutang usaha pelanggan, pendapatan penjualan B2B, PPN keluaran, ongkir, dan diskon',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $arInvVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $arInvRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $arInvLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'total_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AR_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Piutang Usaha Pelanggan',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'discount_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_DISCOUNT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Diskon Penjualan Faktur',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'subtotal_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_REVENUE_B2B',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pendapatan Penjualan B2B',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'shipping_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SHIPPING_REVENUE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pendapatan Ongkos Kirim',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'tax_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_OUTPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'PPN Keluaran Faktur Penjualan',
            ],
        ];

        foreach ($arInvLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $arInvVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 13. ar.payment.received_and_allocated (PR-AR-03)
        $arPayRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'ar.payment.received_and_allocated'],
            [
                'rule_name' => 'Penerimaan Pembayaran Piutang Pelanggan',
                'source_domain' => 'FINANCE',
                'description' => 'Posting penerimaan kas/bank, pelunasan piutang, dan kelebihan bayar pelanggan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $arPayVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $arPayRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $arPayLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'total_received_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_PAYMENT_METHOD',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Dana Kas / Bank',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'bank_charge_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'BANK_ADMIN_FEE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Biaya Administrasi Bank Penerimaan',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'allocated_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AR_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pelunasan Piutang Usaha Pelanggan',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'unapplied_credit_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'CUSTOMER_UNAPPLIED_CREDIT_LIABILITY',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Kelebihan Bayar Pelanggan (Unapplied Credit)',
            ],
        ];

        foreach ($arPayLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $arPayVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 14. ar.credit_note.applied (PR-AR-04)
        $arCnRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'ar.credit_note.applied'],
            [
                'rule_name' => 'Aplikasi Nota Kredit Penjualan',
                'source_domain' => 'SALES',
                'description' => 'Posting aplikasi nota kredit ke faktur piutang pelanggan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $arCnVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $arCnRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $arCnLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'subtotal_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'SALES_RETURN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Retur Penjualan Nota Kredit',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'tax_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'TAX_OUTPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Koreksi PPN Keluaran Nota Kredit',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'applied_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'AR_CONTROL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Piutang Pelanggan',
            ],
        ];

        foreach ($arCnLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $arCnVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 15. cash_bank.transfer.posted
        $cbTransferRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'cash_bank.transfer.posted'],
            [
                'rule_name' => 'Transfer Internal Kas & Bank',
                'source_domain' => 'FINANCE',
                'description' => 'Posting perpindahan dana antar akun kas/bank secara atomik',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $cbTransferVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $cbTransferRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $cbTransferLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Transfer Internal',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengeluaran Transfer Internal',
            ],
        ];

        foreach ($cbTransferLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $cbTransferVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 16. cash_bank.pg_settlement.posted
        $cbSettlementRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'cash_bank.pg_settlement.posted'],
            [
                'rule_name' => 'Settlement Cair Payment Gateway / QRIS',
                'source_domain' => 'FINANCE',
                'description' => 'Pencairan dana kliring payment gateway ke rekening bank dikurangi MDR fee',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $cbSettlementVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $cbSettlementRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $cbSettlementLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'net_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Bersih Settlement PG ke Bank',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'mdr_fee_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'PAYMENT_GATEWAY_FEE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Potongan MDR Merchant Gateway',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'gross_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'PG_CLEARING',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Saldo Kliring Payment Gateway',
            ],
        ];

        foreach ($cbSettlementLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $cbSettlementVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 17. cash_bank.bank_fee.posted
        $cbFeeRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'cash_bank.bank_fee.posted'],
            [
                'rule_name' => 'Biaya Administrasi Bank',
                'source_domain' => 'FINANCE',
                'description' => 'Pencatatan beban admin bank dari penyesuaian rekonsiliasi',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $cbFeeVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $cbFeeRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $cbFeeLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'BANK_ADMIN_FEE',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Administrasi Bank',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pengurangan Saldo Bank atas Beban Admin',
            ],
        ];

        foreach ($cbFeeLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $cbFeeVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 18. cash_bank.bank_interest.posted
        $cbInterestRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'cash_bank.bank_interest.posted'],
            [
                'rule_name' => 'Pendapatan Bunga Bank',
                'source_domain' => 'FINANCE',
                'description' => 'Pencatatan pendapatan jasa giro / bunga bank dari penyesuaian rekonsiliasi',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $cbInterestVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $cbInterestRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $cbInterestLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penambahan Saldo Bank atas Pendapatan Bunga',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'INTEREST_INCOME',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Pendapatan Bunga Bank / Jasa Giro',
            ],
        ];

        foreach ($cbInterestLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $cbInterestVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 19. expense.approved (Akrual Beban Operasional)
        $expenseAppRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'expense.approved'],
            [
                'rule_name' => 'Akrual Beban Operasional',
                'source_domain' => 'FINANCE',
                'description' => 'Akrual beban saat disetujui: Debit Beban (DYNAMIC/GENERAL), Debit PPN Masukan (bila ada), Kredit Beban Akrual',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $expenseAppVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $expenseAppRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $expenseAppLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'subtotal_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_EXPENSE_CATEGORY',
                'system_account_key' => 'EXPENSE_GENERAL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Beban Operasional: {expense_number}',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'tax_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_TAX_CODE',
                'system_account_key' => 'TAX_INPUT_PPN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'PPN Masukan Beban: {expense_number}',
            ],
            [
                'line_sequence' => 3,
                'side' => 'CREDIT',
                'amount_source_key' => 'total_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'ACCRUED_EXPENSE',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Utang Beban Akrual: {expense_number}',
            ],
        ];

        foreach ($expenseAppLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $expenseAppVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 20. expense.paid (Pelunasan Pembayaran Beban)
        $expensePaidRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'expense.paid'],
            [
                'rule_name' => 'Pelunasan Pembayaran Beban Operasional',
                'source_domain' => 'FINANCE',
                'description' => 'Pelunasan beban: Debit Utang Beban Akrual, Kredit Kas / Bank',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $expensePaidVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $expensePaidRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $expensePaidLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'total_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'ACCRUED_EXPENSE',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Pelunasan Utang Beban: {expense_number}',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'total_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'CASH_MAIN',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Pengeluaran Kas/Bank Beban: {expense_number}',
            ],
        ];

        foreach ($expensePaidLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $expensePaidVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 21. fixed_asset.capitalized (Kapitalisasi Perolehan Aset Tetap)
        $faCapRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'fixed_asset.capitalized'],
            [
                'rule_name' => 'Kapitalisasi Perolehan Aset Tetap',
                'source_domain' => 'FINANCE',
                'description' => 'Kapitalisasi aset tetap: Debit Aset Tetap (DYNAMIC_BY_ASSET_CATEGORY), Kredit Kas / Bank',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $faCapVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $faCapRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $faCapLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'acquisition_cost',
                'account_resolution_strategy' => 'DYNAMIC_BY_ASSET_CATEGORY',
                'system_account_key' => 'FIXED_ASSET_EQUIPMENT',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Perolehan Aset Tetap: {asset_code}',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'acquisition_cost',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Pengeluaran Kas/Bank Perolehan Aset: {asset_code}',
            ],
        ];

        foreach ($faCapLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $faCapVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 22. fixed_asset.depreciation.posted (Penyusutan Bulanan Aset Tetap)
        $faDeprRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'fixed_asset.depreciation.posted'],
            [
                'rule_name' => 'Penyusutan Bulanan Aset Tetap',
                'source_domain' => 'FINANCE',
                'description' => 'Pencatatan beban penyusutan bulanan: Debit Beban Penyusutan, Kredit Akumulasi Penyusutan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $faDeprVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $faDeprRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $faDeprLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'depreciation_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'DEPRECIATION_EXPENSE',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Beban Penyusutan Aset Tetap: {asset_code}',
            ],
            [
                'line_sequence' => 2,
                'side' => 'CREDIT',
                'amount_source_key' => 'depreciation_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_ASSET_CATEGORY',
                'system_account_key' => 'ACCUM_DEPR_EQUIPMENT',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Akumulasi Penyusutan Aset Tetap: {asset_code}',
            ],
        ];

        foreach ($faDeprLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $faDeprVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }

        // 23. fixed_asset.disposed (Pelepasan / Penjualan Aset Tetap)
        $faDispRule = PostingRule::firstOrCreate(
            ['tenant_id' => $tenantId, 'event_code' => 'fixed_asset.disposed'],
            [
                'rule_name' => 'Pelepasan Aset Tetap (Disposal / Sale / Scrap)',
                'source_domain' => 'FINANCE',
                'description' => 'Pelepasan aset tetap: Debit Kas (jika ada penjualan), Debit Akumulasi Penyusutan, Kredit Nilai Perolehan Aset Tetap, Debit Rugi / Kredit Untung Pelepasan',
                'is_active' => true,
                'approval_status' => 'APPROVED',
            ]
        );

        $faDispVersion = PostingRuleVersion::firstOrCreate(
            ['tenant_id' => $tenantId, 'posting_rule_id' => $faDispRule->id, 'version_number' => 1],
            [
                'effective_from' => '2026-01-01',
                'status' => 'ACTIVE',
            ]
        );

        $faDispLines = [
            [
                'line_sequence' => 1,
                'side' => 'DEBIT',
                'amount_source_key' => 'proceeds_amount',
                'account_resolution_strategy' => 'DYNAMIC_BY_CASH_BANK',
                'system_account_key' => 'BANK_MAIN',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penerimaan Kas Penjualan Aset Tetap: {asset_code}',
            ],
            [
                'line_sequence' => 2,
                'side' => 'DEBIT',
                'amount_source_key' => 'accumulated_depreciation',
                'account_resolution_strategy' => 'DYNAMIC_BY_ASSET_CATEGORY',
                'system_account_key' => 'ACCUM_DEPR_EQUIPMENT',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Penghapusan Akumulasi Penyusutan Aset: {asset_code}',
            ],
            [
                'line_sequence' => 3,
                'side' => 'DEBIT',
                'amount_source_key' => 'loss_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'LOSS_ON_ASSET_DISPOSAL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Kerugian Pelepasan Aset Tetap: {asset_code}',
            ],
            [
                'line_sequence' => 4,
                'side' => 'CREDIT',
                'amount_source_key' => 'acquisition_cost',
                'account_resolution_strategy' => 'DYNAMIC_BY_ASSET_CATEGORY',
                'system_account_key' => 'FIXED_ASSET_EQUIPMENT',
                'zero_amount_behavior' => 'ERROR',
                'description_template' => 'Penghapusan Nilai Perolehan Aset Tetap: {asset_code}',
            ],
            [
                'line_sequence' => 5,
                'side' => 'CREDIT',
                'amount_source_key' => 'gain_amount',
                'account_resolution_strategy' => 'SYSTEM_KEY',
                'system_account_key' => 'GAIN_ON_ASSET_DISPOSAL',
                'zero_amount_behavior' => 'SKIP_LINE',
                'description_template' => 'Keuntungan Pelepasan Aset Tetap: {asset_code}',
            ],
        ];

        foreach ($faDispLines as $line) {
            PostingRuleLine::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'posting_rule_version_id' => $faDispVersion->id,
                    'line_sequence' => $line['line_sequence'],
                ],
                $line
            );
        }
    }
}
