<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
$statuses = db()->query('SELECT id,name FROM pp_status ORDER BY name')->fetchAll();
$cities   = db()->query('SELECT id,name FROM cities ORDER BY name')->fetchAll();
$today = date('Y-m-d'); $due = calc_due($today);
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Új tétel</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h5 m-0">Új tétel</h1>
    <a class="btn btn-outline-secondary" href="records.php">Vissza</a>
  </div>

<?php
$err = $_GET['err'] ?? '';
if ($err === 'dup'): ?>
  <div class="alert alert-danger">Már létezik ilyen Eventus sorszám (nem lehet duplikálni).</div>
<?php endif; ?>


  <div class="card"><div class="card-body">
    <form method="post" action="actions/record_create.php" class="row g-3">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <div class="col-md-3">
        <label class="form-label">Eventus (max 15)</label>
        <input name="eventus" maxlength="15" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">PP státusz</label>
        <select name="pp_status_id" class="form-select" required>
          <?php foreach($statuses as $s): ?><option value="<?=$s['id']?>"><?=h($s['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Kiadva</label>
        <input name="issued_at" type="date" class="form-control" value="<?=$today?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">+38 nap (mentés után frissül)</label>
        <input type="text" class="form-control" value="<?=$due?>" disabled>
      </div>
      <div class="col-md-4">
        <label class="form-label">Város</label>
        <select name="city_id" class="form-select" required>
          <?php foreach($cities as $c): ?><option value="<?=$c['id']?>"><?=h($c['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-8">
        <label class="form-label">Utca, házszám</label>
        <input name="address" maxlength="190" class="form-control">
      </div>
      <div class="col-md-12">
        <label class="form-label">Elvégzendő művelet (max 100)</label>
        <input name="operation" maxlength="100" class="form-control" pattern="^[^\r\n]{0,100}$" placeholder="sortörés nélkül">
      </div>
      <div class="col-md-12">
        <label class="form-label">Munka leírása</label>
        <textarea name="long_desc" rows="5" class="form-control"></textarea>
      </div>
      <div class="col-md-3 form-check ms-2">
        <input id="arch" type="checkbox" class="form-check-input" name="archived" value="1">
        <label class="form-check-label ms-1" for="arch">Archív</label>
      </div>
      <div class="col-12">
        <button class="btn btn-success">Létrehozás</button>
      </div>
    </form>
  </div></div>
</div>
</body></html>
