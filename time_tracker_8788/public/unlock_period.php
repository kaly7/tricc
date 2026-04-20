<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if (!tracker_is_admin($config)) {
    http_response_code(403);
    exit('Nincs jogosultság.');
}
try {
    tracker_unlock_period($config, (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
    tracker_flash_set('success', 'A zárolás feloldása megtörtént.');
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId);
