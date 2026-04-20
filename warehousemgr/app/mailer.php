<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * PHPMailer alapú segédfüggvények.
 * A külsős átadások e-mail küldése és a logó beágyazása innen történik.
 */

/**
 * E-mail konfiguráció beolvasása a külön config fájlból.
 */
function warehouse_mail_config(): array {
    $file = __DIR__ . '/config/mail.php';
    if (is_file($file)) {
        $cfg = require $file;
        return is_array($cfg) ? $cfg : [];
    }
    return [];
}

function warehouse_mail_embed_logo_abs(): ?string {
    $png = __DIR__ . '/../public/assets/perfect-phone-logo.png';
    if (is_file($png)) return $png;
    $jpg = __DIR__ . '/../public/assets/perfect-phone-logo.jpg';
    if (is_file($jpg)) return $jpg;
    return null;
}

/**
 * HTML e-mail küldése egy csatolt dokumentummal.
 * A külsős átadások szállítólevele ezt a csatornát használja.
 */
function warehouse_send_html_mail_with_attachment(
    string $to,
    string $subject,
    string $htmlBody,
    string $altBody,
    string $attachmentAbs,
    string $attachmentName
): void {
    $cfg = warehouse_mail_config();
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Hiányzik a vendor/autoload.php. Futtasd a warehousemgr könyvtárban: composer install');
    }
    require_once $autoload;
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        throw new RuntimeException('A PHPMailer nincs telepítve. Futtasd a warehousemgr könyvtárban: composer require phpmailer/phpmailer');
    }

    $from = trim((string)($cfg['mail_from'] ?? ''));
    if ($from === '') {
        throw new RuntimeException('Hiányzik a mail_from beállítás az app/config/mail.php fájlban.');
    }

    $fromName = trim((string)($cfg['mail_from_name'] ?? ''));
    $bcc = trim((string)($cfg['mail_bcc'] ?? ''));
    $smtpHost = trim((string)($cfg['smtp_host'] ?? ''));
    $smtpPort = (int)($cfg['smtp_port'] ?? 587);
    $smtpSecure = trim((string)($cfg['smtp_secure'] ?? ''));
    $smtpUser = trim((string)($cfg['smtp_user'] ?? ''));
    $smtpPass = (string)($cfg['smtp_pass'] ?? '');
    $smtpOptions = isset($cfg['smtp_options']) && is_array($cfg['smtp_options']) ? $cfg['smtp_options'] : [];
    $logEnabled = (bool)($cfg['mail_debug'] ?? false);
    $logFile = (string)($cfg['mail_debug_log'] ?? (__DIR__ . '/../storage/logs/mail.log'));
    $smtpDebugLevel = (int)($cfg['smtp_debug_level'] ?? 0);

    $log = static function (string $line) use ($logEnabled, $logFile): void {
        if (!$logEnabled) return;
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND);
    };

    $debugLines = [];

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    if ($smtpDebugLevel > 0) {
        $mail->SMTPDebug = $smtpDebugLevel;
        $mail->Debugoutput = static function ($str, $level) use (&$debugLines): void {
            $debugLines[] = "[SMTP:$level] $str";
        };
    }

    if ($smtpHost !== '') {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->Port = $smtpPort;
        if ($smtpSecure !== '') {
            $mail->SMTPSecure = $smtpSecure;
        }
        if ($smtpUser !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
        } else {
            $mail->SMTPAuth = false;
        }
        if ($smtpOptions !== []) {
            $mail->SMTPOptions = $smtpOptions;
        }
    } else {
        $mail->isMail();
    }

    $mail->setFrom($from, $fromName !== '' ? $fromName : '');
    $mail->addAddress($to);
    if ($bcc !== '') {
        $mail->addBCC($bcc);
    }
    $mail->Subject = $subject;
    $mail->isHTML(true);
    $mail->Body = $htmlBody;
    $mail->AltBody = $altBody;

    $logoAbs = warehouse_mail_embed_logo_abs();
    if ($logoAbs && strpos($htmlBody, 'cid:companylogo') !== false) {
        $cidName = basename($logoAbs);
        $mail->addEmbeddedImage($logoAbs, 'companylogo', $cidName);
    }

    if ($attachmentAbs !== '' && is_file($attachmentAbs)) {
        $mail->addAttachment($attachmentAbs, $attachmentName !== '' ? $attachmentName : basename($attachmentAbs));
    }

    try {
        $ok = $mail->send();
        $log('SEND to=' . $to . ' subject=' . $subject . ' ok=' . ($ok ? '1' : '0'));
        foreach ($debugLines as $line) {
            $log($line);
        }
        if (!$ok) {
            throw new RuntimeException('Az e-mail küldése sikertelen.');
        }
    } catch (Throwable $e) {
        $log('ERROR to=' . $to . ' subject=' . $subject . ' msg=' . $e->getMessage());
        foreach ($debugLines as $line) {
            $log($line);
        }
        throw $e;
    }
}
