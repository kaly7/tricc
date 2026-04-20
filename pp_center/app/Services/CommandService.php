<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class CommandService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function queueCommand(string $deviceId, string $commandType, array $payload, string $actor = 'web'): string
    {
        $requestId = trim((string) ($payload['request_id'] ?? ''));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(8));
            $payload['request_id'] = $requestId;
        }

        $stmt = $this->db->prepare("INSERT INTO command_queue (device_id, request_id, command_type, payload_json, status, created_by, created_at) VALUES (:device_id, :request_id, :command_type, :payload_json, 'queued', :created_by, NOW())");
        $stmt->execute([
            'device_id' => $deviceId,
            'request_id' => $requestId,
            'command_type' => $commandType,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $actor,
        ]);

        return $requestId;
    }

    public function fetchQueued(int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM command_queue WHERE status = 'queued' ORDER BY created_at ASC LIMIT {$limit}");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recent(int $limit = 50): array
    {
        return $this->recentPage(1, $limit);
    }

    public function recentPage(int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT cq.*, cr.result_ok, cr.result_message, cr.payload_json AS result_payload_json, cr.received_at AS result_received_at
                FROM command_queue cq
                LEFT JOIN (
                    SELECT cr1.*
                    FROM command_results cr1
                    INNER JOIN (
                        SELECT request_id, MAX(id) AS max_id
                        FROM command_results
                        GROUP BY request_id
                    ) latest ON latest.max_id = cr1.id
                ) cr ON cr.request_id = cq.request_id
                ORDER BY cq.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function recentByDevice(string $deviceId, int $limit = 20): array
    {
        return $this->recentByDevicePage($deviceId, 1, $limit);
    }

    public function recentByDevicePage(string $deviceId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT cq.*, cr.result_ok, cr.result_message, cr.payload_json AS result_payload_json, cr.received_at AS result_received_at
                FROM command_queue cq
                LEFT JOIN (
                    SELECT cr1.*
                    FROM command_results cr1
                    INNER JOIN (
                        SELECT request_id, MAX(id) AS max_id
                        FROM command_results
                        GROUP BY request_id
                    ) latest ON latest.max_id = cr1.id
                ) cr ON cr.request_id = cq.request_id
                WHERE cq.device_id = :device_id
                ORDER BY cq.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['device_id' => $deviceId]);
        return $stmt->fetchAll();
    }

    public function count(?string $deviceId = null): int
    {
        if ($deviceId !== null && $deviceId !== '') {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM command_queue WHERE device_id = :device_id");
            $stmt->execute(['device_id' => $deviceId]);
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->db->query("SELECT COUNT(*) FROM command_queue")->fetchColumn();
    }

    public function markSent(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE command_queue SET status = 'sent', sent_at = NOW() WHERE id = :id AND status = 'queued'");
        $stmt->execute(['id' => $id]);
    }

    public function markAcked(string $requestId, string $deviceId, array $payload): void
    {
        $ok = (int) (($payload['ok'] ?? true) ? 1 : 0);
        $message = (string) ($payload['message'] ?? '');
        $status = $ok === 1 ? 'acked' : 'failed';

        $stmt = $this->db->prepare("UPDATE command_queue SET status = :status, acked_at = NOW() WHERE request_id = :request_id");
        $stmt->execute([
            'status' => $status,
            'request_id' => $requestId,
        ]);

        $insert = $this->db->prepare(
            "INSERT INTO command_results (device_id, request_id, result_ok, result_message, payload_json, received_at)
             VALUES (:device_id, :request_id, :result_ok, :result_message, :payload_json, NOW())"
        );
        $insert->execute([
            'device_id' => $deviceId,
            'request_id' => $requestId,
            'result_ok' => $ok,
            'result_message' => $message,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function markFailed(int $id, string $message): void
    {
        $stmt = $this->db->prepare("UPDATE command_queue SET status = 'failed', acked_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id]);

        $insert = $this->db->prepare("INSERT INTO command_results (device_id, request_id, result_ok, result_message, payload_json, received_at)
            SELECT device_id, request_id, 0, :message, payload_json, NOW() FROM command_queue WHERE id = :id LIMIT 1");
        $insert->execute(['id' => $id, 'message' => $message]);
    }
}
