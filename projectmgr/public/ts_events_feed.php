<?php
// JSON feed FullCalendar-hoz

require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Auth;
use App\Middleware;
use App\Db;

Auth::start();
Middleware::requireAuth(); // csak bejelentkezve

$pdo = Db::pdo();

// FullCalendar által küldött dátum tartomány
$start = $_GET['start'] ?? null;
$end   = $_GET['end']   ?? null;

// Biztonság kedvéért alap szűrés
$where = [];
$params = [];

if ($start && $end) {
    // start/end formátum: YYYY-MM-DD
    $where[] = 'e.work_date BETWEEN ? AND ?';
    $params[] = $start;
    $params[] = $end;
}

$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
  SELECT
    e.*,
    wt.name  AS work_type_name,
    wt.color AS work_type_color,
    p.number AS project_number,
    p.name   AS project_name
  FROM work_events e
  LEFT JOIN work_types wt ON e.work_type_id = wt.id
  LEFT JOIN projects p    ON e.project_id = p.id
  $whereSql
  ORDER BY e.work_date ASC, e.time_from ASC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$events = [];

foreach ($rows as $r) {
    $allDay = empty($r['time_from']) && empty($r['time_to']);

    if ($allDay) {
        $startStr = $r['work_date'];          // pl. 2025-11-15
        $endStr   = null;                     // allDay esetén elég a start
    } else {
        $startStr = $r['work_date'].'T'.substr((string)$r['time_from'], 0, 5).':00';
        if (!empty($r['time_to'])) {
            $endStr = $r['work_date'].'T'.substr((string)$r['time_to'], 0, 5).':00';
        } else {
            $endStr = null;
        }
    }

    $titleParts = [];
    if (!empty($r['work_type_name'])) {
        $titleParts[] = $r['work_type_name'];
    }
    if (!empty($r['title'])) {
        $titleParts[] = $r['title'];
    }
    if (!empty($r['project_number'])) {
        $titleParts[] = '('.$r['project_number'].')';
    }

    $title = implode(' – ', $titleParts);

    $event = [
        'id'    => (string)$r['id'],
        'title' => $title !== '' ? $title : 'Munkavégzés',
        'start' => $startStr,
        'allDay'=> $allDay,
        'color' => $r['work_type_color'] ?: null,
        // extra adatok, tooltiphez, stb.
        'extendedProps' => [
            'location' => $r['location'],
            'status'   => $r['status'],
            'project'  => [
                'number' => $r['project_number'],
                'name'   => $r['project_name'],
            ],
            'requires_notification' => (bool)$r['requires_notification'],
        ],
    ];

    if ($endStr !== null) {
        $event['end'] = $endStr;
    }

    $events[] = $event;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($events);