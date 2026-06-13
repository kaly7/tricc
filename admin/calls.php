<?php
session_start();
if (!isset($_SESSION['tricc_admin'])) { header('Location: login.php'); exit; }

require_once '_db.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;

// AJAX kérés: JSON visszaadása
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(['calls' => lkActiveCalls()]);
    exit;
}

function lkActiveCalls(): array {
    $cfg = require __DIR__ . '/../../config.php';
    $db  = db();

    $rooms = lkApi('ListRooms', [], $cfg);
    $calls = [];

    foreach ($rooms['rooms'] ?? [] as $room) {
        if (!preg_match('/^room_(\d+)$/', $room['name'], $m)) continue;
        $roomId = (int)$m[1];

        $st = $db->prepare("SELECT COALESCE(name, ?) as name FROM rooms WHERE id=?");
        $st->execute(['Szoba #' . $roomId, $roomId]);
        $roomName = $st->fetchColumn() ?: ('Szoba #' . $roomId);

        $parts = lkApi('ListParticipants', ['room' => $room['name']], $cfg);
        $participants = [];
        foreach ($parts['participants'] ?? [] as $p) {
            $userId = (int)($p['identity'] ?? 0);
            $st2 = $db->prepare("SELECT name FROM users WHERE id=?");
            $st2->execute([$userId]);
            $uName = $st2->fetchColumn() ?: ($p['name'] ?? 'user_' . $userId);
            $participants[] = [
                'user_id'   => $userId,
                'user_name' => $uName,
                'joined_at' => date('H:i:s', (int)($p['joinedAt'] ?? 0)),
            ];
        }

        $calls[] = [
            'room_id'      => $roomId,
            'room_name'    => $roomName,
            'participants' => $participants,
            'started_at'   => date('H:i:s', (int)($room['creationTime'] ?? 0)),
        ];
    }

    return $calls;
}

function lkApi(string $method, array $body, array $cfg): array {
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

$title = 'Aktív hívások';
$active_page = 'calls';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-telephone-fill text-success me-2"></i>Aktív hívások</h4>
  <button class="btn btn-sm btn-outline-secondary" onclick="loadCalls()">
    <i class="bi bi-arrow-clockwise"></i> Frissítés
  </button>
</div>

<div id="calls-container">
  <div class="text-center text-muted py-5">
    <div class="spinner-border spinner-border-sm me-2"></div>Betöltés…
  </div>
</div>

<script>
function loadCalls() {
  fetch('calls.php?json=1')
    .then(r => r.json())
    .then(data => {
      const calls = data.calls ?? [];
      const el = document.getElementById('calls-container');

      if (!calls.length) {
        el.innerHTML = '<div class="alert alert-secondary"><i class="bi bi-telephone-x me-2"></i>Jelenleg nincs aktív hanghívás.</div>';
        return;
      }

      el.innerHTML = calls.map(c => `
        <div class="card mb-3 shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-headset me-2 text-success"></i>${esc(c.room_name)}</strong>
            <span class="badge bg-success">${c.participants.length} résztvevő</span>
          </div>
          <ul class="list-group list-group-flush">
            ${c.participants.map(p => `
              <li class="list-group-item d-flex justify-content-between">
                <span><i class="bi bi-person me-2 text-muted"></i>${esc(p.user_name)}</span>
                <small class="text-muted">csatlakozott: ${esc(p.joined_at)}</small>
              </li>
            `).join('')}
          </ul>
          <div class="card-footer text-muted small">Hívás kezdete: ${esc(c.started_at)}</div>
        </div>
      `).join('');
    })
    .catch(() => {
      document.getElementById('calls-container').innerHTML =
        '<div class="alert alert-danger">Nem sikerült betölteni. LiveKit fut?</div>';
    });
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

loadCalls();
setInterval(loadCalls, 30000);
</script>
<?php
$content = ob_get_clean();
require '_layout.php';
