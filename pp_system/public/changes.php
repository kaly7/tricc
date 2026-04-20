<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
$id = (int)($_GET['record_id'] ?? 0);
$rec = db()->prepare('SELECT r.*, ps.name pp_name FROM records r JOIN pp_status ps ON ps.id=r.pp_status_id WHERE r.id=?');
$rec->execute([$id]); $r=$rec->fetch(); if(!$r){ echo 'Nincs tétel.'; exit; }
$st = db()->prepare('SELECT rc.*, u.name user_name FROM record_changes rc JOIN users u ON u.id=rc.changed_by WHERE record_id=? ORDER BY changed_at DESC');
$st->execute([$id]); $logs = $st->fetchAll();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Napló – <?=h($r['eventus'])?></title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 m-0">Változásnapló – <?=h($r['eventus'])?></h1>
    <a class="btn btn-outline-secondary" href="records.php">Vissza</a>
  </div>
  <div class="card"><div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr><th>Időpont</th><th>Felhasználó</th><th>Mező</th><th>Régi érték</th><th>Új érték</th></tr>
      </thead>
      <tbody>
        <?php foreach($logs as $l): ?>
          <tr>
            <td><?=h($l['changed_at'])?></td>
            <td><?=h($l['user_name'])?></td>
            <td><?=h($l['field'])?></td>
            <td><?=h($l['old_value'])?></td>
            <td><?=h($l['new_value'])?></td>
          </tr>
        <?php endforeach; if(!$logs): ?>
          <tr><td colspan="5" class="text-center p-4 text-muted">Nincs változás.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div></div>
</div>
</body></html>
