<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response};

class RoomController {
    public static function list(): never {
        $auth = Auth::require();
        $db   = DB::get();
        $st   = $db->prepare("
            SELECT r.id, r.name, r.type, r.created_at,
                   r.delete_requested_by,
                   rm.is_muted,
                   COUNT(DISTINCT rm2.user_id) AS member_count,
                   (SELECT COALESCE(NULLIF(m.content, ''), m.file_name, SUBSTRING_INDEX(m.file_url, '/', -1))
                    FROM messages m WHERE m.room_id=r.id ORDER BY m.created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM messages WHERE room_id=r.id ORDER BY created_at DESC LIMIT 1) AS last_message_at,
                   (SELECT COUNT(*) FROM messages m
                    WHERE m.room_id=r.id
                    AND (rm.last_read_at IS NULL OR m.created_at > rm.last_read_at)) AS unread_count
            FROM rooms r
            JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ? AND rm.hidden_at IS NULL
            JOIN room_members rm2 ON rm2.room_id = r.id
            GROUP BY r.id
            ORDER BY last_message_at DESC, r.created_at DESC
        ");
        $st->execute([$auth['user_id']]);
        $rooms = $st->fetchAll();

        // Direct szobáknál other_user mező hozzáadása
        foreach ($rooms as &$room) {
            if ($room['type'] === 'direct') {
                $ou = $db->prepare("
                    SELECT u.id, u.name, u.avatar_url
                    FROM room_members rm JOIN users u ON u.id = rm.user_id
                    WHERE rm.room_id = ? AND rm.user_id != ?
                    LIMIT 1
                ");
                $ou->execute([$room['id'], $auth['user_id']]);
                $room['other_user'] = $ou->fetch() ?: null;
            } else {
                $room['other_user'] = null;
            }
        }
        unset($room);

        Response::ok($rooms);
    }

    public static function create(): never {
        $auth = Auth::require();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = $body['type'] ?? 'group';
        $name = trim($body['name'] ?? '');

        if ($type === 'group' && !$name) Response::abort(400, 'Csoportnév megadása kötelező.');
        if ($type === 'direct') {
            $other = (int)($body['user_id'] ?? 0);
            if (!$other) Response::abort(400, 'Célfelhasználó megadása kötelező.');
            self::getOrCreateDirect($auth['user_id'], $other);
        }

        $db = DB::get();
        $db->prepare("INSERT INTO rooms (name, type, created_by) VALUES (?,?,?)")->execute([$name, 'group', $auth['user_id']]);
        $room_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO room_members (room_id, user_id, role) VALUES (?,?,'admin')")->execute([$room_id, $auth['user_id']]);

        $members = $body['members'] ?? [];
        foreach ($members as $uid) {
            $uid = (int)$uid;
            if ($uid && $uid !== $auth['user_id'])
                $db->prepare("INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?,?)")->execute([$room_id, $uid]);
        }

        Response::ok(['room_id' => $room_id]);
    }

    private static function getOrCreateDirect(int $a, int $b): never {
        $db  = DB::get();
        $st  = $db->prepare("
            SELECT r.id FROM rooms r
            JOIN room_members ra ON ra.room_id=r.id AND ra.user_id=?
            JOIN room_members rb ON rb.room_id=r.id AND rb.user_id=?
            WHERE r.type='direct'
            LIMIT 1
        ");
        $st->execute([$a, $b]);
        $row = $st->fetch();
        if ($row) {
            // Ha rejtett volt, unhide automatikusan
            $db->prepare("UPDATE room_members SET hidden_at=NULL WHERE room_id=? AND user_id=?")
               ->execute([(int)$row['id'], $a]);
            Response::ok(['room_id' => (int)$row['id']]);
        }

        $db->prepare("INSERT INTO rooms (name, type, created_by) VALUES ('','direct',?)")->execute([$a]);
        $room_id = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO room_members (room_id, user_id) VALUES (?,?)")->execute([$room_id, $a]);
        $db->prepare("INSERT INTO room_members (room_id, user_id) VALUES (?,?)")->execute([$room_id, $b]);
        Response::ok(['room_id' => $room_id]);
    }

    public static function get(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $db   = DB::get();
        $room = $db->prepare("SELECT id, name, type, created_by, created_at, pinned_message_id, delete_requested_by FROM rooms WHERE id=?");
        $room->execute([$room_id]);
        $r = $room->fetch();
        if (!$r) Response::abort(404, 'Szoba nem található.');

        $mems = $db->prepare("SELECT u.id, u.name, u.avatar_url, rm.role FROM room_members rm JOIN users u ON u.id=rm.user_id WHERE rm.room_id=?");
        $mems->execute([$room_id]);
        $r['members'] = $mems->fetchAll();

        // Pinned message
        $r['pinned_message'] = null;
        if ($r['pinned_message_id']) {
            $pm = $db->prepare("
                SELECT m.id, m.content, m.type, u.name AS user_name
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.id = ?
            ");
            $pm->execute([$r['pinned_message_id']]);
            $r['pinned_message'] = $pm->fetch() ?: null;
        }
        unset($r['pinned_message_id']);

        Response::ok($r);
    }

    public static function pin(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $msg_id = (int)($body['message_id'] ?? 0);
        if (!$msg_id) Response::abort(400, 'message_id megadása kötelező.');

        // Ellenőrzés: az üzenet valóban ebben a szobában van-e
        $chk = DB::get()->prepare("SELECT id FROM messages WHERE id=? AND room_id=?");
        $chk->execute([$msg_id, $room_id]);
        if (!$chk->fetch()) Response::abort(404, 'Üzenet nem található ebben a szobában.');

        DB::get()->prepare("UPDATE rooms SET pinned_message_id=? WHERE id=?")->execute([$msg_id, $room_id]);
        Response::ok();
    }

    public static function unpin(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        DB::get()->prepare("UPDATE rooms SET pinned_message_id=NULL WHERE id=?")->execute([$room_id]);
        Response::ok();
    }

    public static function mute(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        DB::get()->prepare("UPDATE room_members SET is_muted=1 WHERE room_id=? AND user_id=?")
                 ->execute([$room_id, $auth['user_id']]);
        Response::ok();
    }

    public static function unmute(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        DB::get()->prepare("UPDATE room_members SET is_muted=0 WHERE room_id=? AND user_id=?")
                 ->execute([$room_id, $auth['user_id']]);
        Response::ok();
    }

    public static function hide(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $db = DB::get();
        $db->prepare("UPDATE room_members SET hidden_at=NOW() WHERE room_id=? AND user_id=?")
           ->execute([$room_id, $auth['user_id']]);
        // delete_requested_by törlése: újranyitáskor ne jelenjen meg a banner
        $db->prepare("UPDATE rooms SET delete_requested_by=NULL WHERE id=?")->execute([$room_id]);
        // Ha mindenki elrejtette, szoba valódi törlése (CASCADE törli az üzeneteket és tagságokat is)
        $remaining = $db->prepare("SELECT COUNT(*) FROM room_members WHERE room_id=? AND hidden_at IS NULL");
        $remaining->execute([$room_id]);
        if ((int)$remaining->fetchColumn() === 0) {
            $db->prepare("DELETE FROM rooms WHERE id=?")->execute([$room_id]);
        }
        Response::ok();
    }

    public static function markRead(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $db  = DB::get();
        $uid = $auth['user_id'];

        $db->prepare("UPDATE room_members SET last_read_at=NOW() WHERE room_id=? AND user_id=?")
           ->execute([$room_id, $uid]);

        // Kiolvasás: érintett üzenetek (olvasatlan delivery rekordok)
        $affected = $db->prepare("
            SELECT md.message_id, md.delivered_at, m.sender_id
            FROM message_deliveries md
            JOIN messages m ON m.id = md.message_id
            WHERE md.user_id=? AND md.read_at IS NULL AND m.room_id=?
        ");
        $affected->execute([$uid, $room_id]);
        $rows = $affected->fetchAll();

        if ($rows) {
            $now = date('Y-m-d H:i:s');
            $db->prepare("
                UPDATE message_deliveries SET read_at=?
                WHERE user_id=? AND read_at IS NULL
                  AND message_id IN (SELECT id FROM messages WHERE room_id=?)
            ")->execute([$now, $uid, $room_id]);

            foreach ($rows as $r) {
                self::wsBroadcastRaw([
                    'target_user' => (int)$r['sender_id'],
                    'payload'     => [
                        'type'         => 'status_update',
                        'room_id'      => $room_id,
                        'message_id'   => (int)$r['message_id'],
                        'user_id'      => $uid,
                        'delivered_at' => $r['delivered_at'] ?: null,
                        'read_at'      => $now,
                    ],
                ]);
            }
        }

        Response::ok();
    }

    public static function addMember(int $room_id): never {
        $auth = Auth::require();
        self::assertAdmin($room_id, $auth['user_id']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $uid  = (int)($body['user_id'] ?? 0);
        if (!$uid) Response::abort(400, 'user_id megadása kötelező.');
        DB::get()->prepare("INSERT IGNORE INTO room_members (room_id, user_id) VALUES (?,?)")->execute([$room_id, $uid]);
        Response::ok();
    }

    public static function removeMember(int $room_id, int $user_id): never {
        $auth = Auth::require();
        if ($auth['user_id'] !== $user_id) self::assertAdmin($room_id, $auth['user_id']);
        $db = DB::get();

        // Kilépő neve a WS broadcasthoz
        $u = $db->prepare("SELECT name FROM users WHERE id=?");
        $u->execute([$user_id]);
        $name = $u->fetchColumn() ?: 'Valaki';

        $db->prepare("DELETE FROM room_members WHERE room_id=? AND user_id=?")->execute([$room_id, $user_id]);

        // Ha nincs több tag, töröljük a szobát
        $cnt = $db->prepare("SELECT COUNT(*) FROM room_members WHERE room_id=?");
        $cnt->execute([$room_id]);
        if ((int)$cnt->fetchColumn() === 0) {
            $db->prepare("DELETE FROM rooms WHERE id=?")->execute([$room_id]);
        } else {
            self::wsBroadcastRaw(['type' => 'member_left', 'room_id' => $room_id,
                                  'user_id' => $user_id, 'user_name' => $name]);
        }

        Response::ok();
    }

    public static function deleteRequest(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $db  = DB::get();
        $uid = $auth['user_id'];

        // Lekérjük a kérelmező nevét
        $u = $db->prepare("SELECT name FROM users WHERE id=?");
        $u->execute([$uid]);
        $name = $u->fetchColumn() ?: 'Valaki';

        // Beállítjuk delete_requested_by
        $db->prepare("UPDATE rooms SET delete_requested_by=? WHERE id=?")->execute([$uid, $room_id]);

        // Rendszer üzenet beszúrása
        $db->prepare("INSERT INTO messages (room_id, sender_id, type, content) VALUES (?,?,?,'system')")
           ->execute([$room_id, $uid, 'system']);
        // Content frissítés (az auto-increment id után)
        $msg_id = (int)$db->lastInsertId();
        $content = "$name törölni szeretné ezt a beszélgetést.";
        $db->prepare("UPDATE messages SET content=? WHERE id=?")->execute([$content, $msg_id]);

        // WS broadcast
        $msg = ['id' => $msg_id, 'room_id' => $room_id, 'sender_id' => $uid,
                'user_name' => $name, 'type' => 'system', 'content' => $content,
                'file_url' => null, 'file_name' => null, 'created_at' => date('Y-m-d H:i:s')];
        // Külön message event (system üzenet megjelenítéséhez) + delete_request event (banner)
        self::wsBroadcastRaw(['type' => 'message', 'room_id' => $room_id, 'message' => $msg]);
        self::wsBroadcastRaw(['type' => 'delete_request', 'room_id' => $room_id,
                              'user_id' => $uid, 'user_name' => $name, 'message' => $msg]);

        Response::ok(['message_id' => $msg_id]);
    }

    public static function keep(int $room_id): never {
        $auth = Auth::require();
        self::assertMember($room_id, $auth['user_id']);
        $db  = DB::get();
        $uid = $auth['user_id'];

        // Lekérjük a megtartó nevét és az initiátor id-t
        $u = $db->prepare("SELECT name FROM users WHERE id=?");
        $u->execute([$uid]);
        $name = $u->fetchColumn() ?: 'Valaki';

        $r = $db->prepare("SELECT delete_requested_by FROM rooms WHERE id=?");
        $r->execute([$room_id]);
        $initiator_id = (int)$r->fetchColumn();

        // delete_requested_by törlése
        $db->prepare("UPDATE rooms SET delete_requested_by=NULL WHERE id=?")->execute([$room_id]);

        // Rendszer üzenet
        $content = "$name megtartotta a beszélgetést.";
        $db->prepare("INSERT INTO messages (room_id, sender_id, type, content) VALUES (?,?,?,'system')")
           ->execute([$room_id, $uid, 'system']);
        $msg_id = (int)$db->lastInsertId();
        $db->prepare("UPDATE messages SET content=? WHERE id=?")->execute([$content, $msg_id]);

        // Initiátor kilép (ha még tag)
        if ($initiator_id) {
            $db->prepare("DELETE FROM room_members WHERE room_id=? AND user_id=?")->execute([$room_id, $initiator_id]);
        }

        // WS broadcast
        $msg = ['id' => $msg_id, 'room_id' => $room_id, 'sender_id' => $uid,
                'user_name' => $name, 'type' => 'system', 'content' => $content,
                'file_url' => null, 'file_name' => null, 'created_at' => date('Y-m-d H:i:s')];
        self::wsBroadcastRaw(['type' => 'message', 'room_id' => $room_id, 'message' => $msg]);

        Response::ok();
    }

    private static function wsBroadcastRaw(array $payload): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode($payload, JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }

    public static function assertMemberStatic(int $room_id, int $user_id): void {
        $st = DB::get()->prepare("SELECT 1 FROM room_members WHERE room_id=? AND user_id=?");
        $st->execute([$room_id, $user_id]);
        if (!$st->fetch()) Response::abort(403, 'Nincs hozzáférésed ehhez a szobához.');
    }

    private static function assertMember(int $room_id, int $user_id): void {
        self::assertMemberStatic($room_id, $user_id);
    }

    private static function assertAdmin(int $room_id, int $user_id): void {
        $st = DB::get()->prepare("SELECT role FROM room_members WHERE room_id=? AND user_id=?");
        $st->execute([$room_id, $user_id]);
        $row = $st->fetch();
        if (!$row || $row['role'] !== 'admin') Response::abort(403, 'Admin jog szükséges a szobában.');
    }
}
