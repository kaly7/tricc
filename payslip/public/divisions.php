<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

if (!Auth::isAdmin()) { http_response_code(403); echo "Forbidden"; exit; }

$pdo = Db::pdo();

function slugify_hu(string $s): string {
    $s = trim(mb_strtolower($s, 'UTF-8'));
    if (class_exists('Transliterator')) {
        $tr = Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        if ($tr) $s = $tr->transliterate($s);
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'divizio';
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') $err = 'A név kötelező.';
        else {
            $slug = slugify_hu($name);

            // ensure unique slug
            $base = $slug;
            $i = 2;
            while (true) {
                $st = $pdo->prepare("SELECT 1 FROM divisions WHERE slug=? LIMIT 1");
                $st->execute([$slug]);
                if (!$st->fetch()) break;
                $slug = $base . '-' . $i;
                $i++;
            }

            try {
                $st = $pdo->prepare("INSERT INTO divisions(name,slug,active) VALUES(?,?,1)");
                $st->execute([$name, $slug]);
                $msg = "Divízió hozzáadva: $name";
            } catch (Throwable $e) {
                $err = "Hiba: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['active'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE divisions SET active=? WHERE id=?")->execute([$active ? 1 : 0, $id]);
            $msg = "Mentve.";
        }
    }
}

$rows = $pdo->query("
  SELECT d.id,d.name,d.slug,d.active,
         (SELECT COUNT(*) FROM uploads u WHERE u.division_id=d.id) AS uploads_count
  FROM divisions d
  ORDER BY d.active DESC, d.name ASC
")->fetchAll();

page_header('Divíziók');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-4">
      <h1 class="h5 mb-3">Új divízió</h1>
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="action" value="add">
        <div class="mb-3">
          <label class="form-label">Divízió neve</label>
          <input class="form-control" name="name" placeholder="pl. Irodaház" required>
          <div class="form-text">A rendszer automatikusan készít egy “slug”-ot mappanévhez.</div>
        </div>
        <button class="btn btn-primary" type="submit">Hozzáadás</button>
        <a class="btn btn-outline-secondary" href="index.php">Vissza</a>
      </form>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card p-3">
      <h2 class="h6 mb-3">Divíziók</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th>Név</th><th>Slug</th><th>Aktív</th><th>Feltöltés</th><th></th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h($r['name']) ?></td>
                <td class="text-muted"><?= h($r['slug']) ?></td>
                <td>
                  <?php if ((int)$r['active'] === 1): ?>
                    <span class="badge bg-success">Igen</span>
                  <?php else: ?>
                    <span class="badge bg-secondary">Nem</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$r['uploads_count'] ?></td>
                <td class="text-end">
                  <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="active" value="<?= (int)$r['active'] ? 0 : 1 ?>">
                    <?php if ((int)$r['active'] === 1): ?>
                      <button class="btn btn-sm btn-outline-danger" type="submit">Letilt</button>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-primary" type="submit">Engedélyez</button>
                    <?php endif; ?>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="small text-muted mt-2">
        Törlés helyett “Letilt”-ot használunk, hogy régi feltöltések hivatkozása megmaradjon.
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>
