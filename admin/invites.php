<?php
require '_db.php';
tricc_auth();

require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;

$db = tricc_db();
$errors = [];
$sent_to = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Új meghívó generálás (+opcionális email küldés) ---
    if ($action === 'create') {
        $custom  = strtoupper(trim($_POST['code'] ?? ''));
        $expires = $_POST['expires_at'] ?? '';
        $email   = trim($_POST['send_email'] ?? '');
        $recipient_name = trim($_POST['recipient_name'] ?? '');

        $code = $custom ?: 'TRICC-' . strtoupper(bin2hex(random_bytes(4)));
        $exp  = $expires ?: null;

        // Duplikált kód ellenőrzés
        $chk = $db->prepare("SELECT id FROM invite_codes WHERE code=?");
        $chk->execute([$code]);
        if ($chk->fetch()) {
            $errors[] = "Ez a kód már létezik: $code";
        } else {
            $db->prepare("INSERT INTO invite_codes (code, created_by, expires_at) VALUES (?,?,?)")
               ->execute([$code, $_SESSION['tricc_admin']['id'], $exp]);

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'mail.t-online.hu';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'noreply@perfect-phone.hu';
                    $mail->Password   = 'PPn0R3p1@y-25';
                    $mail->SMTPSecure = 'tls';
                    $mail->Port       = 587;
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom('noreply@perfect-phone.hu', 'Tricc');
                    $mail->addAddress($email, $recipient_name ?: $email);
                    $mail->Subject = 'Meghívó a Tricc csevegő alkalmazásba';
                    $expStr = $exp ? date('Y. F j.', strtotime($exp)) : 'nincs lejárat';
                    $mail->Body = "Szia" . ($recipient_name ? " $recipient_name" : "") . "!\n\n"
                        . "Meghívást kaptál a Tricc belső csevegőbe.\n\n"
                        . "Meghívókódod: $code\n"
                        . "Lejárat: $expStr\n\n"
                        . "Telepítés után a regisztrációnál add meg ezt a kódot.\n\n"
                        . "Üdv,\n3C Távközlési Kft";
                    $mail->AltBody = $mail->Body;
                    $mail->send();
                    $sent_to = $email;
                    flash("Meghívó létrehozva ($code) és elküldve: $email");
                } catch (\Exception $e) {
                    flash("Meghívó létrehozva ($code), de az email küldés sikertelen: " . $mail->ErrorInfo, 'danger');
                }
            } else {
                flash("Meghívó létrehozva: $code");
            }
            header('Location: invites.php'); exit;
        }
    }

    // --- Törlés ---
    if ($action === 'delete') {
        $id = (int)($_POST['invite_id'] ?? 0);
        $chk = $db->prepare("SELECT used_by FROM invite_codes WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if ($row && !$row['used_by']) {
            $db->prepare("DELETE FROM invite_codes WHERE id=?")->execute([$id]);
            flash('Meghívó törölve.');
        } else {
            flash('Már felhasznált meghívó nem törölhető.', 'danger');
        }
        header('Location: invites.php'); exit;
    }
}

$invites = $db->query("
    SELECT ic.id, ic.code, ic.created_by, u.name AS created_by_name,
           ic.used_by, u2.name AS used_by_name, u2.email AS used_by_email,
           ic.used_at, ic.expires_at, ic.created_at
    FROM invite_codes ic
    LEFT JOIN users u  ON u.id  = ic.created_by
    LEFT JOIN users u2 ON u2.id = ic.used_by
    ORDER BY ic.id DESC
")->fetchAll();

$title = 'Meghívók'; $active_page = 'invites';
require '_layout.php';
?>

<div class="row g-4">

  <!-- Bal: Meghívó generálás -->
  <div class="col-md-4">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-envelope-plus"></i> Új meghívó
      </div>
      <div class="card-body">
        <?php foreach ($errors as $e): ?>
          <div class="alert alert-danger py-2 small"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
        <form method="post">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label fw-semibold small">Kód <span class="text-muted">(opcionális, auto ha üres)</span></label>
            <input type="text" name="code" class="form-control text-uppercase"
                   placeholder="pl. TRICC-KOVACS"
                   value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"
                   style="font-family:monospace">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Lejárat <span class="text-muted">(opcionális)</span></label>
            <input type="date" name="expires_at" class="form-control"
                   value="<?= htmlspecialchars($_POST['expires_at'] ?? '') ?>">
          </div>
          <hr>
          <p class="small text-muted mb-2">Email küldés (opcionális)</p>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Név</label>
            <input type="text" name="recipient_name" class="form-control"
                   placeholder="Kovács Péter"
                   value="<?= htmlspecialchars($_POST['recipient_name'] ?? '') ?>">
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold small">Email cím</label>
            <input type="email" name="send_email" class="form-control"
                   placeholder="pelda@ceg.hu"
                   value="<?= htmlspecialchars($_POST['send_email'] ?? '') ?>">
          </div>
          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-plus-circle"></i> Meghívó generálása
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Jobb: Meghívók listája -->
  <div class="col-md-8">
    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-list-ul"></i> Meghívók (<?= count($invites) ?>)</span>
        <span class="small text-muted">
          <?= count(array_filter($invites, fn($i) => !$i['used_by'])) ?> szabad,
          <?= count(array_filter($invites, fn($i) => $i['used_by'])) ?> felhasznált
        </span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
          <thead class="table-light">
            <tr>
              <th>Kód</th>
              <th>Létrehozta</th>
              <th>Felhasználta</th>
              <th>Lejárat</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($invites as $inv): ?>
            <?php
              $expired = $inv['expires_at'] && strtotime($inv['expires_at']) < time();
              $used    = (bool)$inv['used_by'];
            ?>
            <tr class="<?= $used ? 'table-light text-muted' : '' ?>">
              <td>
                <code class="<?= $used ? 'text-muted' : 'text-primary' ?>"><?= htmlspecialchars($inv['code']) ?></code>
                <?php if (!$used && $expired): ?>
                  <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1">Lejárt</span>
                <?php elseif (!$used && !$expired): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle ms-1">Szabad</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($inv['created_by_name'] ?? '—') ?></td>
              <td>
                <?php if ($used): ?>
                  <span title="<?= htmlspecialchars($inv['used_by_email'] ?? '') ?>">
                    <?= htmlspecialchars($inv['used_by_name'] ?? '—') ?>
                  </span>
                  <div class="text-muted" style="font-size:.75rem"><?= date('Y.m.d', strtotime($inv['used_at'])) ?></div>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($inv['expires_at']): ?>
                  <span class="<?= $expired ? 'text-danger' : '' ?>">
                    <?= date('Y.m.d', strtotime($inv['expires_at'])) ?>
                  </span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!$used): ?>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="invite_id" value="<?= $inv['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                          onclick="return confirm('Törlöd ezt a meghívót?')">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</div>
</body>
</html>
