<?php

namespace Tests\Unit;

use App\Services\DatabaseErrorClassifier;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

class DatabaseErrorClassifierTest extends TestCase
{
    private function createQueryException(string $sqlState, int $driverCode, string $driverMessage): QueryException
    {
        $previous = new \PDOException($driverMessage, (int) $sqlState);
        $previous->errorInfo = [$sqlState, $driverCode, $driverMessage];

        return new QueryException(
            'mysql',
            'INSERT INTO test ...',
            [],
            $previous
        );
    }

    public function test_identifies_duplicate_entry(): void
    {
        $e = $this->createQueryException('23000', 1062, "Duplicate entry 'xyz' for key 'provisioning_nonces_nonce_unique'");

        $this->assertTrue(DatabaseErrorClassifier::isDuplicateEntry($e));
        $this->assertTrue(DatabaseErrorClassifier::isDuplicateEntry($e, 'provisioning_nonces_nonce_unique'));
        $this->assertFalse(DatabaseErrorClassifier::isDuplicateEntry($e, 'other_key'));
        $this->assertFalse(DatabaseErrorClassifier::isForeignKeyViolation($e));
        $this->assertFalse(DatabaseErrorClassifier::isDeadlock($e));
    }

    public function test_identifies_foreign_key_violation(): void
    {
        $e1 = $this->createQueryException('23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails');
        $e2 = $this->createQueryException('23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails');

        $this->assertTrue(DatabaseErrorClassifier::isForeignKeyViolation($e1));
        $this->assertTrue(DatabaseErrorClassifier::isForeignKeyViolation($e2));
        $this->assertFalse(DatabaseErrorClassifier::isDuplicateEntry($e1));
        $this->assertFalse(DatabaseErrorClassifier::isDuplicateEntry($e2));
    }

    public function test_identifies_not_null_violation(): void
    {
        $e1 = $this->createQueryException('23000', 1048, "Column 'tenant_id' cannot be null");
        $e2 = $this->createQueryException('HY000', 1364, "Field 'name' doesn't have a default value");

        $this->assertTrue(DatabaseErrorClassifier::isNotNullViolation($e1));
        $this->assertTrue(DatabaseErrorClassifier::isNotNullViolation($e2));
        $this->assertFalse(DatabaseErrorClassifier::isDuplicateEntry($e1));
    }

    public function test_identifies_deadlock_and_lock_wait_timeout(): void
    {
        $deadlock = $this->createQueryException('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction');
        $timeout = $this->createQueryException('HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction');

        $this->assertTrue(DatabaseErrorClassifier::isDeadlock($deadlock));
        $this->assertTrue(DatabaseErrorClassifier::isLockConflict($deadlock));
        $this->assertFalse(DatabaseErrorClassifier::isLockWaitTimeout($deadlock));

        $this->assertTrue(DatabaseErrorClassifier::isLockWaitTimeout($timeout));
        $this->assertTrue(DatabaseErrorClassifier::isLockConflict($timeout));
        $this->assertFalse(DatabaseErrorClassifier::isDeadlock($timeout));
    }

    public function test_identifies_connection_errors(): void
    {
        $e1 = $this->createQueryException('HY000', 2002, 'Connection refused');
        $e2 = $this->createQueryException('HY000', 2006, 'MySQL server has gone away');
        $e3 = $this->createQueryException('HY000', 2013, 'Lost connection to MySQL server during query');

        $this->assertTrue(DatabaseErrorClassifier::isConnectionError($e1));
        $this->assertTrue(DatabaseErrorClassifier::isConnectionError($e2));
        $this->assertTrue(DatabaseErrorClassifier::isConnectionError($e3));
    }
}
