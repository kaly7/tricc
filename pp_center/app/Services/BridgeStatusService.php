<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class BridgeStatusService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function heartbeat(string $workerName, string $status = 'running', array $details = []): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO worker_status (worker_name, status, heartbeat_at, last_error, details_json, updated_at)
             VALUES (:worker_name, :status, NOW(), NULL, :details_json, NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                heartbeat_at = NOW(),
                details_json = VALUES(details_json),
                updated_at = NOW()"
        );

        $stmt->execute([
            'worker_name' => $workerName,
            'status' => $status,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function error(string $workerName, string $message, array $details = []): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO worker_status (worker_name, status, heartbeat_at, last_error, details_json, updated_at)
             VALUES (:worker_name, 'error', NOW(), :last_error, :details_json, NOW())
             ON DUPLICATE KEY UPDATE
                status = 'error',
                heartbeat_at = NOW(),
                last_error = VALUES(last_error),
                details_json = VALUES(details_json),
                updated_at = NOW()"
        );

        $stmt->execute([
            'worker_name' => $workerName,
            'last_error' => $message,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function all(): array
    {
        return $this->db->query("SELECT * FROM worker_status ORDER BY worker_name ASC")->fetchAll();
    }

    public function find(string $workerName): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM worker_status WHERE worker_name = :worker_name LIMIT 1");
        $stmt->execute(['worker_name' => $workerName]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
