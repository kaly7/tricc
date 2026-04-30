<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function send_mail(string $to, string $toName, string $subject, string $htmlBody): bool {
  $cfg = config();
  $logFile = (string)($cfg['mail_debug_log'] ?? (__DIR__ . '/../storage/logs/mail.log'));
  $debug   = (bool)($cfg['mail_debug'] ?? false);

  $log = function(string $line) use ($debug, $logFile): void {
    if (!$debug) return;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
  };

  $log("SEND to={$to} subj={$subject}");

  try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
    if ($smtpHost !== '') {
      $mail->isSMTP();
      $mail->Host       = $smtpHost;
      $mail->Port       = (int)($cfg['smtp_port'] ?? 587);
      $secure = trim((string)($cfg['smtp_secure'] ?? ''));
      if ($secure !== '') $mail->SMTPSecure = $secure;
      $mail->SMTPAuth   = true;
      $mail->Username   = (string)($cfg['smtp_user'] ?? '');
      $mail->Password   = (string)($cfg['smtp_pass'] ?? '');
    } else {
      $mail->isMail();
    }

    $fromName = (string)($cfg['mail_from_name'] ?? 'Jármű nyilvántartó');
    $mail->setFrom((string)($cfg['mail_from'] ?? ''), $fromName);
    $mail->addAddress($to, $toName);

    $bcc = trim((string)($cfg['mail_bcc'] ?? ''));
    if ($bcc !== '') $mail->addBCC($bcc);

    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body    = $htmlBody;
    $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '</p>', '</li>'], "\n", $htmlBody)));

    $ok = $mail->send();
    $log('RESULT ok=' . ($ok ? '1' : '0'));
    return $ok;
  } catch (Throwable $e) {
    $log('ERROR ' . $e->getMessage());
    return false;
  }
}

// Admin emailcíme az auth_db-ből
function get_admin_emails(): array {
  try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=auth_db;charset=utf8mb4', 'ppdb', 'abrakadabra', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $moduleKey = (string)config()['module_slug'];
    $rows = $pdo->prepare("SELECT u.email, u.full_name FROM users u JOIN user_module_roles umr ON umr.user_id=u.id JOIN modules m ON m.id=umr.module_id JOIN roles r ON r.id=umr.role_id WHERE m.module_key=? AND r.role_key='admin' AND u.is_active=1 AND u.email IS NOT NULL AND u.email != ''");
    $rows->execute([$moduleKey]);
    return $rows->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return []; }
}

function notify_admin(string $subject, string $htmlBody): void {
  foreach (get_admin_emails() as $adm) {
    send_mail((string)$adm['email'], (string)($adm['full_name'] ?? ''), $subject, $htmlBody);
  }
}
