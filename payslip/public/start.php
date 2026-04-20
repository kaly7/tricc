<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) { header('Location: log.php'); exit; }

$cmd = '/usr/bin/php ' . escapeshellarg(APP_ROOT . '/worker/process_upload.php') . ' ' . escapeshellarg((string)$uploadId);
exec($cmd . ' > /dev/null 2>&1 &');

page_header('Feldolgozás');
?>
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card p-4">
      <h1 class="h5 mb-2">Feldolgozás elindítva</h1>
      <div id="p" class="text-muted">Progress betöltése...</div>

      <div class="d-flex gap-2 mt-3">
        <a class="btn btn-outline-primary" href="log.php?upload_id=<?= (int)$uploadId ?>">Log megnyitása</a>
        <a class="btn btn-outline-secondary" href="index.php">Főoldal</a>
      </div>
    </div>
  </div>
</div>

<script>
async function poll(){
  const r = await fetch('progress.php?upload_id=<?= (int)$uploadId ?>');
  const j = await r.json();
  if (j.error && typeof j.error === 'string') { document.getElementById('p').innerText = j.error; return; }
  const pct = j.total > 0 ? Math.round((j.done / j.total) * 100) : 0;
  document.getElementById('p').innerHTML =
    `<div class="mt-2">
       <span class="badge bg-primary">${j.done}/${j.total}</span>
       <span class="text-muted ms-2">${pct}%</span>
     </div>
     <div class="small text-muted mt-2">
       Mentve: ${j.saved} · Küldve: ${j.mailed} · Nincs egyezés: ${j.no_match} · Hiba: ${j.error} · current: ${j.current_page}
     </div>`;
  if (j.running) setTimeout(poll, 1200);
}
poll();
</script>
<?php page_footer(); ?>
