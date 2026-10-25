<?php

namespace App\Modules\Settings\Services;

use App\Services\Context\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Domain 1 (TX-010) — Klasifikasi database error spesifik MySQL/InnoDB.
 *
 * Membedakan driver error code MySQL aktual daripada mengandalkan SQLSTATE 23000
 * generik yang mencakup berbagai jenis pelanggaran integritas (FK, NOT NULL, duplicate).
 */
class DatabaseErrorClassifier
{
    public const MYSQL_ER_DUP_ENTRY = 1062;

    public const MYSQL_ER_LOCK_WAIT_TIMEOUT = 1205;

    public const MYSQL_ER_LOCK_DEADLOCK = 1213;

    public const MYSQL_ER_BAD_NULL_ERROR = 1048;

    public const MYSQL_ER_NO_DEFAULT_FOR_FIELD = 1364;

    public const MYSQL_ER_ROW_IS_REFERENCED = 1451;

    public const MYSQL_ER_NO_REFERENCED_ROW = 1452;

    public const MYSQL_CR_CONNECTION_ERROR = 2002;

    public const MYSQL_CR_SERVER_GONE_ERROR = 2006;

    public const MYSQL_CR_SERVER_LOST = 2013;

    /**
     * Dapatkan kode error spesifik driver MySQL/MariaDB.
     */
    public static function getDriverCode(QueryException $e): int
    {
        return (int) ($e->errorInfo[1] ?? 0);
    }

    /**
     * Apakah error merupakan pelanggaran duplicate key (ER_DUP_ENTRY / 1062)?
     */
    public static function isDuplicateEntry(QueryException $e, ?string $constraintOrIndex = null): bool
    {
        if (self::getDriverCode($e) !== self::MYSQL_ER_DUP_ENTRY) {
            return false;
        }

        if ($constraintOrIndex === null) {
            return true;
        }

        $message = (string) ($e->errorInfo[2] ?? $e->getMessage());

        return str_contains($message, "'{$constraintOrIndex}'")
            || str_contains($message, "for key '{$constraintOrIndex}'")
            || str_contains($message, "for key \"{$constraintOrIndex}\"");
    }

    /**
     * Alias untuk isDuplicateEntry — apakah error merupakan pelanggaran unique constraint.
     */
    public static function isUniqueConstraintViolation(QueryException $e, ?string $constraintOrIndex = null): bool
    {
        return self::isDuplicateEntry($e, $constraintOrIndex);
    }

    /**
     * Apakah error merupakan foreign key constraint violation (1451 / 1452)?
     */
    public static function isForeignKeyViolation(QueryException $e): bool
    {
        $code = self::getDriverCode($e);

        return $code === self::MYSQL_ER_ROW_IS_REFERENCED || $code === self::MYSQL_ER_NO_REFERENCED_ROW;
    }

    /**
     * Apakah error merupakan NOT NULL constraint violation (1048 / 1364)?
     */
    public static function isNotNullViolation(QueryException $e): bool
    {
        $code = self::getDriverCode($e);

        return $code === self::MYSQL_ER_BAD_NULL_ERROR || $code === self::MYSQL_ER_NO_DEFAULT_FOR_FIELD;
    }

    /**
     * Apakah error merupakan deadlock InnoDB (1213)?
     */
    public static function isDeadlock(QueryException $e): bool
    {
        return self::getDriverCode($e) === self::MYSQL_ER_LOCK_DEADLOCK;
    }

    /**
     * Apakah error merupakan lock wait timeout InnoDB (1205)?
     */
    public static function isLockWaitTimeout(QueryException $e): bool
    {
        return self::getDriverCode($e) === self::MYSQL_ER_LOCK_WAIT_TIMEOUT;
    }

    /**
     * Apakah error merupakan kegagalan locking transien yang aman di-retry (deadlock / lock timeout)?
     */
    public static function isLockConflict(QueryException $e): bool
    {
        $isConflict = self::isDeadlock($e) || self::isLockWaitTimeout($e);

        if ($isConflict) {
            self::recordConflictTelemetry($e);
        }

        return $isConflict;
    }

    /**
     * Alias eksplisit untuk isLockConflict — apakah error berupa deadlock (1213) atau lock timeout (1205).
     */
    public static function isDeadlockOrLockTimeout(QueryException $e): bool
    {
        return self::isLockConflict($e);
    }

    /**
     * Catat telemetri konkurensi terstruktur untuk APM (Datadog/NewRelic/Prometheus/Loki)
     * saat terjadi konflik kunci InnoDB tanpa membocorkan PII pelanggan.
     */
    public static function recordConflictTelemetry(QueryException $e, ?string $context = null): void
    {
        try {
            $code = self::getDriverCode($e);
            $type = match ($code) {
                self::MYSQL_ER_LOCK_DEADLOCK => 'deadlock',
                self::MYSQL_ER_LOCK_WAIT_TIMEOUT => 'lock_wait_timeout',
                default => 'other_conflict',
            };

            $tenantContext = app()->bound(TenantContext::class)
                ? app(TenantContext::class)
                : null;

            Log::channel('daily')->warning('[CONCURRENCY_TELEMETRY] InnoDB lock conflict detected', [
                'event' => 'database_lock_conflict',
                'conflict_type' => $type,
                'driver_code' => $code,
                'tenant_id' => $tenantContext?->getTenantId(),
                'store_id' => $tenantContext?->getStoreId(),
                'context' => $context ?? (app()->runningInConsole() ? 'cli' : request()->path()),
                'sql_signature' => substr($e->getSql(), 0, 200),
                'occurred_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable) {
            // Fail-safe: telemetri tidak boleh menginterupsi alur eksepsi utama
        }
    }

    /**
     * Apakah error merupakan koneksi database putus / hilang?
     */
    public static function isConnectionError(QueryException $e): bool
    {
        $code = self::getDriverCode($e);

        return in_array($code, [
            self::MYSQL_CR_CONNECTION_ERROR,
            self::MYSQL_CR_SERVER_GONE_ERROR,
            self::MYSQL_CR_SERVER_LOST,
        ], true);
    }
}
