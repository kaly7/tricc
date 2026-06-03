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
        $conn->send(json_encode(['type' => 'joined', 'room_id' => $room_id]));
    }

    private function handleLeave(ConnectionInterface $conn, array $msg): void {
        $uid     = $this->users[$conn->resourceId] ?? null;
        $room_id = (int)($msg['room_id'] ?? 0);
        if (!$uid || !$room_id) return;
        $this->roomUsers[$room_id] = array_values(
            array_filter($this->roomUsers[$room_id] ?? [], fn($u) => $u !== $uid)
        );
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
        $this->broadcastRoom($room_id, null, [
            'type'    => 'message',
            'room_id' => $room_id,
            'message' => $message,
        ]);
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
        $payload = json_encode(['type' => 'presence', 'user_id' => $uid, 'online' => $online]);
        foreach ($this->conns as $conn) {
            $conn->send($payload);
        }
    }

    private function isMember(int $room_id, int $user_id): bool {
        $st = DB::get()->prepare("SELECT 1 FROM room_members WHERE room_id=? AND user_id=?");
        $st->execute([$room_id, $user_id]);
        return (bool)$st->fetch();
    }
}
