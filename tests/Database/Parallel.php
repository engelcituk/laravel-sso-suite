<?php

namespace LaravelSso\Tests\Database;

use Illuminate\Support\Facades\DB;

final class Parallel
{
    public static function wait(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for '.basename($path));
            }
            usleep(1000);
        }
    }

    public static function barrier(string $directory, string $phase, int $index, int $count): void
    {
        touch($directory.'/'.$phase.'-'.$index);
        for ($i = 0; $i < $count; $i++) {
            self::wait($directory.'/'.$phase.'-'.$i);
        }
    }

    public static function run(int $count, callable $work): array
    {
        if (! function_exists('pcntl_fork') || ! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            throw new \RuntimeException('Concurrency tests require Linux pcntl and the real-engine laboratory.');
        }
        $directory = (getenv('SSO_LAB_RESULTS') ?: '/results').'/'.getenv('SSO_LAB_RUN').'/parallel-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create private concurrency evidence directory.');
        }
        // Never inherit a live PDO socket: each process opens an independent connection.
        DB::disconnect();
        $children = [];
        try {
            for ($index = 0; $index < $count; $index++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Cannot fork database worker.');
                }
                if ($pid === 0) {
                    try {
                        $actualIsolation = DB::getDriverName() === 'mysql'
                            ? DB::selectOne('select @@transaction_isolation as isolation')->isolation
                            : DB::selectOne('show transaction_isolation')->transaction_isolation;
                        if (strtolower(str_replace('-', ' ', $actualIsolation)) !== strtolower(DB::connection()->getConfig('isolation_level'))) {
                            throw new \RuntimeException('Concurrent worker did not retain the requested isolation level.');
                        }
                        self::barrier($directory, 'ready', $index, $count);
                        $result = ['ok' => true, 'result' => $work($index, $directory)];
                    } catch (\Throwable $error) {
                        $result = ['ok' => false, 'error' => $error::class, 'message' => $error->getMessage()];
                    }
                    file_put_contents($directory.'/result-'.$index.'.json', json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect();
                    exit(0);
                }
                $children[$index] = $pid;
            }
            $deadline = microtime(true) + 50;
            $pending = $children;
            while ($pending !== []) {
                foreach ($pending as $index => $pid) {
                    $state = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($state === $pid) {
                        unset($pending[$index], $children[$index]);
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Concurrent workers exceeded the laboratory deadline.');
                }
                usleep(1000);
            }
            $results = [];
            for ($index = 0; $index < $count; $index++) {
                $result = json_decode(file_get_contents($directory.'/result-'.$index.'.json'), true, flags: JSON_THROW_ON_ERROR);
                if (! $result['ok']) {
                    throw new \RuntimeException($result['error'].': '.$result['message']);
                }
                $results[] = $result['result'];
            }

            return $results;
        } finally {
            foreach ($children as $pid) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            DB::disconnect();
        }
    }
}
