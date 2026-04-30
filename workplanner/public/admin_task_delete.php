<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();

$id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$csrf = (string)($_GET['_csrf'] ?? '');
start_session();
if (!$id || !isset($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], $csrf)) {
  flash_set('err','Érvénytelen kérés.'); redirect('admin_tasks.php');
}

db()->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]);
audit('task_delete','task',$id);
touch_last_modified();
flash_set('ok','Feladat törölve.');
redirect('admin_tasks.php');
