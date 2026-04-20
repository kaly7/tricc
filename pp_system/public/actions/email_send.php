<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';
require_once __DIR__.'/../../src/helpers.php';

$cfg = require __DIR__.'/../../src/config.php';
$useSmtp = isset($cfg['mail']['driver']) && strtolower($cfg['mail']['driver']) === 'smtp';
if ($useSmtp) {
    require_once __DIR__.'/../../src/mailer.php'; // definiálja: send_mail_smtp(array $to, string $subject, string $body): bool
}

$u = current_user();
$record_id   = (int)($_POST['record_id'] ?? 0);
$template_id = (int)($_POST['template_id'] ?? 0);

if ($record_id<=0 || $template_id<=0) { header('Location: ../records.php?err=mail_bad'); exit; }

$db = db();

/* Rekord betöltés */
$st = $db->prepare("SELECT r.*, ps.name AS pp_name, c.name AS city_name
                    FROM records r
                    JOIN pp_status ps ON ps.id=r.pp_status_id
                    JOIN cities c ON c.id=r.city_id
                    WHERE r.id=? AND r.deleted_at IS NULL");
$st->execute([$record_id]);
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { header('Location: ../records.php?err=mail_norec'); exit; }

/* Sablon betöltés */
$st = $db->prepare("SELECT * FROM email_templates WHERE id=?");
$st->execute([$template_id]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if (!$t) { header('Location: ../records.php?err=mail_notpl'); exit; }

/* Jogosultság: admin mindent küldhet, user csak ha engedélyezett */
if ($u['role'] !== 'admin') {
  $p = $db->prepare("SELECT 1 FROM email_template_permissions WHERE template_id=? AND user_id=?");
  $p->execute([$template_id, $u['id']]);
  if (!$p->fetch()) { header('Location: ../records.php?err=mail_perm'); exit; }
}

/* PP-státusz feltétel egyezés */
$p2 = $db->prepare("SELECT 1 FROM email_template_status WHERE template_id=? AND pp_status_id=?");
$p2->execute([$template_id, $r['pp_status_id']]);
if (!$p2->fetch()) { header('Location: ../records.php?err=mail_status'); exit; }

/* Duplikált küldés tiltása */
$st = $db->prepare("SELECT 1 FROM email_sends WHERE record_id=? AND template_id=?");
$st->execute([$record_id, $template_id]);
if ($st->fetch()) { header('Location: ../records.php?err=mail_dupe'); exit; }

/* Címzettek a sablonból (aktív userek, nem üres e-mail) */
$rq = $db->prepare("SELECT u.email FROM email_template_recipients tr
                    JOIN users u ON u.id=tr.user_id
                    WHERE tr.template_id=? AND u.is_active=1 AND u.email<>''");
$rq->execute([$template_id]);
$emails = array_column($rq->fetchAll(PDO::FETCH_ASSOC), 'email');
if (!$emails) { header('Location: ../records.php?err=mail_norcpt'); exit; }

/* Mezők összeállítása a sablon szerint */
$fields = array_filter(explode(',', $t['fields_csv'] ?? ''));
$lines = [];
foreach ($fields as $f) {
  switch($f) {
    case 'eventus':    $lines[] = 'Eventus: '.$r['eventus']; break;
    case 'pp_status':  $lines[] = 'PP státusz: '.$r['pp_name']; break;
    case 'issued_at':  $lines[] = 'Kiadva: '.$r['issued_at']; break;
    case 'due_at':     $lines[] = '+38 nap: '.$r['due_at']; break;
    case 'city':       $lines[] = 'Város: '.$r['city_name']; break;
    case 'address':    $lines[] = 'Cím: '.$r['address']; break;
    case 'operation':  $lines[] = 'Elvégzendő művelet: '.$r['operation']; break;
    case 'long_desc':  $lines[] = "Leírás:\n".$r['long_desc']; break;
  }
}
$body_parts = [];
if (trim($t['body'])!=='') $body_parts[] = $t['body'];
if ($lines) $body_parts[] = implode("\n", $lines);
$body = implode("\n\n", $body_parts);

/* Küldés */
$ok = false;
if ($useSmtp) {
    // SMTP (PHPMailer)
    $ok = send_mail_smtp($emails, $t['subject'], $body);
} else {
    // mail()
    $headers = 'From: '.$cfg['mail']['from']."\r\n";
    $ok = true;
    foreach ($emails as $to) {
      if (!@mail($to, $t['subject'], $body, $headers)) $ok = false;
    }
}

/* Napló és vissza */
if ($ok) {
  $ins = $db->prepare("INSERT INTO email_sends (record_id, template_id, sent_by, sent_to) VALUES (?,?,?,?)");
  $ins->execute([$record_id, $template_id, $u['id'], implode(',', $emails)]);
  header('Location: ../records.php?msg=mail_ok'); exit;
} else {
  header('Location: ../records.php?err=mail_fail'); exit;
}