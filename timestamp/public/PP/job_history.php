<?php
require __DIR__ . '/partials/header.php';
require_login($pdo);
$id = (int)($_GET['id'] ?? 0);
if (!$id) { echo "<div class='alert alert-danger'>Hiányzó tétel ID.</div>"; require __DIR__ . '/partials/footer.php'; exit; }

$chk=$pdo->prepare("SELECT t.*, s.name AS pp_status_name, c.name AS city_name FROM tasks t LEFT JOIN pp_statuses s ON s.id=t.pp_status_id LEFT JOIN cities c ON c.id=t.city_id WHERE t.id=?");
$chk->execute([$id]); $task=$chk->fetch();
if (!$task){ echo "<div class='alert alert-warning'>A tétel nem található.</div>"; require __DIR__ . '/partials/footer.php'; exit; }

$st=$pdo->prepare("SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id WHERE entity='tasks' AND entity_id=? ORDER BY a.id DESC");
$st->execute([$id]); $logs=$st->fetchAll();

function diff_fields($before_json, $after_json): array {
    $changes=[]; $before=$before_json?json_decode($before_json,true):null; $after=$after_json?json_decode($after_json,true):null;
    $fields=['pp_status_id','kiadva','eventus','city_id','irsz','utca','hazszam','elvegzendo','korzet','leiras','vallalt_hatarido','megjegyzes'];
    foreach($fields as $f){ $b=$before[$f]??null; $a=$after[$f]??null; if($b!==$a){ $changes[$f]=['before'=>$b,'after'=>$a]; } }
    return $changes;
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Változástörténet – Tétel #<?= (int)$id ?></h1>
  <a class="btn btn-outline-secondary" href="jobs_list.php">Vissza a listára</a>
</div>

<?php if (!$logs): ?>
  <div class="alert alert-info">Ehhez a tételhez még nincs napló.</div>
<?php else: ?>
  <div class="list-group">
    <?php foreach ($logs as $row): $changes=diff_fields($row['before_json'],$row['after_json']); ?>
      <div class="list-group-item">
        <div class="d-flex justify-content-between">
          <div><strong><?= htmlspecialchars($row['action']) ?></strong> – <?= htmlspecialchars($row['user_name'] ?? 'ismeretlen') ?></div>
          <div class="text-muted"><?= htmlspecialchars($row['created_at']) ?></div>
        </div>
        <?php if ($changes): ?>
          <div class="mt-2">
            <table class="table table-sm">
              <thead><tr><th>Mező</th><th>Előtte</th><th>Utána</th></tr></thead>
              <tbody>
              <?php foreach ($changes as $f=>$vals): ?>
                <tr>
                  <td><?= htmlspecialchars($f) ?></td>
                  <td class="cell-wrap"><?= nl2br(htmlspecialchars((string)($vals['before']??''))) ?></td>
                  <td class="cell-wrap"><?= nl2br(htmlspecialchars((string)($vals['after']??''))) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-muted">Nincs releváns mezőváltozás rögzítve.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
