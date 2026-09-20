<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class EnterpriseBackupCommand extends Command
{
    protected $signature = 'backup:run
                            {--retention=7 : Jumlah hari retensi backup lokal}';

    protected $description = 'Backup MySQL ke .sql.gz (kredensial aman dari process-list), bersihkan backup lama, notifikasi Slack.';

    private string $backupDir;

    public function handle(): int
    {
        $startedAt = microtime(true);
        $this->backupDir = storage_path('app/backups');

        if (! File::isDirectory($this->backupDir)) {
            File::makeDirectory($this->backupDir, 0750, true);
        }

        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (! in_array($db['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error("Driver [{$db['driver']}] belum didukung. Hanya mysql/mariadb.");
            Log::channel('daily')->critical('[BACKUP] Driver tidak didukung.', ['driver' => $db['driver'] ?? null]);

            return self::FAILURE;
        }

        $filename = sprintf('yovel-pos_%s_%s.sql.gz', $connection, now()->format('Y-m-d_His'));
        $filepath = "{$this->backupDir}/{$filename}";

        try {
            $this->runMysqldump($db, $filepath);
        } catch (\Throwable $e) {
            Log::channel('daily')->critical('[BACKUP] mysqldump GAGAL.', ['error' => $e->getMessage()]);
            $this->notifySlack(':x: *Backup GAGAL* pada '.now()->toDateTimeString()." — {$e->getMessage()}");
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $sizeMb = round(File::size($filepath) / 1024 / 1024, 2);
        $durationSec = round(microtime(true) - $startedAt, 2);
        $deleted = $this->cleanupOldBackups((int) $this->option('retention'));

        Log::channel('daily')->info('[BACKUP] Sukses.', [
            'file' => $filename, 'size_mb' => $sizeMb,
            'duration_sec' => $durationSec, 'old_files_deleted' => $deleted,
        ]);

        $this->notifySlack(":white_check_mark: *Backup Sukses* `{$filename}` ({$sizeMb}MB, {$durationSec}s). {$deleted} backup lama dibersihkan.");
        $this->info("Backup selesai: {$filename} ({$sizeMb} MB, {$durationSec}s). {$deleted} file lama dihapus.");

        return self::SUCCESS;
    }

    /**
     * Kredensial DB TIDAK PERNAH lewat argumen CLI (mencegah kebocoran via `ps aux`).
     * Dialirkan lewat --defaults-extra-file sementara, permission 0600, dihapus setelah proses.
     */
    private function runMysqldump(array $db, string $filepath): void
    {
        $cnfPath = tempnam(sys_get_temp_dir(), 'pos_backup_');
        file_put_contents($cnfPath, sprintf(
            "[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n",
            $db['username'], $db['password'], $db['host'], $db['port']
        ));
        chmod($cnfPath, 0600);

        try {
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --single-transaction --quick --routines --triggers %s | gzip -9 > %s',
                escapeshellarg($cnfPath),
                escapeshellarg($db['database']),
                escapeshellarg($filepath)
            );

            $process = Process::fromShellCommandline($command);
            $process->setTimeout(600);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            if (! File::exists($filepath) || File::size($filepath) === 0) {
                throw new \RuntimeException('File backup kosong meski proses melapor sukses.');
            }
        } finally {
            @unlink($cnfPath);
        }
    }

    private function cleanupOldBackups(int $retentionDays): int
    {
        $threshold = Carbon::now()->subDays($retentionDays);
        $deleted = 0;

        foreach (File::files($this->backupDir) as $file) {
            if (str_ends_with($file->getFilename(), '.sql.gz')
                && Carbon::createFromTimestamp($file->getMTime())->lt($threshold)) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }

    private function notifySlack(string $message): void
    {
        if (blank(config('logging.channels.slack.url'))) {
            return;
        }
        Log::channel('slack')->info($message);
    }
}
