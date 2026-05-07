<?php
require_once __DIR__ . '/../app/auth.php';
require_admin();
verify_csrf();

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $ok, string $error = ''): void {
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

$aid = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$aid) json_out(false, 'Hiányzó paraméter.');

$st = db()->prepare("SELECT task_id, task_date FROM task_assignments WHERE id=?");
$st->execute([$aid]);
$row = $st->fetch();
if (!$row) json_out(false, 'Hozzárendelés nem található.');

db()->prepare("DELETE FROM task_assignments WHERE id=?")->execute([$aid]);
touch_last_modified($row['task_date']);
audit('task_unassign', 'task', (int)$row['task_id'], ['assignment_id' => $aid]);
json_out(true);
