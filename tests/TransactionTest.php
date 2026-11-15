<?php

namespace LaravelSso\Tests;

use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use LaravelSso\Server\Transactions;
use LaravelSso\Tests\Fixtures\CentralUser;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TransactionTest extends TestCase
{
    public static function owners(): array
    {
        return ['package' => [false], 'caller' => [true]];
    }

    #[DataProvider('owners')]
    public function test_snapshot_retries_rollback_rows_and_publish_only_the_committed_attempt(bool $callerOwned): void
    {
        $attempts = 0;
        $commits = 0;
        $operation = function () use (&$attempts, &$commits): void {
            Transactions::run(function () use (&$attempts, &$commits): void {
                $attempts++;
                CentralUser::create(['name' => 'Retry', 'email' => 'retry@example.test', 'password' => bcrypt('password')]);
                DB::afterCommit(function () use (&$commits): void {
                    $commits++;
                });
                if ($attempts < 3) {
                    // Reproduce Laravel's nested wrapper losing the original SQLSTATE.
                    throw new DeadlockException('Wrapped serialization failure.', 0, new PDOException('Snapshot conflict.', 40001));
                }
            });
        };
        if ($callerOwned) {
            DB::transaction($operation, 3);
        } else {
            $operation();
        }
        $this->assertSame(3, $attempts);
        $this->assertSame(1, $commits);
        $this->assertSame(1, CentralUser::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_other_database_errors_are_not_retried_and_leave_no_partial_commit(): void
    {
        $attempts = 0;
        try {
            Transactions::run(function () use (&$attempts): void {
                $attempts++;
                CentralUser::create(['name' => 'Rollback', 'email' => 'rollback@example.test', 'password' => bcrypt('password')]);
                throw new PDOException('Non-retryable constraint failure.', 23505);
            });
            $this->fail('Non-retryable errors must escape.');
        } catch (PDOException $error) {
            $this->assertSame(23505, $error->getCode());
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(0, CentralUser::count());
        $this->assertSame(0, DB::transactionLevel());
    }
}
