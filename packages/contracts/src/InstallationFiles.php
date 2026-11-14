<?php

declare(strict_types=1);

namespace LaravelSso\Contracts;

/** Filesystem-only installer; never edits environment files or executes database commands. */
final class InstallationFiles
{
    /** @return array{config: string, migration: string} */
    public function install(string $package, string $configDirectory, string $migrationDirectory): array
    {
        $sources = glob($package.'/database/migrations/*.php') ?: [];
        if (count($sources) !== 1) {
            throw new \RuntimeException('The package must contain exactly one initial migration.');
        }
        $source = $sources[0];
        $suffix = substr(basename($source), 18);
        $existing = glob($migrationDirectory.'/*_'.$suffix) ?: [];
        if (count($existing) > 1) {
            throw new \RuntimeException('Duplicate package migrations found. Reconcile them before installation.');
        }
        $config = glob($package.'/config/sso-*.php') ?: [];
        if (count($config) !== 1) {
            throw new \RuntimeException('The package must contain exactly one configuration file.');
        }
        $target = $existing[0] ?? $migrationDirectory.'/'.basename($source);
        $this->copyMissing($config[0], $configDirectory.'/'.basename($config[0]));
        $this->copyMissing($source, $target);

        return ['config' => $configDirectory.'/'.basename($config[0]), 'migration' => $target];
    }

    private function copyMissing(string $source, string $target): void
    {
        if (file_exists($target)) {
            return;
        }
        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new \RuntimeException('Cannot create installation directory.');
        }
        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read package file.');
        }
        // Exclusive creation also prevents a concurrent installer overwriting a file.
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            if (file_exists($target)) {
                return;
            }
            throw new \RuntimeException('Cannot create installation file.');
        }
        $complete = false;
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Incomplete installation write.');
            }
            $complete = true;
        } finally {
            fclose($handle);
            if (! $complete) {
                unlink($target); // Remove only the incomplete file exclusively created by this call.
            }
        }
    }
}
