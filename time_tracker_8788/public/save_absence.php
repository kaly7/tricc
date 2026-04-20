<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
try {
    tracker_save_day_absence($config, $user, $_POST);
    tracker_flash_set('success', 'Az egész napos távollét mentése sikerült.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['absence_date'] ?? '')) ? (string)$_POST['absence_date'] : date('Y-m-d');
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId . '&date=' . urlencode($date));
