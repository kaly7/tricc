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
                SELECT m.id, m.room_id, m.user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.file_url, m.file_name, m.created_at
                FROM messages m JOIN users u ON u.id = m.user_id
                WHERE m.room_id = ? AND m.id < ?
                ORDER BY m.id DESC LIMIT ?
            ");
            $st->execute([$room_id, $before, $limit]);
        } else {
            $st = $db->prepare("
                SELECT m.id, m.room_id, m.user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.file_url, m.file_name, m.created_at
                FROM messages m JOIN users u ON u.id = m.user_id
                WHERE m.room_id = ?
                ORDER BY m.id DESC LIMIT ?
            ");
            $st->execute([$room_id, $limit]);
        }
        $rows = array_reverse($st->fetchAll());
        Response::ok($rows);
    }

    public static function send(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $type    = $body['type'] ?? 'text';
        $content = trim($body['content'] ?? '');
        $file_url  = $body['file_url']  ?? null;
        $file_name = $body['file_name'] ?? null;

        if ($type === 'text' && !$content) Response::abort(400, 'Üzenet tartalma nem lehet üres.');
        if (in_array($type, ['image', 'file']) && !$file_url) Response::abort(400, 'Fájl URL megadása kötelező.');

        $db = DB::get();
        $db->prepare("INSERT INTO messages (room_id, user_id, type, content, file_url, file_name) VALUES (?,?,?,?,?,?)")
           ->execute([$room_id, $auth['user_id'], $type, $content ?: null, $file_url, $file_name]);
        $msg_id = (int)$db->lastInsertId();

        $msg = $db->prepare("
            SELECT m.id, m.room_id, m.user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.file_url, m.file_name, m.created_at
            FROM messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?
        ");
        $msg->execute([$msg_id]);
        $row = $msg->fetch();

        self::pushToMembers($room_id, $auth['user_id'], $row);
        Response::ok($row);
    }

    public static function delete(int $room_id, int $msg_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);
        $db = DB::get();
        $st = $db->prepare("SELECT user_id FROM messages WHERE id = ? AND room_id = ?");
        $st->execute([$msg_id, $room_id]);
        $row = $st->fetch();
        if (!$row) Response::abort(404, 'Üzenet nem található.');
        if ((int)$row['user_id'] !== $auth['user_id']) {
            $adm = $db->prepare("SELECT role FROM room_members WHERE room_id=? AND user_id=?");
            $adm->execute([$room_id, $auth['user_id']]);
            $r = $adm->fetch();
            if (!$r || $r['role'] !== 'admin') Response::abort(403, 'Nincs jogosultság.');
        }
        $db->prepare("DELETE FROM messages WHERE id=?")->execute([$msg_id]);
        Response::ok();
    }

    private static function pushToMembers(int $room_id, int $sender_id, array $msg): void {
        $db = DB::get();
        $sender = $db->prepare("SELECT name FROM users WHERE id=?");
        $sender->execute([$sender_id]);
        $sname = $sender->fetchColumn() ?? 'Ismeretlen';

        $tokens = $db->prepare("
            SELECT pt.device_token FROM push_tokens pt
            JOIN room_members rm ON rm.user_id = pt.user_id
            WHERE rm.room_id = ? AND pt.user_id != ?
        ");
        $tokens->execute([$room_id, $sender_id]);

        $title = $sname;
        $body  = $msg['type'] === 'text' ? ($msg['content'] ?? '') : '📎 ' . ($msg['file_name'] ?? 'Fájl');
        foreach ($tokens->fetchAll() as $t) {
            APNs::send($t['device_token'], $title, $body, [
                'room_id'    => $room_id,
                'message_id' => $msg['id'],
            ]);
        }
    }
}
