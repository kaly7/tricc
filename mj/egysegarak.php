<?php
require_once __DIR__.'/db.php';
$db = db();

$msg = '';

// --- Mentés ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $id         = intval($_POST['id'] ?? 0);
    $sorsz      = intval($_POST['sorsz'] ?? 0);
    $megnevezes = trim($_POST['megnevezes'] ?? '');
    $egyseg     = trim($_POST['egyseg'] ?? 'klt');
    $egyseg_dij = floatval(str_replace([' ', "\xc2\xa0", ','], ['', '', '.'], $_POST['egyseg_dij'] ?? '0'));
    $megjegyzes = trim($_POST['megjegyzes'] ?? '');

    if ($megnevezes === '') {
      $msg = '<div class="alert alert-danger">A megnevezés kötelező.</div>';
    } else {
      if ($id > 0) {
        $db->prepare('UPDATE egysegarak SET sorsz=?, megnevezes=?, egyseg=?, egyseg_dij=?, megjegyzes=? WHERE id=?')
           ->execute([$sorsz, $megnevezes, $egyseg, $egyseg_dij, $megjegyzes, $id]);
        $msg = '<div class="alert alert-success">Tétel frissítve.</div>';
      } else {
        $db->prepare('INSERT INTO egysegarak (sorsz,megnevezes,egyseg,egyseg_dij,megjegyzes) VALUES (?,?,?,?,?)')
           ->execute([$sorsz, $megnevezes, $egyseg, $egyseg_dij, $megjegyzes]);
        $msg = '<div class="alert alert-success">Új tétel hozzáadva.</div>';
      }
    }
  }

  if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
      $db->prepare('DELETE FROM egysegarak WHERE id=?')->execute([$id]);
      $msg = '<div class="alert alert-warning">Tétel törölve.</div>';
    }
  }
}

// Szerkesztendő tétel
$edit = null;
if (isset($_GET['edit'])) {
  $edit = $db->prepare('SELECT * FROM egysegarak WHERE id=?');
  $edit->execute([intval($_GET['edit'])]);
  $edit = $edit->fetch();
}

$tetelek = $db->query('SELECT * FROM egysegarak ORDER BY sorsz, megnevezes')->fetchAll();
?>
<?php $title = 'MJ – Egységárak'; require __DIR__.'/_header.php'; ?>
<div class="container py-2">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Szerződött egységárak</h4>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">← Projektek</a>
  </div>

  <?= $msg ?>

  <!-- Forma -->
  <div class="card mb-4">
    <div class="card-header"><?= $edit ? 'Tétel szerkesztése' : 'Új tétel hozzáadása' ?></div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="row g-2">
          <div class="col-md-1">
            <label class="form-label">Sorsz.</label>
            <input type="number" name="sorsz" class="form-control" value="<?= htmlspecialchars($edit['sorsz'] ?? '') ?>">
          </div>
          <div class="col-md-5">
            <label class="form-label">Megnevezés <span class="text-danger">*</span></label>
            <input type="text" name="megnevezes" class="form-control" value="<?= htmlspecialchars($edit['megnevezes'] ?? '') ?>" required>
          </div>
          <div class="col-md-1">
            <label class="form-label">Egység</label>
            <input type="text" name="egyseg" class="form-control" value="<?= htmlspecialchars($edit['egyseg'] ?? 'klt') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Egységdíj (Ft)</label>
            <input type="text" name="egyseg_dij" class="form-control" value="<?= htmlspecialchars($edit['egyseg_dij'] ?? '0') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Megjegyzés</label>
            <input type="text" name="megjegyzes" class="form-control" value="<?= htmlspecialchars($edit['megjegyzes'] ?? '') ?>">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><?= $edit ? 'Mentés' : 'Hozzáadás' ?></button>
          <?php if ($edit): ?>
            <a href="egysegarak.php" class="btn btn-outline-secondary">Mégsem</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Lista -->
  <div class="card">
    <div class="card-header">Tételek (<?= count($tetelek) ?> db)</div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Megnevezés</th>
            <th>Egység</th>
            <th class="text-end">Egységdíj</th>
            <th>Megjegyzés</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tetelek as $t): ?>
          <tr>
            <td><?= $t['sorsz'] ?></td>
            <td><?= htmlspecialchars($t['megnevezes']) ?></td>
            <td><?= htmlspecialchars($t['egyseg']) ?></td>
            <td class="text-end"><?= number_format($t['egyseg_dij'], 0, ',', ' ') ?> Ft</td>
            <td class="text-muted small"><?= htmlspecialchars($t['megjegyzes']) ?></td>
            <td class="text-end">
              <a href="egysegarak.php?edit=<?= $t['id'] ?>" class="btn btn-xs btn-outline-primary btn-sm py-0">Szerk.</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-xs btn-outline-danger btn-sm py-0">Törl.</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$tetelek): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Még nincs tétel.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php require __DIR__.'/_footer.php'; ?>
