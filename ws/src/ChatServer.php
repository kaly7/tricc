<?php
namespace Tricc\WS;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Tricc\{DB, Auth, APNs, FCM};

class ChatServer implements MessageComponentInterface {
    /** @var array<int, ConnectionInterface> conn_id → connection */
    private array $conns = [];

    /** @var array<int, int> conn_id → user_id */
    private array $users = [];

    /** @var array<int, int[]> user_id → [conn_id, ...] */
    private array $userConns = [];

    /** @var array<int, int[]> room_id → [user_id, ...] */
    private array $roomUsers = [];

    /** @var array<int, int> conn_id → utolsó ping időbélyeg */
    private array $lastPing = [];

    /**
     * Aktív hívások: call_id → [initiator_uid, target_uid, state, created_at]
     * state: 'ringing' | 'active'
     */
    private array $calls = [];

    /** user_id → React timer — aktív hívás közbeni WS disconnect grace period */
    private array $reconnectTimers = [];

    private LoopInterface $loop;

    public function __construct(LoopInterface $loop) {
        $this->loop = $loop;
        $loop->addPeriodicTimer(15, function () {
            $now = time();
            foreach ($this->lastPing as $cid => $ts) {
                if ($now - $ts > 60 && isset($this->conns[$cid])) {
                    echo "[WS] idle timeout #{$cid}, closing\n";
                    $conn = $this->conns[$cid];
                    $this->cleanupConn($cid);
                    $conn->close();
                }
            }
            // 60 mp-nél régebbi, még 'ringing' hívások timeout-olása
            foreach ($this->calls as $call_id => $call) {
                if ($call['state'] === 'ringing' && $now - $call['created_at'] > 60) {
                    $timeout = ['type' => 'call_timeout', 'call_id' => $call_id];
                    $this->sendToUser($call['initiator_uid'], $timeout);
                    $this->sendToUser($call['target_uid'], $timeout);
                    unset($this->calls[$call_id]);
                    echo "[WS] call timeout $call_id\n";
                }
            }
        });
    }

    public function onOpen(ConnectionInterface $conn): void {
        $this->conns[$conn->resourceId] = $conn;
        $this->lastPing[$conn->resourceId] = time();
        echo "[WS] connected #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $raw): void {
        $msg = json_decode($raw, true);
        if (!$msg || !isset($msg['type'])) return;

        switch ($msg['type']) {
            case 'auth':
                $this->handleAuth($from, $msg);
                break;
            case 'join':
                $this->handleJoin($from, $msg);
                break;
            case 'leave':
                $this->handleLeave($from, $msg);
                break;
            case 'typing':
                $this->handleTyping($from, $msg);
                break;
            case 'delivered':
                $this->handleDelivered($from, $msg);
                break;
            case 'ping':
                $this->lastPing[$from->resourceId] = time();
                $from->send(json_encode(['type' => 'pong']));
                break;
            case 'call_invite':    $this->handleCallInvite($from, $msg);    break;
            case 'call_accept':    $this->handleCallSignal($from, $msg, 'call_accepted',  'call_reject_cleanup'); break;
            case 'call_reject':    $this->handleCallSignal($from, $msg, 'call_rejected',  'end'); break;
            case 'call_cancel':    $this->handleCallSignal($from, $msg, 'call_cancelled', 'end'); break;
            case 'call_end':       $this->handleCallSignal($from, $msg, 'call_ended',     'end'); break;
            case 'sdp_offer':
            case 'sdp_answer':
            case 'ice_candidate':  $this->handleCallRelay($from, $msg);     break;
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $id = $conn->resourceId;
        if (!isset($this->conns[$id])) {
            // Már az idle timer kitakarította
            echo "[WS] disconnected #{$id} (already cleaned)\n";
            return;
        }
        $this->cleanupConn($id);
        echo "[WS] disconnected #{$id}\n";
    }

    private function cleanupConn(int $id): void {
        $uid = $this->users[$id] ?? null;
        unset($this->conns[$id]);
        unset($this->lastPing[$id]);

        if ($uid !== null) {
            $this->userConns[$uid] = array_values(
                array_filter($this->userConns[$uid] ?? [], fn($c) => $c !== $id)
            );
            unset($this->users[$id]);

            if (empty($this->userConns[$uid])) {
                unset($this->userConns[$uid]);
                foreach ($this->calls as $call_id => $call) {
                    if ($call['initiator_uid'] !== $uid && $call['target_uid'] !== $uid) continue;
                    $other = $call['initiator_uid'] === $uid ? $call['target_uid'] : $call['initiator_uid'];
                    if ($call['state'] === 'active') {
                        $this->reconnectTimers[$uid] = $this->loop->addTimer(30, function () use ($uid, $call_id, $other) {
                            $this->sendToUser($other, ['type' => 'call_ended', 'call_id' => $call_id]);
                            unset($this->calls[$call_id], $this->reconnectTimers[$uid]);
                            echo "[WS] call_ended (reconnect timeout) $call_id uid=$uid\n";
                        });
                        echo "[WS] call reconnect grace started $call_id uid=$uid\n";
                    } else {
                        $this->sendToUser($other, ['type' => 'call_ended', 'call_id' => $call_id]);
                        unset($this->calls[$call_id]);
                        echo "[WS] call_ended (disconnect) $call_id\n";
                    }
                }
                $this->broadcastPresence($uid, false);
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[WS] error: " . $e->getMessage() . "\n";
        $conn->close();
    }

    // ── handlers ────────────────────────────────────────────────

    private function handleAuth(ConnectionInterface $conn, array $msg): void {
        $token = $msg['token'] ?? '';
        $auth  = $token ? Auth::verify($token) : null;
        if (!$auth) {
            $conn->send(json_encode(['type' => 'error', 'code' => 401, 'message' => 'Érvénytelen token.']));
            $conn->close();
            return;
        }
        $uid = $auth['user_id'];
        $id  = $conn->resourceId;
        $this->users[$id]          = $uid;
        $this->userConns[$uid][]   = $id;

        $conn->send(json_encode(['type' => 'auth_ok', 'user_id' => $uid]));
        $this->broadcastPresence($uid, true);
        echo "[WS] auth ok #{$id} → user $uid\n";

        // Visszatért a grace periódus alatt → hívás folytatódik
        if (isset($this->reconnectTimers[$uid])) {
            $this->loop->cancelTimer($this->reconnectTimers[$uid]);
            unset($this->reconnectTimers[$uid]);
            foreach ($this->calls as $call_id => $call) {
                if ($call['initiator_uid'] !== $uid && $call['target_uid'] !== $uid) continue;
                $other = $call['initiator_uid'] === $uid ? $call['target_uid'] : $call['initiator_uid'];
                // Visszatért félnek: van aktív hívása
                $conn->send(json_encode(['type' => 'call_ongoing', 'call_id' => $call_id], JSON_UNESCAPED_UNICODE));
                // Másik félnek: újra online
                $this->sendToUser($other, ['type' => 'call_reconnected', 'call_id' => $call_id]);
                echo "[WS] call reconnected $call_id uid=$uid\n";
            }
        }
    }

    private function handleJoin(ConnectionInterface $conn, array $msg): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $room_id = (int)($msg['room_id'] ?? 0);
        if (!$uid || !$room_id) return;
        if (!$this->isMember($room_id, $uid)) return;
        if (!in_array($uid, $this->roomUsers[$room_id] ?? [], true)) {
            $this->roomUsers[$room_id][] = $uid;
        }
        $conn->send(json_encode(['type' => 'joined', 'room_id' => $room_id], JSON_UNESCAPED_UNICODE));

        // Presence list: a szoba melyik tagjai online jelenleg
        $st = DB::get()->prepare("SELECT user_id FROM room_members WHERE room_id=?");
        $st->execute([$room_id]);
        $allMembers   = array_map('intval', array_column($st->fetchAll(), 'user_id'));
        $onlineInRoom = array_values(array_intersect($allMembers, array_keys($this->userConns)));
        $conn->send(json_encode([
            'type'            => 'presence_list',
            'room_id'         => $room_id,
            'online_user_ids' => $onlineInRoom,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function handleLeave(ConnectionInterface $conn, array $msg): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $room_id = (int)($msg['room_id'] ?? 0);
        if (!$uid || !$room_id) return;
        $this->roomUsers[$room_id] = array_values(
            array_filter($this->roomUsers[$room_id] ?? [], fn($u) => $u !== $uid)
        );
    }

    private function handleDelivered(ConnectionInterface $conn, array $msg): void {
        $uid    = $this->users[$conn->resourceId] ?? null;
        $msg_id = (int)($msg['message_id'] ?? 0);
        $room_id = (int)($msg['room_id'] ?? 0);
        if (!$uid || !$msg_id || !$room_id) return;

        $db = DB::get();
        $db->prepare("UPDATE message_deliveries SET delivered_at=NOW() WHERE message_id=? AND user_id=? AND delivered_at IS NULL")
           ->execute([$msg_id, $uid]);

        $st = $db->prepare("SELECT sender_id FROM messages WHERE id=?");
        $st->execute([$msg_id]);
        $sender_id = (int)($st->fetchColumn() ?: 0);
        if (!$sender_id) return;

        $dr = $db->prepare("SELECT delivered_at, read_at FROM message_deliveries WHERE message_id=? AND user_id=?");
        $dr->execute([$msg_id, $uid]);
        $row = $dr->fetch();

        $this->sendToUser($sender_id, [
            'type'         => 'status_update',
            'room_id'      => $room_id,
            'message_id'   => $msg_id,
            'user_id'      => $uid,
            'delivered_at' => $row['delivered_at'] ?? null,
            'read_at'      => $row['read_at'] ?? null,
        ]);
    }

    private function handleTyping(ConnectionInterface $conn, array $msg): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $room_id = (int)($msg['room_id'] ?? 0);
        if (!$uid || !$room_id) return;
        $this->broadcastRoom($room_id, $uid, [
            'type'    => 'typing',
            'room_id' => $room_id,
            'user_id' => $uid,
            'typing'  => (bool)($msg['typing'] ?? false),
        ]);
    }

    // ── WebRTC signaling ─────────────────────────────────────────

    private function handleCallInvite(ConnectionInterface $conn, array $msg): void {
        $uid    = $this->users[$conn->resourceId] ?? null;
        $target = (int)($msg['target_user_id'] ?? 0);
        if (!$uid || !$target || $uid === $target) return;

        // Target online?
        if (empty($this->userConns[$target])) {
            $conn->send(json_encode(['type' => 'call_error', 'message' => 'A felhasználó nem elérhető.'], JSON_UNESCAPED_UNICODE));
            return;
        }

        $call_id = uniqid('call_', true);
        $this->calls[$call_id] = [
            'initiator_uid' => $uid,
            'target_uid'    => $target,
            'state'         => 'ringing',
            'created_at'    => time(),
        ];

        // Visszaküldés a hívónak (hogy tudja a call_id-t)
        $conn->send(json_encode(['type' => 'call_initiated', 'call_id' => $call_id], JSON_UNESCAPED_UNICODE));

        // Caller neve
        $st = DB::get()->prepare("SELECT name FROM users WHERE id=?");
        $st->execute([$uid]);
        $name = $st->fetchColumn() ?: 'Ismeretlen';

        $this->sendToUser($target, [
            'type'        => 'incoming_call',
            'call_id'     => $call_id,
            'caller_id'   => $uid,
            'caller_name' => $name,
        ]);

        // Push értesítés — háttérben lévő / zárt apphoz
        $this->sendCallPush($target, $call_id, $uid, $name);

        echo "[WS] call_invite $call_id: $uid → $target\n";
    }

    private function sendCallPush(int $target_uid, string $call_id, int $caller_id, string $caller_name): void {
        try {
            $st = DB::get()->prepare("SELECT token, platform FROM push_tokens WHERE user_id=?");
            $st->execute([$target_uid]);
            $row = $st->fetch();
            if (!$row) return;

            $title = 'Bejövő hívás';
            $body  = "$caller_name hív téged";
            $data  = [
                'type'        => 'incoming_call',
                'call_id'     => $call_id,
                'caller_id'   => (string)$caller_id,
                'caller_name' => $caller_name,
            ];

            if ($row['platform'] === 'android') {
                FCM::send($row['token'], $title, $body, $data);
            } else {
                APNs::send($row['token'], $title, $body, $data, 0);
            }
        } catch (\Throwable $e) {
            error_log("[WS] sendCallPush error: " . $e->getMessage());
        }
    }

    private function handleCallSignal(ConnectionInterface $conn, array $msg, string $outType, string $action): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $call_id = $msg['call_id'] ?? '';
        if (!$uid || !isset($this->calls[$call_id])) return;

        $call  = $this->calls[$call_id];
        $other = $call['initiator_uid'] === $uid ? $call['target_uid'] : $call['initiator_uid'];

        $this->sendToUser($other, ['type' => $outType, 'call_id' => $call_id]);

        if ($action === 'end') {
            unset($this->calls[$call_id]);
            echo "[WS] call $outType $call_id\n";
        } elseif ($action === 'call_reject_cleanup') {
            // accept: állapot frissítése active-ra
            $this->calls[$call_id]['state'] = 'active';
        }
    }

    private function handleCallRelay(ConnectionInterface $conn, array $msg): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $call_id = $msg['call_id'] ?? '';
        if (!$uid || !isset($this->calls[$call_id])) return;

        $call  = $this->calls[$call_id];
        $other = $call['initiator_uid'] === $uid ? $call['target_uid'] : $call['initiator_uid'];

        $msg['from_uid'] = $uid;
        $this->sendToUser($other, $msg);
    }

    // ── public broadcast (called from outside, e.g. REST API) ──

    public function broadcastMessage(int $room_id, array $message): void {
        $json = json_encode(['type' => 'message', 'room_id' => $room_id, 'message' => $message], JSON_UNESCAPED_UNICODE);
        foreach ($this->users as $cid => $uid) {
            if ($this->isMember($room_id, $uid)) {
                $this->conns[$cid]?->send($json);
            }
        }
    }

    public function sendToUser(int $user_id, array $payload): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->userConns[$user_id] ?? [] as $cid) {
            $this->conns[$cid]?->send($json);
        }
    }

    public function broadcastRaw(int $room_id, array $payload): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->users as $cid => $uid) {
            if ($this->isMember($room_id, $uid)) {
                $this->conns[$cid]?->send($json);
            }
        }
    }

    // ── helpers ─────────────────────────────────────────────────

    private function broadcastRoom(int $room_id, ?int $exclude_uid, array $payload): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ($this->roomUsers[$room_id] ?? [] as $uid) {
            if ($uid === $exclude_uid) continue;
            foreach ($this->userConns[$uid] ?? [] as $cid) {
                $this->conns[$cid]?->send($json);
            }
        }
    }

    private function broadcastPresence(int $uid, bool $online): void {
        // Csak azoknak küld, akik közös szobában vannak $uid-val
        $st = DB::get()->prepare("
            SELECT DISTINCT rm2.user_id
            FROM room_members rm1
            JOIN room_members rm2 ON rm2.room_id = rm1.room_id
            WHERE rm1.user_id = ? AND rm2.user_id != ?
        ");
        $st->execute([$uid, $uid]);
        $payload = ['type' => 'presence', 'user_id' => $uid, 'online' => $online];
        foreach ($st->fetchAll() as $row) {
            $this->sendToUser((int)$row['user_id'], $payload);
        }
    }

    private function isMember(int $room_id, int $user_id): bool {
        $st = DB::get()->prepare("SELECT 1 FROM room_members WHERE room_id=? AND user_id=?");
        $st->execute([$room_id, $user_id]);
        return (bool)$st->fetch();
    }
}
