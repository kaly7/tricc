<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$id = (int)($_GET['id'] ?? 0);
$partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$imgw = trim($_GET['imgw'] ?? ''); // default image width for placeholders, e.g. '60mm' or '300px'

// Load template
$stmt = $pdo->prepare("SELECT * FROM templates WHERE id=:id");
$stmt->execute([':id'=>$id]);
$tpl = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$tpl){ http_response_code(404); echo "Sablon nem található."; exit; }
$html = (string)$tpl['content_html'];

// Load partner (fallback: first)
if ($partner_id <= 0) {
  $partner_id = (int)$pdo->query("SELECT id FROM partners ORDER BY id ASC LIMIT 1")->fetchColumn();
}
$partner = null;
if ($partner_id > 0) {
  $st = $pdo->prepare("SELECT * FROM partners WHERE id=:id");
  $st->execute([':id'=>$partner_id]);
  $partner = $st->fetch(PDO::FETCH_ASSOC);
}

// Load contacts for this partner
$contacts = [];
if ($partner) {
  $st = $pdo->prepare("SELECT * FROM partner_contacts WHERE partner_id=:pid ORDER BY is_primary DESC, id ASC");
  $st->execute([':pid'=>$partner['id']]);
  $contacts = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Load project (fallback: first)
if ($project_id <= 0) {
  $project_id = (int)$pdo->query("SELECT id FROM projects ORDER BY id ASC LIMIT 1")->fetchColumn();
}
$project = null;
if ($project_id > 0) {
  $st = $pdo->prepare("SELECT * FROM projects WHERE id=:id");
  $st->execute([':id'=>$project_id]);
  $project = $st->fetch(PDO::FETCH_ASSOC);
}

// Load images keyed by `key`
$images = [];
$st = $pdo->query("SELECT `key`, stored_name, title, alt_text, width, height, mime_type FROM images");
foreach($st as $row){ $images[$row['key']] = $row; }

// Helper to replace simple tags
function replace_simple($html, $map){
  foreach($map as $k=>$v){
    $html = str_replace('{{ '.$k.' }}', h($v), $html);
  }
  return $html;
}

// Build map for partner/project
$map = [];
if ($partner){
  $map['partner.megnevezes'] = $partner['megnevezes'] ?? '';
  $map['partner.cim_irsz'] = $partner['cim_irsz'] ?? '';
  $map['partner.cim_telepules'] = $partner['cim_telepules'] ?? '';
  $map['partner.cim_utca'] = $partner['cim_utca'] ?? '';
  $map['partner.cim_hazszam'] = $partner['cim_hazszam'] ?? '';
  $map['partner.cim_egyeb'] = $partner['cim_egyeb'] ?? '';
}
if ($project){
  $map['project.megnevezes'] = $project['megnevezes'] ?? '';
  $map['project.szam'] = $project['szam'] ?? '';
  $map['project.cim_irsz'] = $project['cim_irsz'] ?? '';
  $map['project.cim_telepules'] = $project['cim_telepules'] ?? '';
  $map['project.cim_utca'] = $project['cim_utca'] ?? '';
  $map['project.cim_hazszam'] = $project['cim_hazszam'] ?? '';
  $map['project.cim_egyeb'] = $project['cim_egyeb'] ?? '';
  $map['project.gps_lat'] = $project['gps_lat'] ?? '';
  $map['project.gps_lng'] = $project['gps_lng'] ?? '';
  $map['project.kezdo_datum'] = $project['kezdo_datum'] ?? '';
}

$html = replace_simple($html, $map);

// Contacts: {{ contact.nev }} or with index {{ contact.nev.2 }}
function replace_contacts($html, $contacts){
  // default (no index): use first (primary-first)
  $fields = ['nev','beosztas','telefon','email'];
  foreach($fields as $f){
    $val = isset($contacts[0][$f]) ? $contacts[0][$f] : '';
    $html = str_replace('{{ contact.'.$f.' }}', htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8'), $html);
  }
  // indexed
  $html = preg_replace_callback('/\{\{\s*contact\.(nev|beosztas|telefon|email)\.(\d+)\s*\}\}/u', function($m) use ($contacts){
    $field = $m[1]; $idx = max(1, (int)$m[2]); $i = $idx - 1;
    $val = isset($contacts[$i][$field]) ? $contacts[$i][$field] : '';
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
  }, $html);
  return $html;
}
$html = replace_contacts($html, $contacts);

// Images: {{ image.key }} or {{ image.key|w=40mm }} (optional h=...)
$defaultImgW = $imgw !== '' ? $imgw : '40mm';
$allowedUnits = ['mm','px','cm'];

$html = preg_replace_callback('/\{\{\s*image\.([a-z0-9_\-]+)(\|[^}]*)?\s*\}\}/i', function($m) use ($images, $defaultImgW, $allowedUnits){
  $key = $m[1];
  $optstr = isset($m[2]) ? $m[2] : '';
  $opts = [];
  if ($optstr) {
    // remove leading pipe
    $optstr = ltrim($optstr, '|');
    foreach (explode('|', $optstr) as $chunk){
      $chunk = trim($chunk);
      if ($chunk==='') continue;
      $kv = explode('=', $chunk, 2);
      if (count($kv)==2){
        $opts[strtolower(trim($kv[0]))] = trim($kv[1]);
      }
    }
  }
  if (!isset($images[$key])) {
    return '<span style="color:#a00; border:1px dashed #a00; padding:1px 3px;">[kép nem található: '.htmlspecialchars($key, ENT_QUOTES, 'UTF-8').']</span>';
  }
  $img = $images[$key];
  $src = 'uploads/' . rawurlencode($img['stored_name']);
  $alt = htmlspecialchars($img['alt_text'] ?? ($img['title'] ?? $key), ENT_QUOTES, 'UTF-8');
  $w = isset($opts['w']) ? $opts['w'] : $defaultImgW;
  $h = isset($opts['h']) ? $opts['h'] : 'auto';

  $sanitize = function($val, $fallback) use ($allowedUnits){
    if ($val==='auto') return 'auto';
    if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*(mm|px|cm)?\s*$/i', $val, $mm)){
      $num = $mm[1]; $unit = $mm[2] ?: 'px';
      if (!in_array(strtolower($unit), $allowedUnits, true)) $unit = 'px';
      return $num.$unit;
    }
    return $fallback;
  };

  $wcss = $sanitize($w, '40mm');
  $hcss = $sanitize($h, 'auto');
  $style = 'max-width:'.$wcss.'; height:auto;';
  if ($hcss !== 'auto') { $style = 'width:'.$wcss.'; height:'.$hcss.'; object-fit:contain;'; }

  // If non-image MIME, show a badge
  if (isset($img['mime_type']) && strpos($img['mime_type'], 'image/') !== 0) {
    return '<span style="display:inline-block;padding:2px 6px;border:1px solid #bbb;border-radius:6px;background:#f5f5f5;">[fájl: '.htmlspecialchars($img['title'] ?? $key, ENT_QUOTES, 'UTF-8').']</span>';
  }

  return '<img src="'.$src.'" alt="'.$alt.'" style="'.$style.'">';
}, $html);

// Output with page frame + toolbar
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Előnézet</title>
  <style>
    :root{
      --canvas:#f0f2f5;
      --border:#d8dde3;
      --shadow:0 6px 24px rgba(0,0,0,.08);
    }
    @page { size: A4 portrait; margin: 20mm; }
    body { background:var(--canvas); margin:0; font-family: system-ui, Segoe UI, Roboto, Arial, sans-serif; }
    .topbar{
      position:sticky; top:0; z-index:100;
      display:flex; justify-content:space-between; align-items:center;
      padding:10px 14px; background:#fff; border-bottom:1px solid var(--border);
      box-shadow: 0 2px 10px rgba(0,0,0,.03);
    }
    .btn{
      display:inline-block; padding:8px 12px; border:1px solid var(--border);
      border-radius:10px; text-decoration:none; color:#111; background:#fff; cursor:pointer;
      font-size:14px;
    }
    .btn.primary{ border-color:#254; background:#2c5; color:#fff; }
    .wrap{ padding:18px 0 36px; }
    .page{
      width:210mm; min-height:297mm; margin:0 auto; padding:20mm; box-sizing:border-box;
      background:#fff; border:1px solid var(--border); box-shadow: var(--shadow);
    }
    /* content defaults */
    h1,h2,h3 { margin: 0 0 8px; }
    p { margin: 0 0 8px; }
    table { border-collapse: collapse; width:100%; }
    th, td { border: 1px solid #ddd; padding: 4px 6px; }
    img { display:inline-block; }
    .meta { position:sticky; top:60px; right:0; text-align:right; margin:0 auto 8px; width:210mm; box-sizing:border-box; padding:0 20mm; color:#666; font-size:11px; }
    @media print {
      body { background:#fff; }
      .topbar, .meta { display:none !important; }
      .wrap { padding:0; }
      .page { border:none; box-shadow:none; width:auto; min-height:auto; padding:0; margin:0; }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div style="display:flex; gap:6px; align-items:center;">
      <!-- button class="btn" onclick="history.back()">← Vissza</button -->
      <button class="btn" onclick="window.close()">✖ Bezárás</button>
    </div>
    <div style="display:flex; gap:6px; align-items:center;">
      <button class="btn" onclick="print()">Nyomtatás</button>
    </div>
  </div>

  <div class="wrap">
    <div class="meta">Partner: <?= $partner ? h($partner['megnevezes']) : '—' ?> &nbsp;•&nbsp; Projekt: <?= $project ? h($project['megnevezes']) : '—' ?></div>
    <div class="page">
      <?= $html ?>
    </div>
  </div>
</body>
</html>
