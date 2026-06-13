<?php
namespace Tricc\Controllers;

use Tricc\{Auth, Response, DB, APNs};
use Firebase\JWT\JWT;

class CallController {

    // POST /call/token   body: {room_id: int}
    public static function token(): never {
        $auth   = Auth::require();
        $cfg    = require __DIR__ . '/../../../config.php';
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $roomId = isset($body['room_id']) ? (int)$body['room_id'] : 0;

        if ($roomId <= 0) Response::abort(400, 'room_id kötelező.');

        $db = DB::get();
        $st = $db->prepare('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?');
        $st->execute([$roomId, $auth['user_id']]);
        if (!$st->fetch()) Response::abort(403, 'Nem tagja a szobának.');

        $st2 = $db->prepare('SELECT name FROM users WHERE id=?');
        $st2->execute([$auth['user_id']]);
        $displayName = $st2->fetchColumn() ?: ('user_' . $auth['user_id']);

        $lkRoomName = 'room_' . $roomId;
        $now = time();
        $payload = [
            'iss'   => $cfg['livekit_key'],
            'sub'   => (string)$auth['user_id'],
            'jti'   => uniqid('lk_', true),
            'iat'   => $now,
            'exp'   => $now + 7200,
            'name'  => $displayName,
            'video' => (object)[
                'room'           => $lkRoomName,
                'roomJoin'       => true,
                'canPublish'     => true,
                'canSubscribe'   => true,
                'canPublishData' => true,
            ],
        ];

        $token = JWT::encode($payload, $cfg['livekit_secret'], 'HS256');

        Response::json([
            'token' => $token,
            'url'   => $cfg['livekit_url'],
            'room'  => $lkRoomName,
        ]);
    }

    // POST /rooms/{id}/call/notify
    // WS broadcast + APNs push a szobatársaknak hogy hívás indult.
    public static function notifyCallStarted(int $roomId): never {
        $auth = Auth::require();
        $db   = DB::get();

        $st = $db->prepare('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?');
        $st->execute([$roomId, $auth['user_id']]);
        if (!$st->fetch()) Response::abort(403, 'Nem tagja a szobának.');

        $st2 = $db->prepare('SELECT name FROM users WHERE id=?');
        $st2->execute([$auth['user_id']]);
        $userName = $st2->fetchColumn() ?: ('user_' . $auth['user_id']);

        $st3 = $db->prepare("SELECT COALESCE(name, '') as name FROM rooms WHERE id=?");
        $st3->execute([$roomId]);
        $roomName = $st3->fetchColumn() ?: ('Szoba #' . $roomId);

        // WS broadcast az összes szobatagjának
        self::wsBroadcast([
            'type'      => 'call_started',
            'room_id'   => $roomId,
            'room_name' => $roomName,
            'user_name' => $userName,
        ]);

        // APNs push az offline (nem-muted) tagoknak
        $tokens = $db->prepare("
            SELECT pt.token, pt.user_id FROM push_tokens pt
            JOIN room_members rm ON rm.user_id = pt.user_id
            WHERE rm.room_id = ? AND pt.user_id != ? AND rm.is_muted = 0
        ");
        $tokens->execute([$roomId, $auth['user_id']]);

        $title = $userName . ' hanghívást indított';
        $body  = '"' . $roomName . '" csoportban';
        foreach ($tokens->fetchAll() as $t) {
            APNs::send($t['token'], $title, $body, [
                'type'    => 'call_started',
                'room_id' => $roomId,
            ]);
        }

        Response::ok();
    }

    private static function wsBroadcast(array $payload): void {
        try {
            $sock = @stream_socket_client('tcp://127.0.0.1:9455', $errno, $errstr, 0.3);
            if ($sock) {
                fwrite($sock, json_encode($payload, JSON_UNESCAPED_UNICODE));
                fclose($sock);
            }
        } catch (\Throwable) {}
    }
}
