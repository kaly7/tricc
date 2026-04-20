<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

$rows = $pdo->query("SELECT b.*, p.megnevezes AS project_name FROM batches b LEFT JOIN projects p ON p.id=b.project_id ORDER BY b.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container">
  <div class="card hdr">
    <div><strong>Archívum</strong> — generált dokumentum csomagok</div>
    <div><a class="btn" href="index.php">← Főmenü</a> <a class="btn" href="generate.php">⚙ Generálás</a></div>
  </div>

  <div class="card">
    <table class="table">
      <thead><tr><th>#</th><th>Név</th><th>Projekt</th><th>Dátum</th><th>PDF</th><th>HTML</th><th>Művelet</th></tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?><br><small class="muted"><code><?= htmlspecialchars($r['slug']) ?></code></small></td>
            <td><?= htmlspecialchars($r['project_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
            <td><?php if($r['combined_pdf_path']): ?><a class="btn" target="_blank" href="<?= htmlspecialchars($r['combined_pdf_path']) ?>">Megnyitás</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td><?php if($r['combined_html_path']): ?><a class="btn" target="_blank" href="<?= htmlspecialchars($r['combined_html_path']) ?>">Megnyitás</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            <td>
              <form method="post" action="batch_delete.php" onsubmit="return confirm('Biztosan törlöd ezt a csomagot? Ez a fájlokat is törli.');" style="display:inline;">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn" type="submit">🗑 Törlés</button>
              </form>
              <a class="btn" href="batch_view.php?id=<?= (int)$r['id'] ?>">Részletek</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
