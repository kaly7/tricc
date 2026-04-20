<?php
declare(strict_types=1);

// Minimal PHPMailer loader (composer nélkül)
require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send text email with a single attachment.
 * Uses PHPMailer.
 * - If smtp_host is set in config.php, sends via SMTP.
 * - Otherwise uses PHP mail() transport via PHPMailer.
 */
function send_mail_with_attachment(
  string $to,
  string $subject,
  string $bodyText,
  string $from,
  ?string $bcc,
  string $attachmentAbs,
  string $attachmentName
): bool {
  $cfg = require __DIR__ . '/config.php';

  // Debug logging (optional)
  $logEnabled = (bool)($cfg['mail_debug'] ?? false);
  $logFile = (string)($cfg['mail_debug_log'] ?? (__DIR__ . '/../storage/logs/mail.log'));
  $smtpDebugLevel = (int)($cfg['smtp_debug_level'] ?? 0); // 0..4 (PHPMailer)
  $smtpDebugLines = [];

  $log = function(string $line) use ($logEnabled, $logFile): void {
    if (!$logEnabled) return;
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
  };

  $log('[' . date('Y-m-d H:i:s') . "] SEND to={$to} subj=" . $subject);

  try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    // SMTP debug capture
    if ($smtpDebugLevel > 0) {
      $mail->SMTPDebug = $smtpDebugLevel;
      $mail->Debugoutput = function($str, $level) use (&$smtpDebugLines) {
        $smtpDebugLines[] = "[SMTP:$level] $str";
      };
    }

    $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
    if ($smtpHost !== '') {
      $mail->isSMTP();
      $mail->Host = $smtpHost;
      $mail->Port = (int)($cfg['smtp_port'] ?? 587);

      $secure = trim((string)($cfg['smtp_secure'] ?? ''));
      if ($secure !== '') {
        // 'tls' vagy 'ssl'
        $mail->SMTPSecure = $secure;
      }

      $smtpUser = (string)($cfg['smtp_user'] ?? '');
      $smtpPass = (string)($cfg['smtp_pass'] ?? '');
      if ($smtpUser !== '') {
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
      } else {
        $mail->SMTPAuth = false;
      }

      // Opcionális, ha önaláírt / belsős tanúsítvány van
      if (isset($cfg['smtp_options']) && is_array($cfg['smtp_options'])) {
        $mail->SMTPOptions = $cfg['smtp_options'];
      }
    } else {
      // PHPMailer mail() transport (helyi MTA kell)
      $mail->isMail();
    }

    $fromName = (string)($cfg['mail_from_name'] ?? '');
    $mail->setFrom($from, $fromName !== '' ? $fromName : '');
    $mail->addAddress($to);
    if ($bcc && trim($bcc) !== '') {
      $mail->addBCC(trim($bcc));
    }

    $mail->Subject = $subject;

    $isHtml = preg_match('/<\s*(html|body|div|table|p|br|img|ul|li|h[1-6])\b/i', $bodyText) === 1;
    if ($isHtml) {
      $mail->isHTML(true);
      $logoAbs = __DIR__ . '/../public/assets/perfect-phone-logo.png';
      if (strpos($bodyText, 'cid:companylogo') !== false && is_file($logoAbs)) {
        $mail->addEmbeddedImage($logoAbs, 'companylogo', 'perfect-phone-logo.png');
      }
      $mail->Body = $bodyText;
      $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>'], ["\n", "\n", "\n", "\n\n", "\n", "\n"], $bodyText)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
      $mail->isHTML(false);
      $plain = str_replace(["\r\n", "\r"], ["\n", "\n"], $bodyText);
      $plain = preg_replace("/\n{3,}/", "\n\n", $plain);
      $mail->Body = str_replace("\n", "\r\n", $plain);
      $mail->AltBody = $mail->Body;
    }

    if (is_file($attachmentAbs)) {
      $mail->addAttachment($attachmentAbs, $attachmentName);
    }

    $ok = $mail->send();
    $log('[' . date('Y-m-d H:i:s') . "] RESULT ok=" . ($ok ? '1' : '0'));
    if (!empty($smtpDebugLines)) {
      foreach ($smtpDebugLines as $l) $log($l);
    }
    return $ok;
  } catch (Throwable $e) {
    $log('[' . date('Y-m-d H:i:s') . '] ERROR ' . $e->getMessage());
    if (!empty($smtpDebugLines)) {
      foreach ($smtpDebugLines as $l) $log($l);
    }
    return false;
  }
}
