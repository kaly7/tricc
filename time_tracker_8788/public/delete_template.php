<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
  tracker_delete_template($config, $id);
  tracker_flash_set('success', 'A sablon törlése megtörtént.');
}
tracker_redirect('/templates.php');
