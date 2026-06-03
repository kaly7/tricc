<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response};

class RoomController {
    public static function list(): never {
        $auth = Auth::require();
        $db   = DB::get();
        $st   = $db->prepare("
            SELECT r.id, r.name, r.type, r.created_at,
                   COUNT(DISTINCT rm2.user_id) AS member_count,
                   (SELECT content FROM messages WHERE room_id=r.id ORDER BY created_at DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM messages WHERE room_id=r.id ORDER BY created_at DESC LIMIT 1) AS last_message_at
            FROM rooms r
            JOIN room_members rm ON rm.room_id = r.id AND rm.user_id = ?
            JOIN room_members rm2 ON rm2.room_id = r.id
            GROUP BY r.id
            ORDER BY last_message_at DESC, r.created_at DESC
        ");
        $st->execute([$auth['user_id']]);
        Response::ok($st->fetchAll());
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
            return self::getOrCreateDirect($auth['user_id'], $other);
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
        if ($row) { Response::ok(['room_id' => (int)$row['id']]); }

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
        $room = $db->prepare("SELECT id, name, type, created_by, created_at FROM rooms WHERE id=?");
        $room->execute([$room_id]);
        $r = $room->fetch();
        if (!$r) Response::abort(404, 'Szoba nem található.');
        $mems = $db->prepare("SELECT u.id, u.name, u.avatar_url, rm.role FROM room_members rm JOIN users u ON u.id=rm.user_id WHERE rm.room_id=?");
        $mems->execute([$room_id]);
        $r['members'] = $mems->fetchAll();
        Response::ok($r);
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
        DB::get()->prepare("DELETE FROM room_members WHERE room_id=? AND user_id=?")->execute([$room_id, $user_id]);
        Response::ok();
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
