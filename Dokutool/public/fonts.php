<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

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

// Local fonts in /public/fonts
$fontsDir = __DIR__ . '/fonts';
$local = [];
if (is_dir($fontsDir)){
  $dh = opendir($fontsDir);
  while(($fn = $dh ? readdir($dh) : false) !== false){
    if ($fn==='.'||$fn==='..') continue;
    $p = $fontsDir . '/' . $fn;
    if (is_file($p) && preg_match('/\.(ttf|otf|woff2?|TTF|OTF|WOFF2?)$/', $fn)){
      $local[] = [
        'family' => preg_replace('/[_\-]+/',' ', pathinfo($fn, PATHINFO_FILENAME)),
        'file' => $fn,
        'path' => $p,
        'url'  => base_url().'/fonts/'.rawurlencode($fn),
        'source' => 'helyi'
      ];
    }
  }
  if ($dh) closedir($dh);
}

// System fonts via fc-list (if available)
$system = [];
$out = @shell_exec('fc-list : family file 2>/dev/null');
if ($out){
  $seen = [];
  foreach (explode("\n", trim($out)) as $line){
    if ($line==='') continue;
    // Format: /path/to/font.ttf: Family Name,Other:style=...
    $parts = explode(':', $line, 2);
    $file = trim($parts[0]);
    $fam  = trim($parts[1] ?? '');
    $fam  = preg_replace('/^([^:]+).*/','$1',$fam);
    $fam  = preg_replace('/,\s*.*/','',$fam);
    $fam  = trim($fam, " \t:");
    if (!isset($seen[$fam])){
      $system[] = ['family'=>$fam, 'file'=>basename($file), 'path'=>$file, 'source'=>'rendszer'];
      $seen[$fam]=1;
    }
  }
}
?>
<div class="container">
  <div class="card hdr">
    <div><strong>Fontok kezelése</strong></div>
    <div><a class="btn" href="index.php">← Főmenü</a></div>
  </div>

  <div class="card">
    <h3>Helyi fontok (/public/fonts)</h3>
    <form method="post" action="fonts_upload.php" enctype="multipart/form-data" style="margin-bottom:12px;">
      <input type="file" name="fontfile" accept=".ttf,.otf,.woff,.woff2" required>
      <button class="btn" type="submit">⬆ Feltöltés</button>
    </form>
    <?php if (empty($local)): ?>
      <p class="muted">Nincs helyi font feltöltve.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Család</th><th>Fájl</th><th>Elérési út</th><th>Minta</th><th>Művelet</th></tr></thead>
        <tbody>
        <?php foreach($local as $f): ?>
          <tr>
            <td><?= htmlspecialchars($f['family']) ?></td>
            <td><?= htmlspecialchars($f['file']) ?></td>
            <td><code><?= htmlspecialchars($f['path']) ?></code></td>
            <td style="font-family: '<?= htmlspecialchars($f['family']) ?>', sans-serif;">Árvíztűrő tükörfúrógép 123 ÁÉÍÓÖŐÚÜŰ</td>
            <td>
              <form method="post" action="fonts_delete.php" onsubmit="return confirm('Biztosan törlöd a fájlt?');" style="display:inline;">
                <input type="hidden" name="file" value="<?= htmlspecialchars($f['file']) ?>">
                <button class="btn" type="submit">🗑 Törlés</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Rendszerfontok</h3>
    <?php if (empty($system)): ?>
      <p class="muted">Az <code>fc-list</code> nem érhető el vagy nem találtunk rendszerfontokat.</p>
    <?php else: ?>
      <table class="table">
        <thead><tr><th>Család</th><th>Forrás</th><th>Útvonal</th><th>Minta</th></tr></thead>
        <tbody>
        <?php foreach($system as $f): ?>
          <tr>
            <td><?= htmlspecialchars($f['family']) ?></td>
            <td><span class="muted"><?= htmlspecialchars($f['source']) ?></span></td>
            <td><code><?= htmlspecialchars($f['path']) ?></code></td>
            <td style="font-family: '<?= htmlspecialchars($f['family']) ?>', sans-serif;">Árvíztűrő tükörfúrógép 123 ÁÉÍÓÖŐÚÜŰ</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <form method="post" action="fonts_test_run.php">
      <button class="btn primary" type="submit">🧪 Tesztlap generálása (HTML + PDF)</button>
    </form>
    <p class="muted">Tesztlap: minden fontból minta, magyar ékezetekkel. Mentés: <code>public/archives/fonts_test/&lt;dátum&gt;/</code></p>
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>
