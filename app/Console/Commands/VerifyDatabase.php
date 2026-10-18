<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class VerifyDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pos:verify-database {--allow-skipped : Do not fail if scripts are empty or skipped}';

    protected $aliases = ['db:verify', 'app:verify-database'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run all SQL verification scripts and report discrepancies';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Running Database Verification Suite...');

        $path = base_path('database/sql/verify');
        if (! File::exists($path)) {
            $this->error("Verification folder not found: $path");

            return 1;
        }

        $files = File::files($path);
        $hasErrors = false;
        $skippedCount = 0;
        $passedCount = 0;

        foreach ($files as $file) {
            $this->info("\n--- Running ".$file->getFilename().' ---');
            $sql = trim(file_get_contents($file->getPathname()));

            if (empty($sql)) {
                $this->warn('SKIPPED: File is empty.');
                $skippedCount++;

                continue;
            }

            try {
                $cleanSql = trim(preg_replace('/^--.*$/m', '', $sql));
                if (stripos($cleanSql, 'SELECT') === 0) {
                    $results = DB::select($sql);
                    if (count($results) > 0) {
                        // Check if the query returned a "PASS" row (from the UNION)
                        $firstRow = (array) $results[0];
                        if (isset($firstRow['result']) && $firstRow['result'] === 'PASS') {
                            $this->info('PASSED: Script executed successfully. '.json_encode($firstRow));
                            $passedCount++;

                            continue;
                        }

                        $this->error('FAILED: Found '.count($results).' discrepancies.');
                        $hasErrors = true;

                        $headers = array_keys((array) $results[0]);
                        $rows = array_map(function ($row) {
                            return (array) $row;
                        }, array_slice($results, 0, 10));

                        $this->table($headers, $rows);
                        if (count($results) > 10) {
                            $this->warn('...and '.(count($results) - 10).' more rows.');
                        }
                    } else {
                        $this->info('PASSED: No discrepancies found.');
                        $passedCount++;
                    }
                } else {
                    DB::unprepared($sql);
                    $this->info('PASSED: Script executed successfully.');
                    $passedCount++;
                }
            } catch (\Exception $e) {
                $this->error('ERROR Executing Script: '.$e->getMessage());
                $hasErrors = true;
            }
        }

        $this->newLine();
        if ($hasErrors) {
            $this->error('Verification Suite completed with ERRORS. Check the logs above.');

            return 1;
        }

        if ($skippedCount > 0 && ! $this->option('allow-skipped')) {
            $this->error("Verification Suite completed with {$skippedCount} SKIPPED check(s). Database integrity cannot be verified when checks are skipped.");

            return 1;
        }

        $this->info("Verification Suite completed SUCCESSFULLY ({$passedCount} checks passed). Database integrity is verified.");

        return 0;
    }
}
