<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
try {
  tracker_save_template($config, $_POST, !empty($_POST['id']) ? (int)$_POST['id'] : null);
  tracker_flash_set('success', 'A sablon mentése megtörtént.');
} catch (Throwable $e) {
  tracker_flash_set('error', $e->getMessage());
}
tracker_redirect('/templates.php');
