<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
try {
    if (!empty($_POST['id'])) {
        tracker_delete_vehicle($config, (int)$_POST['id']);
        tracker_flash_set('success', 'A jármű törlése sikerült.');
    }
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
tracker_redirect('/vehicles.php');
