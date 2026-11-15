<?php

declare(strict_types=1);

namespace LaravelSso\Client;

use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;
use PDOException;

final class Transactions
{
    public static function run(Closure $work): mixed
    {
        return DB::transaction(function () use ($work): mixed {
            try {
                return $work();
            } catch (PDOException $error) {
                for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause instanceof PDOException && (string) $cause->getCode() === '40001') {
                        throw new DeadlockException('Identity transaction requires a fresh snapshot.', 40001, $error);
                    }
                }
                throw $error;
            }
        }, 10);
    }
}
