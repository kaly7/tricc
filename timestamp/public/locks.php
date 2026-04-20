<?php
require_once __DIR__ . '/../functions.php';
require_login(); require_admin();
$error=null; $ok=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $start=parse_date($_POST['start']??''); $end=parse_date($_POST['end']??'');
    if (!$start || !$end || $start>$end){ $error='Érvénytelen intervallum.'; }
    else {
        db()->prepare("INSERT INTO lock_intervals (start_date, end_date, locked_by) VALUES (:s,:e,:u)")
          ->execute([':s'=>$start, ':e'=>$end, ':u'=>current_user()['id']]);
        $ok='Intervallum lezárva.';
    }
}
if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['del'])) {
    $id=(int)$_GET['del']; db()->prepare("DELETE FROM lock_intervals WHERE id=:id")->execute([':id'=>$id]); redirect('/locks.php');
}
$locks=db()->query("SELECT li.*, u.username FROM lock_intervals li LEFT JOIN users u ON u.id=li.locked_by ORDER BY li.start_date DESC")->fetchAll();
include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Lezárások</h2>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="notice"><?= h($ok) ?></div><?php endif; ?>
  <form method="post" class="flex" style="align-items:flex-end;">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <div><label>Kezdet</label><input type="date" name="start" required></div>
    <div><label>Vég</label><input type="date" name="end" required></div>
    <div><button>Lezár</button></div>
  </form>
  <h3 class="mt16">Lezárt intervallumok</h3>
  <div class="table-container"><table class="table">
    <thead><tr><th>Kezdet</th><th>Vég</th><th>Lezárta</th><th>Időpont</th><th>Művelet</th></tr></thead>
    <tbody>
      <?php foreach ($locks as $l): ?>
        <tr>
          <td data-label="Kezdet"><?= h($l['start_date']) ?></td>
          <td data-label="Vég"><?= h($l['end_date']) ?></td>
          <td data-label="Lezárta"><?= h($l['username'] ?? '—') ?></td>
          <td data-label="Időpont"><?= h($l['locked_at']) ?></td>
          <td data-label="Művelet"><a class="btn secondary" href="?del=<?= (int)$l['id'] ?>" onclick="return confirm('Törlöd a lezárást?')">Törlés</a></td>
        </tr>
      <?php endforeach; if(!$locks): ?><tr><td colspan="5">Nincs lezárás.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>
