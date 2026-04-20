<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/config.php';

function make_mailer(): PHPMailer {
    $mail = new PHPMailer(true);

    // KÉNYSZERÍTETT SMTP
    $mail->isSMTP();
    $mail->Host       = "mail.t-online.hu";
    $mail->SMTPAuth   = true;
    $mail->Username   = "noreply@perfect-phone.hu";
    $mail->Password   = "PPn0R3p1@y-25";
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // SMTP debug → fájlba
    $mail->SMTPDebug  = 2; // 0=off, 2=részletes
    $mail->Debugoutput = function($str, $level) {
        @file_put_contents(__DIR__.'/../storage/logs/email_smtp.log',
            '['.date('Y-m-d H:i:s')."] $str\n", FILE_APPEND);
    };

    // Feladó
    $mail->setFrom("noreply@perfect-phone.hu", "PP-SYSTEM");

    // Kódolás / alapok
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    return $mail;
}

function app_mail_send(string|array $to, string $subject, string $bodyText): array {
    try {
        $m = make_mailer();
        $m->clearAllRecipients();

        // fogadunk stringet vagy tömböt
        $recips = is_array($to) ? $to : [$to];
        foreach ($recips as $addr) {
            $addr = trim((string)$addr);
            if ($addr !== '') {
                $m->addAddress($addr);
            }
        }

        $m->Subject = $subject;
        $m->Body    = $bodyText;   // sima szöveg; ha HTML kell: $m->isHTML(true)

        $ok = $m->send();
        return [$ok, $ok ? null : $m->ErrorInfo];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}