<?php
require __DIR__ . '/partials/header.php';
require_login($pdo);

$pp_statuses = get_pp_statuses($pdo);
$filters = [
  'pp_status_id' => $_GET['pp_status_id'] ?? '',
  'city_id' => $_GET['city_id'] ?? '',
  'eventus' => $_GET['eventus'] ?? '',
  'kiadva_from' => $_GET['kiadva_from'] ?? '',
  'kiadva_to' => $_GET['kiadva_to'] ?? '',
  'hatarido_from' => $_GET['hatarido_from'] ?? '',
  'hatarido_to' => $_GET['hatarido_to'] ?? '',
];

$where=[];$params=[];
if ($filters['pp_status_id']!==''){ $where[]="t.pp_status_id=?"; $params[]=$filters['pp_status_id']; }
if ($filters['city_id']!==''){ $where[]="t.city_id=?"; $params[]=$filters['city_id']; }
if ($filters['eventus']!==''){ $where[]="t.eventus=?"; $params[]=$filters['eventus']; }
if ($filters['kiadva_from']!==''){ $where[]="t.kiadva>=?"; $params[]=$filters['kiadva_from']; }
if ($filters['kiadva_to']!==''){ $where[]="t.kiadva<=?"; $params[]=$filters['kiadva_to']; }
if ($filters['hatarido_from']!==''){ $where[]="DATE_ADD(t.kiadva, INTERVAL 38 DAY)>=?"; $params[]=$filters['hatarido_from']; }
if ($filters['hatarido_to']!==''){ $where[]="DATE_ADD(t.kiadva, INTERVAL 38 DAY)<=?"; $params[]=$filters['hatarido_to']; }

$sql = "SELECT t.*, s.name AS pp_status_name, c.name AS city_name
        FROM tasks t
        LEFT JOIN pp_statuses s ON s.id=t.pp_status_id
        LEFT JOIN cities c ON c.id=t.city_id";
if ($where) $sql .= " WHERE ".implode(" AND ", $where);
$sql .= " ORDER BY COALESCE(t.vallalt_hatarido, DATE_ADD(t.kiadva, INTERVAL 38 DAY)) ASC, t.id DESC LIMIT 500";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll();
$cities = get_cities($pdo);
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Tételek</h1>
  <a class="btn btn-success" href="job_form.php">+ Új tétel</a>
</div>

<form class="row g-2 mb-3">
  <div class="col-md-2">
    <label class="form-label">PP státusz</label>
    <select class="form-select" name="pp_status_id">
      <option value="">-- mindegy --</option>
      <?php foreach ($pp_statuses as $opt): ?>
        <option value="<?= (int)$opt['id'] ?>" <?= $filters['pp_status_id']==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Település</label>
    <select class="form-select" name="city_id">
      <option value="">-- mindegy --</option>
      <?php foreach ($cities as $opt): ?>
        <option value="<?= (int)$opt['id'] ?>" <?= $filters['city_id']==$opt['id']?'selected':'' ?>><?= htmlspecialchars($opt['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label">Eventus</label>
    <input class="form-control" type="number" name="eventus" value="<?= htmlspecialchars($filters['eventus']) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Kiadva (tól)</label>
    <input class="form-control datepick" name="kiadva_from" value="<?= htmlspecialchars($filters['kiadva_from']) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Kiadva (ig)</label>
    <input class="form-control datepick" name="kiadva_to" value="<?= htmlspecialchars($filters['kiadva_to']) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Határidő (tól)</label>
    <input class="form-control datepick" name="hatarido_from" value="<?= htmlspecialchars($filters['hatarido_from']) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label">Határidő (ig)</label>
    <input class="form-control datepick" name="hatarido_to" value="<?= htmlspecialchars($filters['hatarido_to']) ?>">
  </div>
  <div class="col-md-2 align-self-end"><button class="btn btn-primary w-100">Szűrés</button></div>
  <div class="col-md-2 align-self-end"><a class="btn btn-outline-secondary w-100" href="jobs_list.php">Szűrők törlése</a></div>
</form>

<div class="table-responsive">
<table class="table table-sm align-middle">
  <thead><tr>
    <th>#</th><th>PP státusz</th><th>Kiadva</th><th>Határidő (38 nap)</th><th>Vállalt határidő</th>
    <th>Eventus</th><th>Település</th><th>Cím</th><th>Körzet</th><th>Elvégzendő</th><th>Művelet</th>
  <th>Munka leírása</th><th>Megjegyzés</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $color=row_color($r,$settings); $hatarido=(new DateTime($r['kiadva']))->modify('+38 day')->format('Y-m-d'); ?>
    <tr class="row-colored" style="<?= $color ? 'background-color: '.htmlspecialchars($color).';' : '' ?>">
      <td><?= (int)$r['id'] ?></td>
      <td><?= htmlspecialchars($r['pp_status_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['kiadva'] ?? '') ?></td>
      <td><?= htmlspecialchars($hatarido) ?></td>
      <td><?= htmlspecialchars($r['vallalt_hatarido'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['eventus'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['city_name'] ?? '') ?></td>
      <td><?= htmlspecialchars(trim(($r['irsz']? $r['irsz'].' ':'').($r['utca']??'').' '.($r['hazszam']??''))) ?></td>
      <td><?= htmlspecialchars($r['korzet'] ?? '') ?></td>
      <td><div class="cell-wrap" title="<?= htmlspecialchars($r['elvegzendo'] ?? '') ?>"><?= htmlspecialchars($r['elvegzendo'] ?? '') ?></div></td>
      <td class="cell-wrap"><?= nl2br(htmlspecialchars($r['leiras'] ?? '')) ?><span class="ms-1 note-icon" data-bs-toggle="tooltip" title="<?php $n=get_field_note($pdo,(int)$r['id'],'leiras'); echo htmlspecialchars($n['note_text']??''); ?>">🛈</span></td>
      <td class="cell-wrap"><?= nl2br(htmlspecialchars($r['megjegyzes'] ?? '')) ?><span class="ms-1 note-icon" data-bs-toggle="tooltip" title="<?php $n=get_field_note($pdo,(int)$r['id'],'megjegyzes'); echo htmlspecialchars($n['note_text']??''); ?>">🛈</span></td>
      <td>
        <a class="btn btn-sm btn-outline-primary" href="job_form.php?id=<?= (int)$r['id'] ?>">Szerkeszt</a>
        <a class="btn btn-sm btn-outline-secondary" href="job_history.php?id=<?= (int)$r['id'] ?>">Változástörténet</a>
        <a class="btn btn-sm btn-outline-danger" href="job_remove.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Biztosan törlöd?')">Töröl</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Jegyzet szerkesztő modal -->
<div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="note_save.php">
        <?= csrf_field() ?>
        <div class="modal-header">
          <h5 class="modal-title">Mező jegyzet</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="task_id" id="note_task_id">
          <input type="hidden" name="field" id="note_field">
          <div class="mb-3">
            <label class="form-label">Jegyzet</label>
            <textarea class="form-control" name="note_text" id="note_text" rows="5"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Mentés</button>
          <button type="button" class="btn btn-danger" id="note_delete_btn">Törlés</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Mégse</button>
        </div>
      </form>
      <form id="note_delete_form" method="post" action="note_delete.php" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="task_id" id="note_del_task_id">
        <input type="hidden" name="field" id="note_del_field">
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // assign task id to each row (first cell is id)
  document.querySelectorAll('table tbody tr').forEach(tr => {
    const idCell = tr.querySelector('td');
    if (idCell) tr.dataset.taskId = idCell.textContent.trim();
  });
  // click handler for icons
  document.querySelectorAll('.note-icon').forEach(icon => {
    icon.style.userSelect='none';
    icon.addEventListener('click', (e)=>{
      const tr = e.target.closest('tr');
      const taskId = tr?.dataset.taskId || '';
      const fieldCell = e.target.closest('td');
      const idx = Array.from(tr.children).indexOf(fieldCell);
      const headers = Array.from(document.querySelectorAll('thead th')).map(th=>th.textContent.trim());
      let field = 'note';
      const h = (headers[idx]||'').toLowerCase();
      if (h.includes('elvégzendő')) field='elvegzendo';
      else if (h.includes('munka leírása')) field='leiras';
      else if (h.includes('megjegyzés')) field='megjegyzes';

      const modalEl = document.getElementById('noteModal');
      const modal = new bootstrap.Modal(modalEl);
      document.getElementById('note_task_id').value = taskId;
      document.getElementById('note_field').value = field;
      document.getElementById('note_del_task_id').value = taskId;
      document.getElementById('note_del_field').value = field;
      // preload from tooltip
      const existing = e.target.getAttribute('title') || '';
      document.getElementById('note_text').value = existing;
      modal.show();
    });
  });
  document.getElementById('note_delete_btn')?.addEventListener('click', ()=>{
    document.getElementById('note_delete_form').submit();
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Rebind note icon clicks with robust data attributes
  document.querySelectorAll('.note-icon').forEach(icon => {
    const clone = icon.cloneNode(true);
    icon.parentNode.replaceChild(clone, icon);
  });
  document.querySelectorAll('.note-icon').forEach(icon => {
    icon.addEventListener('click', (e)=>{
      const tr = e.target.closest('tr');
      const taskId = tr?.dataset.taskId || '';
      const field = e.target.getAttribute('data-field') || '';
      const modal = new bootstrap.Modal(document.getElementById('noteModal'));
      document.getElementById('note_task_id').value = taskId;
      document.getElementById('note_field').value = field;
      document.getElementById('note_del_task_id').value = taskId;
      document.getElementById('note_del_field').value = field;
      const existing = e.target.getAttribute('title') || '';
      document.getElementById('note_text').value = existing;
      modal.show();
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

