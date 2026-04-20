<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);
$employeeOptions = tracker_employee_options($config);
if (tracker_is_group_leader($config) && !tracker_is_admin($config)) {
    $allowed = tracker_group_member_employee_ids($config);
    $employeeOptions = array_values(array_filter($employeeOptions, static fn($opt) => in_array((int)$opt['employee_id'], $allowed, true)));
}
$employeeMap = tracker_employee_name_map($config);
$shouldRun = (string)($_REQUEST['run'] ?? '') === '1';
[$monthStart, $monthEnd] = array_slice(tracker_month_bounds($_GET['month'] ?? null), 0, 2);
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_REQUEST['date_from'] ?? '')) ? (string)$_REQUEST['date_from'] : $monthStart;
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_REQUEST['date_to'] ?? '')) ? (string)$_REQUEST['date_to'] : $monthEnd;
$employeeId = tracker_is_admin($config) ? (int)($_REQUEST['employee_id'] ?? 0) : (int)$user['hr_employee_id'];
if (tracker_is_group_leader($config) && !tracker_is_admin($config) && !empty($_REQUEST['employee_id'])) {
    $candidate = (int)$_REQUEST['employee_id'];
    if (tracker_can_view_employee($config, $user, $candidate)) {
        $employeeId = $candidate;
    }
}
$rows = [];
$groups = [];
$overall = [
    'employee_count' => 0,
    'entry_count' => 0,
    'total_minutes' => 0,
    'absence_days' => 0,
];

if ($shouldRun) {
$rows = tracker_report_rows($config, $employeeId ?: null, $dateFrom, $dateTo);
$groups = tracker_report_grouped($rows, $employeeMap);
foreach ($groups as &$group) {
    $group['weekly_expected'] = [];
    $group['weekly_percent'] = [];
    foreach (($group['weekly'] ?? []) as $weekKey => $minutes) {
        $bounds = tracker_week_key_bounds((string)$weekKey);
        if ($bounds) {
            $overlap = tracker_date_range_overlap($dateFrom, $dateTo, $bounds[0], $bounds[1]);
            $expected = $overlap ? tracker_expected_minutes_between($config, $overlap[0], $overlap[1]) : 0;
        } else {
            $expected = 0;
        }
        $group['weekly_expected'][$weekKey] = $expected;
        $group['weekly_percent'][$weekKey] = tracker_progress_percent((int)$minutes, (int)$expected);
    }
    $group['monthly_expected'] = [];
    $group['monthly_percent'] = [];
    foreach (($group['monthly'] ?? []) as $monthKey => $minutes) {
        $bounds = tracker_month_key_bounds((string)$monthKey);
        if ($bounds) {
            $overlap = tracker_date_range_overlap($dateFrom, $dateTo, $bounds[0], $bounds[1]);
            $expected = $overlap ? tracker_expected_minutes_between($config, $overlap[0], $overlap[1]) : 0;
        } else {
            $expected = 0;
        }
        $group['monthly_expected'][$monthKey] = $expected;
        $group['monthly_percent'][$monthKey] = tracker_progress_percent((int)$minutes, (int)$expected);
    }
}
unset($group);
$overall = tracker_report_overall_summary($groups);
}

if ($shouldRun && (($_GET['export'] ?? '') === 'csv')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time_tracker_report.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "ï»¿");
    fputcsv($out, ['Dolgozó', 'Dátum / Összesítő', 'Jelleg', 'Részlet', 'Utazás', 'Munka', 'Összesen', 'Megjegyzés'], ';');

    foreach ($groups as $group) {
        fputcsv($out, [$group['employee_name'], '', '', '', '', ''], ';');

        $days = $group['days'];
        $dayCount = count($days);
        foreach ($days as $idx => $day) {
            $rowKind = (string)($day['row_kind'] ?? 'work');
            $isAbsence = ($rowKind === 'absence');
            $isMissing = ($rowKind === 'missing');
            fputcsv($out, [
                $group['employee_name'],
                $day['entry_date'],
                $isAbsence ? ((string)($day['type_label'] ?: 'Távollét')) : ($isMissing ? 'Nincs rögzítve' : 'Munkaidő'),
                tracker_report_day_detail($day),
                ($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['travel_minutes'] ?? 0)),
                ($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['work_only_minutes'] ?? max(0, (int)$day['total_minutes'] - (int)($day['travel_minutes'] ?? 0)))),
                ($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)$day['total_minutes']),
                (string)($day['note'] ?? ''),
            ], ';');

            $currentDate = (string)$day['entry_date'];
            $currentWeekKey = (new DateTimeImmutable($currentDate))->format('o-\WW');
            $currentMonthKey = substr($currentDate, 0, 7);
            $nextDate = $idx + 1 < $dayCount ? (string)$days[$idx + 1]['entry_date'] : null;
            $nextWeekKey = $nextDate ? (new DateTimeImmutable($nextDate))->format('o-\WW') : null;
            $nextMonthKey = $nextDate ? substr($nextDate, 0, 7) : null;

            if ($nextDate === null || $nextWeekKey !== $currentWeekKey) {
                fputcsv($out, [
                    $group['employee_name'],
                    tracker_hu_week_label_from_key($currentWeekKey) . ' összesen',
                    '',
                    '',
                    tracker_minutes_to_hours_compact((int)($group['weekly'][$currentWeekKey] ?? 0)) . ' / ' . tracker_minutes_to_hours_compact((int)($group['weekly_expected'][$currentWeekKey] ?? 0)) . ' (' . tracker_percent_label($group['weekly_percent'][$currentWeekKey] ?? null) . ')',
                    '',
                ], ';');
            }

            if ($nextDate === null || $nextMonthKey !== $currentMonthKey) {
                fputcsv($out, [
                    $group['employee_name'],
                    tracker_hu_month_label_from_key($currentMonthKey) . ' összesen',
                    '',
                    '',
                    tracker_minutes_to_hours_compact((int)($group['monthly'][$currentMonthKey] ?? 0)) . ' / ' . tracker_minutes_to_hours_compact((int)($group['monthly_expected'][$currentMonthKey] ?? 0)) . ' (' . tracker_percent_label($group['monthly_percent'][$currentMonthKey] ?? null) . ')',
                    '',
                ], ';');
            }
        }

        fputcsv($out, [$group['employee_name'], 'Időszak összesen', '', '', tracker_minutes_to_hhmm((int)$group['total_minutes']), ''], ';');
        fputcsv($out, [$group['employee_name'], 'Távollét összesen', '', '', '', (int)($group['absence_days'] ?? 0) . ' nap'], ';');
        foreach (($group['absence_by_type'] ?? []) as $absenceLabel => $absenceCount) {
            fputcsv($out, [$group['employee_name'], ' - ' . (string)$absenceLabel, '', '', '', (int)$absenceCount . ' nap'], ';');
        }
        fputcsv($out, ['', '', '', '', '', ''], ';');
    }
    fclose($out);
    exit;
}

if ($shouldRun && (($_GET['export'] ?? '') === 'pdf')) {
    $pdf = tracker_report_pdf_bytes($groups, $overall, $dateFrom, $dateTo);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="time_tracker_report.pdf"');
    echo $pdf;
    exit;
}

if ($shouldRun && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_report') {
    try {
        $pdf = tracker_report_pdf_bytes($groups, $overall, $dateFrom, $dateTo);
        $to = trim((string)($_POST['email_to'] ?? ''));
        $subject = 'Munkaidő riport (' . $dateFrom . ' - ' . $dateTo . ')';
        $body = "Tisztelt Kolléga!

Csatolva küldjük a kért munkaidő riportot PDF formátumban.

Időszak: {$dateFrom} - {$dateTo}

Üdvözlettel:
Perfect-Phone";
        if (!tracker_send_email_with_attachment($to, $subject, $body, 'time_tracker_report.pdf', $pdf)) {
            throw new RuntimeException('Az e-mail küldése sikertelen volt. Ellenőrizd a szerver mail beállításait.');
        }
        tracker_flash_set('success', 'A riport e-mailben elküldve: ' . $to);
    } catch (Throwable $e) {
        tracker_flash_set('error', $e->getMessage());
    }
    tracker_redirect('/report.php?run=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&employee_id=' . $employeeId);
}

$title = 'Munkaidő / Riport';
require __DIR__ . '/../app/views/layout/header.php';
$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Riportok</h1>
    <div class="text-muted">Napi bontás dolgozónként, külön heti és havi összesítő táblákkal.</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <?php if ($shouldRun): ?><a class="btn btn-outline-secondary" href="?run=1&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&employee_id=<?= (int)$employeeId ?>&export=csv">CSV export</a><?php endif; ?>
    <?php if ($shouldRun): ?><a class="btn btn-outline-secondary" href="?run=1&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>&employee_id=<?= (int)$employeeId ?>&export=pdf">PDF mentés</a><?php endif; ?>
  </div>
</div>
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <input type="hidden" name="run" value="1">
      <div class="col-md-3"><label class="form-label">-tól</label><input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">-ig</label><input type="date" name="date_to" value="<?= h($dateTo) ?>" class="form-control"></div>
      <?php if (tracker_is_admin($config) || tracker_is_group_leader($config)): ?>
      <div class="col-md-4"><label class="form-label">Dolgozó</label><select name="employee_id" class="form-select"><?php if (tracker_is_admin($config)): ?><option value="0">Összes dolgozó</option><?php endif; ?><?php foreach ($employeeOptions as $opt): ?><option value="<?= (int)$opt['employee_id'] ?>"<?= $employeeId === (int)$opt['employee_id'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="col-md-2"><button class="btn btn-primary w-100">Lekérés</button></div>
    </form>
  </div>
</div>
<?php if (!$shouldRun): ?>
<div class="alert alert-info mb-4">Állítsd be a szűrőket, majd kattints a <strong>Lekérés</strong> gombra. A riport automatikusan betöltődik akkor is, ha a <strong>Csapatom</strong> oldalról érkezel.</div>
<?php endif; ?>
<?php if ($shouldRun): ?>
<div class="month-summary-grid mb-4">
  <div class="month-summary-card"><div class="month-summary-label">Dolgozók</div><div class="month-summary-value"><?= (int)$overall['employee_count'] ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Bejegyzések</div><div class="month-summary-value"><?= (int)$overall['entry_count'] ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Időszak összesen</div><div class="month-summary-value"><?= h(tracker_minutes_to_hhmm((int)$overall['total_minutes'])) ?></div></div>
  <div class="month-summary-card"><div class="month-summary-label">Távolléti napok</div><div class="month-summary-value"><?= (int)$overall['absence_days'] ?></div></div>
</div>
<div class="card shadow-sm mb-4">
  <div class="card-header"><div class="fw-semibold">Dolgozónkénti összesítés</div></div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>Dolgozó</th><th>Ledolgozott napok</th><th>Távolléti napok</th><th>Bejegyzések</th><th>Hetek száma</th><th>Heti átlag</th><th>Havi átlag</th><th>Időszak összesen</th></tr></thead>
        <tbody>
        <?php if (!$groups): ?>
          <tr><td colspan="8" class="text-muted">Nincs találat a megadott feltételekre.</td></tr>
        <?php else: foreach ($groups as $group): ?>
          <tr>
            <td><?= h($group['employee_name']) ?></td>
            <td><?= (int)$group['worked_days'] ?></td>
            <td><?= (int)($group['absence_days'] ?? 0) ?></td>
            <td><?= (int)$group['entry_count'] ?></td>
            <td><?= count($group['weekly']) ?></td>
            <td><?= h(tracker_minutes_to_hhmm((int)$group['weekly_average_minutes'])) ?></td>
            <td><?= h(tracker_minutes_to_hhmm((int)$group['monthly_average_minutes'])) ?></td>
            <td class="fw-semibold"><?= h(tracker_minutes_to_hhmm((int)$group['total_minutes'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php if ($groups): foreach ($groups as $group): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
      <div class="fw-semibold"><?= h($group['employee_name']) ?></div>
      <div class="small text-muted">Heti átlag: <?= h(tracker_minutes_to_hhmm((int)$group['weekly_average_minutes'])) ?> · Havi átlag: <?= h(tracker_minutes_to_hhmm((int)$group['monthly_average_minutes'])) ?> · Időszak összesen: <?= h(tracker_minutes_to_hhmm((int)$group['total_minutes'])) ?> · Utazás: <?= h(tracker_minutes_to_hhmm((int)($group['travel_minutes'] ?? 0))) ?> · Munka: <?= h(tracker_minutes_to_hhmm((int)($group['work_only_minutes'] ?? 0))) ?> · Távollét: <?= (int)($group['absence_days'] ?? 0) ?> nap</div>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-lg-6">
        <h2 class="h6">Napi bontás</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Dátum</th><th>Jelleg</th><th>Részlet</th><th>Utazás</th><th>Munka</th><th>Összesen</th><th>Megjegyzés</th></tr></thead>
            <tbody>
            <?php $weekMarkers = tracker_report_week_markers($group['days']); ?>
            <?php foreach ($group['days'] as $day): ?>
              <?php if (!empty($weekMarkers[$day['entry_date']])): ?>
              <tr class="table-light week-marker-row">
                <td colspan="5"><?= h($weekMarkers[$day['entry_date']]) ?></td>
              </tr>
              <?php endif; ?>
              <tr>
                <td><?= h($day['entry_date']) ?></td>
                <?php $rowKind = (string)($day['row_kind'] ?? 'work'); $isAbsence = $rowKind === 'absence'; $isMissing = $rowKind === 'missing'; ?>
                <td><?= h($isAbsence ? ((string)($day['type_label'] ?: 'Távollét')) : ($isMissing ? 'Nincs rögzítve' : 'Munkaidő')) ?></td>
                <td><?= h(tracker_report_day_detail($day)) ?></td>
                <td><?= h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['travel_minutes'] ?? 0))) ?></td>
                <td><?= h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['work_only_minutes'] ?? max(0, (int)$day['total_minutes'] - (int)($day['travel_minutes'] ?? 0))))) ?></td>
                <td><?= h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)$day['total_minutes'])) ?></td>
                <td><?= h((string)($day['note'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-lg-6">
        <h2 class="h6">Heti összesítés</h2>
        <div class="table-responsive mb-4">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Hét</th><th>Utazás</th><th>Munka</th><th>Ledolgozott</th><th>Elvárt</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($group['weekly'] as $weekKey => $minutes): ?>
              <tr>
                <td><?= h(tracker_hu_week_label_from_key((string)$weekKey)) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['weekly_travel'][$weekKey] ?? 0))) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['weekly_work_only'][$weekKey] ?? max(0, $minutes - (int)($group['weekly_travel'][$weekKey] ?? 0))))) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)$minutes)) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['weekly_expected'][$weekKey] ?? 0))) ?></td>
                <td><?= h(tracker_percent_label($group['weekly_percent'][$weekKey] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <h2 class="h6">Havi összesítés</h2>
        <div class="table-responsive mb-4">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Hónap</th><th>Utazás</th><th>Munka</th><th>Ledolgozott</th><th>Elvárt</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($group['monthly'] as $monthKey => $minutes): ?>
              <tr>
                <td><?= h(tracker_hu_month_label_from_key((string)$monthKey)) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['monthly_travel'][$monthKey] ?? 0))) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['monthly_work_only'][$monthKey] ?? max(0, $minutes - (int)($group['monthly_travel'][$monthKey] ?? 0))))) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)$minutes)) ?></td>
                <td><?= h(tracker_minutes_to_hours_compact((int)($group['monthly_expected'][$monthKey] ?? 0))) ?></td>
                <td><?= h(tracker_percent_label($group['monthly_percent'][$monthKey] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <h2 class="h6">Távollét összesítés</h2>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Típus</th><th>Napok száma</th></tr></thead>
            <tbody>
            <?php if (empty($group['absence_by_type'])): ?>
              <tr><td colspan="2" class="text-muted">Nincs távollét a kiválasztott időszakban.</td></tr>
            <?php else: foreach ($group['absence_by_type'] as $absenceLabel => $absenceCount): ?>
              <tr><td><?= h((string)$absenceLabel) ?></td><td><?= (int)$absenceCount ?> nap</td></tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<div class="card shadow-sm">
  <div class="card-header"><div class="fw-semibold">Riport küldése e-mailben</div></div>
  <div class="card-body">
    <form method="post" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="email_report">
      <input type="hidden" name="date_from" value="<?= h($dateFrom) ?>">
      <input type="hidden" name="date_to" value="<?= h($dateTo) ?>">
      <input type="hidden" name="employee_id" value="<?= (int)$employeeId ?>">
      <div class="col-md-9"><label class="form-label">E-mail cím</label><input type="email" name="email_to" class="form-control" placeholder="pl. vezeto@ceg.hu" required value="<?= h((string)($user['email'] ?? '')) ?>"></div>
      <div class="col-md-3"><button class="btn btn-primary w-100">PDF küldése</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
