<?php
require __DIR__ . '/../bootstrap.php';
Auth::requireLogin();
require __DIR__ . '/_layout.php';

use Services\EmployeeService;

$pdo = Db::pdo();
$isAdmin = Auth::isAdmin();

$q = trim((string)($_GET['q'] ?? ''));
$msg = (string)($_GET['msg'] ?? '');
$err = '';

function normalize_tax_id(string $s): string {
    $s = preg_replace('/\D+/', '', $s);
    return (string)$s;
}
function is_valid_tax_id(string $s): bool {
    return (bool)preg_match('/^\d{10}$/', $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isAdmin) { http_response_code(403); echo "Forbidden"; exit; }

    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? '')); // OPTIONAL now
    $taxIdRaw = trim((string)($_POST['tax_id'] ?? ''));
    $taxId = normalize_tax_id($taxIdRaw);

    if ($action === 'save') {
        if ($name === '') $err = 'A név kötelező.';
        if (!$err && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Hibás email formátum.';
        if (!$err && $taxId !== '' && !is_valid_tax_id($taxId)) $err = 'Az adóazonosító jel hibás (10 számjegy kell).';

        if (!$err) {
            $nameNorm = '';
            try { $nameNorm = EmployeeService::normalizeName($name); }
            catch (\Throwable $e) { $nameNorm = mb_strtolower($name, 'UTF-8'); }

            $hasNameNorm = false;
            try { $hasNameNorm = (bool)$pdo->query("SHOW COLUMNS FROM employees LIKE 'name_norm'")->fetch(); }
            catch (\Throwable $e) { $hasNameNorm = false; }

            $hasTaxId = false;
            try { $hasTaxId = (bool)$pdo->query("SHOW COLUMNS FROM employees LIKE 'tax_id'")->fetch(); }
            catch (\Throwable $e) { $hasTaxId = false; }

            $emailVal = ($email === '') ? null : $email;
            $taxVal = ($taxId === '') ? null : $taxId;

            try {
                if ($id > 0) {
                    if ($hasNameNorm && $hasTaxId) {
                        $st = $pdo->prepare("UPDATE employees SET name=?, email=?, tax_id=?, name_norm=? WHERE id=?");
                        $st->execute([$name, $emailVal, $taxVal, $nameNorm, $id]);
                    } elseif ($hasTaxId) {
                        $st = $pdo->prepare("UPDATE employees SET name=?, email=?, tax_id=? WHERE id=?");
                        $st->execute([$name, $emailVal, $taxVal, $id]);
                    } elseif ($hasNameNorm) {
                        $st = $pdo->prepare("UPDATE employees SET name=?, email=?, name_norm=? WHERE id=?");
                        $st->execute([$name, $emailVal, $nameNorm, $id]);
                    } else {
                        $st = $pdo->prepare("UPDATE employees SET name=?, email=? WHERE id=?");
                        $st->execute([$name, $emailVal, $id]);
                    }
                    header("Location: employees.php?msg=updated");
                    exit;
                } else {
                    if ($hasNameNorm && $hasTaxId) {
                        $st = $pdo->prepare("INSERT INTO employees(name,email,tax_id,name_norm) VALUES(?,?,?,?)");
                        $st->execute([$name, $emailVal, $taxVal, $nameNorm]);
                    } elseif ($hasTaxId) {
                        $st = $pdo->prepare("INSERT INTO employees(name,email,tax_id) VALUES(?,?,?)");
                        $st->execute([$name, $emailVal, $taxVal]);
                    } elseif ($hasNameNorm) {
                        $st = $pdo->prepare("INSERT INTO employees(name,email,name_norm) VALUES(?,?,?)");
                        $st->execute([$name, $emailVal, $nameNorm]);
                    } else {
                        $st = $pdo->prepare("INSERT INTO employees(name,email) VALUES(?,?)");
                        $st->execute([$name, $emailVal]);
                    }
                    header("Location: employees.php?msg=created");
                    exit;
                }
            } catch (\Throwable $e) {
                $err = "DB hiba: " . $e->getMessage();
            }
        }
    }
}

$edit = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM employees WHERE id=?");
    $st->execute([$editId]);
    $edit = $st->fetch();
}

// Csak HR-ben nem található (unmatched) rekordok
$sql = "SELECT id, name, email, tax_id FROM employees WHERE hr_id IS NULL";
$params = [];
if ($q !== '') {
    $sql .= " AND (name LIKE ? OR email LIKE ? OR tax_id LIKE ?)";
    $params = ["%$q%", "%$q%", "%".normalize_tax_id($q)."%"];
}
$sql .= " ORDER BY name ASC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

page_header('Nem egyeztetett dolgozók');
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-4">
      <div class="d-flex justify-content-between align-items-center">
        <h1 class="h5 mb-0">Nem egyeztetett dolgozó <?= $edit ? 'módosítása' : 'felvitele' ?></h1>
        <?php if ($edit): ?>
          <a class="btn btn-sm btn-outline-secondary" href="employees.php">Új</a>
        <?php endif; ?>
      </div>

      <?php if ($msg === 'created'): ?><div class="alert alert-success mt-3 py-2">Felvéve.</div><?php endif; ?>
      <?php if ($msg === 'updated'): ?><div class="alert alert-success mt-3 py-2">Mentve.</div><?php endif; ?>
      <?php if ($msg === 'deleted'): ?><div class="alert alert-success mt-3 py-2">Törölve.</div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger mt-3"><?= h($err) ?></div><?php endif; ?>

      <?php if (!$isAdmin): ?>
        <div class="alert alert-warning mt-3">Dolgozó módosítás/felvitel csak adminnak engedélyezett.</div>
      <?php endif; ?>

      <form method="post" class="mt-3">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="mb-3">
          <label class="form-label">Név</label>
          <input class="form-control" name="name" value="<?= h($edit['name'] ?? '') ?>" <?= $isAdmin ? '' : 'disabled' ?> required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email (opcionális)</label>
          <input class="form-control" name="email" type="email" value="<?= h($edit['email'] ?? '') ?>" <?= $isAdmin ? '' : 'disabled' ?>>
          <div class="form-text">Ha nincs kitöltve, automatikus küldés nem fog menni, de a PDF-ek listázhatók.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Adóazonosító jel (Adójel)</label>
          <input class="form-control" name="tax_id" inputmode="numeric" placeholder="10 számjegy"
                 value="<?= h($edit['tax_id'] ?? '') ?>" <?= $isAdmin ? '' : 'disabled' ?>>
        </div>
        <button class="btn btn-primary" type="submit" <?= $isAdmin ? '' : 'disabled' ?>>Mentés</button>
      </form>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card p-3">
      <div class="alert alert-info py-2 small mb-3">
        Csak azok a rekordok látszanak, akik PDF-feldolgozás közben keletkeztek, de <strong>nem találhatók a HR rendszerben</strong>.
        A HR-ből szinkronizált dolgozók automatikusan kezeltek.
      </div>
      <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Lista</h2>
        <form class="d-flex gap-2" method="get">
          <input class="form-control form-control-sm" name="q" placeholder="Keresés név/email/adójel" value="<?= h($q) ?>">
          <button class="btn btn-sm btn-outline-primary" type="submit">Keres</button>
          <?php if ($q !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="employees.php">Töröl</a><?php endif; ?>
        </form>
      </div>

      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr><th>Név</th><th>Email</th><th>Adójel</th><th class="text-end">Művelet</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= h($r['name']) ?></td>
                <td><?= h($r['email'] ?? '') ?></td>
                <td class="text-muted small"><?= h(maskTaxId($r['tax_id'] ?? '')) ?></td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-sm btn-outline-primary" href="employees.php?edit=<?= (int)$r['id'] ?>">Szerkeszt</a>
                  <?php if ($isAdmin): ?>
                    <a class="btn btn-sm btn-outline-danger" href="employee_delete.php?id=<?= (int)$r['id'] ?>"
                       onclick="return confirm('Biztosan törlöd?');">Töröl</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="4" class="text-muted">Nincs találat.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>
