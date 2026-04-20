<?php
// Dynamically build @font-face CSS from /public/fonts/*.ttf (family = file base name with spaces instead of dashes/underscores)
if (!function_exists('base_url')) {
  function base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
  }
}
function build_font_css(): string {
  $fontsDirFs = __DIR__ . '/../public/fonts';
  $fontsDirUrl = base_url() . '/fonts';
  if (!is_dir($fontsDirFs)) return '';
  $css = '';
  $dh = opendir($fontsDirFs);
  if (!$dh) return '';
  while (($fn = readdir($dh)) !== false) {
    if ($fn === '.' || $fn === '..') continue;
    $path = $fontsDirFs . '/' . $fn;
    if (is_file($path) && preg_match('/\.(ttf|otf|woff2?)$/i', $fn)) {
      $ext = pathinfo($fn, PATHINFO_EXTENSION);
      $base = pathinfo($fn, PATHINFO_FILENAME);
      $family = trim(preg_replace('/[_\-]+/',' ', $base));
      $url = $fontsDirUrl . '/' . rawurlencode($fn);
      $format = 'truetype';
      if (preg_match('/woff2?$/i',$ext)) $format = strtolower($ext);
      $css .= "@font-face{font-family:'".$family."';src:url('".$url."') format('".$format."');font-weight:normal;font-style:normal;}\n";
    }
  }
  closedir($dh);
  return $css;
}
