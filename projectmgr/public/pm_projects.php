<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();
$uid = (int)Auth::user()['id'];

// Query params
$show_archived = isset($_GET['archived']) && $_GET['archived']=='1';
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'date'; // date|name
$dir  = strtolower($_GET['dir'] ?? 'desc'); // asc|desc

$validSort = ['date','name'];
if (!in_array($sort, $validSort, true)) $sort = 'date';
$dir = $dir==='asc' ? 'asc' : 'desc';

// Build WHERE and ORDER
$where = ' WHERE 1=1 ';
$params = [];
if (!$show_archived) { $where .= ' AND p.archived = 0 '; }
if ($q !== '') {
  $where .= ' AND (p.name LIKE ? OR p.description LIKE ? OR p.number LIKE ?) ';
  $like = '%'.$q.'%';
  $params[] = $like; $params[] = $like; $params[] = $like;
}
$order = ' ORDER BY ';
if ($sort==='name') {
  $order .= ' p.name '.$dir.', p.id DESC ';
} else {
  $order .= ' p.start_date '.$dir.', p.id '.$dir.' ';
}

// Fetch projects
$sql = 'SELECT p.id, p.number, p.name, p.description, p.archived, p.start_date FROM projects p '.$where.$order;
$st = $pdo->prepare($sql);
$st->execute($params);
$projects = $st->fetchAll(PDO::FETCH_ASSOC);

// Unread map
$seen = $pdo->prepare('SELECT project_id, last_seen_id FROM chat_last_seen WHERE user_id=?');
$seen->execute([$uid]);
$seenMap = [];
foreach ($seen->fetchAll(PDO::FETCH_ASSOC) as $s) { $seenMap[(string)$s['project_id']] = (int)$s['last_seen_id']; }

$latest = $pdo->query('SELECT project_id, MAX(id) AS max_id FROM project_messages WHERE project_id IS NOT NULL GROUP BY project_id')->fetchAll(PDO::FETCH_ASSOC);
$latestMap = [];
foreach ($latest as $r) { $latestMap[(string)$r['project_id']] = (int)$r['max_id']; }

function sort_link($key, $label) {
  $curSort = $_GET['sort'] ?? 'date';
  $curDir  = strtolower($_GET['dir'] ?? 'desc');
  $nextDir = ($curSort===$key && $curDir==='asc') ? 'desc' : 'asc';
  $qs = $_GET;
  $qs['sort'] = $key;
  $qs['dir'] = $nextDir;
  $href = '/pm_projects.php?'.http_build_query($qs);
  $arrow = '';
  if ($curSort===$key) { $arrow = $curDir==='asc' ? '▲' : '▼'; }
  return '<a href="'.htmlspecialchars($href).'" class="text-decoration-none">'.$label.' '.$arrow.'</a>';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Projektek</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="/pm_dir_template.php">Könyvtárséma</a>
    <a class="btn btn-outline-info" href="/pm_chat.php">Globális üzenőfal</a>
    <a class="btn btn-primary" href="/pm_project_create.php">Új projekt</a>
  </div>
</div>

<div class="card p-3 mb-3">
  <form method="get" class="row g-2">
    <div class="col-md-6">
      <label class="form-label">Keresés</label>
      <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="Név, leírás vagy projektszám…">
    </div>
    <div class="col-md-3">
      <label class="form-label">Rendezés</label>
      <select name="sort" class="form-select">
        <option value="date" <?= $sort==='date'?'selected':''; ?>>Dátum</option>
        <option value="name" <?= $sort==='name'?'selected':''; ?>>Név</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Irány</label>
      <select name="dir" class="form-select">
        <option value="desc" <?= $dir==='desc'?'selected':''; ?>>Csökkenő</option>
        <option value="asc" <?= $dir==='asc'?'selected':''; ?>>Növekvő</option>
      </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" value="1" id="archived" name="archived" <?= $show_archived?'checked':''; ?>>
        <label class="form-check-label" for="archived">Archiv</label>
      </div>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Szűrés</button>
      <a class="btn btn-outline-secondary" href="/pm_projects.php">Törlés</a>
    </div>
  </form>
</div>

<div class="card p-0">
  <table class="table table-striped m-0 align-middle">
    <thead><tr>
      <th>#</th>
      <th><?= sort_link('name','Név') ?></th>
      <th>Leírás</th>
      <th><?= sort_link('date','Indítás dátuma') ?></th>
      <th>Chat</th>
      <th>Műveletek</th>
    </tr></thead>
    <tbody>
      <?php foreach($projects as $p):
        $pid = (int)$p['id'];
        $maxId = $latestMap[(string)$pid] ?? 0;
        $seenId = $seenMap[(string)$pid] ?? 0;
        $unread = max(0, $maxId - $seenId);
        $desc = trim((string)($p['description'] ?? ''));
      ?>
        <tr>
          <td><?= htmlspecialchars($p['number']) ?></td>
          <td><?= htmlspecialchars($p['name']) ?> <?= ((int)$p['archived']===1)?'<span class="badge bg-secondary ms-1">archiv</span>':'' ?></td>
          <td>
            <?php if ($desc!==''): ?>
              <a href="#" class="btn btn-sm btn-outline-secondary" title="Leírás megnyitása"
                 onclick='openDesc(<?= json_encode((string)$p["name"]) ?>, <?= json_encode($desc) ?>); return false;'>ℹ️</a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($p['start_date'] ?? '') ?></td>
          <td>
            <a href="/pm_chat.php?id=<?= $pid ?>" class="btn btn-sm btn-outline-primary position-relative">
              Chat
              <?php if ($unread>0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $unread ?></span>
              <?php endif; ?>
            </a>
          </td>
          <td class="text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="/pm_project_edit.php?id=<?= $pid ?>">Szerkesztés</a>
            <a class="btn btn-sm btn-outline-success" href="/pm_files.php?id=<?= $pid ?>">Fájlok</a>
          </td>
        </tr>
      <?php endforeach; if (!$projects): ?>
        <tr><td colspan="6" class="text-muted">Nincs találat.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Leírás modal -->
<div class="modal fade" id="descModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="descTitle">Projekt leírás</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
      </div>
      <div class="modal-body">
        <pre id="descBody" style="white-space: pre-wrap; font-family: inherit;"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bezár</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Robust modal open/close that also works without Bootstrap JS
  const m = document.getElementById('descModal');
  const body = document.getElementById('descBody');
  const title = document.getElementById('descTitle');

  window.openDesc = function(titleText, descText){
    title.textContent = titleText || 'Projekt leírás';
    body.textContent = descText || '';

    if (window.bootstrap && bootstrap.Modal) {
      bootstrap.Modal.getOrCreateInstance(m).show();
    } else {
      // Fallback show
      m.style.display = 'block';
      m.classList.add('show');
      m.removeAttribute('aria-hidden');
      m.setAttribute('aria-modal','true');
      // add basic backdrop
      if (!document.getElementById('modalBackdrop')) {
        const back = document.createElement('div');
        back.id = 'modalBackdrop';
        back.className = 'modal-backdrop fade show';
        document.body.appendChild(back);
      }
      document.body.classList.add('modal-open');
    }
  };

  function closeFallback(){
    // Fallback close
    m.classList.remove('show');
    m.style.display = 'none';
    m.setAttribute('aria-hidden','true');
    m.removeAttribute('aria-modal');
    const back = document.getElementById('modalBackdrop');
    if (back) back.remove();
    document.body.classList.remove('modal-open');
  }

  // Close handlers for fallback if Bootstrap is not present
  document.addEventListener('click', function(e){
    const target = e.target;
    if (target && target.matches('[data-bs-dismiss="modal"]')) {
      if (!(window.bootstrap && bootstrap.Modal)) {
        e.preventDefault();
        closeFallback();
      }
    }
  });

  // ESC key closes in fallback
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && m.classList.contains('show') && !(window.bootstrap && bootstrap.Modal)) {
      closeFallback();
    }
  });
})();
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>
