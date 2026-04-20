<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
try {
    $id = (int)($_POST['id'] ?? 0);
    $newDate = (string)($_POST['new_date'] ?? '');
    if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
        throw new RuntimeException('Érvénytelen áthúzási kérés.');
    }
    tracker_move_entry_to_date($config, $user, $id, $newDate);
    tracker_flash_set('success', 'A bejegyzés új napra került.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['new_date'] ?? '')) ? (string)$_POST['new_date'] : date('Y-m-d');
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId . '&date=' . urlencode($date));
