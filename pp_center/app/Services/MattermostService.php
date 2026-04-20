<?php

namespace App\Services;

use App\Core\Logger;

class MattermostService
{
    public function notify(string $title, string $text, string $severity = 'good', array $fields = []): bool
    {
        if (!cfg('mattermost.enabled', false)) {
            Logger::write('mattermost', 'Mattermost kikapcsolva, üzenet naplózva.', compact('title', 'text', 'severity'));
            return false;
        }

        $payload = [
            'text' => "**{$title}**
{$text}",
            'attachments' => [[
                'color' => $severity === 'danger' ? '#e5484d' : ($severity === 'warning' ? '#f59e0b' : '#22c55e'),
                'fields' => array_map(fn ($name, $value) => ['title' => $name, 'value' => (string) $value, 'short' => true], array_keys($fields), $fields),
            ]],
        ];

        $ch = curl_init((string) cfg('mattermost.incoming_webhook_url'));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        Logger::write('mattermost', 'Mattermost webhook hívás', ['code' => $code, 'response' => $response, 'error' => $error]);

        return $code >= 200 && $code < 300;
    }
}
