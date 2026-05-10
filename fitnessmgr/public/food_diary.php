<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';

$page  = 'food';
$title = 'Étkezési napló';

$u      = current_user() ?? [];
$userId = (int)($u['id'] ?? 0);
$svc    = new FoodService();
$daily  = new DailyService();

// Törlés
if (!empty($_GET['delete'])) {
  $svc->deleteEntry((int)$_GET['delete'], $userId);
  flash_set('ok', 'Bejegyzés törölve.');
  $back = $_GET['date'] ?? today();
  redirect('food_diary.php?date=' . $back);
}

$date    = $_GET['date'] ?? today();
$summary = $daily->getDaySummary($userId, $date);
$meals   = $svc->getDayByMeal($userId, $date);

// Navigáció
$prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday  = $date === today();

require_once __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">🍽️ Étkezési napló</h5>
  <a href="<?= e(base_url('food_add.php?date=' . $date)) ?>" class="btn btn-success btn-sm">+ Étkezés</a>
</div>

<!-- Dátum navigátor -->
<div class="d-flex align-items-center gap-2 mb-4">
  <a href="?date=<?= $prevDate ?>" class="btn btn-outline-secondary btn-sm">◀</a>
  <input type="date" class="form-control form-control-sm" style="max-width:160px"
         value="<?= e($date) ?>" onchange="window.location='?date='+this.value">
  <?php if (!$isToday): ?>
    <a href="?date=<?= today() ?>" class="btn btn-outline-secondary btn-sm">Ma</a>
  <?php endif; ?>
  <?php if ($nextDate <= today()): ?>
    <a href="?date=<?= $nextDate ?>" class="btn btn-outline-secondary btn-sm">▶</a>
  <?php endif; ?>
  <span class="ms-auto text-muted small">
    <?= number_format($summary['calories_in']) ?> / <?= number_format($summary['calorie_goal']) ?> kcal
    (<?= $summary['calorie_pct'] ?>%)
  </span>
</div>

<!-- Kalória haladás -->
<div class="mb-4">
  <div class="progress" style="height:14px">
    <div class="progress-bar bg-<?= progress_color((float)$summary['calorie_pct']) ?>"
         style="width:<?= min(100,$summary['calorie_pct']) ?>%"></div>
  </div>
  <div class="d-flex justify-content-between small text-muted mt-1">
    <span>Fehérje: <?= number_format($summary['protein_g'],0) ?>g</span>
    <span>Szénhidrát: <?= number_format($summary['carbs_g'],0) ?>g</span>
    <span>Zsír: <?= number_format($summary['fat_g'],0) ?>g</span>
  </div>
</div>

<?php foreach (['reggeli','tizorai','ebed','uzsonna','vacsora'] as $mealKey):
  $m = $meals[$mealKey] ?? ['entries'=>[],'calories'=>0];
?>
  <div class="meal-section <?= e($mealKey) ?> mb-4">
    <div class="d-flex justify-content-between meal-header mb-2">
      <span><?= meal_icon($mealKey) ?> <?= meal_label($mealKey) ?></span>
      <span class="d-flex align-items-center gap-2">
        <?php if ($m['calories'] > 0): ?>
          <span class="kcal-badge"><?= $m['calories'] ?> kcal</span>
        <?php endif; ?>
        <a href="<?= e(base_url('food_add.php?meal=' . $mealKey . '&date=' . $date)) ?>"
           class="btn btn-outline-success btn-sm py-0 px-2">+</a>
      </span>
    </div>

    <?php if ($m['entries']): ?>
      <table class="table table-sm table-hover mb-0">
        <tbody>
          <?php foreach ($m['entries'] as $e): ?>
            <tr>
              <td><?= e($e['food_name'] ?? $e['custom_food_name'] ?? 'Ismeretlen') ?></td>
              <td class="text-muted small"><?= $e['amount_g'] ?>g</td>
              <td class="text-end"><span class="kcal-badge"><?= $e['calories'] ?> kcal</span></td>
              <td class="text-end small text-muted">F:<?= number_format((float)$e['protein_g'],0) ?> Sz:<?= number_format((float)$e['carbs_g'],0) ?></td>
              <td class="text-end">
                <a href="?delete=<?= $e['id'] ?>&date=<?= e($date) ?>"
                   class="btn btn-outline-danger btn-sm py-0 px-1"
                   onclick="return confirm('Törlöd?')">✕</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="text-muted small ps-1 mb-0">Nincs bejegyzés.
        <a href="<?= e(base_url('food_add.php?meal=' . $mealKey . '&date=' . $date)) ?>">+ Hozzáadás</a>
      </p>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/_footer.php'; ?>
