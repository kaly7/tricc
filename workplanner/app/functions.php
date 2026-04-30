<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function base_url(string $path = ''): string {
  return '/' . ltrim($path, '/');
}

function e(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function start_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string)config()['session_name']);
    session_start();
  }
}

function flash_set(string $k, string $v): void { start_session(); $_SESSION['_flash'][$k] = $v; }
function flash_get(string $k): ?string {
  start_session();
  if (!isset($_SESSION['_flash'][$k])) return null;
  $v = (string)$_SESSION['_flash'][$k];
  unset($_SESSION['_flash'][$k]);
  return $v;
}

function csrf_token(): string {
  start_session();
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['_csrf'];
}

function verify_csrf(): void {
  start_session();
  $ok = isset($_POST['_csrf'], $_SESSION['_csrf']) && hash_equals((string)$_SESSION['_csrf'], (string)$_POST['_csrf']);
  if (!$ok) { http_response_code(400); exit('CSRF hiba'); }
}

function redirect(string $path): void { header('Location: ' . base_url($path)); exit; }

function audit(string $action, string $entityType = '', int $entityId = 0, array $details = []): void {
  try {
    $u   = current_user();
    $uid = (int)($u['id'] ?? 0);
    db()->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details_json) VALUES (?,?,?,?,?)")
       ->execute([$uid, $action, $entityType ?: null, $entityId ?: null, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
  } catch (Throwable) {}
}

function touch_last_modified(): void {
  try {
    db()->prepare("INSERT INTO config (k,v) VALUES ('last_modified',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
       ->execute([time()]);
  } catch (Throwable) {}
}

function get_last_modified(): int {
  try {
    $v = db()->query("SELECT v FROM config WHERE k='last_modified'")->fetchColumn();
    return $v ? (int)$v : 0;
  } catch (Throwable) { return 0; }
}

// ── Workplannerbe felvett aktív dolgozók ────────────────
function get_employees(): array {
  static $emps = null;
  if ($emps !== null) return $emps;
  try {
    $ids = db()->query("SELECT employee_id FROM wp_employees ORDER BY sort_order, employee_id")
                ->fetchAll(\PDO::FETCH_COLUMN);
    if (!$ids) { $emps = []; return $emps; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = db_hr()->prepare("SELECT id, full_name, company_division FROM employees WHERE id IN ($placeholders) AND is_active=1 ORDER BY full_name");
    $st->execute($ids);
    // sort_order szerinti sorrend megtartása
    $byId = [];
    foreach ($st->fetchAll() as $e) $byId[(int)$e['id']] = $e;
    $emps = array_values(array_filter(array_map(fn($id) => $byId[$id] ?? null, $ids)));
  } catch (Throwable) { $emps = []; }
  return $emps;
}

// ── Összes HR dolgozó (admin felvételhez) ───────────────
function get_all_hr_employees(): array {
  try {
    return db_hr()->query("SELECT id, full_name, company_division FROM employees WHERE is_active=1 ORDER BY full_name")
                  ->fetchAll();
  } catch (Throwable) { return []; }
}

function employees_map(): array {
  static $map = null;
  if ($map !== null) return $map;
  $map = [];
  foreach (get_employees() as $e) $map[(int)$e['id']] = $e;
  return $map;
}

// ── Helyszínek ──────────────────────────────────────────
function get_locations(): array {
  return db()->query("SELECT id, name, color FROM locations ORDER BY use_count DESC, name")->fetchAll();
}

// ── Szín generálás HSL golden-angle alapon ──────────────
function generate_color(int $index): string {
  $hue = fmod($index * 137.508, 360);
  // HSL → RGB (s=0.6, l=0.52)
  $s = 0.60; $l = 0.52;
  $c = (1 - abs(2 * $l - 1)) * $s;
  $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
  $m = $l - $c / 2;
  $h = (int)($hue / 60);
  [$r1, $g1, $b1] = match($h) {
    0 => [$c, $x, 0],
    1 => [$x, $c, 0],
    2 => [0, $c, $x],
    3 => [0, $x, $c],
    4 => [$x, 0, $c],
    default => [$c, 0, $x],
  };
  $r = (int)(($r1 + $m) * 255);
  $g = (int)(($g1 + $m) * 255);
  $b = (int)(($b1 + $m) * 255);
  return sprintf('#%02x%02x%02x', $r, $g, $b);
}

// ── Következő szabad szín (amit még nem használ helyszín) ─
function next_location_color(): string {
  $count = (int)db()->query("SELECT COUNT(*) FROM locations")->fetchColumn();
  return generate_color($count);
}

// ── Munkanapok (hétfő–péntek + tervezett hétvégi munkák) ─
function work_days(string $from, int $count = 5): array {
  // Lekérjük azokat a hétvégi napokat amikre van feladat
  $st = db()->prepare("SELECT DISTINCT task_date FROM tasks WHERE task_date >= ? AND DAYOFWEEK(task_date) IN (1,7) ORDER BY task_date");
  $st->execute([$from]);
  $weekendWithTasks = array_column($st->fetchAll(), 'task_date');

  $days  = [];
  $date  = new DateTime($from);
  $added = 0;
  while ($added < $count) {
    $dow = (int)$date->format('N'); // 1=hétfő, 7=vasárnap
    $ds  = $date->format('Y-m-d');
    if ($dow <= 5 || in_array($ds, $weekendWithTasks, true)) {
      $days[] = $ds;
      $added++;
    }
    $date->modify('+1 day');
  }
  return $days;
}

// ── Feladatok lekérése dátumtartományra ─────────────────
function get_tasks_for_days(array $days): array {
  if (!$days) return [];
  $placeholders = implode(',', array_fill(0, count($days), '?'));
  $st = db()->prepare("
    SELECT t.id, t.title, t.task_date, t.time_from, t.time_to, t.color, t.note,
           t.location_id, l.name AS location_name,
           GROUP_CONCAT(ta.employee_id ORDER BY ta.employee_id) AS employee_ids
    FROM tasks t
    LEFT JOIN locations l ON l.id = t.location_id
    LEFT JOIN task_assignments ta ON ta.task_id = t.id
    WHERE t.task_date IN ($placeholders)
    GROUP BY t.id
    ORDER BY t.task_date, t.time_from, t.id
  ");
  $st->execute($days);
  $rows = $st->fetchAll();
  // employee_ids string → array
  foreach ($rows as &$r) {
    $r['employee_ids'] = $r['employee_ids'] ? array_map('intval', explode(',', $r['employee_ids'])) : [];
  }
  return $rows;
}

// ── Feladatok indexelve: [date][employee_id] = [task, ...] ─
function index_tasks(array $tasks): array {
  $idx = [];
  foreach ($tasks as $t) {
    foreach ($t['employee_ids'] as $eid) {
      $idx[$t['task_date']][$eid][] = $t;
    }
  }
  return $idx;
}

// ── Időpont formázás ────────────────────────────────────
function fmt_time(?string $t): string {
  return $t ? substr($t, 0, 5) : '';
}

// ── Perc konverzió ──────────────────────────────────────
function task_time_to_min(string $t): int {
  [$h, $m] = array_map('intval', explode(':', substr($t, 0, 5)));
  return $h * 60 + $m;
}

// ── Átfedő feladatok ID-je (egy cella task tömbjéből) ───
// Visszaadja azon task ID-k halmazát, amelyek legalább egy másikkal átfednek.
function overlapping_task_ids(array $tasks): array {
  $result = [];
  $timed  = array_values(array_filter($tasks, fn($t) => !empty($t['time_from'])));
  $n      = count($timed);
  for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
      $a     = $timed[$i];
      $b     = $timed[$j];
      $aFrom = task_time_to_min($a['time_from']);
      $aTo   = !empty($a['time_to']) ? task_time_to_min($a['time_to']) : 1440;
      $bFrom = task_time_to_min($b['time_from']);
      $bTo   = !empty($b['time_to']) ? task_time_to_min($b['time_to']) : 1440;
      if ($aFrom < $bTo && $bFrom < $aTo) {
        $result[$a['id']] = true;
        $result[$b['id']] = true;
      }
    }
  }
  return $result;
}

// ── Szín kontrasztja (fekete vagy fehér szöveg) ─────────
function contrast_color(string $hex): string {
  $hex = ltrim($hex, '#');
  [$r, $g, $b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  $lum = (0.299*$r + 0.587*$g + 0.114*$b) / 255;
  return $lum > 0.55 ? '#1a1a1a' : '#ffffff';
}

// ── Feladatkártya HTML ──────────────────────────────────
function task_card(array $t, bool $adminLinks = false): string {
  $bg   = e($t['color']);
  $fg   = e(contrast_color($t['color']));
  $time = fmt_time($t['time_from']);
  if ($t['time_to']) $time .= '–' . fmt_time($t['time_to']);
  $loc  = $t['location_name'] ? ' · ' . e($t['location_name']) : '';
  $edit = $adminLinks ? '<a href="'.base_url('admin_task_edit.php?id='.((int)$t['id'])).'" class="wp-card-edit" title="Szerkesztés">✎</a>' : '';
  return '<div class="wp-card" style="background:'.$bg.';color:'.$fg.'" title="'.e($t['note'] ?? '').'">'
       . $edit
       . ($time ? '<span class="wp-time">'.$time.'</span> ' : '')
       . '<span class="wp-title">'.e($t['title']).'</span>'
       . ($t['location_name'] ? '<span class="wp-loc">'.$loc.'</span>' : '')
       . '</div>';
}
