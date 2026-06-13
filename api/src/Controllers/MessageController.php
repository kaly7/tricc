<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response, APNs};

class MessageController {

    private static function enrichRows(array $rows, int $my_id, \PDO $db): array {
        foreach ($rows as &$row) {
            if ((int)$row['user_id'] === $my_id) {
                $ds = $db->prepare("SELECT user_id, delivered_at, read_at FROM message_deliveries WHERE message_id=?");
                $ds->execute([$row['id']]);
                $row['deliveries'] = $ds->fetchAll();
            } else {
                $row['deliveries'] = [];
            }

            if ($row['reply_to_id']) {
                $row['reply_to'] = [
                    'id'        => (int)$row['reply_to_id'],
                    'content'   => $row['reply_to_content'],
                    'user_name' => $row['reply_to_user_name'],
                ];
            } else {
                $row['reply_to'] = null;
            }
            unset($row['reply_to_id'], $row['reply_to_content'], $row['reply_to_user_name']);

            $rs = $db->prepare("SELECT emoji, COUNT(*) AS count, GROUP_CONCAT(user_id) AS user_ids FROM message_reactions WHERE message_id=? GROUP BY emoji");
            $rs->execute([$row['id']]);
            $reactions = [];
            foreach ($rs->fetchAll() as $r) {
                $uids = array_map('intval', explode(',', $r['user_ids']));
                $reactions[] = [
                    'emoji'    => $r['emoji'],
                    'count'    => (int)$r['count'],
                    'user_ids' => $uids,
                    'mine'     => in_array($my_id, $uids),
                ];
            }
            $row['reactions'] = $reactions;

            $ms = $db->prepare("SELECT user_id FROM message_mentions WHERE message_id=?");
            $ms->execute([$row['id']]);
            $row['mention_user_ids'] = array_map('intval', array_column($ms->fetchAll(), 'user_id'));
            $row['mention_all']      = (bool)$row['mention_all'];
        }
        unset($row);
        return $rows;
    }

    public static function list(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);
        $before = (int)($_GET['before'] ?? 0);
        $limit  = min((int)($_GET['limit'] ?? 50), 100);

        $db = DB::get();
        if ($before) {
            $st = $db->prepare("
                SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.mention_all, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                       m.reply_to_id, m.reply_to_content, m.reply_to_user_name
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.room_id = ? AND m.id < ?
                ORDER BY m.id DESC LIMIT $limit
            ");
            $st->execute([$room_id, $before]);
        } else {
            $st = $db->prepare("
                SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                       m.type, m.content, m.mention_all, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                       m.reply_to_id, m.reply_to_content, m.reply_to_user_name
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.room_id = ?
                ORDER BY m.id DESC LIMIT $limit
            ");
            $st->execute([$room_id]);
        }
        $rows = array_reverse($st->fetchAll());
        Response::ok(self::enrichRows($rows, $auth['user_id'], $db));
    }

    public static function search(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) Response::ok([]);

        $db = DB::get();
        $st = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.mention_all, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                   m.reply_to_id, m.reply_to_content, m.reply_to_user_name
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.room_id = ? AND m.content LIKE ?
            ORDER BY m.id DESC LIMIT 50
        ");
        $st->execute([$room_id, '%' . $q . '%']);
        Response::ok(self::enrichRows($st->fetchAll(), $auth['user_id'], $db));
    }

    public static function media(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $db = DB::get();
        $st = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.mention_all, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                   m.reply_to_id, m.reply_to_content, m.reply_to_user_name
            FROM messages m JOIN users u ON u.id = m.sender_id
            WHERE m.room_id = ? AND m.type IN ('image', 'file', 'video')
            ORDER BY m.id DESC LIMIT 100
        ");
        $st->execute([$room_id]);
        Response::ok(self::enrichRows($st->fetchAll(), $auth['user_id'], $db));
    }

    public static function send(int $room_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $type    = $body['type'] ?? 'text';
        $content = trim($body['content'] ?? '');
        $file_url  = $body['file_url'] ?? null;
        $file_name = $body['file_name'] ?? null;
        $file_size = isset($body['file_size']) ? (int)$body['file_size'] : null;
        $reply_to_id = (int)($body['reply_to_id'] ?? 0);

        if ($type === 'text' && !$content) Response::abort(400, 'Üzenet tartalma nem lehet üres.');
        if (in_array($type, ['image', 'file']) && !$file_url) Response::abort(400, 'Fájl URL megadása kötelező.');

        $db = DB::get();

        // Reply cache
        $reply_to_content = null;
        $reply_to_user_name = null;
        if ($reply_to_id) {
            $rt = $db->prepare("SELECT m.content, u.name FROM messages m JOIN users u ON u.id=m.sender_id WHERE m.id=? AND m.room_id=?");
            $rt->execute([$reply_to_id, $room_id]);
            $rtRow = $rt->fetch();
            if ($rtRow) {
                $reply_to_content   = mb_substr($rtRow['content'], 0, 200);
                $reply_to_user_name = $rtRow['name'];
            } else {
                $reply_to_id = 0;
            }
        }

        $db->prepare("INSERT INTO messages (room_id, sender_id, type, content, file_url, file_name, file_size, reply_to_id, reply_to_content, reply_to_user_name) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$room_id, $auth['user_id'], $type, $content ?: '', $file_url ?? '', $file_name, $file_size, $reply_to_id ?: null, $reply_to_content, $reply_to_user_name]);
        $msg_id = (int)$db->lastInsertId();

        $msg = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                   m.reply_to_id, m.reply_to_content, m.reply_to_user_name
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

        // reply_to objektum összerakása a válaszba
        if ($row['reply_to_id']) {
            $row['reply_to'] = [
                'id'        => (int)$row['reply_to_id'],
                'content'   => $row['reply_to_content'],
                'user_name' => $row['reply_to_user_name'],
            ];
        } else {
            $row['reply_to'] = null;
        }
        unset($row['reply_to_id'], $row['reply_to_content'], $row['reply_to_user_name']);

        $row['deliveries'] = [];
        $row['reactions']  = [];
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

        self::wsBroadcastRaw($room_id, [
            'type'       => 'message_deleted',
            'room_id'    => $room_id,
            'message_id' => $msg_id,
        ]);

        Response::ok();
    }

    public static function edit(int $room_id, int $msg_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $db  = DB::get();
        $st  = $db->prepare("SELECT sender_id, type FROM messages WHERE id=? AND room_id=?");
        $st->execute([$msg_id, $room_id]);
        $row = $st->fetch();
        if (!$row) Response::abort(404, 'Üzenet nem található.');
        if ((int)$row['sender_id'] !== $auth['user_id']) Response::abort(403, 'Csak saját üzenetet szerkeszthetsz.');
        if (!in_array($row['type'], ['text', 'link'])) Response::abort(400, 'Csak szöveges üzenet szerkeszthető.');

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $content = trim($body['content'] ?? '');
        if (!$content) Response::abort(400, 'Tartalom nem lehet üres.');

        $db->prepare("UPDATE messages SET content=?, is_edited=1 WHERE id=?")->execute([$content, $msg_id]);

        $msg = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                   m.reply_to_id, m.reply_to_content, m.reply_to_user_name
            FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?
        ");
        $msg->execute([$msg_id]);
        $updated = $msg->fetch();

        if ($updated['reply_to_id']) {
            $updated['reply_to'] = [
                'id'        => (int)$updated['reply_to_id'],
                'content'   => $updated['reply_to_content'],
                'user_name' => $updated['reply_to_user_name'],
            ];
        } else {
            $updated['reply_to'] = null;
        }
        unset($updated['reply_to_id'], $updated['reply_to_content'], $updated['reply_to_user_name']);
        $updated['deliveries'] = [];
        $updated['reactions']  = [];

        self::wsBroadcastRaw($room_id, [
            'type'    => 'message_edited',
            'room_id' => $room_id,
            'message' => $updated,
        ]);

        Response::ok($updated);
    }

    public static function reactionToggle(int $room_id, int $msg_id): never {
        $auth = Auth::require();
        RoomController::assertMemberStatic($room_id, $auth['user_id']);

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $emoji = trim($body['emoji'] ?? '');
        if (!$emoji) Response::abort(400, 'emoji megadása kötelező.');

        $db  = DB::get();
        $uid = $auth['user_id'];

        // Üzenet ellenőrzése
        $chk = $db->prepare("SELECT id FROM messages WHERE id=? AND room_id=?");
        $chk->execute([$msg_id, $room_id]);
        if (!$chk->fetch()) Response::abort(404, 'Üzenet nem található.');

        // Toggle: ha már van, töröljük; ha nincs, hozzáadjuk
        $ex = $db->prepare("SELECT id FROM message_reactions WHERE message_id=? AND user_id=? AND emoji=?");
        $ex->execute([$msg_id, $uid, $emoji]);
        if ($ex->fetch()) {
            $db->prepare("DELETE FROM message_reactions WHERE message_id=? AND user_id=? AND emoji=?")
               ->execute([$msg_id, $uid, $emoji]);
            $action = 'removed';
        } else {
            $db->prepare("INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (?,?,?)")
               ->execute([$msg_id, $uid, $emoji]);
            $action = 'added';
        }

        // Friss reaction aggregát
        $rs = $db->prepare("SELECT emoji, COUNT(*) AS count, GROUP_CONCAT(user_id) AS user_ids FROM message_reactions WHERE message_id=? GROUP BY emoji");
        $rs->execute([$msg_id]);
        $reactions = [];
        foreach ($rs->fetchAll() as $r) {
            $reactions[] = [
                'emoji'    => $r['emoji'],
                'count'    => (int)$r['count'],
                'user_ids' => array_map('intval', explode(',', $r['user_ids'])),
            ];
        }

        // WS broadcast a szoba tagjainak
        self::wsBroadcastRaw($room_id, [
            'type'       => 'reaction',
            'room_id'    => $room_id,
            'message_id' => $msg_id,
            'user_id'    => $uid,
            'emoji'      => $emoji,
            'action'     => $action,
            'reactions'  => $reactions,
        ]);

        Response::ok(['reactions' => $reactions, 'action' => $action]);
    }

    private static function wsBroadcastRaw(int $room_id, array $payload): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode($payload, JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }

    public static function pushToMembers(int $room_id, int $sender_id, array $msg, int $msg_id): void {
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
        $tokenRows = $tokens->fetchAll();

        file_put_contents('/var/www/html/tricc/uploads/apns_debug.log',
            date('Y-m-d H:i:s') . " pushToMembers room=$room_id sender=$sender_id tokens=" . count($tokenRows) . PHP_EOL,
            FILE_APPEND);
        error_log("[Tricc] pushToMembers room=$room_id sender=$sender_id tokens=" . count($tokenRows));

        $title = $sname;
        $body  = match($msg['type']) {
            'text'  => $msg['content'] ?? '',
            'image' => '🖼 Kép',
            'video' => '🎥 Videó',
            default => '📎 Fájl',
        };
        $now   = date('Y-m-d H:i:s');
        foreach ($tokenRows as $t) {
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

    public static function wsBroadcast(int $room_id, array $message): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode(['room_id' => $room_id, 'message' => $message], JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }
}
