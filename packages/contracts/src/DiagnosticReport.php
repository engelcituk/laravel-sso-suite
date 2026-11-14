<?php

declare(strict_types=1);

namespace LaravelSso\Contracts;

final class DiagnosticReport implements \JsonSerializable
{
    /** @var list<array{code: string, status: string, message: string}> */
    public array $checks = [];

    public function check(string $code, string $message, callable $check): void
    {
        try {
            $passed = (bool) $check();
        } catch (\Throwable) {
            $passed = false;
        }
        $this->checks[] = ['code' => $code, 'status' => $passed ? 'pass' : 'fail', 'message' => $message];
    }

    public function passed(): bool
    {
        return ! in_array('fail', array_column($this->checks, 'status'), true);
    }

    /** @return array{version: int, passed: bool, checks: array} */
    public function jsonSerialize(): array
    {
        return ['version' => 1, 'passed' => $this->passed(), 'checks' => $this->checks];
    }
}
