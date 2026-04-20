<?php
require __DIR__ . '/../partials/header.php';
require_login($pdo);
if (!is_admin($pdo)) { http_response_code(403); echo "Nincs jogosultság."; require __DIR__ . '/../partials/footer.php'; exit; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_verify();
  $pairs = [
    'color_overdue' => $_POST['color_overdue'] ?? '#ffdddd',
    'color_due_soon' => $_POST['color_due_soon'] ?? '#fff3cd',
    'color_ok' => $_POST['color_ok'] ?? '#ddffdd',
    'due_soon_days' => (string)(int)($_POST['due_soon_days'] ?? 7),
  ];
  foreach ($pairs as $k=>$v) {
    $stmt = $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)");
    $stmt->execute([$k,$v]);
  }
  redirect('admin/settings.php');
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4">Színezés / beállítások</h1>
</div>

<form method="post" class="row g-3">
  <?= csrf_field() ?>
  <div class="col-md-3"><label class="form-label">Lejárt (háttérszín)</label><input class="form-control" type="color" name="color_overdue" value="<?= htmlspecialchars($settings['color_overdue'] ?? '#ffdddd') ?>"></div>
  <div class="col-md-3"><label class="form-label">Hamarosan esedékes (háttérszín)</label><input class="form-control" type="color" name="color_due_soon" value="<?= htmlspecialchars($settings['color_due_soon'] ?? '#fff3cd') ?>"></div>
  <div class="col-md-3"><label class="form-label">Rendben (háttérszín)</label><input class="form-control" type="color" name="color_ok" value="<?= htmlspecialchars($settings['color_ok'] ?? '#ddffdd') ?>"></div>
  <div class="col-md-3"><label class="form-label">„Hamarosan” napok száma</label><input class="form-control" type="number" min="1" max="60" name="due_soon_days" value="<?= htmlspecialchars($settings['due_soon_days'] ?? '7') ?>"></div>
  <div class="col-12"><button class="btn btn-primary">Mentés</button></div>
</form>
<?php require __DIR__ . '/../partials/footer.php'; ?>
