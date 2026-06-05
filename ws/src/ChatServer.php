<?php
namespace Tricc\WS;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Tricc\{DB, Auth};

class ChatServer implements MessageComponentInterface {
    /** @var array<int, ConnectionInterface> conn_id → connection */
    private array $conns = [];

    /** @var array<int, int> conn_id → user_id */
    private array $users = [];

    /** @var array<int, int[]> user_id → [conn_id, ...] */
    private array $userConns = [];

    /** @var array<int, int[]> room_id → [user_id, ...] */
    private array $roomUsers = [];

    public function onOpen(ConnectionInterface $conn): void {
        $this->conns[$conn->resourceId] = $conn;
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
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $id = $conn->resourceId;
        $uid = $this->users[$id] ?? null;

        if ($uid !== null) {
            $this->userConns[$uid] = array_values(
                array_filter($this->userConns[$uid] ?? [], fn($c) => $c !== $id)
            );
            if (empty($this->userConns[$uid])) {
                unset($this->userConns[$uid]);
                $this->broadcastPresence($uid, false);
            }
            unset($this->users[$id]);
        }
        unset($this->conns[$id]);
        echo "[WS] disconnected #{$id}\n";
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
