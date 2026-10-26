<?php
require __DIR__ . '/bootstrap.php';
apiRun(fn($service, $input) => $service->notify($input), true);
