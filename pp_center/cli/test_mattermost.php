<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Services\MattermostService;

$service = new MattermostService();
$ok = $service->notify(
    'PP Center teszt',
    'Ez egy kézi tesztüzenet a PHP bridge-ből.',
    'good',
    [
        'Szerver' => gethostname() ?: 'unknown',
        'Idő' => date('Y-m-d H:i:s'),
    ]
);

echo $ok ? "OK\n" : "HIBA\n";
