<?php
namespace Tricc\Controllers;

use Tricc\{Auth, Response, DB, APNs};
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

        self::wsBroadcast([
            'type'      => 'call_started',
            'room_id'   => $roomId,
            'room_name' => $roomName,
            'user_name' => $userName,
        ]);

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

    // POST /call/lk-webhook  — LiveKit server webhook (room_finished → call_ended broadcast)
    public static function lkWebhook(): never {
        $cfg  = require __DIR__ . '/../../../config.php';
        $raw  = file_get_contents('php://input');
        $auth = trim(str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION'] ?? ''));

        if (!$auth) Response::abort(401, 'Missing token');

        try {
            $decoded = JWT::decode($auth, new Key($cfg['livekit_secret'], 'HS256'));
        } catch (\Exception) {
            Response::abort(401, 'Invalid token');
        }

        // Body hash ellenőrzés
        $expectedHash = base64_encode(hash('sha256', $raw, true));
        if (($decoded->sha256 ?? '') !== $expectedHash) {
            Response::abort(400, 'Body hash mismatch');
        }

        $event = json_decode($raw, true);
        if (($event['event'] ?? '') === 'room_finished') {
            $roomName = $event['room']['name'] ?? '';
            if (preg_match('/^room_(\d+)$/', $roomName, $m)) {
                self::wsBroadcast([
                    'type'    => 'call_ended',
                    'room_id' => (int)$m[1],
                ]);
            }
        }

        http_response_code(200);
        exit;
    }

    // GET /admin/calls  — aktív LiveKit szobák és résztvevők
    public static function adminCalls(): never {
        Auth::requireAdmin();
        $cfg  = require __DIR__ . '/../../../config.php';
        $db   = DB::get();

        $rooms = self::lkApiCall('ListRooms', [], $cfg);
        $calls = [];

        foreach ($rooms['rooms'] ?? [] as $room) {
            if (!preg_match('/^room_(\d+)$/', $room['name'], $m)) continue;
            $roomId = (int)$m[1];

            $st = $db->prepare("SELECT COALESCE(name, ?) as name FROM rooms WHERE id=?");
            $st->execute(['Szoba #' . $roomId, $roomId]);
            $roomName = $st->fetchColumn() ?: ('Szoba #' . $roomId);

            $parts = self::lkApiCall('ListParticipants', ['room' => $room['name']], $cfg);
            $participants = [];
            foreach ($parts['participants'] ?? [] as $p) {
                $userId = (int)($p['identity'] ?? 0);
                $st2 = $db->prepare("SELECT name FROM users WHERE id=?");
                $st2->execute([$userId]);
                $uName = $st2->fetchColumn() ?: ($p['name'] ?? 'user_' . $userId);
                $participants[] = [
                    'user_id'   => $userId,
                    'user_name' => $uName,
                    'joined_at' => date('c', (int)($p['joinedAt'] ?? 0)),
                ];
            }

            $calls[] = [
                'room_id'      => $roomId,
                'room_name'    => $roomName,
                'participants' => $participants,
                'started_at'   => date('c', (int)($room['creationTime'] ?? 0)),
            ];
        }

        Response::json(['calls' => $calls]);
    }

    private static function lkApiCall(string $method, array $body, array $cfg): array {
        $now = time();
        $token = JWT::encode([
            'iss'   => $cfg['livekit_key'],
            'sub'   => 'server',
            'jti'   => uniqid('lk_', true),
            'iat'   => $now,
            'exp'   => $now + 60,
            'video' => (object)['roomList' => true, 'roomAdmin' => true],
        ], $cfg['livekit_secret'], 'HS256');

        $ch = curl_init('http://127.0.0.1:17880/twirp/livekit.RoomService/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode((object)$body),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        return json_decode($result ?: '{}', true) ?? [];
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
