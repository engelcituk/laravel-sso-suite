<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use LaravelSso\Server\LogoutDeliveries;

// Separate process: the OP development server is single threaded.
$app = require dirname(__DIR__).'/.runtime/conformance/op/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
while (true) {
    $delivery = $app->make(LogoutDeliveries::class);
    while ($delivery->deliverOne()) {
        // Drain due deliveries, retaining the production retry policy.
    }
    usleep(250000);
}
