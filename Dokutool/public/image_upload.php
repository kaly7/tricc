<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
include __DIR__ . '/header.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// uploads a public alatt
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
$err = null; $ok = null;
if (!is_writable($uploadDir)) { $err = "A feltöltési könyvtár nem írható: " . htmlspecialchars($uploadDir); }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$err) {
  try {
    $key = strtolower(trim($_POST['key'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{2,64}$/', $key)) throw new Exception('Kulcs: csak kisbetű/szám/alsóvonás (2–64).');
    $chk = $pdo->prepare("SELECT COUNT(*) c FROM images WHERE `key`=:k");
    $chk->execute([':k'=>$key]);
    if ((int)$chk->fetch()['c'] > 0) throw new Exception('Már létezik ilyen kulcs: ' . $key);

    $title = trim($_POST['title'] ?? '') ?: null;
    $alt   = trim($_POST['alt_text'] ?? '') ?: null;
    $tags  = trim($_POST['tags'] ?? '') ?: null;

    if (!isset($_FILES['image']) || ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Exception('Nincs fájl kiválasztva, vagy feltöltési hiba.');
    $f = $_FILES['image']; if ($f['size'] > 5*1024*1024) throw new Exception('A fájl túl nagy (max 5 MB).');

    $fi = new finfo(FILEINFO_MIME_TYPE); $mime = $fi->file($f['tmp_name']) ?: 'application/octet-stream';
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new Exception('Csak JPG/PNG/WEBP engedélyezett.');
    $ext = $allowed[$mime];

    $rand = bin2hex(random_bytes(8));
    $stored = date('Ymd_His') . '_' . $rand . '.' . $ext;
    $dest = $uploadDir . '/' . $stored;
    if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('A fájlt nem sikerült áthelyezni.');

    $gw = $gh = null; $info = @getimagesize($dest); if (is_array($info)) { $gw = $info[0] ?? null; $gh = $info[1] ?? null; }

    $stmt = $pdo->prepare("INSERT INTO images (`key`, title, alt_text, tags, original_name, stored_name, mime_type, file_size, width, height)
                           VALUES (:k, :t, :a, :tags, :on, :sn, :m, :sz, :w, :h)");
    $stmt->execute([':k'=>$key, ':t'=>$title, ':a'=>$alt, ':tags'=>$tags, ':on'=>$f['name'], ':sn'=>$stored, ':m'=>$mime, ':sz'=>$f['size'], ':w'=>$gw, ':h'=>$gh]);

    header('Location: images.php?ok=1'); exit;
  } catch (Throwable $e) { $err = "Feltöltési hiba: " . $e->getMessage(); }
}
?>
  <div class="container">
    <div class="hdr card">
      <div><strong>Kép feltöltése</strong></div>
      <div><a class="btn" href="images.php">← Képek</a></div>
    </div>

    <?php if($err): ?><div class="err"><?=$err?></div><?php endif; ?>
    <?php if($ok): ?><div class="ok"><?=$ok?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card">
      <div class="grid cols-3">
        <div class="input"><label>Kulcs (sablon hivatkozás)*</label><input name="key" required placeholder="pl. logo, pecset, fejlec"></div>
        <div class="input"><label>Cím (opcionális)</label><input name="title" placeholder="pl. Vállalati logó"></div>
        <div class="input"><label>Alt szöveg (akadálymentesítés)</label><input name="alt_text" placeholder="pl. Céglogó fehér háttéren"></div>
        <div class="input" style="grid-column:1/-1;"><label>Címkék (vesszővel elválasztva)</label><input name="tags" placeholder="pl. logó, hivatalos, 2025"></div>
        <div class="input" style="grid-column:1/-1;"><label>Kép fájl (JPG/PNG/WEBP, max 5 MB)*</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required></div>
      </div>
      <div style="text-align:right;"><button class="btn primary" type="submit">Feltöltés</button></div>
    </form>

    <div class="card">
      <h3>Használat</h3>
      <p>A sablonban a feltöltött képre így hivatkozhatsz: <code>{{ image.&lt;kulcs&gt; }}</code> (pl. <code>{{ image.logo }}</code>).</p>
    </div>
  </div>
<?php include __DIR__ . '/footer.php'; ?>
