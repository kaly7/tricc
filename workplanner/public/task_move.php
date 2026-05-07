<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

$action   = (string)($_POST['action']    ?? '');
$taskId   = filter_input(INPUT_POST, 'task_id',   FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fromEmp  = filter_input(INPUT_POST, 'from_emp',  FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$toEmp    = filter_input(INPUT_POST, 'to_emp',    FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fromDate = (string)($_POST['from_date'] ?? '');
$toDate   = (string)($_POST['to_date']   ?? '');

if (!in_array($action, ['move', 'copy'], true))        json_out(false, 'Érvénytelen művelet.');
if (!$taskId || !$fromEmp || !$toEmp)                  json_out(false, 'Hiányzó paraméter.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate))  json_out(false, 'Érvénytelen forrás dátum.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))    json_out(false, 'Érvénytelen cél dátum.');

$st = db()->prepare("SELECT id FROM task_assignments WHERE task_id=? AND employee_id=? AND task_date=?");
$st->execute([$taskId, $fromEmp, $fromDate]);
$assignment = $st->fetch();
if (!$assignment) json_out(false, 'Hozzárendelés nem található.');

$uid = (int)(current_user()['id'] ?? 0);

if ($action === 'move') {
    db()->prepare(
        "UPDATE task_assignments SET employee_id=?, task_date=? WHERE task_id=? AND employee_id=? AND task_date=?"
    )->execute([$toEmp, $toDate, $taskId, $fromEmp, $fromDate]);

    touch_last_modified($toDate);
    audit('task_move', 'task', $taskId, [
        'from_emp' => $fromEmp, 'from_date' => $fromDate,
        'to_emp'   => $toEmp,   'to_date'   => $toDate
    ]);
    json_out(true);
}

// copy
db()->prepare(
    "INSERT IGNORE INTO task_assignments (task_id, employee_id, task_date, created_by) VALUES (?,?,?,?)"
)->execute([$taskId, $toEmp, $toDate, $uid]);

touch_last_modified($toDate);
audit('task_copy', 'task', $taskId, ['to_emp' => $toEmp, 'to_date' => $toDate]);
json_out(true);
