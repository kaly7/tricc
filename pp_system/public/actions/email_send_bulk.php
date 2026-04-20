<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';       // require_login_or_redirect() + current_user()
require_once __DIR__ . '/../../src/db.php';         // db()
require_once __DIR__ . '/../../src/helpers.php';    // h(), csrf ellenőrzés, stb. (ha nincs log, írunk itt localt)
require_once __DIR__ . '/../../src/mailer.php';     // make_mailer(), app_mail_send()

require_login_or_redirect();
$u  = current_user();
$db = db();

// ---- kis logger (ha nincs helpers-ben) ----
if (!function_exists('bulk_log')) {
    function bulk_log(string $msg): void {
        $logDir = __DIR__ . '/../../storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
        $line = '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL;
        @file_put_contents($logDir.'/email_bulk.log', $line, FILE_APPEND);
    }
}

// ---- CSRF ----
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['_csrf']) || $_POST['_csrf'] !== csrf_token()) {
    http_response_code(400);
    echo "Hiba a tömeges küldésben\nÉrvénytelen kérés (CSRF).";
    exit;
}

$template_id = (int)($_POST['template_id'] ?? 0);

// ---- sablon betöltés ----
$tpl = $db->prepare("SELECT id, name, subject, body, fields_csv FROM email_templates WHERE id=?");
$tpl->execute([$template_id]);
$tpl = $tpl->fetch(PDO::FETCH_ASSOC);
if (!$tpl) {
    echo "Hiba a tömeges küldésben\nIsmeretlen sablon.";
    exit;
}

// ---- jogosultság ellenőrzés (nem admin esetén) ----
if (!is_admin()) {
    $st = $db->prepare("SELECT 1 FROM email_template_permissions WHERE template_id=? AND user_id=?");
    $st->execute([$template_id, $u['id']]);
    if (!$st->fetchColumn()) {
        echo "Hiba a tömeges küldésben\nNincs jogosultság ehhez a sablonhoz.";
        exit;
    }
}

// ---- címzettek (a sablonhoz rendelt userek e-mail címei) ----
$recips = $db->prepare("
    SELECT u.email
    FROM email_template_recipients er
    JOIN users u ON u.id = er.user_id
    WHERE er.template_id=? AND u.is_active=1
");
$recips->execute([$template_id]);
$recipients = [];
foreach ($recips as $row) {
    $em = trim((string)$row['email']);
    if ($em !== '') $recipients[] = $em;
}
if (empty($recipients)) {
    // vissza records.php-re üzenettel
    header("Location: ../records.php?err=no_recipients");
    exit;
}

// ---- státusz filter (ha van a sablonhoz) ----
$tplStatus = $db->prepare("SELECT pp_status_id FROM email_template_status WHERE template_id=?");
$tplStatus->execute([$template_id]);
$allowedStatus = array_map('intval', array_column($tplStatus->fetchAll(PDO::FETCH_ASSOC), 'pp_status_id')); // lehet üres is

// ---- napló indul ----
bulk_log("---- BULK START by user#{$u['id']} {$u['name']} ----");
bulk_log('POST: '.json_encode($_POST, JSON_UNESCAPED_UNICODE));
bulk_log('GET : '.json_encode($_GET,  JSON_UNESCAPED_UNICODE));
bulk_log("TEMPLATE: {$tpl['name']} (#{$tpl['id']})");
bulk_log('RECIPIENTS: '.json_encode($recipients, JSON_UNESCAPED_UNICODE));

// ---- ugyanaz a szűrés, mint a records.php-ban (GET paramétereket POST-ból kaptuk vissza hidden field-ekkel) ----
$q         = trim((string)($_POST['q'] ?? ''));
$pp_mode   = ($_POST['pp_mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include';
$city_mode = ($_POST['city_mode'] ?? 'include') === 'exclude' ? 'exclude' : 'include';

$pp_raw   = $_POST['pp_status_id'] ?? [];
$city_raw = $_POST['city_id'] ?? [];
if (!is_array($pp_raw))   $pp_raw = [$pp_raw];
if (!is_array($city_raw)) $city_raw = [$city_raw];
$pp_ids   = array_values(array_unique(array_filter(array_map('intval', $pp_raw), fn($v)=>$v>0)));
$city_ids = array_values(array_unique(array_filter(array_map('intval', $city_raw), fn($v)=>$v>0)));

$include_arch    = isset($_POST['include_arch']) ? 1 : 0;
$include_deleted = 0; // bulk felületen a töröltekkel nem számolunk

$where = [];
$p     = [];
if (!$include_deleted || !is_admin()) $where[] = 'r.deleted_at IS NULL';
if (!$include_arch) $where[] = 'r.archived=0';

if (!empty($pp_ids)) {
    $place = implode(',', array_fill(0, count($pp_ids), '?'));
    $where[] = ($pp_mode === 'exclude') ? "r.pp_status_id NOT IN ($place)" : "r.pp_status_id IN ($place)";
    array_push($p, ...$pp_ids);
}
if (!empty($city_ids)) {
    $place = implode(',', array_fill(0, count($city_ids), '?'));
    $where[] = ($city_mode === 'exclude') ? "r.city_id NOT IN ($place)" : "r.city_id IN ($place)";
    array_push($p, ...$city_ids);
}
if ($q !== '') {
    foreach (preg_split('/\s+/', $q) as $t) {
        if ($t === '') continue;
        $like = '%'.$t.'%';
        $where[] = '(r.eventus LIKE ? OR r.address LIKE ? OR r.operation LIKE ? OR r.long_desc LIKE ?)';
        array_push($p, $like, $like, $like, $like);
    }
}
$wsql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// a records.php-hoz igazodva: JOIN-oljuk a szükséges aliasokat
$sql = "SELECT r.*, ps.name AS pp_name, c.name AS city_name
        FROM records r
        JOIN pp_status ps ON ps.id = r.pp_status_id
        JOIN cities    c  ON c.id  = r.city_id
        $wsql
        ORDER BY r.issued_at ASC
        LIMIT 10000";
$st = $db->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

bulk_log("WHERE: ".($wsql ?: '(none)')." | params: ".json_encode($p, JSON_UNESCAPED_UNICODE));
bulk_log("FOUND rows: ".count($rows));

// státusz-szűrés sablon szerint (ha van beállítva)
$matchedRecords = [];
if (!empty($allowedStatus)) {
    $allowedFlip = array_flip($allowedStatus);
    foreach ($rows as $r) {
        if (isset($allowedFlip[(int)$r['pp_status_id']])) {
            $matchedRecords[] = $r;
        } else {
            bulk_log("SKIP record #{$r['id']}: pp_status_id={$r['pp_status_id']} not allowed by template");
        }
    }
    bulk_log('TPL STATUS FILTER: '.json_encode($allowedStatus));
} else {
    bulk_log('INFO: template has no status filter');
    $matchedRecords = $rows;
}
if (empty($matchedRecords)) {
    bulk_log('No records to send after filter. Aborting.');
    header("Location: ../records.php?msg=mail_bulk_done&ok=0&fail=0");
    exit;
}

// ---- sablon mezők: csak ezeket tesszük a levélbe ----
$fieldKeys = array_filter(array_map('trim', explode(',', (string)$tpl['fields_csv'] ?? '')));
if (empty($fieldKeys)) {
    // ha üres, beteszünk egy minimális defaultot
    $fieldKeys = ['eventus', 'due_at', 'city'];
}
$fieldLabels = [
    'eventus'   => 'Eventus',
    'pp_status' => 'PP státusz',
    'issued_at' => 'Kiadva',
    'due_at'    => '+38 nap',
    'city'      => 'Város',
    'address'   => 'Cím',
    'operation' => 'Elvégzendő művelet',
];

// ---- HTML body felépítése ----
$bodyIntro = nl2br(h((string)$tpl['body'])); // sablon szöveg HTML-be
// táblázat fejlécek
$th = '';
foreach ($fieldKeys as $fk) {
    $th .= '<th style="text-align:left; padding:6px 8px; border-bottom:1px solid #ddd;">'.h($fieldLabels[$fk] ?? strtoupper($fk)).'</th>';
}
// táblázat sorai
$tr = '';
foreach ($matchedRecords as $r) {
    $map = [
        'eventus'   => $r['eventus']    ?? '',
        'pp_status' => $r['pp_name']    ?? '',
        'issued_at' => $r['issued_at']  ?? '',
        'due_at'    => $r['due_at']     ?? '',
        'city'      => $r['city_name']  ?? '',
        'address'   => $r['address']    ?? '',
        'operation' => $r['operation']  ?? '',
    ];
    $td = '';
    foreach ($fieldKeys as $fk) {
        $val = (string)($map[$fk] ?? '');
        // hosszú mezők (operation) mehet több sorba
        $valHtml = nl2br(h($val));
        $td .= '<td style="vertical-align:top; padding:6px 8px; border-bottom:1px solid #f1f1f1;">'.$valHtml.'</td>';
    }
    $tr .= '<tr>'.$td.'</tr>';
}

$tableHtml = '
  <table cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse; width:100%; max-width:1000px; font-family:Arial, Helvetica, sans-serif; font-size:14px;">
    <thead>
      <tr style="background:#f7f7f7;">'.$th.'</tr>
    </thead>
    <tbody>'.$tr.'</tbody>
  </table>
';

$bodyHtml = <<<HTML
<div style="font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#222;">
  <div style="margin-bottom:12px;">{$bodyIntro}</div>
  <hr style="border:none; height:1px; background:#ddd; margin:12px 0;">
  {$tableHtml}
</div>
HTML;

// ---- KÜLDÉS: egy darab levél, összes címzettnek ----
try {
    // ezt a wrapper-t úgy állítsd be a mailer.php-ben, hogy HTML módban küldjön:
    // $m->isHTML(true);
    list($sentOk, $sendErr) = app_mail_send($recipients, (string)$tpl['subject'], $bodyHtml);
} catch (Throwable $e) {
    $sentOk = false;
    $sendErr = $e->getMessage();
}

$okCount = $sentOk ? 1 : 0;
$failCount = $sentOk ? 0 : 1;

// ---- DB nyomok: minden érintett rekord kap bejegyzést ----
$toList = implode(',', $recipients);

if ($sentOk) {
    bulk_log("MAIL SENT ok → to={$toList}, records=".count($matchedRecords));
    // email_sends
    $insSend = $db->prepare("INSERT IGNORE INTO email_sends (record_id, template_id, sent_by, sent_to) VALUES (?, ?, ?, ?)");
    // record_changes
    $insChg  = $db->prepare("INSERT INTO record_changes (record_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'email_sent', NULL, ?)");

    foreach ($matchedRecords as $r) {
        $insSend->execute([$r['id'], $tpl['id'], $u['id'], $toList]);
        $insChg->execute([$r['id'], $u['id'], "Template={$tpl['name']} → {$toList}"]);
    }
} else {
    bulk_log("MAIL FAILED: {$sendErr}");
    $insFail = $db->prepare("INSERT INTO record_changes (record_id, changed_by, field, old_value, new_value) VALUES (?, ?, 'email_failed', NULL, ?)");
    foreach ($matchedRecords as $r) {
        $insFail->execute([$r['id'], $u['id'], "Template={$tpl['name']}; Hiba={$sendErr}"]);
    }
}

// ---- vissza records.php ----
$qstring = http_build_query([
    'msg'  => 'mail_bulk_done',
    'ok'   => $okCount,
    'fail' => $failCount,
], '', '&');

header("Location: ../records.php?{$qstring}");
exit;
