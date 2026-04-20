<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth;
use App\Middleware;
use App\Db;
use App\Helpers;

Auth::start();
Middleware::requireAuth();

$pdo = Db::pdo();

// --- Szűrők GET-ből ---
$filter_date       = trim($_GET['date'] ?? '');
$filter_type_id    = isset($_GET['type']) && $_GET['type'] !== '' ? (int)$_GET['type'] : null;
$filter_location   = trim($_GET['location'] ?? '');
$filter_project_id = isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null;
$filter_status     = trim($_GET['status'] ?? '');
$filter_worker_id  = isset($_GET['worker']) && $_GET['worker'] !== '' ? (int)$_GET['worker'] : null;

$filterParams = [
    'date'     => $filter_date,
    'type'     => $filter_type_id !== null ? (string)$filter_type_id : '',
    'location' => $filter_location,
    'project'  => $filter_project_id !== null ? (string)$filter_project_id : '',
    'status'   => $filter_status,
    'worker'   => $filter_worker_id !== null ? (string)$filter_worker_id : '',
];
$filterParamsClean = [];
foreach ($filterParams as $k => $v) {
    if ($v !== '' && $v !== null) {
        $filterParamsClean[$k] = $v;
    }
}
$queryString = http_build_query($filterParamsClean);

// Szótárak a szűrőkhöz
$workTypes = $pdo->query("
  SELECT id, name, color
  FROM work_types
  WHERE is_active = 1
  ORDER BY name
")->fetchAll();

$projects = $pdo->query("
  SELECT id, number, name
  FROM projects
  ORDER BY number, name
")->fetchAll();

$workers = $pdo->query("
  SELECT id, full_name, position
  FROM workers
  WHERE is_active = 1
  ORDER BY full_name
")->fetchAll();

$statusLabels = [
    'planned'     => 'Tervezett',
    'in_progress' => 'Folyamatban',
    'done'        => 'Kész',
    'cancelled'   => 'Törölve',
];

// --- Munkavégzési események lekérdezése (intervallum + szűrők) ---

$whereEvents = [];
$paramsEvents = [];
$joinWorkers = '';

if ($filter_date !== '') {
    // Az adott nap beleesik az intervallumba: work_date..date_to
    $whereEvents[] = 'e.work_date <= ? AND COALESCE(e.date_to, e.work_date) >= ?';
    $paramsEvents[] = $filter_date;
    $paramsEvents[] = $filter_date;
}

if ($filter_type_id !== null) {
    $whereEvents[] = 'e.work_type_id = ?';
    $paramsEvents[] = $filter_type_id;
}

if ($filter_location !== '') {
    $whereEvents[] = 'e.location LIKE ?';
    $paramsEvents[] = '%'.$filter_location.'%';
}

if ($filter_project_id !== null) {
    $whereEvents[] = 'e.project_id = ?';
    $paramsEvents[] = $filter_project_id;
}

if ($filter_status !== '') {
    $whereEvents[] = 'e.status = ?';
    $paramsEvents[] = $filter_status;
}

if ($filter_worker_id !== null) {
    $joinWorkers = 'JOIN work_event_workers wew ON wew.work_event_id = e.id';
    $whereEvents[] = 'wew.worker_id = ?';
    $paramsEvents[] = $filter_worker_id;
}

$whereSqlEvents = $whereEvents ? ('WHERE '.implode(' AND ', $whereEvents)) : '';

$sqlEvents = "
  SELECT
    e.*,
    wt.name  AS work_type_name,
    wt.color AS work_type_color,
    p.number AS project_number,
    p.name   AS project_name
  FROM work_events e
  $joinWorkers
  LEFT JOIN work_types wt ON e.work_type_id = wt.id
  LEFT JOIN projects  p   ON e.project_id    = p.id
  $whereSqlEvents
  ORDER BY e.work_date ASC, e.time_from ASC, e.id ASC
";
$st = $pdo->prepare($sqlEvents);
$st->execute($paramsEvents);
$eventRows = $st->fetchAll();

// --- Eseményhez tartozó dolgozók ---

$wewRows = $pdo->query("
  SELECT
    wew.work_event_id,
    w.full_name,
    w.position,
    wew.role
  FROM work_event_workers wew
  JOIN workers w ON w.id = wew.worker_id
  ORDER BY wew.work_event_id, w.full_name
")->fetchAll();

$workersByEvent = [];
foreach ($wewRows as $wr) {
    $eid = (int)$wr['work_event_id'];
    if (!isset($workersByEvent[$eid])) {
        $workersByEvent[$eid] = [];
    }
    $workersByEvent[$eid][] = [
        'name'     => $wr['full_name'],
        'position' => $wr['position'],
        'role'     => $wr['role'],
    ];
}

// --- Dolgozói napi státuszok lekérdezése (intervallum + szűrők) ---

$whereStatus = [];
$paramsStatus = [];

if ($filter_date !== '') {
    $whereStatus[] = 'wds.status_date <= ? AND COALESCE(wds.status_date_to, wds.status_date) >= ?';
    $paramsStatus[] = $filter_date;
    $paramsStatus[] = $filter_date;
}

if ($filter_worker_id !== null) {
    $whereStatus[] = 'wds.worker_id = ?';
    $paramsStatus[] = $filter_worker_id;
}

$whereSqlStatus = $whereStatus ? ('WHERE '.implode(' AND ', $whereStatus)) : '';

$st = $pdo->prepare("
  SELECT
    wds.id,
    wds.status_date,
    wds.status_date_to,
    wds.worker_id,
    w.full_name,
    w.position,
    st.name  AS status_name,
    st.color AS status_color
  FROM worker_day_statuses wds
  JOIN workers w             ON w.id = wds.worker_id
  JOIN worker_status_types st ON st.id = wds.status_type_id
  $whereSqlStatus
  ORDER BY wds.status_date ASC, w.full_name ASC, wds.id ASC
");
$st->execute($paramsStatus);
$wdsRows = $st->fetchAll();

// --- Calendars (TUI-hoz) ---

$calendars = [];
foreach ($workTypes as $wt) {
    $calendars[] = [
        'id'           => (string)$wt['id'],
        'name'         => $wt['name'],
        'bgColor'      => $wt['color'] ?: '#3a87ad',
        'borderColor'  => $wt['color'] ?: '#2c3e50',
        'color'        => '#ffffff',
        'dragBgColor'  => $wt['color'] ?: '#3a87ad',
    ];
}

// plusz egy "Dolgozói státusz" naptár
$calendars[] = [
    'id'           => 'wstatus',
    'name'         => 'Dolgozói státuszok',
    'bgColor'      => '#6c757d',
    'borderColor'  => '#6c757d',
    'color'        => '#ffffff',
    'dragBgColor'  => '#6c757d',
];

// --- Schedules: Munkavégzések (intervallum, all-day több napra) ---

$eventSchedules = [];
foreach ($eventRows as $r) {
    $eventId  = (int)$r['id'];
    $dateFrom = $r['work_date'];
    $dateTo   = $r['date_to'] ?: $r['work_date'];

    $timeFrom = $r['time_from'];
    $timeTo   = $r['time_to'];

    // Többnapos → all-day, 1 napos időponttal → időzített, egyébként all-day
    $isMultiDay = ($dateTo !== $dateFrom);

    if ($isMultiDay) {
        $isAllDay = true;
        $startStr = $dateFrom.'T00:00:00';
        $endStr   = date('Y-m-d', strtotime($dateTo.' +1 day')).'T00:00:00';
    } else {
        if ($timeFrom || $timeTo) {
            $isAllDay = false;
            $startStr = $dateFrom.'T'.($timeFrom ?: '00:00:00');
            $endStr   = $dateFrom.'T'.($timeTo   ?: ($timeFrom ?: '23:59:59'));
        } else {
            $isAllDay = true;
            $startStr = $dateFrom.'T00:00:00';
            $endStr   = date('Y-m-d', strtotime($dateFrom.' +1 day')).'T00:00:00';
        }
    }

    $eventWorkers = $workersByEvent[$eventId] ?? [];

    $titleParts = [];
    if (!empty($r['work_type_name'])) {
        $titleParts[] = $r['work_type_name'];
    }
    if (!empty($r['title'])) {
        $titleParts[] = $r['title'];
    }
    if (!empty($r['project_number'])) {
        $titleParts[] = '(' . $r['project_number'] . ')';
    }
    $title = implode(' – ', $titleParts);
    if ($title === '') {
        $title = 'Munkavégzés';
    }

    $color = $r['work_type_color'] ?: '#3a87ad';

    $eventSchedules[] = [
        'id'          => (string)$eventId,
        'calendarId'  => (string)$r['work_type_id'],
        'title'       => $title,
        'category'    => $isAllDay ? 'allday' : 'time',
        'isAllDay'    => $isAllDay,
        'start'       => $startStr,
        'end'         => $endStr,
        'location'    => $r['location'],
        'bgColor'     => $color,
        'borderColor' => $color,
        'dragBgColor' => $color,
        'color'       => '#ffffff',
        'raw'         => [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            'status'    => $r['status'],
            'project'   => [
                'number' => $r['project_number'],
                'name'   => $r['project_name'],
            ],
            'requires_notification' => (bool)$r['requires_notification'],
            'workers'  => $eventWorkers,
        ],
    ];
}

// --- Schedules: Dolgozói napi státuszok (mind all-day, több napra csík) ---

$statusSchedules = [];
foreach ($wdsRows as $row) {
    $dateFrom = $row['status_date'];
    $dateTo   = $row['status_date_to'] ?: $row['status_date'];

    $startStr = $dateFrom.'T00:00:00';
    $endStr   = date('Y-m-d', strtotime($dateTo.' +1 day')).'T00:00:00';

    $title = $row['full_name'].' – '.$row['status_name'];
    $color = $row['status_color'] ?: '#6c757d';

    $statusSchedules[] = [
        'id'          => 'wds-'.$row['id'],
        'calendarId'  => 'wstatus',
        'title'       => $title,
        'category'    => 'allday',
        'isAllDay'    => true,
        'start'       => $startStr,
        'end'         => $endStr,
        'location'    => '',
        'bgColor'     => $color,
        'borderColor' => $color,
        'dragBgColor' => $color,
        'color'       => '#ffffff',
        'raw'         => [
            'date_from'       => $dateFrom,
            'date_to'         => $dateTo,
            'worker_id'       => $row['worker_id'],
            'worker_name'     => $row['full_name'],
            'worker_position' => $row['position'],
            'status_name'     => $row['status_name'],
            'status_color'    => $row['status_color'],
        ],
    ];
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Munkavégzés naptár</h1>
  <div class="d-flex gap-2">
    <a href="/ts_events.php<?= $queryString ? ('?'.Helpers::e($queryString)) : '' ?>"
       class="btn btn-sm btn-outline-secondary">
      Lista nézet
    </a>
    <a href="/ts_event_edit.php?return=calendar"
       class="btn btn-sm btn-success">
      Új munkavégzés
    </a>
  </div>
</div>

<!-- Szűrő sáv -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label for="f_date" class="form-label">Dátum</label>
        <input type="date" name="date" id="f_date" class="form-control"
               value="<?= Helpers::e($filter_date) ?>">
        <div class="form-text">Az intervallumba eső tételek jelennek meg.</div>
      </div>

      <div class="col-md-3">
        <label for="f_worker" class="form-label">Dolgozó</label>
        <select name="worker" id="f_worker" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($workers as $w): ?>
            <option value="<?= (int)$w['id'] ?>"
              <?= $filter_worker_id === (int)$w['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($w['full_name']) ?>
              <?php if ($w['position']): ?>
                (<?= Helpers::e($w['position']) ?>)
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label for="f_type" class="form-label">Típus</label>
        <select name="type" id="f_type" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($workTypes as $t): ?>
            <option value="<?= (int)$t['id'] ?>"
              <?= $filter_type_id === (int)$t['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($t['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label for="f_location" class="form-label">Helyszín</label>
        <input type="text" name="location" id="f_location" class="form-control"
               value="<?= Helpers::e($filter_location) ?>">
      </div>

      <div class="col-md-3">
        <label for="f_project" class="form-label">Projekt</label>
        <select name="project" id="f_project" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>"
              <?= $filter_project_id === (int)$p['id'] ? 'selected' : '' ?>>
              <?= Helpers::e($p['number']) ?> – <?= Helpers::e($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label for="f_status" class="form-label">Státusz</label>
        <select name="status" id="f_status" class="form-select">
          <option value="">— Mind —</option>
          <?php foreach ($statusLabels as $k => $lbl): ?>
            <option value="<?= Helpers::e($k) ?>"
              <?= $filter_status === $k ? 'selected' : '' ?>>
              <?= Helpers::e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-12 d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-primary btn-sm">Szűrés</button>
        <a href="/ts_calendar.php" class="btn btn-outline-secondary btn-sm">Szűrő törlése</a>
      </div>
    </form>
  </div>
</div>

<!-- Legend + naptár -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-between">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="fw-semibold me-2">Típusok:</div>
        <?php if (!$calendars): ?>
          <span class="text-muted small">Még nincs munkavégzés típus vagy státusz típus.</span>
        <?php else: ?>
          <?php foreach ($calendars as $c): ?>
            <span class="badge rounded-pill"
                  style="background-color: <?= Helpers::e((string)$c['bgColor']) ?>;">
              <?= Helpers::e((string)$c['name']) ?>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body">

    <!-- Kapcsolók: mit mutasson a naptár -->
    <div class="d-flex justify-content-end mb-2 gap-3">
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" id="showEvents" checked>
        <label class="form-check-label" for="showEvents">Munkavégzések</label>
      </div>
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" id="showWorkerStatuses" checked>
        <label class="form-check-label" for="showWorkerStatuses">Dolgozói státuszok</label>
      </div>
    </div>

    <!-- Navigáció + nézetváltó -->
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" id="ts-cal-prev" class="btn btn-outline-secondary">&laquo;</button>
        <button type="button" id="ts-cal-today" class="btn btn-outline-secondary">Ma</button>
        <button type="button" id="ts-cal-next" class="btn btn-outline-secondary">&raquo;</button>
      </div>
      <div id="ts-cal-title" class="fw-semibold"></div>
      <div class="btn-group btn-group-sm" role="group">
        <button type="button" class="btn btn-outline-secondary active" data-ts-cal-view="month">Hónap</button>
        <button type="button" class="btn btn-outline-secondary" data-ts-cal-view="week">Hét</button>
        <button type="button" class="btn btn-outline-secondary" data-ts-cal-view="day">Nap</button>
      </div>
    </div>

    <div id="ts-calendar" style="height: 800px;"></div>
  </div>
</div>

<!-- Napi események modal -->
<div class="modal fade" id="dayEventsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Napi események</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bezárás"></button>
      </div>
      <div class="modal-body">
        <div id="ts-day-events-title" class="fw-semibold mb-2"></div>
        <div id="ts-day-events-body"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Bezárás</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" type="text/css" href="/assets/tui/tui-calendar.css">
<script src="/assets/tui/tui-code-snippet.min.js"></script>
<script src="/assets/tui/tui-calendar.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var calendars        = <?= json_encode($calendars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var eventSchedules   = <?= json_encode($eventSchedules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var statusSchedules  = <?= json_encode($statusSchedules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  var Calendar = tui.Calendar;

  var calendar = new Calendar('#ts-calendar', {
    defaultView: 'month',
    taskView: false,
    scheduleView: ['allday', 'time'],
    usageStatistics: false,
    isReadOnly: false,

    month: { startDayOfWeek: 1 },
    week:  {
      startDayOfWeek: 1,
      hourStart: 0,
      hourEnd: 24
    },
    day: {
      startDayOfWeek: 1,
      hourStart: 0,
      hourEnd: 24
    },

    template: {
      // Havi nézet napnevei (röviden, magyarul)
      monthDayname: function (dayname) {
        var labels = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];
        return '<span class="tui-full-calendar-weekday-grid-date">' +
               labels[dayname.day] +
               '</span>';
      },

      // Ha több esemény van, mint ami elfér a cellában
      monthGridHeaderExceed: function(hiddenCount) {
        return '<span class="tui-full-calendar-weekday-grid-more-schedules">+' +
                 hiddenCount +
               ' további</span>';
      },

      // Heti / napi nézet napfejléc
      weekDayname: function (model) {
        var labels = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];
        var dayLabel = labels[model.day];
        return '<span class="tui-full-calendar-dayname-date">' +
                 model.date +
               '.</span>&nbsp;' +
               '<span class="tui-full-calendar-dayname-name">' +
                 dayLabel +
               '</span>';
      },

      // "More" popup címe magyar dátummal + napnévvel
      monthMoreTitleDate: function(date, dayname) {
        var y = '', m = '', d = '';
        var parts = date.split('.');
        if (parts.length === 3) {
          y = parseInt(parts[0], 10);
          m = parseInt(parts[1], 10);
          d = parseInt(parts[2], 10);
        } else {
          var p2 = date.split('-');
          if (p2.length === 3) {
            y = parseInt(p2[0], 10);
            m = parseInt(p2[1], 10);
            d = parseInt(p2[2], 10);
          }
        }

        var monthNames = [
          'január','február','március','április','május','június',
          'július','augusztus','szeptember','október','november','december'
        ];

        var dayMap = {
          'Sun': 'vasárnap',
          'Mon': 'hétfő',
          'Tue': 'kedd',
          'Wed': 'szerda',
          'Thu': 'csütörtök',
          'Fri': 'péntek',
          'Sat': 'szombat'
        };

        var dayLabel = dayMap[dayname] || dayname;
        var dateLabel = '';
        if (d && m && y) {
          dateLabel = d + '. ' + monthNames[m - 1] + ' ' + y + '.';
        } else {
          dateLabel = date;
        }

        return ''
          + '<span class="tui-full-calendar-month-more-title-day">'
          + dateLabel
          + '</span> '
          + '<span class="tui-full-calendar-month-more-title-day-label">'
          + dayLabel
          + '</span>';
      },

      // Idősáv esemény címkéje: "HH:MM Cím"
      time: function(schedule) {
        var s = schedule.start;
        var hh = ('0' + s.getHours()).slice(-2);
        var mm = ('0' + s.getMinutes()).slice(-2);
        return '<strong>' + hh + ':' + mm + '</strong> ' + schedule.title;
      },

      // Egész napos esemény címkéje
      allday: function(schedule) {
        return schedule.title;
      },

      // "All day" felirat magyarul
      alldayTitle: function() {
        return '<span class="tui-full-calendar-left-content">Egész nap</span>';
      },

      // Idővonal (bal oldali órák) 24 órás formátumban
      timegridDisplayPrimaryTime: function(time) {
        var h = time.hour;
        var hh = (h < 10 ? '0' : '') + h;
        return hh + ':00';
      },
      timegridDisplayTime: function(time) {
        var h = time.hour;
        var m = time.minutes;
        var hh = (h < 10 ? '0' : '') + h;
        var mm = (m < 10 ? '0' : '') + m;
        return hh + ':' + mm;
      }
    }
  });

  calendar.setCalendars(calendars);

  var titleEl = document.getElementById('ts-cal-title');
  var monthNames = ['január','február','március','április','május','június',
                    'július','augusztus','szeptember','október','november','december'];

  var currentView = 'month';

  function updateTitle() {
    var current = calendar.getDate();
    var year = current.getFullYear();
    var month = current.getMonth();
    titleEl.textContent = year + '. ' + monthNames[month];
  }

  // Kapcsolók logika
  var showEvents = true;
  var showWorkerStatuses = true;

  function rebuildSchedules() {
    var merged = [];
    if (showEvents) {
      merged = merged.concat(eventSchedules);
    }
    if (showWorkerStatuses) {
      merged = merged.concat(statusSchedules);
    }
    calendar.clear();
    calendar.createSchedules(merged, true);
    calendar.render();
  }

  updateTitle();
  rebuildSchedules();

  document.getElementById('ts-cal-prev').addEventListener('click', function () {
    calendar.prev();
    updateTitle();
  });
  document.getElementById('ts-cal-next').addEventListener('click', function () {
    calendar.next();
    updateTitle();
  });
  document.getElementById('ts-cal-today').addEventListener('click', function () {
    calendar.today();
    updateTitle();
  });

  function setView(view) {
    if (view === currentView) return;
    calendar.changeView(view, true);
    currentView = view;
    updateTitle();
    document.querySelectorAll('[data-ts-cal-view]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-ts-cal-view') === view);
    });
  }

  document.querySelectorAll('[data-ts-cal-view]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var view = btn.getAttribute('data-ts-cal-view');
      setView(view);
    });
  });

  document.getElementById('showEvents').addEventListener('change', function () {
    showEvents = this.checked;
    rebuildSchedules();
  });
  document.getElementById('showWorkerStatuses').addEventListener('change', function () {
    showWorkerStatuses = this.checked;
    rebuildSchedules();
  });

  // Helper: HTML escape
  var HelpersJs = {
    escapeHtml: function (str) {
      if (str === null || str === undefined) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  };

  // Bootstrap modal
  var dayModalEl = document.getElementById('dayEventsModal');
  var dayModal   = new bootstrap.Modal(dayModalEl);
  var dayTitleEl = document.getElementById('ts-day-events-title');
  var dayBodyEl  = document.getElementById('ts-day-events-body');

  function dateToStr(d) {
    var y = d.getFullYear();
    var m = d.getMonth() + 1;
    var day = d.getDate();
    if (m < 10) m = '0' + m;
    if (day < 10) day = '0' + day;
    return y + '-' + m + '-' + day;
  }

  // adott nap (YYYY-MM-DD) középidejét adjuk vissza (Date obj)
  function dayMidnight(dateStr) {
    return new Date(dateStr + 'T00:00:00');
  }

  // Intervallumellenőrzés: s.start..s.end (end exkluzív), tartalmazza-e dateStr napját?
  function scheduleCoversDate(schedule, dateStr) {
    try {
      var start = new Date(schedule.start);
      var end   = new Date(schedule.end);
      var d     = dayMidnight(dateStr);
      return (start <= d && d < end);
    } catch (e) {
      return false;
    }
  }

  function showDayEvents(dateStr) {
    // Cím
    var parts = dateStr.split('-');
    if (parts.length === 3) {
      var y = parseInt(parts[0], 10);
      var m = parseInt(parts[1], 10);
      var d = parseInt(parts[2], 10);
      dayTitleEl.textContent = y + '. ' + monthNames[m-1] + ' ' + d + '.';
    } else {
      dayTitleEl.textContent = dateStr;
    }

    var html = '';

    // Dolgozói státuszok az adott napra (intervallum alapján)
    if (showWorkerStatuses) {
      var dayStatusList = statusSchedules.filter(function (s) {
        return scheduleCoversDate(s, dateStr);
      });

      if (dayStatusList.length > 0) {
        html += '<div class="mb-3">';
        html += '<div class="fw-semibold mb-1">Dolgozói napi státuszok:</div>';
        html += '<ul class="list-group list-group-flush">';
        dayStatusList.forEach(function (s) {
          var w = s.raw || {};
          var line = HelpersJs.escapeHtml(w.worker_name || '');
          if (w.worker_position) {
            line += ' <span class="text-muted">(' + HelpersJs.escapeHtml(w.worker_position) + ')</span>';
          }
          if (w.status_name) {
            line += ' – ' + HelpersJs.escapeHtml(w.status_name);
          }
          html += '<li class="list-group-item d-flex justify-content-between align-items-center">';
          html += '<span>' + line + '</span>';
          if (w.status_color) {
            html += '<span class="badge" style="background-color:' +
                    HelpersJs.escapeHtml(w.status_color) +
                    ';">&nbsp;&nbsp;</span>';
          }
          html += '</li>';
        });
        html += '</ul>';
        html += '</div>';
      }
    }

    // Munkavégzési események az adott napra (intervallum alapján)
    if (showEvents) {
      var dayEvents = eventSchedules.filter(function (s) {
        return scheduleCoversDate(s, dateStr);
      });

      if (dayEvents.length > 0) {
        html += '<div class="fw-semibold mb-1">Munkavégzési események:</div>';
        html += '<ul class="list-group list-group-flush">';
        dayEvents.forEach(function (s) {
          var loc = s.location ? ('<div class="small text-muted">Helyszín: ' + HelpersJs.escapeHtml(s.location) + '</div>') : '';
          var proj = '';
          if (s.raw && s.raw.project && (s.raw.project.number || s.raw.project.name)) {
            var pn = s.raw.project.number || '';
            var pn2 = s.raw.project.name || '';
            proj = '<div class="small text-muted">Projekt: ' + HelpersJs.escapeHtml((pn + ' ' + pn2).trim()) + '</div>';
          }

          var statusLabel = '';
          if (s.raw && s.raw.status) {
            var map = {
              'planned': 'Tervezett',
              'in_progress': 'Folyamatban',
              'done': 'Kész',
              'cancelled': 'Törölve'
            };
            statusLabel = '<span class="badge bg-secondary me-1">' +
                          HelpersJs.escapeHtml(map[s.raw.status] || s.raw.status) +
                          '</span>';
          }

          var notif = '';
          if (s.raw && s.raw.requires_notification) {
            notif = '<span class="badge bg-warning text-dark ms-1">Bejelentés szükséges</span>';
          }

          // idő intervallum (csak ha nem all-day)
          var timeRange = '';
          if (!s.isAllDay && s.start && s.end) {
            try {
              var st = new Date(s.start);
              var et = new Date(s.end);
              var sh = ('0' + st.getHours()).slice(-2) + ':' + ('0' + st.getMinutes()).slice(-2);
              var eh = ('0' + et.getHours()).slice(-2) + ':' + ('0' + et.getMinutes()).slice(-2);
              timeRange = '<div class="small text-muted">Idő: ' + sh + ' - ' + eh + '</div>';
            } catch (e) {}
          }

          var workersLine = '';
          if (s.raw && Array.isArray(s.raw.workers) && s.raw.workers.length > 0) {
            var workerTexts = s.raw.workers.map(function (w) {
              var partsW = [];
              if (w.name) partsW.push(w.name);
              if (w.position) partsW.push('(' + w.position + ')');
              if (w.role) partsW.push('- ' + w.role);
              return partsW.join(' ');
            });
            workersLine = '<div class="small">Dolgozók (eseményhez): ' +
                          HelpersJs.escapeHtml(workerTexts.join(', ')) +
                          '</div>';
          }

          html += '<li class="list-group-item">';
          html += '<div class="d-flex justify-content-between align-items-start">';
          html += '<div>';
          html += '<div class="fw-semibold">' + HelpersJs.escapeHtml(s.title) + '</div>';
          html += timeRange;
          html += loc;
          html += proj;
          html += workersLine;
          html += '</div>';
          html += '<div>' + statusLabel + notif + '</div>';
          html += '</div>';
          html += '<div class="mt-2 d-flex gap-2">';
          html += '<a href="/ts_event_edit.php?id=' + encodeURIComponent(s.id) + '&return=calendar" class="btn btn-sm btn-outline-primary">Megnyitás</a>';
          html += '<a href="/ts_event_edit.php?copy_id=' + encodeURIComponent(s.id) + '&return=calendar" class="btn btn-sm btn-outline-secondary">Másolat</a>';
          html += '</div>';
          html += '</li>';
        });
        html += '</ul>';
      }
    }

    if (html === '') {
      html = '<div class="text-muted">Nincs megjeleníthető adat ezen a napon.</div>';
    }

    dayBodyEl.innerHTML = html;
    dayModal.show();
  }

  // Eseményre kattintás:
  // - ha munkavégzés (numeric id) → ts_event_edit.php
  // - ha dolgozói státusz (wds-123) → ts_worker_day_edit.php
  calendar.on('clickSchedule', function (event) {
    var schedule = event.schedule;
    if (!schedule || !schedule.id) return;

    var idStr = String(schedule.id);

    // Dolgozói státusz: id = "wds-123"
    if (idStr.startsWith('wds-')) {
      var wdsId = idStr.substring(4); // "123"
      if (wdsId && !isNaN(parseInt(wdsId, 10))) {
        window.location.href = '/ts_worker_day_edit.php?id=' +
                               encodeURIComponent(wdsId) + '&return=calendar';
        return;
      }
    }

    // Munkavégzés: numerikus id
    if (!isNaN(parseInt(idStr, 10))) {
      window.location.href = '/ts_event_edit.php?id=' +
                             encodeURIComponent(idStr) +
                             '&return=calendar';
    }
  });
  // Napra kattintás (guide) → napi lista
  calendar.on('beforeCreateSchedule', function (event) {
    var d = event.start;
    var dayStr = dateToStr(d);
    if (event.guide && typeof event.guide.clearGuideElement === 'function') {
      event.guide.clearGuideElement();
    }
    showDayEvents(dayStr);
  });

  window.addEventListener('resize', function () {
    calendar.render();
  });
});
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php';