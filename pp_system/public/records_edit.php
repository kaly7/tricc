<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM records WHERE id=?'); $st->execute([$id]); $r = $st->fetch();
if(!$r){ http_response_code(404); echo 'Nincs ilyen tétel.'; exit; }
$statuses = db()->query('SELECT id,name FROM pp_status ORDER BY name')->fetchAll();
$cities   = db()->query('SELECT id,name FROM cities ORDER BY name')->fetchAll();
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tétel szerkesztése</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 m-0">Tétel szerkesztése</h1>
    <a class="btn btn-outline-secondary" href="records.php">Vissza</a>
  </div>
  <div class="card"><div class="card-body">
    <form method="post" action="actions/record_update.php" class="row g-3">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="id" value="<?=$r['id']?>">
      <!--- <div class="col-md-3">
        <label class="form-label">Eventus (max 15)</label>
        <input name="eventus" maxlength="15" class="form-control" value="<?=h($r['eventus'])?>" required>
      </div>
	--->

<div class="col-md-3">
  <label class="form-label">Eventus</label>
  <input class="form-control" value="<?=h($r['eventus'])?>" disabled>
  <div class="form-text">Az Eventus csak létrehozáskor módosítható.</div>
</div>
      <div class="col-md-3">
        <label class="form-label">PP státusz</label>
        <select name="pp_status_id" class="form-select" required>
          <?php foreach($statuses as $s): ?>
            <option value="<?=$s['id']?>" <?=$r['pp_status_id']==$s['id']?'selected':''?>><?=h($s['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Kiadva</label>
        <input name="issued_at" type="date" class="form-control" value="<?=h($r['issued_at'])?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">+38 nap</label>
        <input type="text" class="form-control" value="<?=h($r['due_at'])?>" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Város</label>
        <select name="city_id" class="form-select" required>
          <?php foreach($cities as $c): ?>
            <option value="<?=$c['id']?>" <?=$r['city_id']==$c['id']?'selected':''?>><?=h($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label">Utca, házszám</label>
        <input name="address" maxlength="190" class="form-control" value="<?=h($r['address'])?>">
      </div>
      <div class="col-md-12">
        <label class="form-label">Elvégzendő művelet (max 100)</label>
        <input name="operation" maxlength="100" class="form-control" pattern="^[^\r\n]{0,100}$" value="<?=h($r['operation'])?>">
      </div>
      <div class="col-md-12">
        <label class="form-label">Munka leírása</label>
        <textarea name="long_desc" rows="6" class="form-control"><?=h($r['long_desc'])?></textarea>
      </div>
      <div class="col-md-3 form-check ms-2">
        <input id="arch" type="checkbox" class="form-check-input" name="archived" value="1" <?=$r['archived']?'checked':''?>>
        <label class="form-check-label ms-1" for="arch">Archív</label>
      </div>
      <div class="col-12">
        <button class="btn btn-primary">Mentés</button>
        <a class="btn btn-secondary" href="records.php">Mégse</a>
      </div>
    </form>
  </div></div>
</div>
</body></html>
