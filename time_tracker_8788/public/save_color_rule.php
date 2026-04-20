<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
try {
    tracker_save_day_color_rule($config, $_POST, !empty($_POST['id']) ? (int)$_POST['id'] : null);
    tracker_flash_set('success', 'A színszabály mentése sikerült.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
tracker_redirect('/color_rules.php');
