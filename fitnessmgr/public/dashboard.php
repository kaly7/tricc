<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';

$page  = 'dashboard';
$title = 'Dashboard';

require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/WaterService.php';

$u      = current_user();
$userId = (int)$u['id'];
$today  = today();

// Víz gyors-rögzítés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['log_water'])) {
  verify_csrf();
  $ml = (int)($_POST['water_ml'] ?? 0);
  if ($ml > 0 && $ml <= 5000) {
    (new WaterService())->addLog($userId, $ml, $today);
  }
  redirect('dashboard.php');
}

require_once __DIR__ . '/_header.php';

$dailySvc  = new DailyService();
$weightSvc = new WeightService();
$foodSvc   = new FoodService();
$exSvc     = new ExerciseService();

$summary  = $dailySvc->getDaySummary($userId, $today);
$profile  = $weightSvc->getProfile($userId);
$latestW  = $weightSvc->getLatest($userId);
$wChange  = $weightSvc->getChange($userId);
$trend    = $dailySvc->getWeekTrend($userId, 7);
$dayMeals = $foodSvc->getDayByMeal($userId, $today);
$exDay    = $exSvc->getDayEntries($userId, $today);

$calPct      = $summary['calorie_pct'];
$calColor    = progress_color((float)$calPct);
$exPct       = $summary['exercise_pct'];
$exColor     = progress_color((float)$exPct);
$remaining   = max(0, $summary['calorie_goal'] - $summary['calories_in']);
?>

<div class="row g-3 mb-4">
  <!-- Kalória stat -->
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100 text-center p-3">
      <div class="label">Mai kalória</div>
      <div class="display-6 text-<?= $calColor ?>"><?= number_format($summary['calories_in']) ?></div>
      <div class="text-muted small">/ <?= number_format($summary['calorie_goal']) ?> kcal</div>
      <div class="progress mt-2">
        <div class="progress-bar bg-<?= $calColor ?>" style="width:<?= min(100,$calPct) ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Mozgás stat -->
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100 text-center p-3">
      <div class="label">Mozgás</div>
      <div class="display-6 text-<?= $exColor ?>"><?= $summary['exercise_min'] ?></div>
      <div class="text-muted small">/ <?= $summary['exercise_goal'] ?> perc</div>
      <div class="progress mt-2">
        <div class="progress-bar bg-<?= $exColor ?>" style="width:<?= min(100,$exPct) ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Súly stat -->
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100 text-center p-3">
      <div class="label">Aktuális súly</div>
      <?php if ($latestW): ?>
        <div class="display-6"><?= number_format((float)$latestW['weight_kg'], 1) ?> kg</div>
        <?php if ($wChange !== null): ?>
          <div class="small <?= $wChange <= 0 ? 'text-success' : 'text-danger' ?>">
            <?= ($wChange > 0 ? '+' : '') . number_format($wChange, 1) ?> kg
          </div>
        <?php endif; ?>
        <?php if ($latestW['bmi']): ?>
          <div class="text-muted small">BMI <?= number_format((float)$latestW['bmi'], 1) ?> – <?= e(bmi_category((float)$latestW['bmi'])) ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div class="display-6 text-muted">–</div>
        <div class="small"><a href="<?= e(base_url('weight_log.php')) ?>">Rögzíts mérést</a></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cél stat -->
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100 text-center p-3">
      <div class="label">Maradék kalória</div>
      <div class="display-6 <?= $remaining > 0 ? 'text-success' : 'text-danger' ?>">
        <?= $remaining > 0 ? $remaining : '0' ?>
      </div>
      <div class="text-muted small">kcal marad ma</div>
      <?php if ($summary['target_weight'] && $latestW): ?>
        <div class="small text-muted mt-1">
          Célsúlytól: <?= round((float)$latestW['weight_kg'] - $summary['target_weight'], 1) ?> kg
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Víz stat -->
  <?php
    $waterMl   = $summary['water_ml'];
    $waterGoal = $summary['water_goal'];
    $waterPct  = $summary['water_pct'];
    $waterColor = $waterPct >= 100 ? 'success' : ($waterPct >= 50 ? 'info' : 'warning');
    $waterL    = number_format($waterMl / 1000, 1);
    $waterGoalL = number_format($waterGoal / 1000, 1);
  ?>
  <div class="col-6 col-md-3">
    <div class="card stat-card h-100 text-center p-3">
      <div class="label">💧 Vízfogyasztás</div>
      <div class="display-6 text-<?= $waterColor ?>"><?= $waterL ?>L</div>
      <div class="text-muted small">/ <?= $waterGoalL ?>L napi cél</div>
      <div class="progress mt-2">
        <div class="progress-bar bg-<?= $waterColor ?>" style="width:<?= min(100,$waterPct) ?>%"></div>
      </div>
      <form method="post" class="mt-2">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="log_water" value="1">
        <div class="btn-group w-100">
          <button type="submit" name="water_ml" value="250" class="btn btn-outline-info btn-sm py-0">🥤</button>
          <button type="submit" name="water_ml" value="500" class="btn btn-outline-info btn-sm py-0">500</button>
          <button type="submit" name="water_ml" value="1000" class="btn btn-outline-info btn-sm py-0">1L</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Mai étkezések -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>🍽️ Mai étkezések</strong>
        <a href="<?= e(base_url('food_add.php')) ?>" class="btn btn-success btn-sm">+ Étkezés</a>
      </div>
      <div class="card-body">
        <?php foreach (['reggeli','tizorai','ebed','uzsonna','vacsora'] as $meal):
          $m = $dayMeals[$meal];
          if (empty($m['entries'])) continue;
        ?>
          <div class="meal-section <?= e($meal) ?> mb-3">
            <div class="meal-header"><?= meal_icon($meal) ?> <?= meal_label($meal) ?> – <span class="kcal-badge"><?= $m['calories'] ?> kcal</span></div>
            <?php foreach ($m['entries'] as $e): ?>
              <div class="d-flex justify-content-between align-items-center food-row py-1 border-bottom">
                <span><?= e($e['food_name'] ?? $e['custom_food_name'] ?? 'Ismeretlen') ?>
                  <small class="text-muted"><?= $e['amount_g'] ?>g</small>
                </span>
                <span class="d-flex align-items-center gap-2">
                  <span class="kcal-badge"><?= $e['calories'] ?> kcal</span>
                  <a href="<?= e(base_url('food_diary.php?delete=' . $e['id'])) ?>"
                     class="delete-btn btn btn-outline-danger btn-sm py-0 px-1"
                     onclick="return confirm('Törlöd ezt a bejegyzést?')" title="Törlés">✕</a>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <?php if (array_sum(array_map(fn($m) => count($m['entries']), $dayMeals)) === 0): ?>
          <p class="text-muted text-center py-3">Ma még nincs rögzített étkezés.<br>
            <a href="<?= e(base_url('food_add.php')) ?>" class="btn btn-outline-success btn-sm mt-2">+ Étkezés hozzáadása</a>
          </p>
        <?php endif; ?>

        <!-- Makró összesítő -->
        <?php if ($summary['calories_in'] > 0): ?>
          <div class="mt-3 pt-2 border-top">
            <div class="row g-2 text-center">
              <div class="col-4">
                <div class="small text-muted">Fehérje</div>
                <div class="fw-semibold"><?= number_format($summary['protein_g'], 0) ?>g</div>
                <div class="progress" style="height:5px"><div class="progress-bar bg-primary" style="width:<?= min(100, round($summary['protein_g']/$summary['protein_goal_g']*100)) ?>%"></div></div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Szénhidrát</div>
                <div class="fw-semibold"><?= number_format($summary['carbs_g'], 0) ?>g</div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Zsír</div>
                <div class="fw-semibold"><?= number_format($summary['fat_g'], 0) ?>g</div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Jobb oldal: mozgás + heti trend -->
  <div class="col-lg-5">
    <!-- Mai edzés -->
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>🏃 Mai edzés</strong>
        <a href="<?= e(base_url('exercise_diary.php')) ?>" class="btn btn-outline-success btn-sm">+ Edzés</a>
      </div>
      <div class="card-body">
        <?php if ($exDay): ?>
          <?php foreach ($exDay as $ex): ?>
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span><?= e($ex['type_name'] ?? $ex['custom_exercise_name'] ?? 'Mozgás') ?></span>
              <span class="text-muted small"><?= $ex['duration_min'] ?> perc · <?= $ex['calories_burned'] ?> kcal</span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="text-muted small mb-0">Ma még nincs rögzített mozgás.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Heti trend -->
    <div class="card">
      <div class="card-header"><strong>📈 Heti kalória trend</strong></div>
      <div class="card-body">
        <canvas id="weekChart" height="120"></canvas>
      </div>
    </div>
  </div>
</div>

<?php
$trendLabels   = json_encode(array_map(fn($d) => date('m.d', strtotime($d['date'])), $trend), JSON_UNESCAPED_UNICODE);
$trendCalories = json_encode(array_column($trend, 'calories'));
$goalLine      = json_encode(array_fill(0, count($trend), $summary['calorie_goal']));

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('weekChart'), {
  type: 'bar',
  data: {
    labels: {$trendLabels},
    datasets: [
      { label: 'Kalória', data: {$trendCalories}, backgroundColor: 'rgba(34,197,94,.6)', borderRadius: 4 },
      { label: 'Cél', data: {$goalLine}, type: 'line', borderColor: '#ef4444', borderDash: [5,3], pointRadius: 0, fill: false }
    ]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>
JS;
?>

<?php require_once __DIR__ . '/_footer.php'; ?>
