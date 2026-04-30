<?php
require_once __DIR__ . '/../app/auth.php';
require_login();

$page  = 'my_tasks';
$myEid = my_employee_id();
$today = date('Y-m-d');
$from  = $_GET['from'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $today;

$days    = work_days($from, 5);
$tasks   = get_tasks_for_days($days);
$taskIdx = index_tasks($tasks);

$prevDate = (new DateTime($days[0]))->modify('-7 days')->format('Y-m-d');
$nextDate = (new DateTime(end($days)))->modify('+1 day')->format('Y-m-d');
$hunDays   = ['','Hétfő','Kedd','Szerda','Csütörtök','Péntek','Szombat','Vasárnap'];
$hunMonths = ['','január','február','március','április','május','június','július','augusztus','szeptember','október','november','december'];

require __DIR__ . '/_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h5 mb-0">Saját feladataim</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($prevDate) ?>">← Előző</a>
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($today) ?>">Ma</a>
    <a class="btn btn-sm btn-outline-secondary" href="?from=<?= e($nextDate) ?>">Következő →</a>
  </div>
</div>

<?php if (!$myEid): ?>
  <div class="alert alert-warning">A fiókodhoz nincs HR-dolgozó rendelve. Kérj segítséget az adminisztrátortól.</div>
<?php else: ?>
  <?php
  $hasAny = false;
  foreach ($days as $d) {
    if (!empty($taskIdx[$d][$myEid])) { $hasAny = true; break; }
  }
  if (!$hasAny): ?>
    <div class="alert alert-info">Erre az időszakra nincs tervezett feladatod.</div>
  <?php endif; ?>

  <div class="row g-3">
    <?php foreach ($days as $d):
      $dow = (int)(new DateTime($d))->format('N');
      $dm  = explode('-', $d);
      $cellTasks = $taskIdx[$d][$myEid] ?? [];
      $isToday   = ($d === $today);
    ?>
    <div class="col-sm-6 col-lg-4 col-xl-2-4">
      <div class="card h-100 <?= $isToday ? 'border-primary' : '' ?>">
        <div class="card-header py-2 <?= $isToday ? 'bg-primary text-white' : 'bg-light' ?>">
          <strong><?= e($hunDays[$dow]) ?></strong>
          <span class="<?= $isToday ? 'opacity-75' : 'text-muted' ?> small ms-1">
            <?= (int)$dm[2] ?>. <?= $hunMonths[(int)$dm[1]] ?>
          </span>
          <?php if ($isToday): ?><span class="badge bg-warning text-dark ms-1">ma</span><?php endif; ?>
        </div>
        <div class="card-body p-2">
          <?php if (!$cellTasks): ?>
            <p class="text-muted small mb-0">—</p>
          <?php else: ?>
            <?php foreach ($cellTasks as $t): ?>
              <?= task_card($t, false) ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
