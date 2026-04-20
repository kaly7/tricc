<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Külsős átadáshoz tartozó e-mail kiküldő végpont.
 * Elfogadott külsős átadásból PDF-et készít, majd csatolmányként elküldi.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/pdf_external_handover.php';
require_once __DIR__ . '/../app/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method Not Allowed';
    exit;
}

$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId < 1) {
    flash_set('err', 'Hiányzó vagy érvénytelen átadás azonosító.');
    header('Location: /transfers.php');
    exit;
}

// Csak érvényes, elfogadott külsős átadásból készülhet és küldhető e-mail.
try {
    $transfer = warehouse_transfer_find($config, $transferId);
    if (!$transfer) {
        throw new RuntimeException('Az átadás nem található.');
    }
    if (warehouse_transfer_type_normalize((string)($transfer['transfer_type'] ?? 'internal')) !== 'external') {
        throw new RuntimeException('Csak külsős átadáshoz küldhető szállítólevél e-mail.');
    }
    if ((string)($transfer['status'] ?? '') !== 'accepted') {
        throw new RuntimeException('Csak elfogadott külsős átadáshoz küldhető e-mail.');
    }

    $sourceId = (int)($transfer['source_warehouse_id'] ?? 0);
    $targetId = (int)($transfer['target_warehouse_id'] ?? 0);
    if (!warehouse_module_admin($config) && !warehouse_user_can_view_warehouse($config, $sourceId) && !warehouse_user_can_view_warehouse($config, $targetId)) {
        throw new RuntimeException('Nincs jogosultságod az e-mail küldéshez.');
    }

    $to = trim((string)($transfer['receiver_email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A külsős átadásnál nincs érvényes e-mail cím megadva.');
    }

    $pdf = warehouse_generate_external_transfer_pdf_mpdf($config, $transfer);
    $pdfAbs = (string)($pdf['abs'] ?? '');
    $pdfFilename = (string)($pdf['filename'] ?? ('kulso_atadas_' . $transferId . '.pdf'));
    if ($pdfAbs === '' || !is_file($pdfAbs)) {
        throw new RuntimeException('A csatolandó PDF nem található.');
    }

    $tpl = __DIR__ . '/../templates/email/external_transfer_email.html';
    if (!is_file($tpl)) {
        throw new RuntimeException('Az e-mail sablon nem található.');
    }
    $logoHtml = '<img src="cid:companylogo" alt="Perfect-Phone" style="max-width:180px;height:auto;display:block;">';
    $html = (string)file_get_contents($tpl);
    $vars = [
        'LOGO' => $logoHtml,
        'REFERENCE_NO' => htmlspecialchars((string)($transfer['reference_no'] ?? warehouse_transfer_reference($transferId)), ENT_QUOTES, 'UTF-8'),
        'PARTNER_NAME' => htmlspecialchars((string)($transfer['partner_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'RECEIVER_NAME' => htmlspecialchars((string)($transfer['receiver_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        'HANDOVER_AT' => htmlspecialchars((string)($transfer['accepted_at'] ?? ($transfer['requested_at'] ?? '')), ENT_QUOTES, 'UTF-8'),
    ];
    foreach ($vars as $key => $value) {
        $html = str_replace('{{' . $key . '}}', $value, $html);
    }
    $html = (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);

    $subject = 'Szállítólevél: ' . ((string)($transfer['reference_no'] ?? '') !== '' ? (string)$transfer['reference_no'] : warehouse_transfer_reference($transferId));
    $alt = "Tisztelt " . trim((string)($transfer['partner_name'] ?? 'Partner')) . "!\n\n"
        . "A csatolt PDF-ben küldjük a külsős átadáshoz tartozó szállítólevelet / átadás-átvételi jegyzőkönyvet.\n\n"
        . "Szállítólevélszám: " . ((string)($transfer['reference_no'] ?? '') !== '' ? (string)$transfer['reference_no'] : warehouse_transfer_reference($transferId)) . "\n"
        . "Átvevő: " . trim((string)($transfer['receiver_name'] ?? '')) . "\n"
        . "Dátum: " . trim((string)($transfer['accepted_at'] ?? ($transfer['requested_at'] ?? ''))) . "\n\n"
        . "Üdvözlettel:\nPerfect-Phone";

    warehouse_send_html_mail_with_attachment(
        $to,
        $subject,
        $html,
        $alt,
        $pdfAbs,
        $pdfFilename
    );

    warehouse_audit($config, 'transfer.external_email_sent', 'stock_transfer', $transferId, [
        'reference_no' => (string)($transfer['reference_no'] ?? ''),
        'receiver_email' => $to,
        'pdf_filename' => $pdfFilename,
    ]);

    flash_set('msg', 'A szállítólevél e-mail elküldve: ' . $to);
} catch (Throwable $e) {
    warehouse_audit($config, 'transfer.external_email_failed', 'stock_transfer', $transferId > 0 ? $transferId : null, [
        'error' => $e->getMessage(),
    ]);
    flash_set('err', 'E-mail küldési hiba: ' . $e->getMessage());
}

header('Location: /transfers.php');
exit;
