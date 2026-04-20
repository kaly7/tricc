<?php
declare(strict_types=1);
function audit(PDO $pdo, ?int $user_id, string $entity, string $action, ?int $entity_id, $before=null, $after=null): void {
    $stmt=$pdo->prepare("INSERT INTO audit_log (user_id,entity,action,entity_id,before_json,after_json,ip_addr,created_at) VALUES (?,?,?,?,?,?,?,NOW())");
    $ip=$_SERVER['REMOTE_ADDR']??null;
    $bj=$before?json_encode($before, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $aj=$after?json_encode($after, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
    $stmt->execute([$user_id,$entity,$action,$entity_id,$bj,$aj,$ip]);
}
