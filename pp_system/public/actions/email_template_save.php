<?php
// Mentés: e-mail sablon + hozzárendelések (PP-státuszok, kik küldhetik, kik kapják)
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

// --- Bejövő adatok ---
$id        = (int)($_POST['id'] ?? 0);
$name      = trim((string)($_POST['name'] ?? ''));
$subject   = trim((string)($_POST['subject'] ?? ''));
$body      = trim((string)($_POST['body'] ?? ''));
$fields    = (array)($_POST['fields'] ?? []);            // kiválasztott mezők a levélbe
$pp_ids    = (array)($_POST['pp_status_id'] ?? []);      // sablon érvényes PP státuszai
$can_users = (array)($_POST['user_ids'] ?? []);          // kik KÜLDHETIK
$rcp_users = (array)($_POST['user_recipients'] ?? []);   // kik KAPJÁK

// mezők whitelist
$allowed_fields = ['eventus','pp_status','issued_at','due_at','city','address','operation','long_desc'];
$fields = array_values(array_intersect($fields, $allowed_fields));
$fields_csv = implode(',', $fields);

// minimális validáció
if ($name === '' || $subject === '') {
  header('Location: ../admin_emails.php?err=save'); exit;
}

$db = db();
$db->beginTransaction();

try {
  if ($id > 0) {
    // frissítés
    $st = $db->prepare('UPDATE email_templates SET name=?, subject=?, body=?, fields_csv=? WHERE id=?');
    $st->execute([$name, $subject, $body, $fields_csv, $id]);

    // kapcsolatok ürítése
    $db->prepare('DELETE FROM email_template_status WHERE template_id=?')->execute([$id]);
    $db->prepare('DELETE FROM email_template_permissions WHERE template_id=?')->execute([$id]);
    $db->prepare('DELETE FROM email_template_recipients WHERE template_id=?')->execute([$id]);
  } else {
    // létrehozás
    $st = $db->prepare('INSERT INTO email_templates (name, subject, body, fields_csv) VALUES (?,?,?,?)');
    $st->execute([$name, $subject, $body, $fields_csv]);
    $id = (int)$db->lastInsertId();
  }

  // PP státusz hozzárendelések
  if (!empty($pp_ids)) {
    $ins = $db->prepare('INSERT INTO email_template_status (template_id, pp_status_id) VALUES (?, ?)');
    foreach ($pp_ids as $pid) {
      $pid = (int)$pid; if ($pid > 0) { $ins->execute([$id, $pid]); }
    }
  }

  // Ki KÜLDHETI
  if (!empty($can_users)) {
    $ins = $db->prepare('INSERT INTO email_template_permissions (template_id, user_id) VALUES (?, ?)');
    foreach ($can_users as $uid) {
      $uid = (int)$uid; if ($uid > 0) { $ins->execute([$id, $uid]); }
    }
  }

  // Kik KAPJÁK (címzettek)
  if (!empty($rcp_users)) {
    $ins = $db->prepare('INSERT INTO email_template_recipients (template_id, user_id) VALUES (?, ?)');
    foreach ($rcp_users as $uid) {
      $uid = (int)$uid; if ($uid > 0) { $ins->execute([$id, $uid]); }
    }
  }

  $db->commit();
  header('Location: ../admin_emails.php?msg=saved'); exit;

} catch (Throwable $e) {
  $db->rollBack();
  // debugoláshoz ideiglenesen kiírhatod: echo $e->getMessage();
  header('Location: ../admin_emails.php?err=save'); exit;
}