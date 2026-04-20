# 1) Hozd létre a projektet /var/www/html/payslip alatt (teljes v1)
set -euo pipefail

APP_DIR="/var/www/html/payslip"
WWW_USER="www-data"
WWW_GROUP="www-data"


# 3) config.php (DB + SMTP a te adataiddal)
cat > "$APP_DIR/app/Config/config.php" <<'EOF'
<?php
// DB
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'payslip');
define('DB_USER', 'ppdb');
define('DB_PASS', 'abrakadabra');
define('DB_CHARSET', 'utf8mb4');

// Mail
define('MAIL_ALLOW_DUPLICATE_SENDS', true);
define('SMTP_HOST', 'mail.t-online.hu');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreplay@perfect-phone.hu');
define('SMTP_PASS', 'PPn0R3p1@y-25');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM', 'noreplay@perfect-phone.hu');
define('SMTP_FROM_NAME', 'PP rendszer');

// App paths
define('APP_ROOT', realpath(__DIR__ . '/../../'));
define('STORAGE_DIR', APP_ROOT . '/storage');
define('UPLOADS_DIR', STORAGE_DIR . '/uploads');
define('OUTPUT_DIR',  STORAGE_DIR . '/output');
define('TMP_DIR',     STORAGE_DIR . '/tmp');

// Tools
define('BIN_QPDF', '/usr/bin/qpdf');
define('BIN_PDFINFO', '/usr/bin/pdfinfo');
define('BIN_PDFTOTEXT', '/usr/bin/pdftotext');
EOF
sudo chown "$WWW_USER":"$WWW_GROUP" "$APP_DIR/app/Config/config.php"
sudo chmod 640 "$APP_DIR/app/Config/config.php"

# 4) bootstrap.php + Db.php
cat > "$APP_DIR/bootstrap.php" <<'EOF'
<?php
require __DIR__ . '/app/Config/config.php';
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/Db.php';

spl_autoload_register(function ($class) {
    $base = __DIR__ . '/app/';
    $file = $base . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require $file;
});
EOF

cat > "$APP_DIR/app/Db.php" <<'EOF'
<?php
class Db {
    private static ?PDO $pdo = null;

    public static function pdo(): PDO {
        if (self::$pdo) return self::$pdo;

        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return self::$pdo;
    }
}
EOF

# 5) Services
cat > "$APP_DIR/app/Services/LoggerService.php" <<'EOF'
<?php
namespace Services;

class LoggerService {
    public static function log(string $level, string $action, string $message, ?int $uploadId=null, ?int $pageJobId=null, ?array $ctx=null): void {
        $pdo = \Db::pdo();
        $stmt = $pdo->prepare("INSERT INTO audit_log(level, action, upload_id, page_job_id, message, context_json)
                               VALUES(?,?,?,?,?,?)");
        $stmt->execute([
            $level,
            $action,
            $uploadId,
            $pageJobId,
            mb_substr($message, 0, 512),
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null
        ]);
    }
}
EOF

cat > "$APP_DIR/app/Services/EmployeeService.php" <<'EOF'
<?php
namespace Services;

class EmployeeService {

    public static function normalizeName(string $name): string {
        $name = trim($name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = mb_strtolower($name, 'UTF-8');

        if (class_exists('\Transliterator')) {
            $tr = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($tr) $name = $tr->transliterate($name);
        }

        $name = preg_replace('/[^a-z0-9 \-]/', '', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return $name;
    }

    public static function findByNorm(string $nameNorm): ?array {
        $pdo = \Db::pdo();
        $stmt = $pdo->prepare("SELECT id, name, email FROM employees WHERE name_norm = ? AND active=1 LIMIT 1");
        $stmt->execute([$nameNorm]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
EOF

cat > "$APP_DIR/app/Services/MailService.php" <<'EOF'
<?php
namespace Services;

use PHPMailer\PHPMailer\PHPMailer;

class MailService {
    public static function sendPayslip(string $to, string $toName, string $subject, string $body, string $attachmentPath): void {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to, $toName);

        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;

        $mail->addAttachment($attachmentPath);

        $mail->send();
    }
}
EOF

cat > "$APP_DIR/app/Services/PdfService.php" <<'EOF'
<?php
namespace Services;

class PdfService {

    public static function getTotalPages(string $pdfPath): int {
        $cmd = escapeshellcmd(BIN_PDFINFO) . ' ' . escapeshellarg($pdfPath);
        $out = shell_exec($cmd);
        if (!$out) return 0;
        if (preg_match('/^Pages:\s+(\d+)/mi', $out, $m)) return (int)$m[1];
        return 0;
    }

    public static function splitToPages(string $pdfPath, string $outDir): void {
        if (!is_dir($outDir)) mkdir($outDir, 0770, true);
        $pattern = rtrim($outDir, '/') . '/page-%03d.pdf';
        $cmd = escapeshellcmd(BIN_QPDF) . " --split-pages " . escapeshellarg($pdfPath) . " " . escapeshellarg($pattern);
        $ret = 0;
        system($cmd, $ret);
        if ($ret !== 0) throw new \RuntimeException("qpdf split failed with code $ret");
    }

    public static function extractNameFromPagePdf(string $pagePdfPath): ?string {
        $cmd = escapeshellcmd(BIN_PDFTOTEXT) . ' ' . escapeshellarg($pagePdfPath) . ' -';
        $text = shell_exec($cmd);
        if (!$text) return null;

        // minta: "Név: ... Adójel: ..."
        if (preg_match('/Név:\s*(.*?)\s+Adójel:/u', $text, $m)) {
            $name = trim($m[1]);
            $name = preg_replace('/\s+/u', ' ', $name);
            return $name ?: null;
        }
        return null;
    }

    public static function safeFileName(string $name): string {
        $n = \Services\EmployeeService::normalizeName($name);
        $n = preg_replace('/\s+/', '_', $n);
        $n = preg_replace('/_+/', '_', $n);
        $n = trim($n, '_');
        return $n ?: 'unknown';
    }
}
EOF

# 6) public/.htaccess
cat > "$APP_DIR/public/.htaccess" <<'EOF'
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]
RewriteRule ^ index.php [QSA,L]
Options -Indexes
EOF

# 7) public pages
cat > "$APP_DIR/public/index.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Payslip splitter</title>
  <style>body{font-family:system-ui,Arial;margin:24px} a{display:inline-block;margin:6px 0}</style>
</head>
<body>
  <h2>Payslip splitter</h2>
  <ul>
    <li><a href="upload.php">PDF feltöltés</a></li>
    <li><a href="employees_import.php">Dolgozók import (CSV)</a></li>
    <li><a href="log.php">Log / státusz</a></li>
  </ul>
</body>
</html>
EOF

cat > "$APP_DIR/public/upload.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';

use Services\LoggerService;
use Services\PdfService;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month = $_POST['month'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) die("Hibás hónap (YYYY-MM).");

    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) die("Nincs PDF vagy hiba történt.");

    $tmp = $_FILES['pdf']['tmp_name'];
    $orig = basename($_FILES['pdf']['name']);

    $monthDir = UPLOADS_DIR . '/' . $month;
    if (!is_dir($monthDir)) mkdir($monthDir, 0770, true);

    $stored = $monthDir . '/original.pdf';
    if (!move_uploaded_file($tmp, $stored)) die("Nem tudtam elmenteni a feltöltést.");

    $sha = hash_file('sha256', $stored);
    $pages = PdfService::getTotalPages($stored);

    $pdo = Db::pdo();
    $stmt = $pdo->prepare("INSERT INTO uploads(original_filename, month, stored_path, total_pages, file_sha256, uploaded_by)
                           VALUES(?,?,?,?,?,?)");
    $stmt->execute([$orig, $month, $stored, $pages, $sha, $_SERVER['REMOTE_ADDR'] ?? null]);
    $uploadId = (int)$pdo->lastInsertId();

    LoggerService::log('INFO', 'UPLOAD', "Upload rögzítve: $orig, oldalak: $pages", $uploadId, null, ['sha256'=>$sha]);

    header("Location: start.php?upload_id=" . $uploadId);
    exit;
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Feltöltés</title></head>
<body>
<h2>PDF feltöltés</h2>
<form method="post" enctype="multipart/form-data">
  Hónap (YYYY-MM): <input name="month" placeholder="2025-11" required><br><br>
  PDF: <input type="file" name="pdf" accept="application/pdf" required><br><br>
  <button type="submit">Feltöltés</button>
</form>
<p><a href="index.php">Vissza</a></p>
</body></html>
EOF

cat > "$APP_DIR/public/start.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) die("Hibás upload_id.");

$cmd = '/usr/bin/php ' . escapeshellarg(APP_ROOT . '/worker/process_upload.php') . ' ' . escapeshellarg((string)$uploadId);
exec($cmd . ' > /dev/null 2>&1 &');
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Feldolgozás</title></head>
<body>
<h2>Feldolgozás elindítva</h2>
<div id="p">Progress betöltése...</div>
<script>
async function poll(){
  const r = await fetch('progress.php?upload_id=<?= (int)$uploadId ?>');
  const j = await r.json();
  const pct = j.total > 0 ? Math.round((j.done / j.total) * 100) : 0;
  document.getElementById('p').innerHTML =
    `<b>${j.done}/${j.total}</b> (${pct}%) | sent: ${j.sent} | no_match: ${j.no_match} | failed: ${j.failed} | current_page: ${j.current_page}`;
  if (j.running) setTimeout(poll, 1200);
}
poll();
</script>
<p><a href="log.php?upload_id=<?= (int)$uploadId ?>">Log megnyitása</a></p>
<p><a href="index.php">Főoldal</a></p>
</body></html>
EOF

cat > "$APP_DIR/public/progress.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';

$uploadId = (int)($_GET['upload_id'] ?? 0);
if ($uploadId <= 0) { header('Content-Type: application/json'); echo json_encode(['error'=>'bad upload_id']); exit; }

$pdo = Db::pdo();
$u = $pdo->prepare("SELECT total_pages FROM uploads WHERE id=?");
$u->execute([$uploadId]);
$upload = $u->fetch();
$total = $upload ? (int)$upload['total_pages'] : 0;

$q = $pdo->prepare("
  SELECT
    SUM(status IN ('SENT','FAILED','NO_MATCH')) AS done,
    SUM(status = 'SENT') AS sent,
    SUM(status = 'FAILED') AS failed,
    SUM(status = 'NO_MATCH') AS no_match,
    MAX(CASE WHEN status IN ('SENT','FAILED','NO_MATCH') THEN page_no ELSE 0 END) AS current_page
  FROM page_jobs
  WHERE upload_id=?
");
$q->execute([$uploadId]);
$s = $q->fetch() ?: [];

$done = (int)($s['done'] ?? 0);
$running = ($total > 0) && ($done < $total);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'total' => $total,
  'done' => $done,
  'sent' => (int)($s['sent'] ?? 0),
  'failed' => (int)($s['failed'] ?? 0),
  'no_match' => (int)($s['no_match'] ?? 0),
  'current_page' => (int)($s['current_page'] ?? 0),
  'running' => $running
], JSON_UNESCAPED_UNICODE);
EOF

cat > "$APP_DIR/public/log.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$uploadId = (int)($_GET['upload_id'] ?? 0);
$pdo = Db::pdo();

?><!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Log</title>
<style>table{border-collapse:collapse} td,th{border:1px solid #999;padding:6px}.bad{background:#ffe5e5}.warn{background:#fff6d6}.ok{background:#e8ffe8}</style>
</head><body>
<h2>Log / státusz</h2>

<?php if ($uploadId > 0): ?>
  <p>Upload ID: <b><?= $uploadId ?></b></p>
  <p><a href="start.php?upload_id=<?= $uploadId ?>">Újraindít</a> | <a href="log.php">Vissza a listához</a></p>
  <?php
    $stmt = $pdo->prepare("SELECT * FROM page_jobs WHERE upload_id=? ORDER BY page_no ASC");
    $stmt->execute([$uploadId]);
    $rows = $stmt->fetchAll();
  ?>
  <table>
    <tr><th>Oldal</th><th>Név</th><th>Email</th><th>Status</th><th>Hiba</th></tr>
    <?php foreach ($rows as $r):
      $cls = ($r['status']==='SENT') ? 'ok' : (($r['status']==='NO_MATCH') ? 'warn' : (($r['status']==='FAILED') ? 'bad' : ''));
    ?>
    <tr class="<?= $cls ?>">
      <td><?= (int)$r['page_no'] ?></td>
      <td><?= h($r['extracted_name'] ?? '') ?></td>
      <td><?= h($r['email_to'] ?? '') ?></td>
      <td><?= h($r['status']) ?></td>
      <td><?= h($r['error_message'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php else: ?>
  <?php $rows = $pdo->query("SELECT id, month, original_filename, total_pages, uploaded_at FROM uploads ORDER BY id DESC LIMIT 50")->fetchAll(); ?>
  <table>
    <tr><th>ID</th><th>Hónap</th><th>Fájl</th><th>Oldalak</th><th>Idő</th><th>Művelet</th></tr>
    <?php foreach ($rows as $r): $id=(int)$r['id']; ?>
      <tr>
        <td><?= $id ?></td>
        <td><?= h($r['month']) ?></td>
        <td><?= h($r['original_filename']) ?></td>
        <td><?= (int)$r['total_pages'] ?></td>
        <td><?= h($r['uploaded_at']) ?></td>
        <td><a href="log.php?upload_id=<?= $id ?>">Megnyit</a> | <a href="start.php?upload_id=<?= $id ?>">Indít</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>

<p><a href="index.php">Főoldal</a></p>
</body></html>
EOF

cat > "$APP_DIR/public/employees_import.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';

use Services\EmployeeService;
use Services\LoggerService;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = "CSV feltöltés hiba.";
    } else {
        $content = file_get_contents($_FILES['csv']['tmp_name']);
        if ($content === false) $msg = "Nem tudtam olvasni a CSV-t.";
        else {
            $lines = preg_split('/\R/u', $content);
            $pdo = Db::pdo();
            $ins = $pdo->prepare("INSERT INTO employees(name, name_norm, email, active)
                                  VALUES(?,?,?,1)
                                  ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), active=1");
            $count=0;
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line==='' || str_starts_with($line, '#')) continue;
                $sep = (strpos($line, ';') !== false) ? ';' : ',';
                $parts = array_map('trim', explode($sep, $line));
                if (count($parts) < 2) continue;
                $name = $parts[0];
                $email = $parts[1];
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $norm = EmployeeService::normalizeName($name);
                if ($norm==='') continue;
                $ins->execute([$name, $norm, $email]);
                $count++;
            }
            LoggerService::log('INFO', 'EMP_IMPORT', "Dolgozók import: $count sor", null, null, ['count'=>$count]);
            $msg = "Import kész: $count sor feldolgozva.";
        }
    }
}
?><!doctype html><html lang="hu"><head><meta charset="utf-8"><title>Dolgozók import</title></head>
<body>
<h2>Dolgozók import (CSV)</h2>
<p>Formátum: <code>Név;Email</code> vagy <code>Név,Email</code> soronként.</p>
<?php if ($msg): ?><p><b><?= h($msg) ?></b></p><?php endif; ?>
<form method="post" enctype="multipart/form-data">
  CSV: <input type="file" name="csv" accept=".csv,text/csv" required>
  <button type="submit">Import</button>
</form>
<p><a href="index.php">Vissza</a></p>
</body></html>
EOF

# 8) worker
cat > "$APP_DIR/worker/process_upload.php" <<'EOF'
<?php
require __DIR__ . '/../bootstrap.php';

use Services\PdfService;
use Services\EmployeeService;
use Services\MailService;
use Services\LoggerService;

$uploadId = (int)($argv[1] ?? 0);
if ($uploadId <= 0) { echo "Usage: php process_upload.php <upload_id>\n"; exit(1); }

$pdo = Db::pdo();
$u = $pdo->prepare("SELECT * FROM uploads WHERE id=?");
$u->execute([$uploadId]);
$upload = $u->fetch();
if (!$upload) { echo "Upload not found\n"; exit(1); }

$pdfPath = $upload['stored_path'];
$month = $upload['month'];
$total = (int)$upload['total_pages'];

$outputMonthDir = OUTPUT_DIR . '/' . $month;
$tmpMonthDir = TMP_DIR . '/' . $month . '/split_' . $uploadId;

@mkdir($outputMonthDir, 0770, true);
@mkdir($tmpMonthDir, 0770, true);

LoggerService::log('INFO', 'PROCESS_START', "Feldolgozás indul", $uploadId);

try {
    PdfService::splitToPages($pdfPath, $tmpMonthDir);
    LoggerService::log('INFO', 'SPLIT', "Szétbontás kész", $uploadId, null, ['dir'=>$tmpMonthDir]);

    for ($page = 1; $page <= $total; $page++) {
        $pagePdf = $tmpMonthDir . '/' . sprintf('page-%03d.pdf', $page);
        if (!is_file($pagePdf)) {
            LoggerService::log('ERROR', 'PAGE_MISSING', "Hiányzó oldal PDF", $uploadId, null, ['page'=>$page]);
            continue;
        }

        $pdo->prepare("INSERT IGNORE INTO page_jobs(upload_id,page_no,status) VALUES(?,?, 'PENDING')")
            ->execute([$uploadId, $page]);

        $stmt = $pdo->prepare("SELECT id,status FROM page_jobs WHERE upload_id=? AND page_no=?");
        $stmt->execute([$uploadId, $page]);
        $job = $stmt->fetch();
        $jobId = (int)$job['id'];

        if (!MAIL_ALLOW_DUPLICATE_SENDS && $job['status']==='SENT') continue;

        $name = PdfService::extractNameFromPagePdf($pagePdf);
        if (!$name) {
            $pdo->prepare("UPDATE page_jobs SET status='FAILED', error_message=? WHERE id=?")
                ->execute(["Nem található Név mező", $jobId]);
            LoggerService::log('ERROR', 'EXTRACT_NAME', "Nem találtam nevet", $uploadId, $jobId, ['page'=>$page]);
            continue;
        }

        $nameNorm = EmployeeService::normalizeName($name);
        $safe = PdfService::safeFileName($name);
        $finalPath = $outputMonthDir . '/' . $safe . '.pdf';

        if (!copy($pagePdf, $finalPath)) {
            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, status='FAILED', error_message=? WHERE id=?")
                ->execute([$name, $nameNorm, "Nem tudtam menteni: $finalPath", $jobId]);
            LoggerService::log('ERROR', 'SAVE', "Mentés sikertelen", $uploadId, $jobId, ['path'=>$finalPath]);
            continue;
        }

        $emp = EmployeeService::findByNorm($nameNorm);
        if (!$emp) {
            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, output_path=?, status='NO_MATCH' WHERE id=?")
                ->execute([$name, $nameNorm, $finalPath, $jobId]);
            LoggerService::log('WARN', 'NO_MATCH', "Nincs egyező dolgozó", $uploadId, $jobId, ['name'=>$name]);
            continue;
        }

        $email = $emp['email'];

        try {
            $subject = "Bérlap - " . $month;
            $body = "Szia $name,\n\nCsatolva küldjük a bérlapodat ($month).\n\nPP rendszer";
            MailService::sendPayslip($email, $name, $subject, $body, $finalPath);

            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, employee_id=?, email_to=?, output_path=?, status='SENT', sent_at=NOW(), error_message=NULL WHERE id=?")
                ->execute([$name, $nameNorm, $emp['id'], $email, $finalPath, $jobId]);

            LoggerService::log('INFO', 'SEND_MAIL', "Kiküldve", $uploadId, $jobId, ['email'=>$email,'page'=>$page]);
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE page_jobs SET extracted_name=?, extracted_name_norm=?, employee_id=?, email_to=?, output_path=?, status='FAILED', error_message=? WHERE id=?")
                ->execute([$name, $nameNorm, $emp['id'], $email, $finalPath, $e->getMessage(), $jobId]);
            LoggerService::log('ERROR', 'SEND_MAIL', "Küldés hiba", $uploadId, $jobId, ['err'=>$e->getMessage()]);
        }

        usleep(150000);
    }

    LoggerService::log('INFO', 'PROCESS_DONE', "Feldolgozás kész", $uploadId);
} catch (\Throwable $e) {
    LoggerService::log('ERROR', 'PROCESS_FATAL', "Feldolgozás leállt: " . $e->getMessage(), $uploadId);
    exit(1);
}
EOF

# 9) SQL schema
cat > "$APP_DIR/sql/schema.sql" <<'EOF'
CREATE DATABASE IF NOT EXISTS payslip CHARACTER SET utf8mb4 COLLATE utf8mb4_hungarian_ci;
CREATE USER IF NOT EXISTS 'ppdb'@'localhost' IDENTIFIED BY 'abrakadabra';
GRANT ALL PRIVILEGES ON payslip.* TO 'ppdb'@'localhost';
FLUSH PRIVILEGES;

USE payslip;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  name_norm VARCHAR(255) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  original_filename VARCHAR(255) NOT NULL,
  month CHAR(7) NOT NULL,
  stored_path VARCHAR(512) NOT NULL,
  total_pages INT NOT NULL DEFAULT 0,
  file_sha256 CHAR(64) NOT NULL,
  uploaded_by VARCHAR(128) NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS page_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  upload_id INT NOT NULL,
  page_no INT NOT NULL,
  extracted_name VARCHAR(255) NULL,
  extracted_name_norm VARCHAR(255) NULL,
  employee_id INT NULL,
  email_to VARCHAR(255) NULL,
  output_path VARCHAR(512) NULL,
  status ENUM('PENDING','SENT','FAILED','NO_MATCH') NOT NULL DEFAULT 'PENDING',
  error_message TEXT NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_upload_page (upload_id, page_no),
  INDEX(upload_id),
  INDEX(status),
  INDEX(extracted_name_norm)
);

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  level ENUM('INFO','WARN','ERROR') NOT NULL DEFAULT 'INFO',
  action VARCHAR(64) NOT NULL,
  upload_id INT NULL,
  page_job_id BIGINT NULL,
  message VARCHAR(512) NOT NULL,
  context_json JSON NULL,
  INDEX(action),
  INDEX(upload_id),
  INDEX(page_job_id)
);
EOF

# 10) Vendor install (PHPMailer)
cd "$APP_DIR"
sudo -u "$WWW_USER" composer install --no-dev

echo "OK: projekt legenerálva ide: $APP_DIR"
echo "Következő: mysql -u root -p < $APP_DIR/sql/schema.sql"
