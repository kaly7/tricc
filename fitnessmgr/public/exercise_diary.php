<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';

$page  = 'exercise';
$title = 'Edzés napló';

$u      = current_user() ?? [];
$userId = (int)($u['id'] ?? 0);
$svc    = new ExerciseService();

// Rögzítés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exercise'])) {
  verify_csrf();
  try {
    $svc->addEntry($userId, $_POST);
    flash_set('ok', 'Edzés rögzítve!');
    redirect('exercise_diary.php?date=' . ($_POST['done_at'] ?? today()));
  } catch (Throwable $e) {
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
}

// Törlés
if (!empty($_GET['delete'])) {
  $svc->deleteEntry((int)$_GET['delete'], $userId);
  flash_set('ok', 'Edzés törölve.');
  $d = $_GET['date'] ?? today();
  redirect('exercise_diary.php?date=' . $d);
}

$date     = $_GET['date'] ?? today();
$entries  = $svc->getDayEntries($userId, $date);
$totals   = $svc->getDayTotals($userId, $date);
$allTypes = $svc->getAll();
$byCategory = [];
foreach ($allTypes as $t) { $byCategory[$t['category']][] = $t; }

$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));

require_once __DIR__ . '/_header.php';
?>

<div class="row g-4">
  <!-- Felvitel form -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><strong>🏃 Edzés rögzítése</strong></div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="save_exercise" value="1">

          <div class="mb-3">
            <label class="form-label">Mozgásforma</label>
            <select name="exercise_type_id" class="form-select" id="exType" onchange="updateCalcInfo()">
              <option value="">– Egyéni / nem listázott –</option>
              <?php foreach ($byCategory as $cat => $types): ?>
                <optgroup label="<?= e(ucfirst($cat)) ?>">
                  <?php foreach ($types as $t): ?>
                    <option value="<?= $t['id'] ?>" data-cal="<?= $t['calories_per_hour'] ?>">
                      <?= e($t['name']) ?> (~<?= $t['calories_per_hour'] ?> kcal/h)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="customExerciseName" class="mb-3">
            <label class="form-label">Egyéni elnevezés</label>
            <input type="text" name="custom_exercise_name" class="form-control" placeholder="pl. kerékpározás, séta...">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Időtartam (perc) *</label>
              <input type="number" name="duration_min" class="form-control" min="1" max="600" value="30"
                     id="durationMin" onchange="updateCalcInfo()" required>
            </div>
            <div class="col-6">
              <label class="form-label">Égetett kalória</label>
              <div class="form-control bg-light" id="burnedCalc">~150 kcal</div>
            </div>
          </div>

          <div id="manualBurned" class="mb-3 d-none">
            <label class="form-label">Manuális kalória</label>
            <input type="number" name="calories_burned" class="form-control" min="0" placeholder="kcal">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Dátum</label>
              <input type="date" name="done_at" class="form-control" value="<?= e($date) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Megjegyzés</label>
            <input type="text" name="notes" class="form-control" placeholder="opcionális">
          </div>

          <button type="submit" class="btn btn-success w-100">💾 Rögzítés</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Napló lista -->
  <div class="col-lg-7">
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="?date=<?= $prevDate ?>" class="btn btn-outline-secondary btn-sm">◀</a>
      <input type="date" class="form-control form-control-sm" style="max-width:160px"
             value="<?= e($date) ?>" onchange="window.location='?date='+this.value">
      <?php if ($nextDate <= today()): ?>
        <a href="?date=<?= $nextDate ?>" class="btn btn-outline-secondary btn-sm">▶</a>
      <?php endif; ?>
      <?php if ($date !== today()): ?>
        <a href="?date=<?= today() ?>" class="btn btn-outline-secondary btn-sm">Ma</a>
      <?php endif; ?>
    </div>

    <?php if ($totals['duration_min'] > 0): ?>
      <div class="alert alert-success py-2">
        <strong>Összesen:</strong> <?= $totals['duration_min'] ?> perc, <?= $totals['calories_burned'] ?> kcal égett
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><strong>📋 Edzések – <?= e($date) ?></strong></div>
      <?php if ($entries): ?>
        <table class="table table-hover mb-0">
          <tbody>
            <?php foreach ($entries as $ex): ?>
              <tr>
                <td>
                  <strong><?= e($ex['type_name'] ?? $ex['custom_exercise_name'] ?? 'Mozgás') ?></strong>
                  <?php if ($ex['category']): ?>
                    <span class="badge bg-secondary ms-1"><?= e($ex['category']) ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-muted"><?= $ex['duration_min'] ?> perc</td>
                <td><span class="kcal-badge"><?= $ex['calories_burned'] ?> kcal</span></td>
                <td class="text-muted small"><?= e($ex['notes'] ?? '') ?></td>
                <td>
                  <a href="?delete=<?= $ex['id'] ?>&date=<?= e($date) ?>"
                     class="btn btn-outline-danger btn-sm py-0 px-1"
                     onclick="return confirm('Törlöd?')">✕</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="card-body text-center text-muted py-4">
          Ma nincs rögzített edzés.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function updateCalcInfo() {
  const sel = document.getElementById('exType');
  const dur = parseInt(document.getElementById('durationMin').value) || 30;
  const opt = sel.options[sel.selectedIndex];
  const cal100 = opt ? parseInt(opt.getAttribute('data-cal')) || 0 : 0;

  const hasType = sel.value !== '';
  document.getElementById('customExerciseName').style.display = hasType ? 'none' : '';
  document.getElementById('manualBurned').className = hasType ? 'd-none mb-3' : 'mb-3';

  if (cal100 > 0) {
    const burned = Math.round(cal100 * dur / 60);
    document.getElementById('burnedCalc').textContent = '~' + burned + ' kcal';
  } else {
    document.getElementById('burnedCalc').textContent = '–';
  }
}
updateCalcInfo();
</script>
JS;
?>
<?php require_once __DIR__ . '/_footer.php'; ?>
