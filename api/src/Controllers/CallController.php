<?php
namespace Tricc\Controllers;

use Tricc\{Auth, Response, DB};
use Firebase\JWT\JWT;

class CallController {

    // POST /call/token   body: {room_id: int}
    // Visszaad egy LiveKit access tokent az adott chat szobához.
    public static function token(): never {
        $auth   = Auth::require();
        $cfg    = require __DIR__ . '/../../../config.php';
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $roomId = isset($body['room_id']) ? (int)$body['room_id'] : 0;

        if ($roomId <= 0) Response::abort(400, 'room_id kötelező.');

        // Jogosultság: a hívó tagja-e a szobának?
        $db = DB::get();
        $st = $db->prepare('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?');
        $st->execute([$roomId, $auth['user_id']]);
        if (!$st->fetch()) Response::abort(403, 'Nem tagja a szobának.');

        // Megjelenítési név
        $st2 = $db->prepare('SELECT name FROM users WHERE id=?');
        $st2->execute([$auth['user_id']]);
        $displayName = $st2->fetchColumn() ?: ('user_' . $auth['user_id']);

        $lkRoomName  = 'room_' . $roomId;
        $lkKey       = $cfg['livekit_key'];
        $lkSecret    = $cfg['livekit_secret'];

        $now = time();
        $payload = [
            'iss'   => $lkKey,
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

        $token = JWT::encode($payload, $lkSecret, 'HS256');

        Response::json([
            'token' => $token,
            'url'   => $cfg['livekit_url'],
            'room'  => $lkRoomName,
        ]);
    }
}
