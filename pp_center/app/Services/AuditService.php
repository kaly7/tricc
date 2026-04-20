<?php

namespace App\Services;

use App\Core\Database;
use PDO;

class AuditService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function log(string $source, string $actor, string $action, ?string $deviceId = null, array $details = []): void
    {
        $stmt = $this->db->prepare("INSERT INTO audit_log (source, actor, action, device_id, details_json, created_at) VALUES (:source, :actor, :action, :device_id, :details_json, NOW())");
        $stmt->execute([
            'source' => $source,
            'actor' => $actor,
            'action' => $action,
            'device_id' => $deviceId,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
