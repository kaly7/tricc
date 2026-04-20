<?php
namespace Services;

use PHPMailer\PHPMailer\PHPMailer;

class MailService {

    public static function sendWithAttachment(
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyText,
        string $attachmentPath,
        ?string $overrideTo = null
    ): void {
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

        // Test/override routing (optional)
        if ($overrideTo && filter_var($overrideTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($overrideTo, $toName ?: $toEmail);
            $mail->addReplyTo($toEmail, $toName ?: $toEmail);
            $subject = "[TEST → {$overrideTo}] " . $subject;
        } else {
            $mail->addAddress($toEmail, $toName ?: $toEmail);
        }

        $mail->Subject = $subject;
        $mail->Body = $bodyText;
        $mail->AltBody = $bodyText;

        $mail->addAttachment($attachmentPath);

        $mail->send();
    }
}
