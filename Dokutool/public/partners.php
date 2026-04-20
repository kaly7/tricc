<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

$err = null; $ok = null;
if (($_POST['action'] ?? '') === 'delete') {
  try {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $stmt = $pdo->prepare("DELETE FROM partners WHERE id = :id");
      $stmt->execute([':id' => $id]);
      $ok = "Partner törölve.";
    }
  } catch (Throwable $e) { $err = "Törlési hiba: " . $e->getMessage(); }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
  $stmt = $pdo->prepare("SELECT * FROM partners WHERE megnevezes LIKE :q ORDER BY id DESC");
  $stmt->execute([':q' => '%' . $q . '%']);
} else {
  $stmt = $pdo->query("SELECT * FROM partners ORDER BY id DESC");
}
$rows = $stmt->fetchAll();
?>
  <div class="container">
    <div class="card hdr">
      <form method="get" action="partners.php" style="display:flex; gap:8px;">
        <div class="input">
          <label>Keresés</label>
          <input type="text" name="q" value="<?=htmlspecialchars($q)?>" placeholder="Megnevezés...">
        </div>
        <div style="align-self:flex-end;">
          <button class="btn" type="submit">Keres</button>
          <a class="btn ghost" href="partners.php">Minden</a>
        </div>
      </form>

	<div style="display:flex; gap:8px; align-items:center;">
	    <a class="btn" href="index.php">← Főmenü</a>
	    <a class="btn" href="fields.php">Partner mezők</a>
	    <a class="btn primary" href="partner_edit.php">+ Új felvitel</a>
	</div>
    </div>

    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>

    <div class="card">
      <table class="table">
        <thead><tr><th>ID</th><th>Megnevezés</th><th>Cím</th><th style="width:200px;">Művelet</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): $cim = trim(($r['cim_irsz'] ?? '').' '.($r['cim_telepules'] ?? '').' '.($r['cim_utca'] ?? '').' '.($r['cim_hazszam'] ?? '')); ?>
          <tr>
            <td><?=$r['id']?></td>
            <td><?=htmlspecialchars($r['megnevezes'])?></td>
            <td><?=htmlspecialchars($cim)?></td>
            <td>
              <div class="toolbar">
                <a class="btn" href="partner_edit.php?id=<?=$r['id']?>">Szerkeszt</a>
                <form method="post" action="partners.php" onsubmit="return confirm('Biztosan törlöd?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=$r['id']?>">
                  <button class="btn ghost" type="submit">Törlés</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($rows)): ?><tr><td colspan="4"><em>Nincs találat.</em></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php include __DIR__ . '/footer.php'; ?>
