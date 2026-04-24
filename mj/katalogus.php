<?php
require_once __DIR__.'/db.php';
$db = db();

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
      $db->prepare('DELETE FROM anyagar_katalogus WHERE id=?')->execute([$id]);
      $msg = '<div class="alert alert-warning">Tétel törölve.</div>';
    }
  }

  if ($action === 'save') {
    $id      = intval($_POST['id'] ?? 0);
    $nev     = trim($_POST['megnevezes'] ?? '');
    $gyarto  = trim($_POST['gyarto'] ?? '');
    $tipus   = trim($_POST['tipus'] ?? '');
    $rendszam= trim($_POST['rendeles_szam'] ?? '');
    $egyseg  = trim($_POST['egyseg'] ?? 'db');
    $anyagar = floatval(str_replace([' ',"\xc2\xa0",','],['','','.'], $_POST['anyagar_egyseg'] ?? '0'));
    $munka   = floatval(str_replace([' ',"\xc2\xa0",','],['','','.'], $_POST['munkadij_egyseg'] ?? '0'));
    if ($nev === '') {
      $msg = '<div class="alert alert-danger">A megnevezés kötelező.</div>';
    } elseif ($id > 0) {
      $db->prepare('UPDATE anyagar_katalogus SET megnevezes=?,gyarto=?,tipus=?,rendeles_szam=?,egyseg=?,anyagar_egyseg=?,munkadij_egyseg=? WHERE id=?')
         ->execute([$nev,$gyarto,$tipus,$rendszam,$egyseg,$anyagar,$munka,$id]);
      $msg = '<div class="alert alert-success">Tétel frissítve.</div>';
    }
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $s = $db->prepare('SELECT * FROM anyagar_katalogus WHERE id=?');
  $s->execute([intval($_GET['edit'])]);
  $edit = $s->fetch();
}

$q = trim($_GET['q'] ?? '');
$tetelek = $db->prepare('SELECT * FROM anyagar_katalogus WHERE megnevezes LIKE ? ORDER BY megnevezes LIMIT 500');
$tetelek->execute(['%'.$q.'%']);
$tetelek = $tetelek->fetchAll();
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MJ – Anyagár katalógus</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container-fluid py-3 px-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Anyagár katalógus <span class="badge bg-secondary"><?= count($tetelek) ?> tétel</span></h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">← Projektek</a>
  </div>

  <?= $msg ?>

  <?php if ($edit): ?>
  <div class="card mb-3">
    <div class="card-header">Tétel szerkesztése</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?>">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label small">Megnevezés</label>
            <input type="text" name="megnevezes" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['megnevezes']) ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Gyártó</label>
            <input type="text" name="gyarto" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['gyarto']) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label small">Típus</label>
            <input type="text" name="tipus" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['tipus']) ?>">
          </div>
          <div class="col-md-1">
            <label class="form-label small">Egység</label>
            <input type="text" name="egyseg" class="form-control form-control-sm" value="<?= htmlspecialchars($edit['egyseg']) ?>">
          </div>
          <div class="col-md-1">
            <label class="form-label small">Anyagár/e</label>
            <input type="text" name="anyagar_egyseg" class="form-control form-control-sm" value="<?= $edit['anyagar_egyseg'] ?>">
          </div>
          <div class="col-md-1">
            <label class="form-label small">Munkadíj/e</label>
            <input type="text" name="munkadij_egyseg" class="form-control form-control-sm" value="<?= $edit['munkadij_egyseg'] ?>">
          </div>
          <div class="col-md-1 d-flex align-items-end gap-1">
            <button type="submit" class="btn btn-primary btn-sm">Ment</button>
            <a href="katalogus.php" class="btn btn-outline-secondary btn-sm">✕</a>
          </div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Keresés -->
  <form method="get" class="mb-3 d-flex gap-2" style="max-width:400px">
    <input type="text" name="q" class="form-control form-control-sm" placeholder="Keresés…" value="<?= htmlspecialchars($q) ?>">
    <button class="btn btn-outline-secondary btn-sm">🔍</button>
    <?php if ($q): ?><a href="katalogus.php" class="btn btn-outline-secondary btn-sm">✕</a><?php endif; ?>
  </form>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.88em">
        <thead class="table-dark">
          <tr>
            <th>Megnevezés</th>
            <th>Gyártó</th>
            <th>Típus</th>
            <th>Rend. szám</th>
            <th>Egység</th>
            <th class="text-end">Anyagár/e</th>
            <th class="text-end">Munkadíj/e</th>
            <th>Frissítve</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tetelek as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['megnevezes']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($t['gyarto']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($t['tipus']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($t['rendeles_szam']) ?></td>
            <td><?= htmlspecialchars($t['egyseg']) ?></td>
            <td class="text-end"><?= number_format($t['anyagar_egyseg'],0,',', ' ') ?> Ft</td>
            <td class="text-end"><?= number_format($t['munkadij_egyseg'],0,',', ' ') ?> Ft</td>
            <td class="text-muted small"><?= substr($t['frissitve'],0,10) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <a href="katalogus.php?edit=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm py-0">✎</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm py-0">✕</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$tetelek): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">Nincs tétel<?= $q ? ' a keresési feltételre' : '. Mentsd el egy projekt anyagárait a Katalógus gombbal.' ?>.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
