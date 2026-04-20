<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

// Optional: show PHP errors if debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

$err = null; $ok = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// SAVE
if (($_POST['action'] ?? '') === 'save') {
  try {
    $data = [
      ':megnevezes' => trim($_POST['megnevezes'] ?? ''),
      ':szam'       => trim($_POST['szam'] ?? '') ?: null,
      ':irsz'       => trim($_POST['cim_irsz'] ?? '') ?: null,
      ':telepules'  => trim($_POST['cim_telepules'] ?? '') ?: null,
      ':utca'       => trim($_POST['cim_utca'] ?? '') ?: null,
      ':hazszam'    => trim($_POST['cim_hazszam'] ?? '') ?: null,
      ':egyeb'      => trim($_POST['cim_egyeb'] ?? '') ?: null,
      ':gps_lat'    => strlen($_POST['gps_lat'] ?? '') ? (float)$_POST['gps_lat'] : null,
      ':gps_lng'    => strlen($_POST['gps_lng'] ?? '') ? (float)$_POST['gps_lng'] : null,
      ':kezdo'      => trim($_POST['kezdo_datum'] ?? '') ?: null,
    ];

    if ($id > 0) {
      $data[':id'] = $id;
      $stmt = $pdo->prepare("UPDATE projects
        SET megnevezes=:megnevezes, szam=:szam,
            cim_irsz=:irsz, cim_telepules=:telepules, cim_utca=:utca, cim_hazszam=:hazszam, cim_egyeb=:egyeb,
            gps_lat=:gps_lat, gps_lng=:gps_lng, kezdo_datum=:kezdo
        WHERE id=:id");
      $stmt->execute($data);
      $ok = "Projekt mentve.";
    } else {
      if ($data[':megnevezes'] === '') throw new Exception('Megnevezés kötelező.');
      $stmt = $pdo->prepare("INSERT INTO projects
        (megnevezes, szam, cim_irsz, cim_telepules, cim_utca, cim_hazszam, cim_egyeb, gps_lat, gps_lng, kezdo_datum)
        VALUES (:megnevezes, :szam, :irsz, :telepules, :utca, :hazszam, :egyeb, :gps_lat, :gps_lng, :kezdo)");
      $stmt->execute($data);
      $id = (int)$pdo->lastInsertId();
      header('Location: project_edit.php?id=' . $id . '&ok=1');
      exit;
    }

    // Save dynamic fields
    if ($id > 0 && isset($_POST['field']) && is_array($_POST['field'])) {
      foreach ($_POST['field'] as $fid => $val) {
        $fid = (int)$fid;
        $stmt = $pdo->prepare("INSERT INTO project_field_values (project_id, field_id, value_text)
                               VALUES (:pid, :fid, :val)
                               ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)");
        $stmt->execute([':pid' => $id, ':fid' => $fid, ':val' => $val]);
      }
    }

  } catch (Throwable $e) { $err = 'Mentési hiba: ' . $e->getMessage(); }
}

// Load project
$project = null;
if ($id > 0) {
  $s = $pdo->prepare("SELECT * FROM projects WHERE id=:id");
  $s->execute([':id' => $id]);
  $project = $s->fetch();
}

// Load active dynamic fields
$fields = $pdo->query("SELECT * FROM project_fields WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// Load existing values for this project
$values = [];
if ($id > 0 && !empty($fields)) {
  $ids = implode(',', array_map(function($f){ return (int)$f['id']; }, $fields));
  if ($ids !== '') {
    $vs = $pdo->query("SELECT field_id, value_text FROM project_field_values WHERE project_id=".(int)$id." AND field_id IN ($ids)")->fetchAll();
    foreach ($vs as $v) { $values[(int)$v['field_id']] = $v['value_text']; }
  }
}

if (isset($_GET['ok'])) $ok = 'Projekt létrehozva.';
?>
  <div class="container">
    <div class="hdr card">
      <div><strong><?= $id>0 ? 'Projekt szerkesztése' : 'Új projekt' ?></strong></div>
      <div><a class="btn" href="projects.php">← Projektek listája</a></div>
    </div>

    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>

    <form method="post" class="card">
      <input type="hidden" name="action" value="save">
      <div class="grid cols-3">
        <div class="input"><label>Megnevezés *</label><input name="megnevezes" required value="<?=htmlspecialchars($project['megnevezes'] ?? '')?>"></div>
        <div class="input"><label>Szám</label><input name="szam" value="<?=htmlspecialchars($project['szam'] ?? '')?>"></div>
        <div class="input"><label>Kezdő dátum</label><input type="date" name="kezdo_datum" value="<?=htmlspecialchars($project['kezdo_datum'] ?? '')?>"></div>

        <div class="input"><label>Irányítószám</label><input name="cim_irsz" value="<?=htmlspecialchars($project['cim_irsz'] ?? '')?>"></div>
        <div class="input"><label>Település</label><input name="cim_telepules" value="<?=htmlspecialchars($project['cim_telepules'] ?? '')?>"></div>
        <div class="input"><label>Utca</label><input name="cim_utca" value="<?=htmlspecialchars($project['cim_utca'] ?? '')?>"></div>
        <div class="input"><label>Házszám</label><input name="cim_hazszam" value="<?=htmlspecialchars($project['cim_hazszam'] ?? '')?>"></div>
        <div class="input"><label>Egyéb</label><input name="cim_egyeb" value="<?=htmlspecialchars($project['cim_egyeb'] ?? '')?>"></div>

        <div class="input"><label>GPS lat</label><input name="gps_lat" type="number" step="0.0000001" value="<?=htmlspecialchars($project['gps_lat'] ?? '')?>"></div>
        <div class="input"><label>GPS lng</label><input name="gps_lng" type="number" step="0.0000001" value="<?=htmlspecialchars($project['gps_lng'] ?? '')?>"></div>
      </div>

      <?php if(!empty($fields)): ?>
      <hr style="margin:12px 0; border-color:var(--border);">
      <h3>Egyedi mezők (projekt)</h3>
      <div class="grid cols-3">
        <?php foreach($fields as $f): $fid=(int)$f['id']; $val=$values[$fid] ?? ''; $req = $f['required'] ? 'required' : ''; ?>
          <div class="input">
            <label><?=htmlspecialchars($f['label'])?> <?=$f['required']? '*':''?></label>
            <?php if($f['type']==='textarea'): ?>
              <textarea name="field[<?=$fid?>]" <?=$req?>><?=htmlspecialchars($val)?></textarea>
            <?php elseif($f['type']==='select'):
              $opts = json_decode($f['options_json'] ?? '[]', true) ?: [];
            ?>
              <select name="field[<?=$fid?>]" <?=$req?>>
                <option value="">-- Válassz --</option>
                <?php foreach($opts as $o): $s = ($val===$o)?'selected':''; ?>
                  <option <?=$s?>><?=htmlspecialchars($o)?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif($f['type']==='checkbox'): ?>
              <input type="hidden" name="field[<?=$fid?>]" value="0">
              <input type="checkbox" name="field[<?=$fid?>]" value="1" <?=($val==='1')?'checked':''?>>
            <?php else: ?>
              <input type="<?=$f['type']?>" name="field[<?=$fid?>]" value="<?=htmlspecialchars($val)?>" <?=$req?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div style="text-align:right; margin-top:12px;">
        <button class="btn primary" type="submit">Mentés</button>
      </div>
    </form>
  </div>
<?php include __DIR__ . '/footer.php'; ?>
