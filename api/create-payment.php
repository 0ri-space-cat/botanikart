<?php
require __DIR__ . '/bootstrap.php';
apiRun(fn($service, $input) => $service->create($input, $_SERVER['REMOTE_ADDR'] ?? ''));
