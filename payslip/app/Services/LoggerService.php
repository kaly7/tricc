<?php
namespace Services;

class LoggerService {
    public static function log(string $level, string $action, string $message, ?int $uploadId=null, ?int $pageJobId=null, ?array $ctx=null): void {
        $pdo = \Db::pdo();
        $stmt = $pdo->prepare("INSERT INTO audit_log(level, action, upload_id, page_job_id, message, context_json)
                               VALUES(?,?,?,?,?,?)");
        $stmt->execute([
            $level,
            $action,
            $uploadId,
            $pageJobId,
            mb_substr($message, 0, 512),
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null
        ]);
    }
}
