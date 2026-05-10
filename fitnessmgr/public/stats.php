<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/ExerciseService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';
require_once __DIR__ . '/../app/Services/AIService.php';

$page  = 'stats';
$title = 'Statisztikák';

$u      = current_user() ?? [];
$userId = (int)($u['id'] ?? 0);

$foodSvc     = new FoodService();
$exerciseSvc = new ExerciseService();
$weightSvc   = new WeightService();
$dailySvc    = new DailyService();
$ai          = new AIService();

$days     = (int)($_GET['days'] ?? 30);
$days     = in_array($days, [7, 14, 30, 90]) ? $days : 30;
$trend    = $dailySvc->getWeekTrend($userId, $days);
$wHistory = $weightSvc->getHistory($userId, $days);
$profile  = $weightSvc->getProfile($userId);

// Napi AI értékelés
$aiEval = null;
if ($ai->isEnabled() && !empty($_GET['ai_eval'])) {
  $todayEntries = $foodSvc->getDayEntries($userId, today());
  if ($todayEntries) {
    $aiEval = $ai->evaluateDailyIntake($todayEntries, $profile ?? []);
  }
}

// Recept javaslat
$recipe = null;
if ($ai->isEnabled() && !empty($_GET['recipe'])) {
  $goal    = (int)($profile['daily_calorie_goal'] ?? 2000);
  $todCal  = $foodSvc->getDayTotals($userId, today())['calories'];
  $maxCal  = max(300, $goal - $todCal);
  $recipe  = $ai->suggestRecipe($maxCal);
}

// Összesítők
$totalCal   = array_sum(array_column($trend, 'calories'));
$totalMin   = array_sum(array_column($trend, 'exercise_min'));
$activeDays = count(array_filter($trend, fn($d) => $d['calories'] > 0));
$avgCal     = $activeDays > 0 ? (int)round($totalCal / $activeDays) : 0;

require_once __DIR__ . '/_header.php';
?>

<!-- Időszak választó -->
<div class="d-flex gap-2 mb-4">
  <?php foreach ([7=>'7 nap',14=>'2 hét',30=>'1 hónap',90=>'3 hónap'] as $d=>$label): ?>
    <a href="?days=<?= $d ?>" class="btn btn-<?= $days===$d ? 'success' : 'outline-secondary' ?> btn-sm"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<!-- Összesítő kártyák -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card stat-card text-center p-3">
      <div class="label">Aktív napok</div>
      <div class="display-6"><?= $activeDays ?>/<?= $days ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card text-center p-3">
      <div class="label">Átlag kalória/nap</div>
      <div class="display-6"><?= number_format($avgCal) ?></div>
      <div class="text-muted small">cél: <?= number_format((int)($profile['daily_calorie_goal'] ?? 2000)) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card text-center p-3">
      <div class="label">Összes mozgás</div>
      <div class="display-6"><?= $totalMin ?></div>
      <div class="text-muted small">perc <?= $days ?> nap alatt</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card text-center p-3">
      <div class="label">Összes kalória</div>
      <div class="display-6"><?= number_format($totalCal) ?></div>
      <div class="text-muted small"><?= $days ?> nap</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Kalória trend -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><strong>📊 Kalória trend</strong></div>
      <div class="card-body">
        <canvas id="calChart" height="120"></canvas>
      </div>
    </div>
  </div>

  <!-- Súly trend -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><strong>⚖️ Súly trend</strong></div>
      <div class="card-body">
        <?php if (count($wHistory) >= 2): ?>
          <canvas id="weightMini" height="160"></canvas>
        <?php else: ?>
          <p class="text-muted text-center py-4 small">Legalább 2 mérés kell a trendhez.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- AI elemzések -->
<?php if ($ai->isEnabled()): ?>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card ai-card">
      <div class="card-body">
        <div class="card-title">🤖 Mai étkezés értékelése</div>
        <?php if ($aiEval): ?>
          <p class="small"><?= nl2br(e($aiEval)) ?></p>
        <?php else: ?>
          <p class="small opacity-75">AI értékeli a mai nap étkezéseit és javaslatot ad.</p>
          <a href="?days=<?= $days ?>&ai_eval=1" class="btn btn-light btn-sm">Értékelés kérése</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card ai-card">
      <div class="card-body">
        <div class="card-title">👨‍🍳 Recept javaslat</div>
        <?php if ($recipe): ?>
          <p class="small"><?= nl2br(e($recipe)) ?></p>
        <?php else: ?>
          <p class="small opacity-75">AI javasol egy egészséges ételt a mai maradék kalória keretedhez.</p>
          <a href="?days=<?= $days ?>&recipe=1" class="btn btn-light btn-sm">Recept javaslat</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
$labels  = json_encode(array_map(fn($d) => date('m.d', strtotime($d['date'])), $trend), JSON_UNESCAPED_UNICODE);
$calData = json_encode(array_column($trend, 'calories'));
$exData  = json_encode(array_column($trend, 'exercise_min'));
$goalVal = json_encode(array_fill(0, count($trend), (int)($profile['daily_calorie_goal'] ?? 2000)));

$wLabels  = json_encode(array_column($wHistory, 'measured_at'));
$wWeights = json_encode(array_map(fn($r) => (float)$r['weight_kg'], $wHistory));

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('calChart'), {
  data: {
    labels: {$labels},
    datasets: [
      { type:'bar', label:'Kalória', data:{$calData}, backgroundColor:'rgba(34,197,94,.55)', borderRadius:3 },
      { type:'line', label:'Cél', data:{$goalVal}, borderColor:'#ef4444', borderDash:[5,3], pointRadius:0, fill:false },
      { type:'line', label:'Mozgás (perc)', data:{$exData}, borderColor:'#3b82f6', yAxisID:'y1', pointRadius:2 }
    ]
  },
  options: {
    plugins:{ legend:{ position:'top' } },
    scales:{
      y:{ beginAtZero:true, title:{ display:true, text:'kcal' } },
      y1:{ position:'right', beginAtZero:true, title:{ display:true, text:'perc' }, grid:{ drawOnChartArea:false } }
    }
  }
});

if (document.getElementById('weightMini')) {
  new Chart(document.getElementById('weightMini'), {
    type:'line',
    data:{
      labels:{$wLabels},
      datasets:[{ label:'Súly (kg)', data:{$wWeights}, borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,.1)', fill:true, tension:.3 }]
    },
    options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ suggestedMin: Math.min(...{$wWeights})-2 } } }
  });
}
</script>
JS;
?>
<?php require_once __DIR__ . '/_footer.php'; ?>
