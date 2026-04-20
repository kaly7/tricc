<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
tracker_require_employee($user);

$selectedEmployeeId = tracker_is_admin($config) && !empty($_GET['employee_id']) ? (int)$_GET['employee_id'] : (int)$user['hr_employee_id'];
$today = new DateTimeImmutable('today');
$dateParam = $_GET['date'] ?? null;
$baseDate = ($dateParam && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dateParam)) ? new DateTimeImmutable((string)$dateParam) : $today;
$monthParam = $_GET['month'] ?? $baseDate->format('Y-m');
[$monthStart, $monthEnd, $monthDate] = tracker_month_bounds($monthParam);
$entries = tracker_entries_between($config, $selectedEmployeeId, $monthStart, $monthEnd);
$types = tracker_entry_types($config);
$absenceTypes = tracker_absence_types($config);
$workTemplates = tracker_templates($config, 'work');
$absenceTemplates = tracker_templates($config, 'absence');
$vehicles = tracker_vehicle_options($config);
$employeeOptions = tracker_employee_options($config);
$employeeMap = tracker_employee_name_map($config);
$locks = tracker_active_locks($config);
$dayAbsences = tracker_day_absences_between($config, $selectedEmployeeId, $monthStart, $monthEnd);
$holidays = tracker_holidays_between($config, $monthStart, $monthEnd);

$entriesByDate = [];
$dayTotals = [];
$dayTravelTotals = [];
$dayWorkOnlyTotals = [];
foreach ($entries as $entry) {
    foreach (tracker_entry_day_segments($entry, $monthStart, $monthEnd) as $segment) {
        $date = (string)$segment['segment_date'];
        $entriesByDate[$date][] = $segment;
        $dayTotals[$date] = (int)($dayTotals[$date] ?? 0) + tracker_entry_segment_total_minutes($segment);
        $dayTravelTotals[$date] = (int)($dayTravelTotals[$date] ?? 0) + tracker_entry_segment_travel_minutes($segment);
        $dayWorkOnlyTotals[$date] = (int)($dayWorkOnlyTotals[$date] ?? 0) + tracker_entry_segment_work_minutes($segment);
    }
}
$dayAbsenceMap = [];
foreach ($dayAbsences as $absence) {
    $dayAbsenceMap[(string)$absence['absence_date']] = $absence;
}
$holidayMap = [];
foreach ($holidays as $holiday) {
    $holidayMap[(string)$holiday['holiday_date']] = $holiday;
}

$selectedDate = $dateParam ?: $baseDate->format('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$selectedDate)) {
    $selectedDate = $baseDate->format('Y-m-d');
}
$selectedDayEntries = $entriesByDate[$selectedDate] ?? [];
$selectedDayAbsence = $dayAbsenceMap[$selectedDate] ?? null;
$selectedHoliday = $holidayMap[$selectedDate] ?? null;
$editId = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editEntry = $editId ? tracker_entry_find($config, $editId) : null;
if ($editEntry && !tracker_is_admin($config) && (int)$editEntry['employee_id'] !== (int)$user['hr_employee_id']) {
    $editEntry = null;
}

$title = 'Munkaidő / Naptár';
require __DIR__ . '/../app/views/layout/header.php';

$success = tracker_flash_get('success');
$error = tracker_flash_get('error');
if ($success !== '') echo '<div class="alert alert-success">' . h($success) . '</div>';
if ($error !== '') echo '<div class="alert alert-danger">' . h($error) . '</div>';

$prevMonth = $monthDate->modify('-1 month')->format('Y-m');
$nextMonth = $monthDate->modify('+1 month')->format('Y-m');
$monthTitle = tracker_hu_month_title($monthDate);

$firstWeekday = (int)$monthDate->format('N');
$daysInMonth = (int)$monthDate->format('t');
$monthTotalMinutes = array_sum(array_map('intval', $dayTotals));
$monthEntryCount = count($entries);
$workedDays = count(array_filter($dayTotals, static fn($m) => (int)$m > 0));
$expectedWorkdays = tracker_expected_workdays_between($config, $monthStart, $monthEnd);
$expectedMonthMinutes = tracker_expected_minutes_between($config, $monthStart, $monthEnd);
$monthProgressPercent = tracker_progress_percent($monthTotalMinutes, $expectedMonthMinutes);
$chosenEmp = (int)($editEntry['employee_id'] ?? $selectedEmployeeId);
[$editStartHour, $editStartMinute] = tracker_split_time(substr((string)($editEntry['start_time'] ?? ''), 0, 5));
[$editEndHour, $editEndMinute] = tracker_split_time(substr((string)($editEntry['end_time'] ?? ''), 0, 5));
$timeMode = (string)($_GET['time_mode'] ?? 'end');
$durationHoursValue = 0;
$durationMinutesValue = 0;
$editVehicleId = (int)($editEntry['vehicle_id'] ?? 0);
$editTravelKm = isset($editEntry['travel_km']) && $editEntry['travel_km'] !== null ? (float)$editEntry['travel_km'] : null;
if ($editEntry && !empty($editEntry['start_time']) && !empty($editEntry['end_time'])) {
    $grossMinutes = tracker_compute_minutes((string)substr((string)$editEntry['start_time'],0,5), (string)substr((string)$editEntry['end_time'],0,5), 0);
    $netWithBreak = max(0, $grossMinutes - (int)($editEntry['break_minutes'] ?? 0));
    $durationHoursValue = intdiv($netWithBreak, 60);
    $durationMinutesValue = tracker_pick_closest_step($netWithBreak % 60);
}
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
  <div>
    <h1 class="h3 mb-1">Munkaidő naptár</h1>
    <div class="text-muted">Havi áttekintés és napi rögzítés egy felületen. A jobb oldali listából egy bejegyzés áthúzható egy másik napra.</div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="?month=<?= h($prevMonth) ?>&employee_id=<?= (int)$selectedEmployeeId ?>">&laquo; Előző</a>
    <a class="btn btn-outline-secondary" href="?month=<?= h((new DateTimeImmutable('first day of this month'))->format('Y-m')) ?>&employee_id=<?= (int)$selectedEmployeeId ?>">Aktuális hónap</a>
    <a class="btn btn-outline-secondary" href="?month=<?= h($nextMonth) ?>&employee_id=<?= (int)$selectedEmployeeId ?>">Következő &raquo;</a>
  </div>
</div>

<div class="month-summary-grid mb-4">
  <div class="month-summary-card">
    <div class="month-summary-label">Havi összes munkaidő</div>
    <div class="month-summary-value"><?= h(tracker_minutes_to_hhmm($monthTotalMinutes)) ?></div>
    <div class="small text-muted mt-1">
      Elvárt: <?= h(tracker_minutes_to_hhmm($expectedMonthMinutes)) ?>
      <?php if ($monthProgressPercent !== null): ?>
        (<?= h(number_format($monthProgressPercent, 1, ',', '')) ?>%)
      <?php endif; ?>
    </div>
    <div class="small text-muted">Munkanapok száma: <?= (int)$expectedWorkdays ?></div>
  </div>
  <div class="month-summary-card">
    <div class="month-summary-label">Bejegyzések száma</div>
    <div class="month-summary-value"><?= (int)$monthEntryCount ?></div>
  </div>
  <div class="month-summary-card">
    <div class="month-summary-label">Ledolgozott napok</div>
    <div class="month-summary-value"><?= (int)$workedDays ?></div>
  </div>
</div>

<div class="row g-4 align-items-start">
  <div class="col-xl-8">
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="fw-semibold"><?= h($monthTitle) ?></div>
        <?php if (tracker_is_admin($config)): ?>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
          <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
          <label class="form-label mb-0 small text-muted">Dolgozó</label>
          <select name="employee_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($employeeOptions as $opt): ?>
              <option value="<?= (int)$opt['employee_id'] ?>"<?= (int)$selectedEmployeeId === (int)$opt['employee_id'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="calendar-grid calendar-weekdays mb-2">
          <?php $weekdayFull = tracker_weekday_labels_hu_full(); $weekdayShort = tracker_weekday_labels_hu_short(); foreach ($weekdayFull as $idx => $wd): ?>
            <div class="calendar-weekday"><span class="weekday-desktop"><?= h($wd) ?></span><span class="weekday-mobile"><?= h($weekdayShort[$idx] ?? $wd) ?></span></div>
          <?php endforeach; ?>
        </div>
        <div class="calendar-grid">
          <?php for ($i = 1; $i < $firstWeekday; $i++): ?><div class="calendar-day empty"></div><?php endfor; ?>
          <?php for ($day = 1; $day <= $daysInMonth; $day++):
            $dt = $monthDate->setDate((int)$monthDate->format('Y'), (int)$monthDate->format('m'), $day);
            $date = $dt->format('Y-m-d');
            $total = (int)($dayTotals[$date] ?? 0);
            $locked = tracker_is_locked($config, $date);
            $count = count($entriesByDate[$date] ?? []);
            $absence = $dayAbsenceMap[$date] ?? null;
            $holiday = $holidayMap[$date] ?? null;
            $rule = tracker_day_color_rule_match($config, $total);
            $classes = ['calendar-day'];
            if ($date === $selectedDate) $classes[] = 'selected';
            if ($date === (new DateTimeImmutable('today'))->format('Y-m-d')) $classes[] = 'today';
            if ($locked) $classes[] = 'locked';
            if ((int)$dt->format('N') >= 6) $classes[] = 'weekend';
            if ($absence) $classes[] = 'absence-day';
            if ($holiday) $classes[] = 'holiday-day';
            $cellStyle = '';
            if ($absence) {
                $textColor = !empty($absence['text_color']) ? (string)$absence['text_color'] : tracker_contrast_text_color((string)$absence['bg_color']);
                $cellStyle = 'background:' . $absence['bg_color'] . ';color:' . $textColor . ';';
            } elseif ($holiday) {
                $textColor = !empty($holiday['text_color']) ? (string)$holiday['text_color'] : tracker_contrast_text_color((string)$holiday['bg_color']);
                $cellStyle = 'background:' . $holiday['bg_color'] . ';color:' . $textColor . ';';
            } elseif ($rule) {
                $textColor = !empty($rule['text_color']) ? (string)$rule['text_color'] : tracker_contrast_text_color((string)$rule['bg_color']);
                $cellStyle = 'background:' . $rule['bg_color'] . ';color:' . $textColor . ';';
            }
          ?>
          <a class="<?= h(implode(' ', $classes)) ?> calendar-dropzone" data-date="<?= h($date) ?>" style="<?= h($cellStyle) ?>" href="?month=<?= h($monthDate->format('Y-m')) ?>&employee_id=<?= (int)$selectedEmployeeId ?>&date=<?= h($date) ?>">
            <div class="day-top">
              <span class="day-number"><?= $day ?></span>
              <span class="day-top-right"><?php if ($date === (new DateTimeImmutable('today'))->format('Y-m-d')): ?><span class="calendar-badge today-badge">Ma</span><?php endif; ?><?php if ($locked): ?><span class="day-lock" title="Lezárt nap">🔒</span><?php endif; ?></span>
            </div>
            <?php if ($absence || $holiday): ?>
              <div class="day-badges mt-2">
                <?php if ($absence): ?><span class="calendar-badge absence-badge"><?= h((string)($absence['badge_text'] ?: $absence['type_label'])) ?></span><?php endif; ?>
                <?php if ($holiday): ?><span class="calendar-badge holiday-badge"><?= h((string)($holiday['badge_text'] ?: 'ÜN')) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($count > 0): ?>
              <div class="day-summary mt-2"><?= h(tracker_entry_count_label($count)) ?></div>
              <div class="fw-semibold mt-1"><?= h(tracker_minutes_human($total)) ?></div>
            <?php endif; ?>
          </a>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center gap-3">
        <div>
          <div class="fw-semibold">Kiválasztott nap</div>
          <div class="text-muted small"><?= h($selectedDate) ?></div>
        </div>
        <span class="badge rounded-pill text-bg-light border">Napi összesen: <?= h(tracker_minutes_to_hhmm((int)($dayTotals[$selectedDate] ?? 0))) ?> · Utazás: <?= h(tracker_minutes_to_hhmm((int)($dayTravelTotals[$selectedDate] ?? 0))) ?> · Munka: <?= h(tracker_minutes_to_hhmm((int)($dayWorkOnlyTotals[$selectedDate] ?? 0))) ?></span>
      </div>
      <div class="card-body">
        <?php if ($selectedHoliday): ?>
          <div class="alert alert-danger-subtle border d-flex justify-content-between align-items-center gap-3 mb-3">
            <div>
              <div class="fw-semibold"><?= h((string)$selectedHoliday['label']) ?></div>
              <div class="small text-muted">Ünnepnap jelölés ezen a napon.</div>
            </div>
            <span class="calendar-badge holiday-badge"><?= h((string)($selectedHoliday['badge_text'] ?: 'ÜN')) ?></span>
          </div>
        <?php endif; ?>
        <?php if ($selectedDayAbsence): ?>
          <div class="alert alert-success-subtle border d-flex justify-content-between align-items-center gap-3 mb-3">
            <div>
              <div class="fw-semibold"><?= h((string)$selectedDayAbsence['type_label']) ?></div>
              <?php if (!empty($selectedDayAbsence['note'])): ?><div class="small text-muted"><?= h((string)$selectedDayAbsence['note']) ?></div><?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="calendar-badge absence-badge"><?= h((string)($selectedDayAbsence['badge_text'] ?: $selectedDayAbsence['type_label'])) ?></span>
              <?php if (!tracker_is_locked($config, $selectedDate)): ?>
              <form method="post" action="/delete_absence.php" onsubmit="return confirm('Biztosan törlöd az egész napos távollétet?');">
                <input type="hidden" name="id" value="<?= (int)$selectedDayAbsence['id'] ?>">
                <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
                <input type="hidden" name="employee_id" value="<?= (int)$selectedEmployeeId ?>">
                <input type="hidden" name="date" value="<?= h($selectedDate) ?>">
                <button class="btn btn-sm btn-outline-danger">Törlés</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
        <?php if (!$selectedDayEntries): ?>
          <div class="text-muted">Erre a napra még nincs rögzített időalapú bejegyzés.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Típus</th>
                  <th>Kezdés</th>
                  <th>Vége</th>
                  <th>Szünet</th>
                  <th>Összesen</th>
                  <th>Utazás</th>
                  <th>Munka</th>
                  <th>Jármű / km</th>
                  <th>Megjegyzés</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedDayEntries as $entry): ?>
                <tr
                  class="draggable-entry-row"
                  draggable="true"
                  data-entry-id="<?= (int)$entry['id'] ?>"
                  data-copyable="<?= empty($entry['group_uid']) ? '1' : '0' ?>"
                  data-entry-date="<?= h((string)$entry['entry_date']) ?>"
                  data-entry-kind="<?= h((string)$entry['entry_kind']) ?>"
                  data-start-time="<?= h(substr((string)$entry['start_time'], 0, 5)) ?>"
                  data-end-time="<?= h(substr((string)$entry['end_time'], 0, 5)) ?>"
                  data-break-minutes="<?= (int)$entry['break_minutes'] ?>"
                  data-note="<?= h((string)$entry['note']) ?>"
                  data-vehicle-id="<?= (int)($entry['vehicle_id'] ?? 0) ?>"
                  data-travel-km="<?= h((string)($entry['travel_km'] ?? '')) ?>"
                >
                  <td>
                    <span class="badge <?= h($entry['color_class'] ?: 'text-bg-secondary') ?>"><?= h($entry['type_label'] ?: $entry['entry_kind']) ?></span>
                    <?php if (!empty($entry['group_uid'])): ?><span class="badge text-bg-light border ms-1">Közös</span><?php endif; ?>
                  </td>
                  <td><?= h((string)($entry['display_start_time'] ?? substr((string)$entry['start_time'], 0, 5))) ?></td>
                  <td><?= h((string)($entry['display_end_time'] ?? tracker_time_display_with_offset((string)$entry['end_time'], !empty($entry['crosses_midnight']), true))) ?></td>
                  <td><?= (int)$entry['break_minutes'] ?> perc</td>
                  <td><?= h(tracker_minutes_to_hhmm(tracker_entry_segment_total_minutes($entry))) ?></td>
                  <td><?= h(tracker_minutes_to_hhmm(tracker_entry_segment_travel_minutes($entry))) ?></td>
                  <td><?= h(tracker_minutes_to_hhmm(tracker_entry_segment_work_minutes($entry))) ?></td>
                  <td><?php if (!empty($entry['vehicle_plate']) || (float)tracker_entry_segment_travel_km($entry) > 0): ?><?= h(trim((string)($entry['vehicle_plate'] ?? ''))) ?><?php if ((float)tracker_entry_segment_travel_km($entry) > 0): ?> · <?= h(rtrim(rtrim(number_format((float)tracker_entry_segment_travel_km($entry), 1, '.', ''), '0'), '.')) ?> km<?php endif; ?><?php else: ?>—<?php endif; ?></td>
                  <td><?= h((string)$entry['note']) ?></td>
                </tr>
                <tr class="entry-actions-row">
                  <td colspan="9">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                      <span class="badge text-bg-light border drag-badge" title="Húzd másik napra">⇄ Húzás</span>
                      <?php if (empty($entry['group_uid']) && !tracker_is_locked($config, (string)$entry['entry_date'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary copy-entry-btn" data-bs-toggle="modal" data-bs-target="#copyEntryModal">Másolás</button>
                      <?php endif; ?>
                      <?php if (!tracker_is_locked($config, (string)$entry['entry_date'])): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="?month=<?= h($monthDate->format('Y-m')) ?>&employee_id=<?= (int)$selectedEmployeeId ?>&date=<?= h($selectedDate) ?>&edit=<?= (int)$entry['id'] ?>">Szerkeszt</a>
                        <form method="post" action="/delete_entry.php" class="d-inline" onsubmit="return confirm('Biztosan törlöd a bejegyzést?');">
                          <input type="hidden" name="id" value="<?= (int)$entry['id'] ?>">
                          <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
                          <input type="hidden" name="employee_id" value="<?= (int)$selectedEmployeeId ?>">
                          <button class="btn btn-sm btn-outline-danger">Törlés</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <div class="fw-semibold">Egész napos távollét</div>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#allDayAbsenceCollapse" aria-expanded="false" aria-controls="allDayAbsenceCollapse">Nyit / csuk</button>
      </div>
      <div id="allDayAbsenceCollapse" class="collapse">
      <div class="card-body border-top">
        <?php if (tracker_is_locked($config, $selectedDate)): ?>
          <div class="alert alert-warning mb-0">A kiválasztott nap zárolt, ezért a távollét sem módosítható.</div>
        <?php elseif ($selectedDayAbsence): ?>
          <div class="alert alert-light border mb-0">Erre a napra már rögzítve van: <strong><?= h((string)$selectedDayAbsence['type_label']) ?></strong>.</div>
        <?php elseif (!empty($selectedDayEntries)): ?>
          <div class="alert alert-light border mb-0">Erre a napra már van időalapú bejegyzés, ezért egész napos távollét most nem rögzíthető.</div>
        <?php else: ?>
        <form method="post" action="/save_absence.php" class="vstack gap-3">
          <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
          <?php if (tracker_is_admin($config)): ?>
          <div>
            <label class="form-label">Dolgozó</label>
            <select name="employee_id" class="form-select" required>
              <?php foreach ($employeeOptions as $opt): ?>
                <option value="<?= (int)$opt['employee_id'] ?>"<?= $chosenEmp === (int)$opt['employee_id'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A lista a <strong>Távollét típusok</strong> admin oldalon kezelt elemekből jön.</div>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label">Dátum</label>
            <input type="date" name="absence_date" value="<?= h($selectedDate) ?>" class="form-control" required>
          </div>
          <?php if ($absenceTemplates): ?>
          <div>
            <label class="form-label">Távolléti sablon</label>
            <select name="absence_template_id" id="absence_template_id" class="form-select">
              <option value="">Válassz sablont</option>
              <?php foreach ($absenceTemplates as $tpl): ?>
                <option value="<?= (int)$tpl['id'] ?>"
                  data-absence-type-id="<?= (int)$tpl['absence_type_id'] ?>"
                  data-note="<?= h((string)($tpl['note'] ?? '')) ?>"
                ><?= h((string)$tpl['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A sablon kitölti a távollét típusát és a megjegyzést.</div>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label">Távollét típusa</label>
            <select name="absence_type_id" id="absence_type_id" class="form-select" required>
              <option value="">Válassz típust</option>
              <?php foreach ($absenceTypes as $atype): ?>
                <option value="<?= (int)$atype['id'] ?>"><?= h((string)$atype['label']) ?><?= !empty($atype['badge_text']) ? ' [' . h((string)$atype['badge_text']) . ']' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="form-label">Megjegyzés</label>
            <textarea name="note" id="absence_note" rows="2" class="form-control"></textarea>
          </div>
          <div><button class="btn btn-outline-success">Távollét rögzítése</button></div>
        </form>
        <?php endif; ?>
      </div>
      </div>
    </div>

    <?php if (tracker_is_admin($config)): ?>
    <div class="card shadow-sm mt-4">
      <div class="card-header"><div class="fw-semibold">Időszak lezárás / feloldás</div></div>
      <div class="card-body">
        <div class="row g-4 align-items-start">
          <div class="col-lg-6">
            <form method="post" action="/lock_period.php" class="vstack gap-3">
              <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
              <input type="hidden" name="employee_id" value="<?= (int)$selectedEmployeeId ?>">
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label">-tól</label><input type="date" name="date_from" class="form-control" required value="<?= h($monthStart) ?>"></div>
                <div class="col-md-6"><label class="form-label">-ig</label><input type="date" name="date_to" class="form-control" required value="<?= h($monthEnd) ?>"></div>
              </div>
              <div><label class="form-label">Indok</label><input type="text" name="reason" class="form-control" placeholder="Pl. hó végi lezárás"></div>
              <div><button class="btn btn-outline-primary">Lezárás</button></div>
            </form>
          </div>
          <div class="col-lg-6">
            <div class="fw-semibold mb-3">Aktív zárolások</div>
            <?php if (!$locks): ?>
              <div class="text-muted">Nincs aktív zárolás.</div>
            <?php else: ?>
              <div class="list-group list-group-flush">
                <?php foreach ($locks as $lock): ?>
                  <div class="list-group-item px-0">
                    <div class="d-flex justify-content-between gap-3">
                      <div>
                        <div class="fw-semibold"><?= h((string)$lock['date_from']) ?> – <?= h((string)$lock['date_to']) ?></div>
                        <div class="small text-muted"><?= h((string)$lock['reason']) ?></div>
                      </div>
                      <form method="post" action="/unlock_period.php" onsubmit="return confirm('Feloldod ezt a zárolást?');">
                        <input type="hidden" name="id" value="<?= (int)$lock['id'] ?>">
                        <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
                        <input type="hidden" name="employee_id" value="<?= (int)$selectedEmployeeId ?>">
                        <button class="btn btn-sm btn-outline-secondary">Feloldás</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <div class="card shadow-sm mb-4 sticky-lg-top tracker-sticky">
      <div class="card-header">
        <div class="fw-semibold"><?= $editEntry ? 'Bejegyzés szerkesztése' : 'Új bejegyzés' ?></div>
      </div>
      <div class="card-body">
        <?php if (tracker_is_locked($config, $selectedDate)): ?>
          <div class="alert alert-warning mb-0">A kiválasztott nap zárolt, ezért nem módosítható.</div>
        <?php elseif ($selectedDayAbsence): ?>
          <div class="alert alert-light border mb-0">Erre a napra egész napos távollét van rögzítve, ezért időalapú bejegyzés nem vehető fel.</div>
        <?php else: ?>
        <form method="post" action="/save_entry.php" class="vstack gap-3">
          <input type="hidden" name="id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
          <input type="hidden" name="month" value="<?= h($monthDate->format('Y-m')) ?>">
          <?php if (tracker_is_admin($config)): ?>
          <div>
            <label class="form-label">Dolgozó</label>
            <select name="employee_id" class="form-select" required>
              <?php foreach ($employeeOptions as $opt): ?>
                <option value="<?= (int)$opt['employee_id'] ?>"<?= $chosenEmp === (int)$opt['employee_id'] ? ' selected' : '' ?>><?= h($opt['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div>
            <label class="form-label">Dátum</label>
            <input type="date" name="entry_date" value="<?= h((string)($editEntry['entry_date'] ?? $selectedDate)) ?>" class="form-control" required>
          </div>
          <?php if (!$editEntry && $workTemplates): ?>
          <div>
            <label class="form-label">Sablon</label>
            <select name="work_template_id" id="work_template_id" class="form-select">
              <option value="">Válassz sablont</option>
              <?php foreach ($workTemplates as $tpl): ?>
                <option value="<?= (int)$tpl['id'] ?>"
                  data-entry-kind="<?= h((string)$tpl['entry_kind']) ?>"
                  data-start-time="<?= h(substr((string)$tpl['start_time'], 0, 5)) ?>"
                  data-end-time="<?= h(substr((string)$tpl['end_time'], 0, 5)) ?>"
                  data-break-minutes="<?= (int)$tpl['break_minutes'] ?>"
                  data-note="<?= h((string)($tpl['note'] ?? '')) ?>"
                ><?= h((string)$tpl['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A sablon kitölti az időket, a szünetet és a megjegyzést.</div>
          </div>
          <?php endif; ?>
          <div class="d-none">
            <label class="form-label">Munkaidő típusa</label>
            <select name="entry_kind" id="entry_kind" class="form-select" required>
              <?php $currentKind = (string)($editEntry['entry_kind'] ?? 'work'); foreach ($types as $type): ?>
                <option value="<?= h($type['code']) ?>"<?= $currentKind === $type['code'] ? ' selected' : '' ?>><?= h($type['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2">
            <div class="col-sm-6">
              <label class="form-label">Kezdés</label>
              <div class="row g-2">
                <div class="col-6">
                  <select name="start_hour" id="start_hour" class="form-select" required>
                    <option value="">Óra</option>
                    <?php foreach (tracker_hour_options() as $hour): ?>
                      <option value="<?= $hour ?>"<?= $editStartHour !== null && $editStartHour === $hour ? ' selected' : '' ?>><?= sprintf('%02d', $hour) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6">
                  <select name="start_minute" id="start_minute" class="form-select" required>
                    <option value="">Perc</option>
                    <?php foreach (tracker_minute_options() as $minute): ?>
                      <option value="<?= $minute ?>"<?= $editStartMinute !== null && $editStartMinute === $minute ? ' selected' : '' ?>><?= sprintf('%02d', $minute) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <label class="form-label d-block">Rögzítés módja</label>
              <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="time_mode" id="time_mode_end" value="end"<?= $timeMode !== 'duration' ? ' checked' : '' ?>>
                <label class="btn btn-outline-secondary" for="time_mode_end">Vége idő</label>
                <input type="radio" class="btn-check" name="time_mode" id="time_mode_duration" value="duration"<?= $timeMode === 'duration' ? ' checked' : '' ?>>
                <label class="btn btn-outline-secondary" for="time_mode_duration">Időtartam</label>
              </div>
            </div>
          </div>

          <div class="row g-2 tracker-time-mode tracker-mode-end">
            <div class="col-sm-6">
              <label class="form-label">Vége</label>
              <div class="row g-2">
                <div class="col-6">
                  <select name="end_hour" id="end_hour" class="form-select">
                    <option value="">Óra</option>
                    <?php foreach (tracker_hour_options() as $hour): ?>
                      <option value="<?= $hour ?>"<?= $editEndHour !== null && $editEndHour === $hour ? ' selected' : '' ?>><?= sprintf('%02d', $hour) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6">
                  <select name="end_minute" id="end_minute" class="form-select">
                    <option value="">Perc</option>
                    <?php foreach (tracker_minute_options() as $minute): ?>
                      <option value="<?= $minute ?>"<?= $editEndMinute !== null && $editEndMinute === $minute ? ' selected' : '' ?>><?= sprintf('%02d', $minute) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Számolt időtartam</label>
              <div class="form-control bg-light" id="calculated_duration">—</div>
            </div>
          </div>

          <div class="row g-2 tracker-time-mode tracker-mode-duration d-none">
            <div class="col-sm-6">
              <label class="form-label">Időtartam</label>
              <div class="row g-2">
                <div class="col-6">
                  <select name="duration_hours" id="duration_hours" class="form-select">
                    <?php for ($h=0; $h<=16; $h++): ?>
                      <option value="<?= $h ?>"<?= $durationHoursValue === $h ? ' selected' : '' ?>><?= sprintf('%02d', $h) ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="col-6">
                  <select name="duration_minutes" id="duration_minutes" class="form-select">
                    <?php foreach (tracker_minute_options() as $minute): ?>
                      <option value="<?= $minute ?>"<?= $durationMinutesValue === $minute ? ' selected' : '' ?>><?= sprintf('%02d', $minute) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Számolt befejezés</label>
              <div class="form-control bg-light" id="calculated_end_time">—</div>
            </div>
          </div>
          <div>
            <label class="form-label">Szünet (perc)</label>
            <input type="number" min="0" step="1" name="break_minutes" id="break_minutes" value="<?= (int)($editEntry['break_minutes'] ?? 0) ?>" class="form-control">
          </div>

          <div class="card bg-light-subtle border-0">
            <div class="card-body py-3">
              <div class="fw-semibold mb-2">Utazási adatok (opcionális)</div>
              <div class="row g-2">
                <div class="col-sm-6">
                  <label class="form-label">Rendszám</label>
                  <select name="vehicle_id" id="vehicle_id" class="form-select">
                    <option value="">Nincs kiválasztva</option>
                    <?php foreach ($vehicles as $veh): ?>
                      <option value="<?= (int)$veh['id'] ?>" data-avg-speed="<?= h((string)$veh['avg_speed_kmh']) ?>"<?= $editVehicleId === (int)$veh['id'] ? ' selected' : '' ?>><?= h((string)$veh['plate_number']) ?><?= !empty($veh['label']) ? ' · ' . h((string)$veh['label']) : '' ?><?= !empty($veh['avg_speed_kmh']) ? ' · ' . h(rtrim(rtrim(number_format((float)$veh['avg_speed_kmh'], 1, '.', ''), '0'), '.')) . ' km/h' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label">Km</label>
                  <input type="number" min="0" step="0.1" name="travel_km" id="travel_km" value="<?= $editTravelKm !== null ? h((string)rtrim(rtrim(number_format($editTravelKm, 1, '.', ''), '0'), '.')) : '' ?>" class="form-control">
                </div>
              </div>
              <div class="small text-muted mt-2">A rendszer az utazási időt automatikusan számolja a kiválasztott jármű átlagsebessége alapján.</div>
              <div class="small mt-1"><strong>Számolt utazási idő:</strong> <span id="calculated_travel_time">—</span></div>
            </div>
          </div>
          <?php if (!$editEntry): ?>
          <div>
            <label class="form-label">Együtt dolgozó kollégák</label>
            <select name="coworker_employee_ids[]" class="form-select" multiple size="6">
              <?php foreach ($employeeOptions as $opt): ?>
                <?php if ((int)$opt['employee_id'] === $chosenEmp) continue; ?>
                <option value="<?= (int)$opt['employee_id'] ?>"><?= h($opt['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">A kijelölt kollégákhoz is létrejön ugyanaz a bejegyzés, ha az adott idősáv náluk nem foglalt és a nap nincs lezárva.</div>
          </div>
          <?php else: ?>
          <div class="alert alert-light border mb-0">A közös rögzítés csak új bejegyzés létrehozásakor használható. Utána minden dolgozó a saját rekordját külön szerkesztheti.</div>
          <?php endif; ?>
          <div>
            <label class="form-label">Megjegyzés</label>
            <textarea name="note" id="entry_note" rows="3" class="form-control"><?= h((string)($editEntry['note'] ?? '')) ?></textarea>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><?= $editEntry ? 'Mentés' : 'Rögzítés' ?></button>
            <?php if ($editEntry): ?><a class="btn btn-outline-secondary" href="?month=<?= h($monthDate->format('Y-m')) ?>&employee_id=<?= (int)$selectedEmployeeId ?>&date=<?= h($selectedDate) ?>">Mégse</a><?php endif; ?>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
<div class="modal fade" id="copyEntryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form id="copyEntryForm">
        <div class="modal-header">
          <h5 class="modal-title">Bejegyzés másolása</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="source_entry_id" id="copy_source_entry_id" value="">
          <div class="mb-3">
            <label for="copy_target_date" class="form-label">Céldátum</label>
            <input type="date" class="form-control" id="copy_target_date" required>
          </div>
          <div class="small text-muted">Csak saját, nem közös bejegyzés másolható. Az ütközés- és zárolásellenőrzés mentéskor lefut.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Mégse</button>
          <button type="submit" class="btn btn-primary">Másolás</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
