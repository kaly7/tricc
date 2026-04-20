<?php
require __DIR__ . '/../app/functions.php';
require __DIR__ . '/../app/auth.php';
require_login();
$u = current_user();

if (($u['role'] ?? '') !== 'admin') {
  http_response_code(403);
  $title = 'Nincs jogosultság';
  $page = 'Nincs jogosultság';
  require __DIR__ . '/_header.php';
  ?>
  <div class="alert alert-danger">Ehhez az oldalhoz admin jogosultság szükséges.</div>
  <?php
  require __DIR__ . '/_footer.php';
  exit;
}

$title = 'Függő átadások';
$page  = 'Függő átadások';

$pdo = db();
$hr  = db_hr();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'cancel_pending') {
    $assignId = (int)($_POST['assignment_id'] ?? 0);
    if ($assignId <= 0) {
      flash_set('err', 'Hiányzó átadás azonosító.');
      header('Location: pending_transfers.php');
      exit;
    }

    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("SELECT * FROM asset_assignments WHERE id=? AND status='pending' LIMIT 1");
      $st->execute([$assignId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) {
        throw new RuntimeException('A függő átadás nem található.');
      }

      $pdo->prepare("UPDATE asset_assignments
                        SET status='cancelled',
                            responded_at=NOW(),
                            response_note=CASE
                              WHEN response_note IS NULL OR response_note='' THEN 'Admin által visszavonva'
                              ELSE response_note
                            END
                      WHERE id=?")
          ->execute([$assignId]);

      // Biztonságból visszaállítjuk az eszközt az eredeti birtokoshoz
      $pdo->prepare("UPDATE assets SET current_employee_id=? WHERE id=?")
          ->execute([(int)$row['from_employee_id'], (int)$row['asset_id']]);

      $pdo->commit();
      flash_set('ok', 'A függő átadás visszavonva, az eszköz az eredeti birtokosnál marad.');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      flash_set('err', 'Hiba a függő átadás visszavonásakor: ' . $e->getMessage());
    }

    header('Location: pending_transfers.php');
    exit;
  }
}

// HR névtérkép
$empMap = [];
try {
  foreach ($hr->query("SELECT id, full_name FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC) as $e) {
    $empMap[(int)$e['id']] = (string)$e['full_name'];
  }
} catch (Throwable $e) {
  $empMap = [];
}

// Auth Center user névtérkép (ha elérhető)
$userMap = [];
try {
  if (function_exists('auth_pdo')) {
    $auth = auth_pdo();
    foreach ($auth->query("SELECT id, COALESCE(NULLIF(full_name,''), NULLIF(username,''), email) AS nm FROM users")->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $userMap[(int)$r['id']] = (string)$r['nm'];
    }
  }
} catch (Throwable $e) {
  $userMap = [];
}

$rows = [];
try {
  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM asset_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[(string)$c['Field']] = true;
  }
  if (!isset($cols['status'])) {
    throw new RuntimeException("Hiányzik az asset_assignments.status mező.");
  }

  $sql = "SELECT aa.*, a.name AS asset_name, a.sku AS asset_sku, a.qr_value AS asset_qr, a.current_employee_id
          FROM asset_assignments aa
          JOIN assets a ON a.id = aa.asset_id
          WHERE aa.status='pending'
          ORDER BY aa.id DESC";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  flash_set('err', 'A függő átadások nem tölthetők be: ' . $e->getMessage());
  $rows = [];
}

require __DIR__ . '/_header.php';
?>

<div class="container" style="max-width:1100px">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Függő átadások</h4>
      <div class="text-secondary small">Azok a belső átadások, amelyeket a címzett még nem fogadott el.</div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="alert alert-info">Nincs függőben lévő belső átadás.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>#</th>
            <th>Eszköz</th>
            <th>Eredeti birtokos</th>
            <th>Címzett</th>
            <th>Jelenleg kinél</th>
            <th>Kezdeményezte</th>
            <th>Lejárat</th>
            <th>Megjegyzés</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $id = (int)$r['id'];
              $assetLabel = (string)($r['asset_name'] ?? '');
              $sku = (string)($r['asset_sku'] ?? '');
              $qr  = (string)($r['asset_qr'] ?? '');
              if ($sku !== '') $assetLabel .= ' | SKU: ' . $sku;
              if ($qr !== '')  $assetLabel .= ' | QR: ' . $qr;

              $fromName = $empMap[(int)($r['from_employee_id'] ?? 0)] ?? ('#' . (int)($r['from_employee_id'] ?? 0));
              $toName   = $empMap[(int)($r['to_employee_id'] ?? 0)] ?? ('#' . (int)($r['to_employee_id'] ?? 0));
              $currName = $empMap[(int)($r['current_employee_id'] ?? 0)] ?? ((int)($r['current_employee_id'] ?? 0) > 0 ? ('#' . (int)$r['current_employee_id']) : '—');
              $byName   = $userMap[(int)($r['assigned_by_user_id'] ?? 0)] ?? ('#' . (int)($r['assigned_by_user_id'] ?? 0));
              $exp      = (string)($r['expires_at'] ?? '');
              $note     = (string)($r['note'] ?? '');
            ?>
            <tr>
              <td><?= $id ?></td>
              <td><?= e($assetLabel) ?></td>
              <td><?= e($fromName) ?></td>
              <td><?= e($toName) ?></td>
              <td><?= e($currName) ?></td>
              <td><?= e($byName) ?></td>
              <td><?= e($exp !== '' ? $exp : '—') ?></td>
              <td><?= e($note !== '' ? $note : '—') ?></td>
              <td class="text-end">
                <form method="post" onsubmit="return confirm('Biztosan visszavonod ezt a függő átadást?');">
                  <input type="hidden" name="action" value="cancel_pending">
                  <input type="hidden" name="assignment_id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Visszavonás</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
