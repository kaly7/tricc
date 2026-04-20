<?php
// app/mailer.php — levelező segéd (PHPMailer, ha van; különben mail() multipart/mixed)

function mailer_config(): array {
  $cfg = [
    'method'      => 'mail',          // 'smtp' vagy 'mail'
    'smtp_host'   => '',
    'smtp_port'   => 587,
    'smtp_user'   => '',
    'smtp_pass'   => '',
    'smtp_secure' => 'tls',           // 'tls' | 'ssl' | ''
    'from_email'  => 'no-reply@example.com',
    'from_name'   => 'Dokutool',
  ];
  $file = __DIR__ . '/mail_config.php';
  if (file_exists($file)) {
    $user = include $file;
    if (is_array($user)) $cfg = array_merge($cfg, $user);
  }
  return $cfg;
}

function _encode_subject(string $s): string {
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
  }
  return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function _norm_emails($arr): array {
  $out = [];
  foreach ((array)$arr as $e) {
    $e = trim((string)$e);
    if ($e !== '') $out[] = $e;
  }
  return array_values(array_unique($out));
}

function send_mail($to_list, string $subject, string $html_body, array $attachments = [], $cc_list = [], $bcc_list = []): bool {
  $cfg = mailer_config();
  $to_list  = _norm_emails($to_list);
  $cc_list  = _norm_emails($cc_list);
  $bcc_list = _norm_emails($bcc_list);
  if (empty($to_list)) return false;

  // PHPMailer (ha telepítve van vendor/autoload.php-val)
  $autoload = __DIR__ . '/../vendor/autoload.php';
  if (file_exists($autoload)) {
    require_once $autoload;
    try {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      if ($cfg['method'] === 'smtp') {
        $mail->isSMTP();
        $mail->Host = $cfg['smtp_host'];
        $mail->Port = (int) $cfg['smtp_port'];
        if (!empty($cfg['smtp_secure'])) $mail->SMTPSecure = $cfg['smtp_secure'];
        if (!empty($cfg['smtp_user'])) {
          $mail->SMTPAuth = true;
          $mail->Username = $cfg['smtp_user'];
          $mail->Password = $cfg['smtp_pass'];
        }
      } else {
        $mail->isMail();
      }
      $mail->CharSet = 'UTF-8';
      $mail->setFrom($cfg['from_email'], $cfg['from_name']);
      foreach ($to_list as $em)  $mail->addAddress($em);
      foreach ($cc_list as $em)  $mail->addCC($em);
      foreach ($bcc_list as $em) $mail->addBCC($em);

      foreach ($attachments as $att) {
        if (!empty($att['path']) && file_exists($att['path'])) {
          $dispName = $att['name'] ?? basename($att['path']);
          $mail->addAttachment($att['path'], $dispName);
        }
      }

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html_body;
      $mail->AltBody = strip_tags($html_body);

      return $mail->send();
    } catch (Throwable $e) {
      // ha bármi gond van, esünk vissza a mail() ágra
    }
  }

  // Fallback: natív mail() multipart/mixed
  $boundary = '=_dokutool_' . md5(uniqid('', true));

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'From: ' . $cfg['from_name'] . ' <' . $cfg['from_email'] . '>';
  if (!empty($cc_list))  $headers[] = 'Cc: ' . implode(', ', $cc_list);
  if (!empty($bcc_list)) $headers[] = 'Bcc: ' . implode(', ', $bcc_list);
  $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

  $body  = '';
  $body .= '--' . $boundary . "\r\n";
  $body .= "Content-Type: text/html; charset=UTF-8\r\n";
  $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
  $body .= $html_body . "\r\n";

  foreach ($attachments as $att) {
    if (empty($att['path']) || !file_exists($att['path'])) continue;
    $fname = $att['name'] ?? basename($att['path']);
    $mime  = $att['mime'] ?? 'application/octet-stream';
    $data  = chunk_split(base64_encode(file_get_contents($att['path'])));

    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Type: ' . $mime . '; name="' . addslashes($fname) . '"' . "\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= 'Content-Disposition: attachment; filename="' . addslashes($fname) . '"' . "\r\n\r\n";
    $body .= $data . "\r\n";
  }

  $body .= '--' . $boundary . "--\r\n";

  $to = implode(', ', $to_list);
  $subj = _encode_subject($subject);
  $headers_str = implode("\r\n", $headers);

  return @mail($to, $subj, $body, $headers_str);
}