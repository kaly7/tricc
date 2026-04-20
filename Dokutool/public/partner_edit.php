<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

$err = null; $ok = null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (($_POST['action'] ?? '') === 'save_partner') {
  try {
    $data = [
      ':megnevezes' => trim($_POST['megnevezes'] ?? ''),
      ':irsz'       => trim($_POST['cim_irsz'] ?? '') ?: null,
      ':telepules'  => trim($_POST['cim_telepules'] ?? '') ?: null,
      ':utca'       => trim($_POST['cim_utca'] ?? '') ?: null,
      ':hazszam'    => trim($_POST['cim_hazszam'] ?? '') ?: null,
      ':egyeb'      => trim($_POST['cim_egyeb'] ?? '') ?: null,
    ];
    if ($id > 0) {
      $data[':id'] = $id;
      $stmt = $pdo->prepare("UPDATE partners SET megnevezes=:megnevezes, cim_irsz=:irsz, cim_telepules=:telepules, cim_utca=:utca, cim_hazszam=:hazszam, cim_egyeb=:egyeb WHERE id=:id");
      $stmt->execute($data);
      $ok = "Partner módosítva.";
    } else {
      if ($data[':megnevezes'] === '') throw new Exception('Megnevezés kötelező.');
      $stmt = $pdo->prepare("INSERT INTO partners (megnevezes, cim_irsz, cim_telepules, cim_utca, cim_hazszam, cim_egyeb) VALUES (:megnevezes, :irsz, :telepules, :utca, :hazszam, :egyeb)");
      $stmt->execute($data);
      $id = (int)$pdo->lastInsertId();
      header('Location: partner_edit.php?id='.$id.'&ok=1');
      exit;
    }

    if ($id > 0 && isset($_POST['field']) && is_array($_POST['field'])) {
      foreach ($_POST['field'] as $fid => $val) {
        $fid = (int)$fid;
        $stmt = $pdo->prepare("INSERT INTO partner_field_values (partner_id, field_id, value_text) VALUES (:pid, :fid, :val)
                               ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)");
        $stmt->execute([':pid'=>$id, ':fid'=>$fid, ':val'=>$val]);
      }
    }

  } catch (Throwable $e) { $err = "Mentési hiba: " . $e->getMessage(); }
}

if (($_POST['action'] ?? '') === 'add_contact' && $id > 0) {
  try {
    $stmt = $pdo->prepare("INSERT INTO partner_contacts (partner_id, nev, beosztas, telefon, email, is_primary) VALUES (:pid, :nev, :beosztas, :telefon, :email, :is_primary)");
    $stmt->execute([
      ':pid'=>$id, ':nev'=>trim($_POST['c_nev'] ?? ''),
      ':beosztas'=>trim($_POST['c_beosztas'] ?? '') ?: null,
      ':telefon'=>trim($_POST['c_telefon'] ?? '') ?: null,
      ':email'=>trim($_POST['c_email'] ?? '') ?: null,
      ':is_primary'=> isset($_POST['c_primary']) ? 1 : 0
    ]);
    $ok = "Kapcsolattartó hozzáadva.";
  } catch (Throwable $e) { $err = "Kapcsolattartó hiba: " . $e->getMessage(); }
}
if (($_POST['action'] ?? '') === 'del_contact' && $id > 0) {
  try {
    $cid = (int)($_POST['contact_id'] ?? 0);
    if ($cid>0){
      $stmt = $pdo->prepare("DELETE FROM partner_contacts WHERE id=:id AND partner_id=:pid");
      $stmt->execute([':id'=>$cid, ':pid'=>$id]);
      $ok = "Kapcsolattartó törölve.";
    }
  } catch (Throwable $e) { $err = "Kapcsolattartó törlési hiba: " . $e->getMessage(); }
}

$partner = null;
if ($id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM partners WHERE id=:id");
  $stmt->execute([':id'=>$id]);
  $partner = $stmt->fetch();
}
$contacts = [];
if ($id > 0) {
  $c = $pdo->prepare("SELECT * FROM partner_contacts WHERE partner_id=:pid ORDER BY is_primary DESC, id ASC");
  $c->execute([':pid'=>$id]);
  $contacts = $c->fetchAll();
}

$fields = $pdo->query("SELECT * FROM partner_fields WHERE active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$values = [];
if ($id > 0 && $fields){
  $ids = implode(',', array_map(fn($f)=> (int)$f['id'], $fields));
  $vs = $pdo->query("SELECT field_id, value_text FROM partner_field_values WHERE partner_id={$id} AND field_id IN ($ids)")->fetchAll();
  foreach($vs as $v){ $values[(int)$v['field_id']] = $v['value_text']; }
}

if (isset($_GET['ok'])) { $ok = "Partner létrehozva, most adhatsz hozzá kapcsolattartókat és egyedi mezőket is."; }
?>
  <div class="container">
    <div class="hdr card">
      <div><strong><?= $id>0 ? 'Partner szerkesztése' : 'Új partner' ?></strong></div>
      <div><a class="btn" href="partners.php">← Vissza a listához</a></div>
    </div>

    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>

    <form method="post" class="card">
      <input type="hidden" name="action" value="save_partner">
      <div class="grid cols-3">
        <div class="input"><label>Megnevezés *</label><input name="megnevezes" required value="<?=htmlspecialchars($partner['megnevezes'] ?? '')?>"></div>
        <div class="input"><label>Irányítószám</label><input name="cim_irsz" value="<?=htmlspecialchars($partner['cim_irsz'] ?? '')?>"></div>
        <div class="input"><label>Település</label><input name="cim_telepules" value="<?=htmlspecialchars($partner['cim_telepules'] ?? '')?>"></div>
        <div class="input"><label>Utca</label><input name="cim_utca" value="<?=htmlspecialchars($partner['cim_utca'] ?? '')?>"></div>
        <div class="input"><label>Házszám</label><input name="cim_hazszam" value="<?=htmlspecialchars($partner['cim_hazszam'] ?? '')?>"></div>
        <div class="input"><label>Egyéb</label><input name="cim_egyeb" value="<?=htmlspecialchars($partner['cim_egyeb'] ?? '')?>"></div>
      </div>

      <?php if(!empty($fields)): ?>
      <hr style="margin:12px 0; border-color:var(--border);">
      <h3>Egyedi mezők</h3>
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

    <?php if ($id > 0): ?>
    <div class="card">
      <h3>Kapcsolattartók</h3>
      <form method="post" class="grid cols-3">
        <input type="hidden" name="action" value="add_contact">
        <div class="input"><label>Név *</label><input name="c_nev" required></div>
        <div class="input"><label>Beosztás</label><input name="c_beosztas"></div>
        <div class="input"><label>Telefon</label><input name="c_telefon"></div>
        <div class="input"><label>Email</label><input type="email" name="c_email"></div>
        <div class="input"><label>Elsődleges</label><input type="checkbox" name="c_primary" value="1"></div>
        <div style="align-self:end;"><button class="btn" type="submit">Hozzáadás</button></div>
      </form>

      <?php
        $c = $pdo->prepare("SELECT * FROM partner_contacts WHERE partner_id=:pid ORDER BY is_primary DESC, id ASC");
        $c->execute([':pid'=>$id]);
        $contacts = $c->fetchAll();
      ?>
      <table class="table" style="margin-top:12px;">
        <thead><tr><th>ID</th><th>Név</th><th>Beosztás</th><th>Telefon</th><th>Email</th><th>Elsődleges</th><th>Művelet</th></tr></thead>
        <tbody>
          <?php foreach($contacts as $c): ?>
          <tr>
            <td><?=$c['id']?></td>
            <td><?=htmlspecialchars($c['nev'] ?? '')?></td>
            <td><?=htmlspecialchars($c['beosztas'] ?? '')?></td>
            <td><?=htmlspecialchars($c['telefon'] ?? '')?></td>
            <td><?=htmlspecialchars($c['email'] ?? '')?></td>
            <td><?=$c['is_primary'] ? 'Igen' : 'Nem'?></td>
            <td>
              <form method="post" onsubmit="return confirm('Biztosan törlöd?');">
                <input type="hidden" name="action" value="del_contact">
                <input type="hidden" name="contact_id" value="<?=$c['id']?>">
                <button class="btn ghost" type="submit">Törlés</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($contacts)): ?><tr><td colspan="7"><em>Még nincs kapcsolattartó.</em></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
<?php include __DIR__ . '/footer.php'; ?>
