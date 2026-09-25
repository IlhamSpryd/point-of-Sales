<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;

class ReconcileLegacyModifiers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:reconcile-modifiers {--dry-run : Only show what would be done} {--chunk=1000 : Chunk size}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile legacy JSON modifiers in order_items.options to the new order_item_modifiers table.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Legacy Modifier Reconciliation...');
        
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');
        
        $query = OrderItem::whereNotNull('options')->where('options', '!=', '[]')->where('options', '!=', '{}');
        $total = $query->count();
        
        $this->info("Found {$total} order items with legacy JSON options.");
        
        if ($total === 0) {
            $this->info('Nothing to reconcile. Exiting.');
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        $migratedCount = 0;
        
        $query->chunkById($chunkSize, function ($items) use ($dryRun, $bar, &$migratedCount) {
            $inserts = [];
            
            foreach ($items as $item) {
                $options = is_string($item->options) ? json_decode($item->options, true) : $item->options;
                
                if (!is_array($options)) continue;
                
                // Assuming legacy options look like:
                // [ ['id' => 1, 'name' => 'Extra Shot', 'price' => 5000, 'qty' => 1], ... ]
                // Or maybe associative.
                
                foreach ($options as $mod) {
                    if (!isset($mod['id']) && !isset($mod['modifier_id'])) continue;
                    
                    $modId = $mod['modifier_id'] ?? $mod['id'];
                    $qty = $mod['qty'] ?? 1;
                    $price = $mod['price'] ?? $mod['extra_price'] ?? 0;
                    
                    if (!$dryRun) {
                        // Check if already exists to prevent duplicate
                        $exists = DB::table('order_item_modifiers')
                            ->where('order_item_id', $item->id)
                            ->where('modifier_id', $modId)
                            ->exists();
                            
                        if (!$exists) {
                            $inserts[] = [
                                'order_item_id' => $item->id,
                                'modifier_id' => $modId,
                                'qty' => $qty,
                                'price' => $price,
                                'line_total' => $price * $qty,
                                'created_at' => $item->created_at,
                                'updated_at' => $item->updated_at,
                            ];
                        }
                    }
                    $migratedCount++;
                }
                $bar->advance();
            }
            
            if (!$dryRun && count($inserts) > 0) {
                DB::table('order_item_modifiers')->insert($inserts);
            }
        });
        
        $bar->finish();
        $this->newLine(2);
        
        if ($dryRun) {
            $this->info("[DRY RUN] Would have migrated {$migratedCount} modifiers.");
        } else {
            $this->info("Successfully migrated {$migratedCount} modifiers to order_item_modifiers table.");
        }
        
        return 0;
    }
}
