<?php
require_once __DIR__ . '/../../src/db.php';
require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$recordId = (int)($_POST['record_id'] ?? 0);
if ($recordId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Hiányzó record_id']);
    exit;
}

try {
    $db = db();

    $st = $db->prepare("SELECT id FROM records WHERE id = ? AND marvin_pending = 1 AND deleted_at IS NULL");
    $st->execute([$recordId]);
    if (!$st->fetchColumn()) {
        echo json_encode(['ok' => false, 'error' => 'Nem található pending Marvin rekord']);
        exit;
    }

    $db->beginTransaction();

    $st = $db->prepare("
        UPDATE records
        SET marvin_pending = 0, marvin_accepted_by = ?, marvin_accepted_at = NOW()
        WHERE id = ?
    ");
    $st->execute([$user['id'], $recordId]);

    log_change($db, $recordId, $user['id'], 'marvin_accept', '', 'AI (Marvin) által küldött rekord elfogadva');

    $db->commit();

    echo json_encode(['ok' => true]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
