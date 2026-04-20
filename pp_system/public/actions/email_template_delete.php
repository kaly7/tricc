<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: ../admin_emails.php'); exit; }

db()->prepare('DELETE FROM email_templates WHERE id=?')->execute([$id]);
header('Location: ../admin_emails.php?msg=deleted'); exit;
