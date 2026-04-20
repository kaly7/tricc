<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

function have_bin($bin){
  $out = @shell_exec('which '.escapeshellarg($bin).' 2>/dev/null');
  return trim((string)$out) !== '';
}
function base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

$fontsDir = __DIR__ . '/fonts';
$families = [];
// gather local font families
if (is_dir($fontsDir)){
  $dh = opendir($fontsDir);
  while(($fn = $dh ? readdir($dh) : false) !== false){
    if ($fn==='.'||$fn==='..') continue;
    if (is_file($fontsDir.'/'.$fn) && preg_match('/\.(ttf|otf|woff2?|TTF|OTF|WOFF2?)$/', $fn)){
      $families[] = preg_replace('/[_\-]+/',' ', pathinfo($fn, PATHINFO_FILENAME));
    }
  }
  if ($dh) closedir($dh);
}
// some common families to test if exist on system
$common = ['Arial','Times New Roman','Courier New','Georgia','Tahoma','Verdana','DejaVu Sans','Liberation Sans','Liberation Serif','Liberation Mono','Noto Sans','Noto Serif'];
$families = array_values(array_unique(array_merge($families, $common)));

$slug = 'fonts-'.date('Ymd-His');
$dir = __DIR__ . '/archives/fonts_test/'.$slug;
if (!is_dir($dir)) mkdir($dir, 0777, true);
$cssFonts = '';
// Embed all local font files with @font-face
if (is_dir($fontsDir)){
  $dh = opendir($fontsDir);
  while(($fn = $dh ? readdir($dh) : false) !== false){
    if ($fn==='.'||$fn==='..') continue;
    $p = $fontsDir.'/'.$fn;
    if (is_file($p) && preg_match('/\.(ttf|otf|woff2?|TTF|OTF|WOFF2?)$/', $fn)){
      $family = preg_replace('/[_\-]+/',' ', pathinfo($fn, PATHINFO_FILENAME));
      $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
      $fmt = 'truetype';
      if ($ext==='otf') $fmt='opentype';
      if ($ext==='woff') $fmt='woff';
      if ($ext==='woff2') $fmt='woff2';
      $url = base_url().'/fonts/'.rawurlencode($fn);
      $cssFonts .= "@font-face{font-family:'".$family."';src:url('".$url."') format('".$fmt."');font-weight:normal;font-style:normal;}\n";
    }
  }
  if ($dh) closedir($dh);
}

$css = '<style>
  @page{size:A4;margin:12mm;}
  body{font-family: system-ui, Arial, sans-serif; margin:0; padding:12mm; background:#fff;}
  h1{margin-top:0;}
  .font{border:1px solid #ddd; padding:10px 12px; border-radius:10px; margin:8px 0;}
  .family{font-size:14px; color:#666;}
  .sample{font-size:18px; margin-top:6px;}
  '.$cssFonts.'
</style>';

$head='<!doctype html><html><head><meta charset="utf-8"><title>Fonts test '.$slug.'</title>'.$css.'</head><body>';
$tail='</body></html>';

$html=$head.'<h1>Fonts test – '.$slug.'</h1>';
foreach($families as $fam){
  $famEsc = htmlspecialchars($fam, ENT_QUOTES, 'UTF-8');
  $html.='<div class="font"><div class="family">'.$famEsc.'</div><div class="sample" style="font-family:\''.$famEsc.'\', sans-serif;">Árvíztűrő tükörfúrógép 12345 — The quick brown fox jumps over the lazy dog.</div></div>';
}
$html.=$tail;

file_put_contents($dir.'/test.html',$html);

// Render PDF using best available renderer
$pdfPath = $dir.'/test.pdf';
$url = base_url().'/archives/fonts_test/'.$slug.'/test.html';
$ok = false;
if (have_bin('google-chrome')){
  $cmd = 'google-chrome --headless --disable-gpu --no-sandbox --print-to-pdf='.escapeshellarg($pdfPath).' --no-pdf-header-footer --print-to-pdf-no-header '.escapeshellarg($url).' 2>&1';
  @shell_exec($cmd);
  $ok = file_exists($pdfPath) && filesize($pdfPath)>0;
}
if (!$ok && have_bin('chromium')){
  $cmd = 'chromium --headless --disable-gpu --no-sandbox --print-to-pdf='.escapeshellarg($pdfPath).' --no-pdf-header-footer --print-to-pdf-no-header '.escapeshellarg($url).' 2>&1';
  @shell_exec($cmd);
  $ok = file_exists($pdfPath) && filesize($pdfPath)>0;
}
if (!$ok && have_bin('chromium-browser')){
  $cmd = 'chromium-browser --headless --disable-gpu --no-sandbox --print-to-pdf='.escapeshellarg($pdfPath).' --no-pdf-header-footer --print-to-pdf-no-header '.escapeshellarg($url).' 2>&1';
  @shell_exec($cmd);
  $ok = file_exists($pdfPath) && filesize($pdfPath)>0;
}
if (!$ok && have_bin('wkhtmltopdf')){
  $cmd = 'wkhtmltopdf --encoding utf-8 --print-media-type '.escapeshellarg($url).' '.escapeshellarg($pdfPath).' 2>&1';
  @shell_exec($cmd);
  $ok = file_exists($pdfPath) && filesize($pdfPath)>0;
}
if (!$ok){
  // leave only HTML
}

header('Location: fonts.php?ok=test&dir=archives/fonts_test/'.$slug);
