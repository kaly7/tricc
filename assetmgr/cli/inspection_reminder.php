<?php
declare(strict_types=1);
/**
 * Felülvizsgálat emlékeztető cron script.
 * Futtatás: php /var/www/html/assetmgr/cli/inspection_reminder.php
 * Crontab példa (naponta egyszer, reggel 7-kor):
 *   0 7 * * * php /var/www/html/assetmgr/cli/inspection_reminder.php >> /var/log/assetmgr_inspection.log 2>&1
 *
 * Emailt küld:
 *   - az eszközt jelenleg birtokló dolgozónak (ha van email-je)
 *   - a központi assetmgr email-re (app_settings: inspection_central_email, default: assetmgr@perfect-phone.hu)
 *
 * Küldési feltétel: next_date <= CURDATE() + 30 nap (lejárt + hamarosan esedékes)
 */

// CLI-ből futtatva nem kell session, HTTP context
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Csak CLI módban futtatható.\n");
}

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/mailer.php';

$cfg = require __DIR__ . '/../app/config.php';
$from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');

$centralEmail = 'assetmgr@perfect-phone.hu';
try {
    $centralEmail = (string)(app_setting_get('inspection_central_email') ?: $centralEmail);
} catch (Throwable $e) {}

$pdo = db();

// Eszközök, amelyeknek a legutóbbi next_date <= ma+30 nap (és > ma-365, hogy ne küldjön régi lejártakra folyamatosan)
$sql = "
    SELECT
        a.id AS asset_id,
        a.name AS asset_name,
        a.sku,
        a.qr_value,
        a.current_employee_id,
        (SELECT next_date FROM asset_inspections
         WHERE asset_id = a.id
         ORDER BY inspection_date DESC, id DESC
         LIMIT 1) AS next_date,
        (SELECT inspection_date FROM asset_inspections
         WHERE asset_id = a.id
         ORDER BY inspection_date DESC, id DESC
         LIMIT 1) AS last_date
    FROM assets a
    WHERE a.is_deleted = 0
      AND a.inspection_required = 1
    HAVING next_date IS NOT NULL
       AND next_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       AND next_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
    ORDER BY next_date ASC
";
$assets = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$assets) {
    echo date('Y-m-d H:i:s') . " Nincs küldendő emlékeztető.\n";
    exit(0);
}

// HR dolgozók email-je
$hrEmailMap = [];
try {
    $hrPdo = db_hr();
    $empIds = array_filter(array_column($assets, 'current_employee_id'));
    if ($empIds) {
        $in = implode(',', array_fill(0, count($empIds), '?'));
        $st = $hrPdo->prepare("SELECT id, full_name, email FROM employees WHERE id IN ($in)");
        $st->execute(array_values($empIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $hrEmailMap[(int)$emp['id']] = [
                'name'  => (string)$emp['full_name'],
                'email' => (string)$emp['email'],
            ];
        }
    }
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " FIGYELEM: HR adatbázis nem érhető el: " . $e->getMessage() . "\n";
}

$sentCount = 0;
$errCount  = 0;

foreach ($assets as $row) {
    $assetId   = (int)$row['asset_id'];
    $assetName = (string)$row['asset_name'];
    $sku       = (string)($row['sku'] ?? '');
    $qr        = (string)($row['qr_value'] ?? '');
    $nextDate  = (string)$row['next_date'];
    $lastDate  = (string)($row['last_date'] ?? '');
    $empId     = (int)($row['current_employee_id'] ?? 0);

    $isOverdue = ($nextDate < date('Y-m-d'));
    $statusLabel = $isOverdue ? 'LEJÁRT' : 'Hamarosan esedékes';

    $holderName  = '';
    $holderEmail = '';
    if ($empId > 0 && isset($hrEmailMap[$empId])) {
        $holderName  = $hrEmailMap[$empId]['name'];
        $holderEmail = $hrEmailMap[$empId]['email'];
    }

    $assetLine = htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8');
    if ($sku !== '') $assetLine .= ' | Cikkszám: ' . htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
    if ($qr !== '')  $assetLine .= ' | QR/Leltár: ' . htmlspecialchars($qr, ENT_QUOTES, 'UTF-8');

    $statusColor = $isOverdue ? '#dc3545' : '#ffc107';
    $statusBg    = $isOverdue ? '#fff5f5' : '#fffdf0';

    $bodyHtml = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
        . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
        . '<div style="background:#fff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
        . '<div style="padding:16px 24px;background:' . $statusColor . ';color:' . ($isOverdue ? '#fff' : '#333') . ';">'
        . '<strong>Felülvizsgálat emlékeztető – ' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</strong>'
        . '</div>'
        . '<div style="padding:24px;">'
        . '<p>Az alábbi eszköz felülvizsgálata/kalibrációja ' . ($isOverdue ? '<strong>lejárt</strong>' : '<strong>hamarosan esedékes</strong>') . '.</p>'
        . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
        . '<tr><td style="padding:6px 0;width:180px;"><strong>Eszköz:</strong></td><td style="padding:6px 0;">' . $assetLine . '</td></tr>'
        . '<tr><td style="padding:6px 0;"><strong>Soron következő időpont:</strong></td><td style="padding:6px 0;color:' . ($isOverdue ? '#dc3545' : '#856404') . ';font-weight:bold;">' . htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8') . '</td></tr>'
        . ($lastDate ? '<tr><td style="padding:6px 0;"><strong>Utolsó felülvizsgálat:</strong></td><td style="padding:6px 0;">' . htmlspecialchars($lastDate, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
        . ($holderName ? '<tr><td style="padding:6px 0;"><strong>Felelős:</strong></td><td style="padding:6px 0;">' . htmlspecialchars($holderName, ENT_QUOTES, 'UTF-8') . '</td></tr>' : '')
        . '</table>'
        . '<p style="margin-top:18px;">Kérjük, gondoskodj a szükséges felülvizsgálatról, és rögzítsd az eredményt az eszköznyilvántartóban.</p>'
        . '<p>Üdvözlettel,<br><strong>AssetMgr – Eszköznyilvántartó</strong></p>'
        . '</div></div></div>'
        . '</body></html>';

    $subject = 'Felülvizsgálat emlékeztető – ' . $assetName . ' (' . $statusLabel . ')';

    $recipients = array_filter([$holderEmail, $centralEmail]);
    $recipients = array_unique($recipients);

    foreach ($recipients as $to) {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        try {
            send_mail_with_attachment($to, $subject, $bodyHtml, $from, null, '', '');
            echo date('Y-m-d H:i:s') . " OK  [$assetName] → $to (next: $nextDate)\n";
            $sentCount++;
        } catch (Throwable $e) {
            echo date('Y-m-d H:i:s') . " ERR [$assetName] → $to : " . $e->getMessage() . "\n";
            $errCount++;
        }
    }
}

echo date('Y-m-d H:i:s') . " Kész. Küldve: $sentCount, Hiba: $errCount\n";
exit($errCount > 0 ? 1 : 0);
