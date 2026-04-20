<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/config.php';

function send_pp_status_to_ai(): array {
    $rows = db()->query("SELECT id, name, color_hex FROM pp_status ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $payload = [
        'source' => 'pp_system',
        'type' => 'pp_status_sync',
        'sent_at' => date('c'),
        'statuses' => $rows
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $subject = 'PP status sync';
    $body = '<pre>' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '</pre>';

    [$ok, $err] = app_mail_send(AI_STATUS_SYNC_EMAIL, $subject, $body);

    $logLine = sprintf(
        "[%s] status_sync ok=%s err=%s payload=%s\n",
        date('Y-m-d H:i:s'),
        $ok ? '1' : '0',
        $err ?? '',
        $json
    );
    @file_put_contents(__DIR__ . '/../storage/logs/pp_status_sync.log', $logLine, FILE_APPEND);

    return [$ok, $err];
}