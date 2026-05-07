<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Budapest');

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

function touch_last_modified(string $date = ''): void {
  try {
    $pdo = db();
    $pdo->prepare("INSERT INTO config (k,v) VALUES ('last_modified',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
        ->execute([time()]);
    if ($date) {
      $pdo->prepare("INSERT INTO config (k,v) VALUES ('last_modified_date',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
          ->execute([$date]);
    }
  } catch (Throwable) {}
}

function get_last_modified_date(): string {
  try {
    $v = db()->query("SELECT v FROM config WHERE k='last_modified_date'")->fetchColumn();
    return $v ? (string)$v : '';
  } catch (Throwable) { return ''; }
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

// ── Feladatbank lekérése ────────────────────────────────
// $statuses: ha üres, mindent visszaad; ha tömb, csak azokat a státuszokat
function get_tasks_bank(array $statuses = []): array {
  try {
    if ($statuses) {
      $ph = implode(',', array_fill(0, count($statuses), '?'));
      $st = db()->prepare("SELECT id, title, status, system_key, color, note FROM tasks WHERE status IN ($ph) ORDER BY title");
      $st->execute($statuses);
    } else {
      $st = db()->query("SELECT id, title, status, system_key, color, note FROM tasks ORDER BY title");
    }
    return $st->fetchAll();
  } catch (Throwable) { return []; }
}

// ── Feladatok lekérése dátumtartományra ─────────────────
function get_tasks_for_days(array $days): array {
  if (!$days) return [];
  $placeholders = implode(',', array_fill(0, count($days), '?'));
  $st = db()->prepare("
    SELECT t.id, t.title, t.status, t.system_key, t.color, t.note,
           ta.id AS assignment_id, ta.employee_id, ta.task_date
    FROM task_assignments ta
    JOIN tasks t ON t.id = ta.task_id
    WHERE ta.task_date IN ($placeholders)
    ORDER BY ta.task_date, t.title, ta.employee_id
  ");
  $st->execute($days);
  return $st->fetchAll();
}

// ── Feladatok indexelve: [date][employee_id] = [task, ...] ─
function index_tasks(array $tasks): array {
  $idx = [];
  foreach ($tasks as $t) {
    $idx[$t['task_date']][(int)$t['employee_id']][] = $t;
  }
  return $idx;
}

// ── Munkanapok (hétfő–péntek + tervezett hétvégi munkák) ─
function work_days(string $from, int $count = 5): array {
  $st = db()->prepare("SELECT DISTINCT task_date FROM task_assignments WHERE task_date >= ? AND DAYOFWEEK(task_date) IN (1,7) ORDER BY task_date");
  $st->execute([$from]);
  $weekendWithTasks = array_column($st->fetchAll(), 'task_date');

  $days  = [];
  $date  = new DateTime($from);
  $added = 0;
  while ($added < $count) {
    $dow = (int)$date->format('N');
    $ds  = $date->format('Y-m-d');
    if ($dow <= 5 || in_array($ds, $weekendWithTasks, true)) {
      $days[] = $ds;
      $added++;
    }
    $date->modify('+1 day');
  }
  return $days;
}

// ── Szín generálás HSL golden-angle alapon ──────────────
function generate_color(int $index): string {
  $hue = fmod($index * 137.508, 360);
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
  return sprintf('#%02x%02x%02x',
    (int)(($r1 + $m) * 255),
    (int)(($g1 + $m) * 255),
    (int)(($b1 + $m) * 255)
  );
}

function next_task_color(): string {
  $count = (int)db()->query("SELECT COUNT(*) FROM tasks")->fetchColumn();
  return generate_color($count);
}

// ── Szín kontrasztja (fekete vagy fehér szöveg) ─────────
function contrast_color(string $hex): string {
  $hex = ltrim($hex, '#');
  [$r, $g, $b] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
  $lum = (0.299*$r + 0.587*$g + 0.114*$b) / 255;
  return $lum > 0.55 ? '#1a1a1a' : '#ffffff';
}

// ── Feladatkártya HTML (my_tasks.php-hez) ───────────────
function task_card(array $t, bool $adminLinks = false): string {
  $isArchiv  = ($t['status'] ?? '') === 'archív';
  $sysKey    = $t['system_key'] ?? '';
  $sysCls    = match($sysKey) {
    'vacation'   => ' task-vacation',
    'sick_leave' => ' task-sick',
    default      => '',
  };
  $statusCls = match($t['status'] ?? '') {
    'passzív' => ' task-passive',
    'vár'     => ' task-waiting',
    'archív'  => ' task-archived',
    default   => '',
  };
  $emoji = match($sysKey) {
    'vacation'   => '🌴 ',
    'sick_leave' => '🤒 ',
    default      => '',
  };
  $bg  = $isArchiv ? '#9ca3af' : e($t['color']);
  $fg  = $isArchiv ? '#ffffff'  : e(contrast_color($t['color']));
  $edit = ($adminLinks && !$sysKey)
    ? '<a href="'.base_url('admin_task_edit.php?id='.((int)$t['id'])).'" class="tl-edit-lnk" title="Szerkesztés">✎</a>'
    : '';
  return '<div class="tl-task'.$statusCls.$sysCls.'" style="background:'.$bg.';color:'.$fg.'">'
       . $edit
       . '<span class="tl-task-title">'.$emoji.e($t['title']).'</span>'
       . '</div>';
}

// ── Státusz badge HTML ───────────────────────────────────
function status_badge(string $status): string {
  $map = [
    'aktív'   => 'success',
    'passzív' => 'secondary',
    'vár'     => 'warning',
    'archív'  => 'dark',
  ];
  $cls = $map[$status] ?? 'secondary';
  return '<span class="badge bg-' . $cls . '">' . e($status) . '</span>';
}
