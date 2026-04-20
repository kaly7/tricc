<?php
require __DIR__ . '/partials/header.php';
require_login($pdo);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$data = ['pp_status_id'=>'','kiadva'=>'','eventus'=>'','city_id'=>'','irsz'=>'','utca'=>'','hazszam'=>'','elvegzendo'=>'','korzet'=>'','leiras'=>'','vallalt_hatarido'=>'','megjegyzes'=>''];
if ($id){ $stmt=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $stmt->execute([$id]); $row=$stmt->fetch(); if($row) $data=array_merge($data,$row); }
$pp_statuses = get_pp_statuses($pdo); $cities=get_cities($pdo);
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4"><?= $id?'Tétel szerkesztése':'Új tétel' ?></h1></div>
<form method="post" action="job_save.php">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="row g-3">
    <div class="col-md-3"><label class="form-label">PP státusz</label>
      <select class="form-select" name="pp_status_id" required>
        <option value="">-- válassz --</option>
        <?php foreach ($pp_statuses as $opt): ?>
          <option value="<?= (int)$opt['id'] ?>" <?= (string)$data['pp_status_id']===(string)$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label">Kiadva (dátum)</label><input class="form-control datepick" name="kiadva" id="kiadva" value="<?= htmlspecialchars($data['kiadva']) ?>" required></div>
    <div class="col-md-3"><label class="form-label">Határidő (38 nap)</label><input class="form-control" id="hatarido_disp" readonly></div>
    <div class="col-md-3"><label class="form-label">Vállalt határidő</label><input class="form-control datepick" name="vallalt_hatarido" value="<?= htmlspecialchars($data['vallalt_hatarido']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Eventus</label><input class="form-control" type="number" name="eventus" value="<?= htmlspecialchars($data['eventus']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Település</label>
      <select class="form-select" name="city_id" required>
        <option value="">-- válassz --</option>
        <?php foreach ($cities as $opt): ?>
          <option value="<?= (int)$opt['id'] ?>" <?= (string)$data['city_id']===(string)$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label">Irányítószám</label><input class="form-control" name="irsz" value="<?= htmlspecialchars($data['irsz']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Utca</label><input class="form-control" name="utca" value="<?= htmlspecialchars($data['utca']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Házszám</label><input class="form-control" name="hazszam" value="<?= htmlspecialchars($data['hazszam']) ?>"></div>
    <div class="col-md-4"><label class="form-label">Elvégzendő</label><input class="form-control" name="elvegzendo" value="<?= htmlspecialchars($data['elvegzendo']) ?>"></div>
    <div class="col-md-3"><label class="form-label">Körzet</label><input class="form-control" name="korzet" value="<?= htmlspecialchars($data['korzet']) ?>"></div>
    <div class="col-md-12"><label class="form-label">Munka leírása</label><textarea class="form-control" name="leiras"><?= htmlspecialchars($data['leiras']) ?></textarea></div>
    <div class="col-md-12"><label class="form-label">Megjegyzés</label><textarea class="form-control" name="megjegyzes"><?= htmlspecialchars($data['megjegyzes']) ?></textarea></div>
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Mentés</button><a class="btn btn-secondary" href="jobs_list.php">Mégse</a></div>
  </div>
</form>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const k=document.getElementById('kiadva'),h=document.getElementById('hatarido_disp');
  function rec(){ if(!k.value){h.value='';return;} const d=new Date(k.value); if(isNaN(d)){h.value='';return;}
    d.setDate(d.getDate()+38); h.value=d.toISOString().slice(0,10); }
  k.addEventListener('change',rec); rec();
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
