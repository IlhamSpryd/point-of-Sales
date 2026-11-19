<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Observability\AlertEvaluatorService;
use Illuminate\Console\Command;

class EvaluateOperationalAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ops:evaluate-alerts {--test : Dispatches a safe test notification ping to verify webhook connectivity}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Evaluates operational metrics against alert-as-code thresholds and dispatches notifications (OPS-03)';

    /**
     * Execute the console command.
     */
    public function handle(AlertEvaluatorService $evaluator): int
    {
        if ($this->option('test')) {
            $this->info('Dispatching test verification ping to configured operational webhook...');

            $webhookUrl = config('observability.alert_webhook_url');
            if (empty($webhookUrl)) {
                $this->warn('[-] Warning: No webhook URL configured (OPS_ALERT_WEBHOOK_URL / ALERT_NOTIFICATION_WEBHOOK_URL is empty).');

                return self::SUCCESS;
            }

            $success = $evaluator->sendTestPing();

            if ($success) {
                $this->info('✓ Test verification ping dispatched successfully (HTTP 200/204 delivered).');

                return self::SUCCESS;
            }

            $this->error('[-] Error: Failed to dispatch test ping to configured webhook.');

            return self::FAILURE;
        }

        $this->info('Evaluating operational alerts against live domain metrics...');

        $result = $evaluator->evaluateAndDispatch();

        $breachingCount = count($result['breaching']);
        $dispatchedCount = count($result['dispatched']);
        $suppressedCount = count($result['suppressed']);

        if ($breachingCount === 0) {
            $this->info('✓ All operational metrics healthy. Zero threshold breaches.');

            return self::SUCCESS;
        }

        $this->warn("! Detected {$breachingCount} threshold breach(es): {$dispatchedCount} dispatched, {$suppressedCount} on cooldown.");

        foreach ($result['breaching'] as $alert) {
            $status = in_array($alert, $result['dispatched'], true) ? 'DISPATCHED' : 'COOLDOWN';
            $this->line(sprintf('  [%s] [%s] %s: %s', $status, strtoupper($alert['severity']), $alert['code'], $alert['message']));
        }

        return self::SUCCESS;
    }
}
