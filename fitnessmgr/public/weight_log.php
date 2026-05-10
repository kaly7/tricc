<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/AIService.php';

$page  = 'weight';
$title = 'Súlynapló';

$u      = current_user() ?? [];
$userId = (int)($u['id'] ?? 0);
$svc    = new WeightService();
$ai     = new AIService();

// Súly mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_weight'])) {
  verify_csrf();
  $weight = (float)str_replace(',', '.', $_POST['weight_kg'] ?? '0');
  $date   = $_POST['measured_at'] ?? today();
  $notes  = trim($_POST['notes'] ?? '');
  if ($weight > 0) {
    $svc->addLog($userId, $weight, $date, $notes ?: null);
    flash_set('ok', 'Súlymérés rögzítve!');
  } else {
    flash_set('err', 'Érvénytelen súly.');
  }
  redirect('weight_log.php');
}

// Törlés
if (!empty($_GET['delete'])) {
  $svc->deleteLog((int)$_GET['delete'], $userId);
  flash_set('ok', 'Mérés törölve.');
  redirect('weight_log.php');
}

$history = $svc->getHistory($userId, 90);
$latest  = $svc->getLatest($userId);
$change  = $svc->getChange($userId);
$profile = $svc->getProfile($userId);

// AI motiváció
$motivation = null;
if ($ai->isEnabled() && !empty($_GET['motivate']) && count($history) >= 3) {
  $motivation = $ai->motivate($history);
}

require_once __DIR__ . '/_header.php';
?>

<div class="row g-4">
  <div class="col-lg-4">
    <!-- Mérés rögzítés -->
    <div class="card">
      <div class="card-header"><strong>⚖️ Mérés rögzítése</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="save_weight" value="1">
          <div class="mb-3">
            <label class="form-label">Súly (kg)</label>
            <input type="number" name="weight_kg" class="form-control" step="0.1" min="30" max="300"
                   placeholder="pl. 82.5" autofocus required>
          </div>
          <div class="mb-3">
            <label class="form-label">Dátum</label>
            <input type="date" name="measured_at" class="form-control" value="<?= today() ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Megjegyzés</label>
            <input type="text" name="notes" class="form-control" placeholder="opcionális">
          </div>
          <button type="submit" class="btn btn-success w-100">💾 Rögzítés</button>
        </form>
      </div>
    </div>

    <!-- Jelenlegi állapot -->
    <?php if ($latest): ?>
    <div class="card mt-3">
      <div class="card-body text-center">
        <div class="text-muted small">Legutóbbi mérés</div>
        <div style="font-size:2.5rem;font-weight:700"><?= number_format((float)$latest['weight_kg'], 1) ?> kg</div>
        <?php if ($latest['bmi']): ?>
          <div class="text-muted">BMI: <?= number_format((float)$latest['bmi'], 1) ?> – <em><?= e(bmi_category((float)$latest['bmi'])) ?></em></div>
        <?php endif; ?>
        <?php if ($change !== null): ?>
          <div class="mt-2 <?= $change <= 0 ? 'text-success' : 'text-danger' ?>">
            <?= ($change > 0 ? '+' : '') . number_format($change, 1) ?> kg az előző méréshez képest
          </div>
        <?php endif; ?>
        <?php if ($profile && $profile['target_weight_kg']): ?>
          <div class="mt-2 text-muted small">
            Célsúly: <?= number_format((float)$profile['target_weight_kg'], 1) ?> kg<br>
            Különbség: <strong><?= number_format((float)$latest['weight_kg'] - (float)$profile['target_weight_kg'], 1) ?> kg</strong>
          </div>
        <?php endif; ?>
        <?php if ($ai->isEnabled() && count($history) >= 3): ?>
          <a href="?motivate=1" class="btn btn-outline-success btn-sm mt-3">🤖 AI motiváció</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($motivation): ?>
    <div class="card mt-3 ai-card">
      <div class="card-body">
        <div class="card-title">🤖 AI tanács</div>
        <p class="mb-0 small"><?= nl2br(e($motivation)) ?></p>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <!-- Grafikon -->
    <?php if (count($history) >= 2): ?>
    <div class="card mb-3">
      <div class="card-header"><strong>📈 Súlytrend (utolsó 90 nap)</strong></div>
      <div class="card-body">
        <canvas id="weightChart"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <!-- Táblázat -->
    <div class="card">
      <div class="card-header"><strong>📋 Mérési előzmények</strong></div>
      <?php if ($history): ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Dátum</th><th>Súly</th><th>BMI</th><th>Megjegyzés</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($history) as $row): ?>
                <tr>
                  <td><?= e($row['measured_at']) ?></td>
                  <td><strong><?= number_format((float)$row['weight_kg'], 1) ?> kg</strong></td>
                  <td><?= $row['bmi'] ? number_format((float)$row['bmi'], 1) : '–' ?></td>
                  <td class="text-muted small"><?= e($row['notes'] ?? '') ?></td>
                  <td>
                    <a href="?delete=<?= $row['id'] ?>" class="btn btn-outline-danger btn-sm py-0 px-1"
                       onclick="return confirm('Törlöd?')">✕</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="card-body text-center text-muted py-4">
          Még nincs súlymérés rögzítve. Add hozzá az első mérésed!
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (count($history) >= 2):
  $labels  = json_encode(array_column($history, 'measured_at'));
  $weights = json_encode(array_map(fn($r) => (float)$r['weight_kg'], $history));
  $target  = $profile && $profile['target_weight_kg'] ? (float)$profile['target_weight_kg'] : null;
  $targetLine = $target ? json_encode(array_fill(0, count($history), $target)) : 'null';
  $extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const datasets = [
  { label: 'Súly (kg)', data: {$weights}, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.1)', fill:true, tension:.3 }
];
if ({$targetLine} !== null) {
  datasets.push({ label: 'Célsúly', data: {$targetLine}, borderColor:'#22c55e', borderDash:[6,3], pointRadius:0, fill:false });
}
new Chart(document.getElementById('weightChart'), {
  type:'line',
  data:{ labels:{$labels}, datasets },
  options:{ plugins:{ legend:{ position:'top' } }, scales:{ y:{ suggestedMin: Math.min(...{$weights})-2 } } }
});
</script>
JS;
endif; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
