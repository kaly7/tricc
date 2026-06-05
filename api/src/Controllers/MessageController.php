<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response, APNs};

class MessageController {
    public static function list(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);
        $before = (int)($_GET['before'] ?? 0);
        $limit  = min((int)($_GET['limit'] ?? 50), 100);

        $db = DB::get();
        if ($before) {
            $st = $db->prepare("
                SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.file_url, m.created_at
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.room_id = ? AND m.id < ?
                ORDER BY m.id DESC LIMIT $limit
            ");
            $st->execute([$room_id, $before]);
        } else {
            $st = $db->prepare("
                SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.file_url, m.created_at
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.room_id = ?
                ORDER BY m.id DESC LIMIT $limit
            ");
            $st->execute([$room_id]);
        }
        $rows = array_reverse($st->fetchAll());

        $my_id = $auth['user_id'];
        foreach ($rows as &$row) {
            if ((int)$row['user_id'] === $my_id) {
                $ds = $db->prepare("SELECT user_id, delivered_at, read_at FROM message_deliveries WHERE message_id=?");
                $ds->execute([$row['id']]);
                $row['deliveries'] = $ds->fetchAll();
            } else {
                $row['deliveries'] = [];
            }
        }
        unset($row);

        Response::ok($rows);
    }

    public static function send(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $type    = $body['type'] ?? 'text';
        $content = trim($body['content'] ?? '');
        $file_url = $body['file_url'] ?? null;

        if ($type === 'text' && !$content) Response::abort(400, 'Üzenet tartalma nem lehet üres.');
        if (in_array($type, ['image', 'file']) && !$file_url) Response::abort(400, 'Fájl URL megadása kötelező.');

        $db = DB::get();
        $db->prepare("INSERT INTO messages (room_id, sender_id, type, content, file_url) VALUES (?,?,?,?,?)")
           ->execute([$room_id, $auth['user_id'], $type, $content ?: '', $file_url ?? '']);
        $msg_id = (int)$db->lastInsertId();

        $msg = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.file_url, m.created_at
            FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?
        ");
        $msg->execute([$msg_id]);
        $row = $msg->fetch();

        // Delivery rekordok létrehozása minden tagnak (küldő kivételével)
        $db->prepare("INSERT IGNORE INTO message_deliveries (message_id, user_id)
            SELECT ?, rm.user_id FROM room_members rm WHERE rm.room_id=? AND rm.user_id!=?")
           ->execute([$msg_id, $room_id, $auth['user_id']]);

        // Auto-unhide + delete_requested_by törlése: új üzenet hatására szoba újra látható, banner eltűnik
        $db->prepare("UPDATE room_members SET hidden_at=NULL WHERE room_id=? AND hidden_at IS NOT NULL")
           ->execute([$room_id]);
        $db->prepare("UPDATE rooms SET delete_requested_by=NULL WHERE id=? AND delete_requested_by IS NOT NULL")
           ->execute([$room_id]);

        $row['deliveries'] = [];
        self::pushToMembers($room_id, $auth['user_id'], $row, $msg_id);
        self::wsBroadcast($room_id, $row);
        Response::ok($row);
    }

    public static function delete(int $room_id, int $msg_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);
        $db = DB::get();
        $st = $db->prepare("SELECT sender_id FROM messages WHERE id = ? AND room_id = ?");
        $st->execute([$msg_id, $room_id]);
        $row = $st->fetch();
        if (!$row) Response::abort(404, 'Üzenet nem található.');
        if ((int)$row['sender_id'] !== $auth['user_id']) {
            $adm = $db->prepare("SELECT role FROM room_members WHERE room_id=? AND user_id=?");
            $adm->execute([$room_id, $auth['user_id']]);
            $r = $adm->fetch();
            if (!$r || $r['role'] !== 'admin') Response::abort(403, 'Nincs jogosultság.');
        }
        $db->prepare("DELETE FROM messages WHERE id=?")->execute([$msg_id]);
        Response::ok();
    }

    private static function pushToMembers(int $room_id, int $sender_id, array $msg, int $msg_id): void {
        $db = DB::get();
        $sender = $db->prepare("SELECT name FROM users WHERE id=?");
        $sender->execute([$sender_id]);
        $sname = $sender->fetchColumn() ?? 'Ismeretlen';

        $tokens = $db->prepare("
            SELECT pt.token, pt.user_id FROM push_tokens pt
            JOIN room_members rm ON rm.user_id = pt.user_id
            WHERE rm.room_id = ? AND pt.user_id != ? AND rm.is_muted = 0
        ");
        $tokens->execute([$room_id, $sender_id]);

        $title = $sname;
        $body  = $msg['type'] === 'text' ? ($msg['content'] ?? '') : '📎 Fájl';
        $now   = date('Y-m-d H:i:s');
        foreach ($tokens->fetchAll() as $t) {
            // Badge = összes olvasatlan üzenet száma a felhasználónak
            $badgeSt = $db->prepare("
                SELECT COUNT(*) FROM messages m
                JOIN room_members rm ON rm.room_id = m.room_id AND rm.user_id = ?
                WHERE rm.hidden_at IS NULL
                  AND (rm.last_read_at IS NULL OR m.created_at > rm.last_read_at)
            ");
            $badgeSt->execute([$t['user_id']]);
            $badge = (int)$badgeSt->fetchColumn();

            $ok = APNs::send($t['token'], $title, $body, [
                'room_id'    => $room_id,
                'message_id' => $msg['id'],
            ], $badge);
            if ($ok) {
                $db->prepare("UPDATE message_deliveries SET delivered_at=? WHERE message_id=? AND user_id=?")
                   ->execute([$now, $msg_id, $t['user_id']]);
                self::wsToUser($sender_id, [
                    'type'         => 'status_update',
                    'room_id'      => $room_id,
                    'message_id'   => $msg_id,
                    'user_id'      => (int)$t['user_id'],
                    'delivered_at' => $now,
                    'read_at'      => null,
                ]);
            }
        }
    }

    private static function wsToUser(int $user_id, array $payload): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode(['target_user' => $user_id, 'payload' => $payload], JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }

    private static function wsBroadcast(int $room_id, array $message): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode(['room_id' => $room_id, 'message' => $message], JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }
}
