<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) {
    http_response_code(403);
    exit('Nincs jogosultság.');
}
try {
    tracker_lock_period($config, (string)$_POST['date_from'], (string)$_POST['date_to'], (string)($_POST['reason'] ?? ''));
    tracker_flash_set('success', 'Az időszak lezárása megtörtént.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId);
