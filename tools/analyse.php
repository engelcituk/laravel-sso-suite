<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$args = [PHP_BINARY];
if ($ini = php_ini_loaded_file()) {
    array_push($args, '-c', $ini);
}
array_push($args, $root.'/vendor/phpstan/phpstan/phpstan', 'analyse', '--memory-limit=1G', '--no-progress');
if (PHP_OS_FAMILY === 'Windows') {
    // Parent/worker Testbench bootstraps can contend on services.php on Windows.
    // --debug runs the same rules in one process, without parallel workers/result cache.
    $args[] = '--debug';
}
$process = proc_open($args, [STDIN, STDOUT, STDERR], $pipes, $root);
exit(is_resource($process) ? proc_close($process) : 1);
