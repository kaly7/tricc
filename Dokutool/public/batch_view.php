<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
require __DIR__ . '/../app/render.php';
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int)($_GET['id'] ?? 0);
if ($id<=0){ die('Hibás azonosító'); }

$batch = $pdo->prepare("SELECT * FROM batches WHERE id=:id");
$batch->execute([':id'=>$id]);
$batch = $batch->fetch(PDO::FETCH_ASSOC);
if (!$batch){ die('Csomag nem található'); }

$items = $pdo->prepare("
  SELECT bi.*, p.megnevezes AS partner_nev, t.name AS tpl_nev
  FROM batch_items bi
  JOIN partners p ON p.id = bi.partner_id
  JOIN templates t ON t.id = bi.template_id
  WHERE bi.batch_id = :b
  ORDER BY p.megnevezes, t.name
");
$items->execute([':b'=>$id]);
$rows = $items->fetchAll(PDO::FETCH_ASSOC);

// csoportosítás partnerenként
$byPartner = [];
foreach($rows as $r){ $byPartner[$r['partner_nev']][] = $r; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$sent = isset($_GET['sent']) ? (int)$_GET['sent'] : null;
$msg  = isset($_GET['msg']) ? (string)$_GET['msg'] : '';
?>
<style>
/* ── Email-küldés overlay ───────────────────────────────────────────── */
#mailOverlay{
  position:fixed; inset:0; background:rgba(255,255,255,.92);
  display:none; align-items:center; justify-content:center; z-index:9999;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
}
#mailOverlay .box{
  background:#fff; border:1px solid #ddd; border-radius:12px;
  padding:20px 26px; box-shadow:0 12px 32px rgba(0,0,0,.1); text-align:center;
}
.loader{
  border:6px solid #eee; border-top:6px solid #09f; border-radius:50%;
  width:54px; height:54px; animation:spin 1s linear infinite; margin:0 auto 12px;
}
@keyframes spin{100%{transform:rotate(360deg)}}
.notice{padding:12px 14px;border:1px solid #cde5cd;background:#effaf0;border-radius:10px;margin:10px 0;color:#245d24;}
.notice.err{border-color:#f0c7c7;background:#fff2f2;color:#8a1f1f;}
</style>

<div class="container">
  <div class="card hdr">
    <div><strong>Generált csomag:</strong> <?= h($batch['name']) ?></div>
    <div><a class="btn" href="batches.php">← Archívum</a> <a class="btn" href="index.php">Főmenü</a></div>
  </div>

  <?php if ($sent !== null): ?>
    <div class="notice"><?= $sent ?> tétel megjelölve elküldöttként.</div>
  <?php endif; ?>
  <?php if ($msg === 'empty'): ?>
    <div class="notice err">Nincs küldhető tétel.</div>
  <?php endif; ?>

  <div class="card">
    <div class="grid cols-3">
      <div><strong>Projekt ID:</strong> <?= (int)$batch['project_id'] ?></div>
      <div><strong>Combined HTML:</strong>
        <?php if(!empty($batch['combined_html_path'])): ?>
          <a class="btn" target="_blank" href="<?= h($batch['combined_html_path']) ?>">Megnyitás</a>
        <?php else: ?>—<?php endif; ?>
      </div>
      <div><strong>Combined PDF:</strong>
        <?php if(!empty($batch['combined_pdf_path'])): ?>
          <a class="btn" target="_blank" href="<?= h($batch['combined_pdf_path']) ?>">Letöltés</a>
        <?php else: ?>—<?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Email küldés</h3>
    <div class="grid cols-2">
      <!-- Összes partner -->
      <form class="send-form" method="post" action="send_emails.php"
            onsubmit="return confirm('Biztosan küldöd az ÖSSZES partnernek?');">
        <input type="hidden" name="batch_id" value="<?= (int)$id ?>">
        <button class="btn primary" type="submit">✉ Küldés MINDENKINEK</button>
      </form>
      <a class="btn" href="generate.php">Új generálás</a>
    </div>
    <small class="muted">A küldés a csomagban eltárolt email-beállításokkal történik. Partnerekre bontva lejjebb is indítható.</small>
  </div>

  <?php foreach($byPartner as $pnev => $items): ?>
  <div class="card">
    <div class="grid cols-2" style="align-items:center;">
      <h3 style="margin:0;"><?= h($pnev) ?></h3>
      <!-- Adott partner -->
      <form class="send-form" method="post" action="send_emails.php"
            onsubmit="return confirm('Küldés ehhez a partnerhez?');" style="text-align:right;">
        <input type="hidden" name="batch_id" value="<?= (int)$id ?>">
        <input type="hidden" name="partner_name" value="<?= h($pnev) ?>">
        <button class="btn" type="submit">✉ Küldés ehhez a partnerhez</button>
      </form>
    </div>
    <table class="table">
      <thead><tr><th>Sablon</th><th>HTML</th><th>PDF</th><th>Küldve</th></tr></thead>
      <tbody>
      <?php foreach($items as $r): ?>
        <tr>
          <td><?= h($r['tpl_nev']) ?></td>
          <td>
            <?php if(!empty($r['item_html_path'])): ?>
              <a class="btn" target="_blank" href="<?= h($r['item_html_path']) ?>">Megnyitás</a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php if(!empty($r['item_pdf_path'])): ?>
              <a class="btn" target="_blank" href="<?= h($r['item_pdf_path']) ?>">Letöltés</a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?= h($r['sent_at'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
</div>

<!-- Overlay -->
<div id="mailOverlay">
  <div class="box">
    <div class="loader"></div>
    <div>Email-ek küldése folyamatban...</div>
    <div class="progress">Kérjük, ne zárd be ezt az ablakot.</div>
  </div>
</div>

<script>
// Minden küldő űrlap submitjára felugrik az overlay, amíg a szerver dolgozik.
document.querySelectorAll('form.send-form').forEach(function(f){
  f.addEventListener('submit', function(){
    var ov = document.getElementById('mailOverlay');
    if (ov) ov.style.display = 'flex';
  });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>