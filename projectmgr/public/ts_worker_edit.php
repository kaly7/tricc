<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth;
use App\Middleware;
use App\Db;
use App\Csrf;
use App\Helpers;

Auth::start();
Middleware::requireAuth();
Auth::requireRole(1);

$pdo = Db::pdo();

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$err = null;

// Alapadatok új rekordhoz
$data = [
    'full_name' => '',
    'position'  => '',
    'note'      => '',
    'is_active' => 1,
    'user_id'   => null,
];

// Szakképesítések listája
$qualifications = $pdo->query("
    SELECT id, name 
    FROM qualifications 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll();

// Felhasználók listája (opcionális kapcsolás)
$users = $pdo->query("
    SELECT id, name, email 
    FROM users 
    ORDER BY name
")->fetchAll();

// Engedélyek listája
$permits = $pdo->query("
    SELECT id, name, requires_renewal 
    FROM permits 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll();

// Már meglévő szakképesítések és engedélyek
$selectedQualificationIds = [];
$workerPermits = [];

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM workers WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(404);
        exit('A kolléga nem található.');
    }

    $data = $row;

    // Szakképesítések
    $st = $pdo->prepare("SELECT qualification_id FROM worker_qualifications WHERE worker_id = ?");
    $st->execute([$id]);
    $selectedQualificationIds = array_column($st->fetchAll(), 'qualification_id');

    // Engedélyek
    $st = $pdo->prepare("
        SELECT permit_id, license_number, valid_from, valid_until, note
        FROM worker_permits
        WHERE worker_id = ?
    ");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $wp) {
        $workerPermits[(int)$wp['permit_id']] = $wp;
    }
}

// Mentés
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check($_POST['csrf_token'] ?? '')) {
        http_response_code(419);
        exit('CSRF hiba');
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $position  = trim($_POST['position'] ?? '');
    $note      = trim($_POST['note'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $user_id_raw = $_POST['user_id'] ?? '';
    $user_id = $user_id_raw === '' ? null : (int)$user_id_raw;

    // Szakképesítések a POST-ból
    $selectedQualificationIds = array_map('intval', $_POST['qualification_ids'] ?? []);

    // Engedélyek a POST-ból
    $selectedPermitIds = array_map('intval', $_POST['permit_ids'] ?? []);
    $permitLicense     = $_POST['permit_license'] ?? [];
    $permitValidFrom   = $_POST['permit_valid_from'] ?? [];
    $permitValidUntil  = $_POST['permit_valid_until'] ?? [];
    $permitNote        = $_POST['permit_note'] ?? [];

    if ($full_name === '') {
        $err = 'A név megadása kötelező.';
    } else {
        if ($id > 0) {
            // Update
            $st = $pdo->prepare("
                UPDATE workers
                SET full_name = ?, position = ?, note = ?, is_active = ?, user_id = ?
                WHERE id = ?
            ");
            $st->execute([
                $full_name,
                $position !== '' ? $position : null,
                $note !== '' ? $note : null,
                $is_active,
                $user_id,
                $id,
            ]);
            $workerId = $id;
            Helpers::flash('ok', 'Kolléga frissítve.');
        } else {
            // Insert
            $st = $pdo->prepare("
                INSERT INTO workers (full_name, position, note, is_active, user_id)
                VALUES (?,?,?,?,?)
            ");
            $st->execute([
                $full_name,
                $position !== '' ? $position : null,
                $note !== '' ? $note : null,
                $is_active,
                $user_id,
            ]);
            $workerId = (int)$pdo->lastInsertId();
            Helpers::flash('ok', 'Kolléga létrehozva.');
        }

        // Szakképesítések szinkronizálása
        $stDel = $pdo->prepare("DELETE FROM worker_qualifications WHERE worker_id = ?");
        $stDel->execute([$workerId]);

        if (!empty($selectedQualificationIds)) {
            $stIns = $pdo->prepare("
                INSERT INTO worker_qualifications (worker_id, qualification_id)
                VALUES (?, ?)
            ");
            foreach ($selectedQualificationIds as $qid) {
                $stIns->execute([$workerId, $qid]);
            }
        }

        // Engedélyek szinkronizálása
        $stDel = $pdo->prepare("DELETE FROM worker_permits WHERE worker_id = ?");
        $stDel->execute([$workerId]);

        if (!empty($selectedPermitIds)) {
            $stIns = $pdo->prepare("
                INSERT INTO worker_permits (worker_id, permit_id, license_number, valid_from, valid_until, note)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            foreach ($selectedPermitIds as $pid) {
                $pid = (int)$pid;
                $lic = trim($permitLicense[$pid] ?? '');
                $vf  = trim($permitValidFrom[$pid] ?? '');
                $vu  = trim($permitValidUntil[$pid] ?? '');
                $nt  = trim($permitNote[$pid] ?? '');

                $vfVal = $vf !== '' ? $vf : null; // 'YYYY-MM-DD'
                $vuVal = $vu !== '' ? $vu : null;

                $stIns->execute([
                    $workerId,
                    $pid,
                    $lic !== '' ? $lic : null,
                    $vfVal,
                    $vuVal,
                    $nt !== '' ? $nt : null,
                ]);
            }
        }

        header('Location: /ts_workers.php');
        exit;
    }

    // Ha hiba van, töltsük vissza a form adatait
    $data = [
        'full_name' => $full_name,
        'position'  => $position,
        'note'      => $note,
        'is_active' => $is_active,
        'user_id'   => $user_id,
    ];

    // Engedélyek visszatöltése a formhoz
    $workerPermits = [];
    foreach ($selectedPermitIds as $pid) {
        $pid = (int)$pid;
        $workerPermits[$pid] = [
            'permit_id'      => $pid,
            'license_number' => $permitLicense[$pid] ?? '',
            'valid_from'     => $permitValidFrom[$pid] ?? null,
            'valid_until'    => $permitValidUntil[$pid] ?? null,
            'note'           => $permitNote[$pid] ?? '',
        ];
    }
}
?>
<div class="card">
  <div class="card-body">
    <h1 class="h5 mb-3">
      <?= $id > 0 ? 'Kolléga szerkesztése' : 'Új kolléga' ?>
    </h1>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= Helpers::e($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <?= Csrf::field() ?>

      <div class="mb-3">
        <label for="full_name" class="form-label">Név</label>
        <input type="text" name="full_name" id="full_name" class="form-control"
               value="<?= Helpers::e((string)$data['full_name']) ?>" required>
      </div>

      <div class="mb-3">
        <label for="position" class="form-label">Beosztás</label>
        <input type="text" name="position" id="position" class="form-control"
               value="<?= Helpers::e((string)$data['position']) ?>">
      </div>

      <div class="mb-3">
        <label for="user_id" class="form-label">Kapcsolt felhasználó (opcionális)</label>
        <select name="user_id" id="user_id" class="form-select">
          <option value="">— Nincs kapcsolva —</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"
              <?= $data['user_id'] == $u['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($u['name']) ?>
              <?php if (!empty($u['email'])): ?>
                (<?= Helpers::e($u['email']) ?>)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">
          Ha a kollégához tartozik belépő felhasználó (users tábla), itt kapcsolhatod össze.
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Szakképesítések</label>
        <div class="border rounded p-2" style="max-height: 250px; overflow-y: auto;">
          <?php if (!$qualifications): ?>
            <div class="text-muted small">Még nincs rögzített szakképesítés.</div>
          <?php else: ?>
            <?php foreach ($qualifications as $q): ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       name="qualification_ids[]"
                       id="q<?= (int)$q['id'] ?>"
                       value="<?= (int)$q['id'] ?>"
                       <?= in_array($q['id'], $selectedQualificationIds, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="q<?= (int)$q['id'] ?>">
                  <?= Helpers::e($q['name']) ?>
                </label>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Engedélyek és érvényesség</label>
        <div class="border rounded p-2" style="max-height: 300px; overflow-y: auto;">
          <?php if (!$permits): ?>
            <div class="text-muted small">Még nincs rögzített engedély.</div>
          <?php else: ?>
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th style="width: 1%;">Van</th>
                  <th>Engedély</th>
                  <th>Szám</th>
                  <th>Érvényes tól</th>
                  <th>Érvényes ig</th>
                  <th>Megjegyzés</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($permits as $p): 
                  $pid = (int)$p['id'];
                  $wp  = $workerPermits[$pid] ?? null;
              ?>
                <tr>
                  <td>
                    <input class="form-check-input" type="checkbox"
                           name="permit_ids[]"
                           value="<?= $pid ?>"
                           <?= $wp ? 'checked' : '' ?>>
                  </td>
                  <td>
                    <?= Helpers::e($p['name']) ?>
                    <?php if ($p['requires_renewal']): ?>
                      <span class="badge bg-warning text-dark ms-1">lejáratos</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <input type="text"
                           name="permit_license[<?= $pid ?>]"
                           class="form-control form-control-sm"
                           value="<?= $wp ? Helpers::e((string)$wp['license_number']) : '' ?>">
                  </td>
                  <td>
                    <input type="date"
                           name="permit_valid_from[<?= $pid ?>]"
                           class="form-control form-control-sm"
                           value="<?= $wp && $wp['valid_from'] ? Helpers::e($wp['valid_from']) : '' ?>">
                  </td>
                  <td>
                    <input type="date"
                           name="permit_valid_until[<?= $pid ?>]"
                           class="form-control form-control-sm"
                           value="<?= $wp && $wp['valid_until'] ? Helpers::e($wp['valid_until']) : '' ?>">
                  </td>
                  <td>
                    <input type="text"
                           name="permit_note[<?= $pid ?>]"
                           class="form-control form-control-sm"
                           value="<?= $wp ? Helpers::e((string)$wp['note']) : '' ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
        <div class="form-text">
          Pipáld be, mely engedélyekkel rendelkezik a kolléga, és add meg az érvényességi időket.
        </div>
      </div>

      <div class="mb-3">
        <label for="note" class="form-label">Megjegyzés</label>
        <textarea name="note" id="note" rows="3" class="form-control"><?= Helpers::e((string)$data['note']) ?></textarea>
      </div>

      <div class="mb-3 form-check">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
               <?= !empty($data['is_active']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="is_active">
          Aktív
        </label>
      </div>

      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">Mentés</button>
        <a href="/ts_workers.php" class="btn btn-secondary">Mégse</a>
      </div>
    </form>
  </div>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';