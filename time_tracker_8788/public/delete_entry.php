<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
try {
    tracker_delete_entry($config, $user, (int)($_POST['id'] ?? 0));
    tracker_flash_set('success', 'A bejegyzés törölve lett.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId);
