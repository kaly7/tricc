<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Külső partnerek törzsadatai.
 * A külsős átadások címzettjei és kapcsolati adatai innen jönnek.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Partnerek';
$loggedIn = true;
$pdo = warehouse_pdo($config);
$editId = (int)($_GET['edit'] ?? $_POST['partner_id'] ?? 0);
$editPartner = $editId > 0 ? warehouse_partner_find($config, $editId) : null;

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

// Egyszerű partner törzs: név, kapcsolattartó és elérhetőségek kezelése.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_partner') {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $partnerName = trim((string)($_POST['partner_name'] ?? ''));
        $receiverName = trim((string)($_POST['receiver_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($partnerName === '') {
            flash_set('err', 'A partner neve kötelező.');
            header('Location: /partners.php' . ($partnerId > 0 ? '?edit=' . $partnerId : ''));
            exit;
        }

        try {
            if ($partnerId > 0) {
                $st = $pdo->prepare('UPDATE warehouse_partners SET partner_name=?, receiver_name=?, phone=?, email=?, note=?, is_active=?, updated_by=? WHERE id=?');
                $st->execute([
                    $partnerName,
                    $receiverName !== '' ? $receiverName : null,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $note !== '' ? $note : null,
                    $isActive,
                    current_auth_user_id(),
                    $partnerId,
                ]);
                warehouse_audit($config, 'partner.update', 'partner', $partnerId, [
                    'partner_name' => $partnerName,
                    'receiver_name' => $receiverName,
                    'phone' => $phone,
                    'email' => $email,
                    'is_active' => $isActive,
                ]);
                flash_set('msg', 'Partner módosítva.');
            } else {
                $st = $pdo->prepare('INSERT INTO warehouse_partners (partner_name, receiver_name, phone, email, note, is_active, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([
                    $partnerName,
                    $receiverName !== '' ? $receiverName : null,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $note !== '' ? $note : null,
                    $isActive,
                    current_auth_user_id(),
                    current_auth_user_id(),
                ]);
                $partnerId = (int)$pdo->lastInsertId();
                warehouse_audit($config, 'partner.create', 'partner', $partnerId, [
                    'partner_name' => $partnerName,
                    'receiver_name' => $receiverName,
                    'phone' => $phone,
                    'email' => $email,
                    'is_active' => $isActive,
                ]);
                flash_set('msg', 'Partner létrehozva.');
            }
        } catch (Throwable $e) {
            flash_set('err', 'Mentési hiba: ' . $e->getMessage());
            header('Location: /partners.php' . ($partnerId > 0 ? '?edit=' . $partnerId : ''));
            exit;
        }

        header('Location: /partners.php');
        exit;
    }

    if ($action === 'toggle_active') {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $pdo->prepare('UPDATE warehouse_partners SET is_active = IF(is_active=1,0,1), updated_by=? WHERE id=?')
                ->execute([current_auth_user_id(), $partnerId]);
            $current = warehouse_partner_find($config, $partnerId);
            warehouse_audit($config, 'partner.toggle_active', 'partner', $partnerId, [
                'new_is_active' => (int)($current['is_active'] ?? 0),
                'partner_name' => (string)($current['partner_name'] ?? ''),
                'receiver_name' => (string)($current['receiver_name'] ?? ''),
            ]);
            flash_set('msg', 'Partner állapota frissítve.');
        }
        header('Location: /partners.php');
        exit;
    }

    if ($action === 'delete_partner') {
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        if ($partnerId > 0) {
            $current = warehouse_partner_find($config, $partnerId);
            $cntSt = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE partner_id = ?');
            $cntSt->execute([$partnerId]);
            $linkedCount = (int)$cntSt->fetchColumn();
            if ($linkedCount > 0) {
                flash_set('err', 'A partner nem törölhető, mert még ' . $linkedCount . ' raktárhoz kapcsolódik.');
            } else {
                $pdo->prepare('DELETE FROM warehouse_partners WHERE id = ?')->execute([$partnerId]);
                warehouse_audit($config, 'partner.delete', 'partner', $partnerId, [
                    'partner_name' => (string)($current['partner_name'] ?? ''),
                    'receiver_name' => (string)($current['receiver_name'] ?? ''),
                ]);
                flash_set('msg', 'Partner törölve.');
            }
        }
        header('Location: /partners.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$partners = warehouse_partners_all($config, false);
$linkedPartnerCounts = [];
if ($partners) {
    $rows = $pdo->query('SELECT partner_id, COUNT(*) AS cnt FROM warehouses WHERE partner_id IS NOT NULL GROUP BY partner_id')->fetchAll();
    foreach ($rows as $row) {
        $linkedPartnerCounts[(int)$row['partner_id']] = (int)$row['cnt'];
    }
}
$formDefaults = [
    'id' => 0,
    'partner_name' => '',
    'receiver_name' => '',
    'phone' => '',
    'email' => '',
    'note' => '',
    'is_active' => 1,
];
$formData = array_merge($formDefaults, $editPartner ?: []);

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0">Partnerek</h1>
    <div class="text-secondary small">Külső partner / átvevő adatok rögzítése későbbi kiválasztáshoz.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/warehouses.php">Raktárak</a>
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Admin napló</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6"><?= ((int)$formData['id'] > 0) ? 'Partner módosítása' : 'Új partner' ?></h2>
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_partner">
          <input type="hidden" name="partner_id" value="<?= (int)$formData['id'] ?>">
          <div class="col-12">
            <label class="form-label">Partner</label>
            <input class="form-control" name="partner_name" required value="<?= h((string)$formData['partner_name']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Átvevő</label>
            <input class="form-control" name="receiver_name" value="<?= h((string)$formData['receiver_name']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">Telefonszám</label>
            <input class="form-control" name="phone" value="<?= h((string)$formData['phone']) ?>">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">E-mail</label>
            <input class="form-control" type="email" name="email" value="<?= h((string)$formData['email']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Megjegyzés</label>
            <textarea class="form-control" name="note" rows="3"><?= h((string)$formData['note']) ?></textarea>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_active" id="partner_is_active" value="1" <?= ((int)$formData['is_active'] === 1) ? 'checked' : '' ?>>
              <label class="form-check-label" for="partner_is_active">Aktív</label>
            </div>
          </div>
          <div class="col-12 d-flex justify-content-between">
            <?php if ((int)$formData['id'] > 0): ?>
              <a class="btn btn-outline-secondary" href="/partners.php">Új űrlap</a>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit">Partner mentése</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <h2 class="h6 mb-0">Partner lista</h2>
          <div class="text-secondary small">A partnerek később kiválaszthatók a külső partner raktárakhoz.</div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle">
            <thead>
              <tr>
                <th>Partner</th>
                <th>Átvevő</th>
                <th>Kapcsolat</th>
                <th>Aktív</th>
                <th class="text-end">Művelet</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($partners as $partner): ?>
              <tr>
                <td>
                  <div class="fw-bold"><?= h((string)$partner['partner_name']) ?></div>
                  <?php if (!empty($partner['note'])): ?><div class="text-secondary small"><?= h((string)$partner['note']) ?></div><?php endif; ?>
                </td>
                <td><?= h((string)($partner['receiver_name'] ?? '—')) ?></td>
                <td>
                  <?php if (!empty($partner['phone'])): ?><div><?= h((string)$partner['phone']) ?></div><?php endif; ?>
                  <?php if (!empty($partner['email'])): ?><div class="text-secondary small"><?= h((string)$partner['email']) ?></div><?php endif; ?>
                  <?php if (empty($partner['phone']) && empty($partner['email'])): ?>—<?php endif; ?>
                </td>
                <td>
                  <?= ((int)$partner['is_active'] === 1) ? '<span class="badge bg-success">Igen</span>' : '<span class="badge bg-secondary">Nem</span>' ?>
                  <?php $linkedCount = (int)($linkedPartnerCounts[(int)$partner['id']] ?? 0); ?>
                  <?php if ($linkedCount > 0): ?><div class="text-secondary small mt-1">Kapcsolt raktár: <?= $linkedCount ?></div><?php endif; ?>
                </td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-primary" href="/partners.php?edit=<?= (int)$partner['id'] ?>">Módosítás</a>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)$partner['is_active'] === 1) ? 'Inaktivál' : 'Aktivál' ?></button>
                  </form>
                  <form method="post" class="d-inline" onsubmit="return confirm('Biztosan törlöd ezt a partnert?');">
                    <input type="hidden" name="action" value="delete_partner">
                    <input type="hidden" name="partner_id" value="<?= (int)$partner['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" <?= $linkedCount > 0 ? 'disabled title="Kapcsolt raktár esetén nem törölhető"' : '' ?>>Törlés</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$partners): ?>
              <tr><td colspan="5" class="text-secondary">Még nincs partner.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
