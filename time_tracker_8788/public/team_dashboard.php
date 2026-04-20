<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
if (!tracker_is_admin($config) && !tracker_is_group_leader($config)) { http_response_code(403); exit('Nincs jogosultság.'); }
$allGroups = tracker_is_admin($config) ? tracker_groups_all($config, true) : array_values(array_filter(tracker_groups_all($config, true), fn($g) => in_array((int)$g['id'], tracker_group_leader_group_ids($config), true)));
$groupId = !empty($_GET['group_id']) ? (int)$_GET['group_id'] : (int)($allGroups[0]['id'] ?? 0);
if ($groupId && !tracker_is_admin($config) && !in_array($groupId, tracker_group_leader_group_ids($config), true)) { http_response_code(403); exit('Nincs jogosultság ehhez a csoporthoz.'); }
[$monthStart, $monthEnd, $monthDate] = tracker_month_bounds($_GET['month'] ?? null);
$rows = $groupId ? tracker_team_dashboard_rows($config, $groupId, $monthStart, $monthEnd) : [];
$expectedWorkdays = tracker_expected_workdays_between($config, $monthStart, $monthEnd);
$expectedMonthMinutesPerEmployee = tracker_expected_minutes_between($config, $monthStart, $monthEnd);
$title = 'Munkaidő / Csapatom';
require __DIR__ . '/../app/views/layout/header.php';
$totalMinutes = array_sum(array_column($rows, 'total_minutes'));
$totalMissing = array_sum(array_column($rows, 'missing_days'));
$totalAbs = array_sum(array_column($rows, 'absence_days'));
$expectedTeamMinutes = $expectedMonthMinutesPerEmployee * count($rows);
$teamProgressPercent = tracker_progress_percent($totalMinutes, $expectedTeamMinutes);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Csapatom</h1>
    <div class="text-muted">Csoportvezetői áttekintés a havi rögzítési állapotról.</div>
  </div>
</div>
<div class="card shadow-sm mb-4"><div class="card-body"><form method="get" class="row g-3 align-items-end">
<div class="col-md-4"><label class="form-label">Csoport</label><select name="group_id" class="form-select"><?php foreach ($allGroups as $g): ?><option value="<?= (int)$g['id'] ?>"<?= $groupId === (int)$g['id'] ? ' selected' : '' ?>><?= h((string)$g['name']) ?></option><?php endforeach; ?></select></div>
<div class="col-md-4"><label class="form-label">Hónap</label><input type="month" name="month" value="<?= h($monthDate->format('Y-m')) ?>" class="form-control"></div>
<div class="col-md-2"><button class="btn btn-primary w-100">Lekérés</button></div>
</form></div></div>
<div class="month-summary-grid mb-4">
  <div class="month-summary-card"><div class="month-summary-label">Tagok</div><div class="month-summary-value"><?= count($rows) ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Hiányzó napok</div><div class="month-summary-value"><?= (int)$totalMissing ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Távolléti napok</div><div class="month-summary-value"><?= (int)$totalAbs ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Havi összes munkaidő</div><div class="month-summary-value"><?= h(tracker_minutes_to_hhmm((int)$totalMinutes)) ?></div><div class="small text-muted mt-1">Elvárt: <?= h(tracker_minutes_to_hhmm((int)$expectedTeamMinutes)) ?><?php if ($teamProgressPercent !== null): ?> (<?= h(number_format($teamProgressPercent, 1, ',', '')) ?>%)<?php endif; ?></div><div class="small text-muted">Munkanapok száma: <?= (int)$expectedWorkdays ?></div></div>
</div>
<div class="card shadow-sm"><div class="card-header"><div class="fw-semibold">Csapattagok állapota</div></div><div class="card-body"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Dolgozó</th><th>Rögzített napok</th><th>Távollétek</th><th>Hiányzó napok</th><th>Lezárt napok</th><th>Munkaidő</th><th>Állapot</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="text-muted">Ehhez a csoporthoz nincs még hozzárendelt dolgozó.</td></tr><?php else: foreach ($rows as $row): ?>
<tr>
<td><?= h($row['employee_name']) ?></td>
<td><?= (int)$row['recorded_days'] ?></td>
<td><?= (int)$row['absence_days'] ?></td>
<td><?= (int)$row['missing_days'] ?></td>
<td><?= (int)$row['locked_days'] ?></td>
<td><?= h(tracker_minutes_to_hhmm((int)$row['total_minutes'])) ?></td>
<td><?php $statusClass = ($row['status']==='Rendben' ? 'text-bg-success' : ($row['status']==='Hiányos' ? 'text-bg-warning' : 'text-bg-secondary')); ?><span class="badge <?= $statusClass ?>"><?= h($row['status']) ?></span></td>
<td><a class="btn btn-sm btn-outline-secondary" href="/report.php?date_from=<?= h($monthStart) ?>&date_to=<?= h($monthEnd) ?>&employee_id=<?= (int)$row['employee_id'] ?>">Riport</a></td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>