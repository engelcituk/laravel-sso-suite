<?php

declare(strict_types=1);

namespace LaravelSso\Server;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class Transactions
{
    /** Retry only complete transactions; a caller-owned transaction must retry itself. */
    public static function run(Closure $work): mixed
    {
        return DB::transaction(function () use ($work): mixed {
            try {
                return $work();
            } catch (PDOException $error) {
                // Laravel's nested-transaction wrapper can turn SQLSTATE '40001' into
                // code 0. Preserve the numeric signal through every savepoint boundary.
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof PDOException && (string) $cause->getCode() === '40001') {
                        throw new DeadlockException('Database transaction requires a fresh snapshot.', 40001, $error);
                    }
                }
                throw $error;
            }
        }, 10);
    }
}
