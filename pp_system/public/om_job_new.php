<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
if(!is_admin()){ http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';

$u = current_user();
$recordId = (int)($_GET['record_id'] ?? 0);
if ($recordId <= 0) { http_response_code(400); echo 'Hiányzó record_id'; exit; }

$st = db()->prepare("SELECT r.*, ps.name AS pp_name, c.name AS city_name FROM records r JOIN pp_status ps ON ps.id=r.pp_status_id JOIN cities c ON c.id=r.city_id WHERE r.id=? AND r.deleted_at IS NULL LIMIT 1");
$st->execute([$recordId]);
$record = $st->fetch(PDO::FETCH_ASSOC);
if (!$record) { http_response_code(404); echo 'A rekord nem található'; exit; }

$st = db()->query("SELECT id, name, color_hex FROM om_job_statuses ORDER BY sort_order, id");
$statuses = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$statuses) {
    $statuses = [['id'=>0,'name'=>'Nincs O&M státusz tábla feltöltve','color_hex'=>'#f0f0f0']];
}

$users = db()->query("SELECT id, name, email FROM users WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$defaultTitle = trim($record['eventus'].' - '.$record['city_name'].' - '.$record['address']);
$defaultDescription = trim((string)$record['long_desc']);
$plannedDate = $record['due_at'] ?: date('Y-m-d');
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Új O&amp;M munka</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
body { background:#f8f9fa; }
.info-grid .label { font-weight:600; color:#555; }
.worker-list { max-height: 260px; overflow:auto; border:1px solid #dee2e6; border-radius:.375rem; background:#fff; }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="my_om_jobs.php">O&amp;M Munkák</a>
      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
        <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
        <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <?php endif; ?>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Új O&amp;M munka</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="records.php">Vissza a tételekhez</a>
      <a class="btn btn-outline-primary" href="records_edit.php?id=<?=$record['id']?>">Kapcsolódó tétel</a>
    </div>
  </div>

  <?php if (($_GET['err'] ?? '') === 'status'): ?>
    <div class="alert alert-danger">Nem található használható O&amp;M státusz.</div>
  <?php elseif (($_GET['err'] ?? '') === 'worker'): ?>
    <div class="alert alert-danger">Legalább egy dolgozót hozzá kell rendelni.</div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header">Kapcsolódó O&amp;M tétel</div>
    <div class="card-body info-grid">
      <div class="row g-3">
        <div class="col-md-3"><div class="label">Eventus</div><div><?=h($record['eventus'])?></div></div>
        <div class="col-md-3"><div class="label">PP státusz</div><div><?=h($record['pp_name'])?></div></div>
        <div class="col-md-3"><div class="label">Kiadva</div><div><?=h($record['issued_at'])?></div></div>
        <div class="col-md-3"><div class="label">Határidő</div><div><?=h($record['due_at'])?></div></div>
        <div class="col-md-4"><div class="label">Város</div><div><?=h($record['city_name'])?></div></div>
        <div class="col-md-8"><div class="label">Cím</div><div><?=h($record['address'])?></div></div>
        <div class="col-12"><div class="label">Művelet</div><div><?=h($record['operation'])?></div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Munkalap adatai</div>
    <div class="card-body">
      <form method="post" action="actions/om_job_create.php" class="row g-3">
        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="record_id" value="<?=$record['id']?>">

        <div class="col-md-8">
          <label class="form-label">Megnevezés</label>
          <input name="title" class="form-control" maxlength="190" required value="<?=h($defaultTitle)?>">
        </div>

        <div class="col-md-2">
          <label class="form-label">Státusz</label>
          <select name="status_id" class="form-select" required>
            <?php foreach ($statuses as $s): ?>
              <option value="<?=$s['id']?>"><?=h($s['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-2">
          <label class="form-label">Prioritás</label>
          <select name="priority" class="form-select">
            <option value="normal">Normál</option>
            <option value="high">Magas</option>
            <option value="urgent">Sürgős</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Tervezett dátum</label>
          <input type="date" name="planned_date" class="form-control" value="<?=h($plannedDate)?>">
        </div>

        <div class="col-md-9">
          <label class="form-label">Leírás</label>
          <textarea name="description" rows="6" class="form-control"><?=h($defaultDescription)?>\n\nKapcsolódó művelet: <?=h($record['operation'])?></textarea>
        </div>

        <div class="col-12">
          <label class="form-label">Dolgozók hozzárendelése</label>
          <div class="worker-list p-2">
            <div class="row g-2">
              <?php foreach ($users as $worker): ?>
                <div class="col-md-4 col-sm-6">
                  <label class="form-check border rounded p-2 h-100 bg-white">
                    <input class="form-check-input me-2" type="checkbox" name="worker_ids[]" value="<?=$worker['id']?>">
                    <span class="form-check-label">
                      <strong><?=h($worker['name'])?></strong><br>
                      <small class="text-muted"><?=h($worker['email'])?></small>
                    </span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-text">Egy vagy több dolgozó is kiválasztható.</div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button class="btn btn-success">Munkalap létrehozása</button>
          <a class="btn btn-secondary" href="records.php">Mégse</a>
        </div>
      </form>
    </div>
  </div>
</div>
</body></html>
