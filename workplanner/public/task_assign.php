<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

$taskId  = filter_input(INPUT_POST, 'task_id',     FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$empId   = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$date    = (string)($_POST['task_date'] ?? '');

if (!$taskId || !$empId)                             json_out(false, 'Hiányzó paraméter.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))    json_out(false, 'Érvénytelen dátum.');

$st = db()->prepare("SELECT id FROM tasks WHERE id=?");
$st->execute([$taskId]);
if (!$st->fetch()) json_out(false, 'Feladat nem található.');

$uid = (int)(current_user()['id'] ?? 0);

db()->prepare("INSERT IGNORE INTO task_assignments (task_id, employee_id, task_date, created_by) VALUES (?,?,?,?)")
   ->execute([$taskId, $empId, $date, $uid]);

touch_last_modified($date);
audit('task_assign', 'task', $taskId, ['employee_id' => $empId, 'date' => $date]);
json_out(true);
