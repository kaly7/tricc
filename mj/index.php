<?php
require_once __DIR__.'/db.php';
$db = db();

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id             = intval($_POST['id'] ?? 0);
    $nev            = trim($_POST['nev'] ?? '');
    $leiras         = trim($_POST['leiras'] ?? '');
    $munka1_osszeg  = $_POST['munka1_osszeg'] !== '' ? floatval(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $_POST['munka1_osszeg'])) : null;

    if ($nev === '') {
      $msg = '<div class="alert alert-danger">A projekt neve kötelező.</div>';
    } else {
      if ($id > 0) {
        $db->prepare('UPDATE projektek SET nev=?, leiras=?, munka1_osszeg=? WHERE id=?')
           ->execute([$nev, $leiras, $munka1_osszeg, $id]);
        $msg = '<div class="alert alert-success">Projekt frissítve.</div>';
      } else {
        $db->prepare('INSERT INTO projektek (nev,leiras,munka1_osszeg) VALUES (?,?,?)')
           ->execute([$nev, $leiras, $munka1_osszeg]);
        $msg = '<div class="alert alert-success">Projekt létrehozva.</div>';
      }
    }
  }

  if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
      $db->prepare('DELETE FROM projektek WHERE id=?')->execute([$id]);
      $msg = '<div class="alert alert-warning">Projekt törölve.</div>';
    }
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $s = $db->prepare('SELECT * FROM projektek WHERE id=?');
  $s->execute([intval($_GET['edit'])]);
  $edit = $s->fetch();
}

$projektek = $db->query('SELECT p.*, (SELECT COUNT(*) FROM tetelek t WHERE t.projekt_id=p.id) AS tetel_db FROM projektek p ORDER BY p.letrehozva DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MJ – Árajánlat készítő</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">MJ Árajánlat készítő</h4>
    <a href="egysegarak.php" class="btn btn-sm btn-outline-secondary">⚙ Szerződött egységárak</a>
  </div>

  <?= $msg ?>

  <!-- Projekt forma -->
  <div class="card mb-4">
    <div class="card-header"><?= $edit ? 'Projekt szerkesztése' : 'Új projekt' ?></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label">Projekt neve <span class="text-danger">*</span></label>
            <input type="text" name="nev" class="form-control" value="<?= htmlspecialchars($edit['nev'] ?? '') ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Leírás</label>
            <input type="text" name="leiras" class="form-control" value="<?= htmlspecialchars($edit['leiras'] ?? '') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Munka1 ref. összeg (Ft)</label>
            <input type="text" name="munka1_osszeg" class="form-control" value="<?= htmlspecialchars($edit['munka1_osszeg'] ?? '') ?>" placeholder="pl. 1434090">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><?= $edit ? 'Mentés' : 'Létrehozás' ?></button>
          <?php if ($edit): ?>
            <a href="index.php" class="btn btn-outline-secondary">Mégsem</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Projekt lista -->
  <div class="card">
    <div class="card-header">Projektek</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>Projekt neve</th>
            <th>Leírás</th>
            <th class="text-end">Ref. összeg</th>
            <th class="text-center">Tételek</th>
            <th>Létrehozva</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projektek as $p): ?>
          <tr>
            <td><a href="projekt.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($p['nev']) ?></a></td>
            <td class="text-muted small"><?= htmlspecialchars($p['leiras']) ?></td>
            <td class="text-end"><?= $p['munka1_osszeg'] !== null ? number_format($p['munka1_osszeg'], 0, ',', ' ').' Ft' : '<span class="text-muted">—</span>' ?></td>
            <td class="text-center"><?= $p['tetel_db'] ?> db</td>
            <td class="small text-muted"><?= substr($p['letrehozva'], 0, 10) ?></td>
            <td class="text-end">
              <a href="projekt.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-primary py-0">Megnyit</a>
              <a href="index.php?edit=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0">Szerk.</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd az egész projektet?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0">Törl.</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$projektek): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Még nincs projekt.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
