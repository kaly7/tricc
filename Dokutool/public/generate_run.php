<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
require __DIR__ . '/../app/render.php';
require __DIR__ . '/../app/fonts_embed.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!function_exists('base_url')) {
  function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}
function have_bin($bin){
  $out = @shell_exec('which '.escapeshellarg($bin).' 2>/dev/null');
  return trim((string)$out) !== '';
}

$batch_name = trim($_POST['batch_name'] ?? '');
$template_ids = $_POST['template_ids'] ?? [];
$partner_ids = $_POST['partner_ids'] ?? [];
$project_id = (int)($_POST['project_id'] ?? 0);
$imgw = trim($_POST['imgw'] ?? '40mm');
$renderer = $_POST['renderer'] ?? 'dompdf';
$pagebreak = $_POST['pagebreak'] ?? 'always';

// email settings
$email_per_partner = isset($_POST['email_per_partner']) ? 1 : 0;
$recipient_mode = $_POST['recipient_mode'] ?? 'contacts_all';
$extra_to = trim($_POST['extra_to'] ?? '');
$extra_cc = trim($_POST['extra_cc'] ?? '');
$extra_bcc = trim($_POST['extra_bcc'] ?? '');
$email_subject_tpl = trim($_POST['email_subject'] ?? '');
$email_body_tpl = trim($_POST['email_body'] ?? '');

if ($batch_name==='' || empty($template_ids) || empty($partner_ids) || $project_id<=0){
  die('Hiányzó paraméter.');
}

// Ensure schema
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS email_subject VARCHAR(255) NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS email_body TEXT NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS recipient_mode VARCHAR(32) NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS extra_to TEXT NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS extra_cc TEXT NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS extra_bcc TEXT NULL");
$pdo->exec("ALTER TABLE batches ADD COLUMN IF NOT EXISTS email_per_partner TINYINT(1) NULL");
$pdo->exec("ALTER TABLE batch_items ADD COLUMN IF NOT EXISTS item_pdf_path VARCHAR(255) NULL");
$pdo->exec("ALTER TABLE batch_items ADD COLUMN IF NOT EXISTS sent_at DATETIME NULL");

function slugify($name){
  $name = trim($name);
  if ($name==='') return 'batch-'.date('Ymd-His');
  $ascii = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);
  $ascii = $ascii !== false ? $ascii : $name;
  $slug = strtolower(preg_replace('/[^a-z0-9]+/','-', $ascii));
  $slug = trim($slug, '-');
  return $slug !== '' ? $slug : 'batch-'.date('Ymd-His');
}
$slug = slugify($batch_name);
$base = $slug; $i = 1;
while(true){
  $stmt=$pdo->prepare("SELECT COUNT(*) FROM batches WHERE slug=:s");
  $stmt->execute([':s'=>$slug]);
  if ($stmt->fetchColumn()==0) break;
  $slug = $base.'-'.(++$i);
}

$archiveDir = __DIR__ . '/archives/' . $slug;
if (!is_dir($archiveDir)) { mkdir($archiveDir, 0777, true); }

// Insert batch + email settings
$stmt=$pdo->prepare("INSERT INTO batches (name, slug, project_id, email_subject, email_body, recipient_mode, extra_to, extra_cc, extra_bcc, email_per_partner) VALUES (:n,:s,:p,:es,:eb,:rm,:to,:cc,:bcc,:epp)");
$stmt->execute([
  ':n'=>$batch_name, ':s'=>$slug, ':p'=>$project_id,
  ':es'=>$email_subject_tpl, ':eb'=>$email_body_tpl, ':rm'=>$recipient_mode,
  ':to'=>$extra_to, ':cc'=>$extra_cc, ':bcc'=>$extra_bcc, ':epp'=>$email_per_partner
]);
$batch_id = (int)$pdo->lastInsertId();

// CSS
$fontCss = build_font_css();
$dejavuCss = '';
$dejavuPath = __DIR__.'/fonts/DejaVuSans.ttf';
if (file_exists($dejavuPath)){
  $dejavuCss = "@font-face{font-family:'DejaVu Sans';src:url('".base_url()."/fonts/DejaVuSans.ttf') format('truetype');font-weight:normal;font-style:normal;}";
}
$printCss = '<style>
  @page{size:A4;margin:20mm;}
  html, body{margin:0; padding:0; background:#fff; font-family: system-ui, Segoe UI, Arial, sans-serif;}
  '.$dejavuCss.$fontCss.'
  .page{ width:210mm; min-height:297mm; margin:0 auto; padding:20mm; box-sizing:border-box; background:#fff; }
  .pb{ page-break-after:always; }
</style>';
$chromeCss = '<style>
  :root{--canvas:#f0f2f5; --border:#d8dde3; --shadow:0 6px 24px rgba(0,0,0,.08);}
  @page{size:A4;margin:20mm;}
  html, body{margin:0; padding:0; background:var(--canvas); font-family: system-ui, Segoe UI, Arial, sans-serif;}
  '.$dejavuCss.$fontCss.'
  .topbar{position:sticky; top:0; z-index:100; display:flex; justify-content:space-between; align-items:center;
          padding:10px 14px; background:#fff; border-bottom:1px solid var(--border); box-shadow: 0 2px 10px rgba(0,0,0,.03);}
  .btn{display:inline-block; padding:8px 12px; border:1px solid var(--border); border-radius:10px; text-decoration:none; color:#111; background:#fff; cursor:pointer; font-size:14px;}
  .wrap{ padding:18px 0 36px; }
  .page{ width:210mm; min-height:297mm; margin:12px auto; padding:20mm; box-sizing:border-box; background:#fff; border:1px solid var(--border); box-shadow: var(--shadow); }
  .pb{ page-break-after:always; }
  @media print{
    @page{ size: A4; margin: 20mm; }
    html, body{ background:#fff; margin:0; padding:0; }
    .topbar{ display:none !important; }
    .wrap{ padding:0; }
    .page{ border:none; box-shadow:none; margin:0; width:auto; min-height:auto; padding:0; }
    .pb{ display:block; page-break-after:always; }
  }
</style>';

$head_screen = '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($batch_name,ENT_QUOTES,'UTF-8').'</title>'.$chromeCss.'</head><body>';
$topbar = '<div class="topbar"><div style="display:flex; gap:6px;"><a class="btn" href="batches.php">📁 Archívum</a><button class="btn" onclick="print()">🖨 Nyomtatás</button></div><div>'.htmlspecialchars($batch_name,ENT_QUOTES,'UTF-8').'</div><div></div></div><div class="wrap">';
$tail = '</div></body></html>';
$combined_screen = $head_screen . $topbar;
$combined_print  = '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($batch_name,ENT_QUOTES,'UTF-8').'</title>'.$printCss.'</head><body>';

function render_html_to_pdf($html_url, $pdf_path, $renderer){
  $ok=false;
  if ($renderer==='chrome'){
    foreach (['google-chrome','chromium','chromium-browser'] as $bin){
      $out = @shell_exec('which '.escapeshellarg($bin).' 2>/dev/null');
      if (trim((string)$out)!==''){
        $cmd = $bin.' --headless --disable-gpu --no-sandbox --print-to-pdf='.escapeshellarg($pdf_path).' --no-pdf-header-footer --print-to-pdf-no-header '.escapeshellarg($html_url).' 2>&1';
        @shell_exec($cmd);
        if (file_exists($pdf_path) && filesize($pdf_path)>0){ $ok=true; break; }
      }
    }
  }
  if (!$ok && $renderer==='wkhtmltopdf'){
    $cmd = 'wkhtmltopdf --encoding utf-8 --print-media-type --disable-smart-shrinking '.escapeshellarg($html_url).' '.escapeshellarg($pdf_path).' 2>&1';
    @shell_exec($cmd);
    $ok = file_exists($pdf_path) && filesize($pdf_path)>0;
  }
  if (!$ok && $renderer==='dompdf'){
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)){
      require_once $autoload;
      try{
        $options = new Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('enable_font_subsetting', true);
        $options->set('dpi', 96);
        $dompdf = new Dompdf\Dompdf($options);
        $dompdf->setPaper('A4','portrait');
        $html = file_get_contents($html_url);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        $pdf = $dompdf->output();
        file_put_contents($pdf_path, $pdf);
        $ok=true;
      } catch(Throwable $e){}
    }
  }
  return $ok;
}

// Build items
$totalItems = count($template_ids) * count($partner_ids);
$counter = 0;

foreach($template_ids as $tid){
  $tplRow = $pdo->prepare("SELECT name FROM templates WHERE id=:id");
  $tplRow->execute([':id'=>(int)$tid]);
  $template_name = (string)$tplRow->fetchColumn();

  foreach($partner_ids as $pid){
    $counter++;
    $html = render_template_html($pdo, (int)$tid, (int)$pid, $project_id, $imgw);

    // Per-item print-only HTML
    $itemHtml = '<!doctype html><html><head><meta charset="utf-8"><title>'
      .htmlspecialchars($batch_name,ENT_QUOTES,'UTF-8').'</title>'.$printCss.'</head><body><div class="page">'
      .$html.'</div></body></html>';

    $itemBase = 'item-t'.$tid.'-p'.$pid;
    $itemHtmlName = $itemBase.'.html';
    $itemPdfName  = $itemBase.'.pdf';
    $itemHtmlPath = $archiveDir.'/'.$itemHtmlName;
    $itemPdfPath  = $archiveDir.'/'.$itemPdfName;

    file_put_contents($itemHtmlPath, $itemHtml);

    // DB row
    $stmt=$pdo->prepare("INSERT INTO batch_items (batch_id, template_id, partner_id, item_html_path, item_pdf_path) VALUES (:b,:t,:p,:h,:f)");
    $stmt->execute([':b'=>$batch_id, ':t'=>(int)$tid, ':p'=>(int)$pid, ':h'=>'archives/'.$slug.'/'.$itemHtmlName, ':f'=>null]);
    $item_id = (int)$pdo->lastInsertId();

    // Render per-item PDF
    $htmlUrl = base_url().'/archives/'.$slug.'/'.$itemHtmlName;
    if (render_html_to_pdf($htmlUrl, $itemPdfPath, $renderer)){
      $pdfRel = 'archives/'.$slug.'/'.$itemPdfName;
      $pdo->prepare("UPDATE batch_items SET item_pdf_path=:p WHERE id=:id")->execute([':p'=>$pdfRel, ':id'=>$item_id]);
    }

    // Append combined
    $combined_screen .= '<div class="page">'.$html.'</div>';
    $combined_print  .= '<div class="page">'.$html.'</div>';
    if ($pagebreak==='always' && $counter < $totalItems){
      $combined_screen .= '<div class="pb"></div>';
      $combined_print  .= '<div class="pb"></div>';
    }
  }
}

$combined_screen .= $tail;
$combined_print  .= '</body></html>';

// Save combined HTML+PDF
$combinedHtmlPath = $archiveDir.'/combined.html';
$combinedHtmlPrintPath = $archiveDir.'/combined_print.html';
file_put_contents($combinedHtmlPath, $combined_screen);
file_put_contents($combinedHtmlPrintPath, $combined_print);
$combinedHtmlRel = 'archives/'.$slug.'/combined.html';

$combinedPdfRel = null;
$combinedPdfPath = $archiveDir.'/combined.pdf';
$htmlUrlCombined = base_url().'/archives/'.$slug.'/combined_print.html';
if (render_html_to_pdf($htmlUrlCombined, $combinedPdfPath, $renderer)){
  $combinedPdfRel = 'archives/'.$slug.'/combined.pdf';
}

// update batch with paths
$stmt=$pdo->prepare("UPDATE batches SET combined_html_path=:h, combined_pdf_path=:p WHERE id=:id");
$stmt->execute([':h'=>$combinedHtmlRel, ':p'=>$combinedPdfRel, ':id'=>$batch_id]);

header('Location: batch_view.php?id='.$batch_id);
