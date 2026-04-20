<?php
require __DIR__.'/../app/auth.php';
require_role('editor');
verify_csrf();
$pdo = db();

$id = (int)($_POST['id'] ?? 0);
$pbx_id = (int)($_POST['pbx_id'] ?? 0);

$st = $pdo->prepare("UPDATE pbx_devices SET is_archived=1 WHERE id=?");
$st->execute([$id]);

flash_set('ok', 'Mellék archiválva.');
redirect('pbx_system_show.php?id='.$pbx_id);
