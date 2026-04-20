<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

$pdo = Db::pdo();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: employees.php"); exit; }

$pdo->beginTransaction();
try {
    // keep historical logs: unlink references from page_jobs (so FK won't block delete)
    // if there is no employee_id column, ignore
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM page_jobs LIKE 'employee_id'")->fetch();
        if ($chk) {
            $st = $pdo->prepare("UPDATE page_jobs SET employee_id=NULL WHERE employee_id=?");
            $st->execute([$id]);
        }
    } catch (\Throwable $ignored) {}

    $st = $pdo->prepare("DELETE FROM employees WHERE id=?");
    $st->execute([$id]);

    $pdo->commit();
    header("Location: employees.php?msg=deleted");
    exit;
} catch (\Throwable $e) {
    try { $pdo->rollBack(); } catch (\Throwable $ignored) {}
    header("Location: employees.php?msg=fail");
    exit;
}
