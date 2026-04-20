<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
try {
    $result = tracker_save_entry($config, $user, $_POST, !empty($_POST['id']) ? (int)$_POST['id'] : null);
    if (($result['mode'] ?? '') === 'create') {
        $createdCount = count($result['created_for'] ?? []);
        $message = 'A bejegyzés mentése sikerült.';
        if ($createdCount > 1) {
            $message = 'A bejegyzés ' . $createdCount . ' dolgozóhoz rögzítve.';
        }
        $skipped = $result['skipped'] ?? [];
        if ($skipped) {
            $parts = [];
            foreach ($skipped as $skip) {
                $parts[] = ($skip['label'] ?? ('#' . (int)$skip['employee_id'])) . ': ' . ($skip['reason'] ?? 'nem rögzíthető');
            }
            $message .= ' Kihagyva: ' . implode('; ', $parts);
        }
        tracker_flash_set('success', $message);
    } else {
        tracker_flash_set('success', 'A bejegyzés mentése sikerült.');
    }
} catch (Throwable $e) {
    tracker_flash_set('error', $e->getMessage());
}
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? (string)$_POST['month'] : date('Y-m');
$employeeId = !empty($_POST['employee_id']) ? '&employee_id=' . (int)$_POST['employee_id'] : '';
tracker_redirect('/index.php?month=' . urlencode($month) . $employeeId . '&date=' . urlencode((string)($_POST['entry_date'] ?? date('Y-m-d'))));
