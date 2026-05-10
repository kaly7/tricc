<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) { http_response_code(401); echo '{"error":"Nincs bejelentkezve"}'; exit; }

$pidFile = realpath(__DIR__ . '/../../storage/tmp') . '/dm_listener.pid';
$logFile = realpath(__DIR__ . '/../../storage/logs') . '/dm_listener.log';
$script  = realpath(__DIR__ . '/../../cli/dm_listener.php');
$php     = PHP_BINARY;

@mkdir(dirname($pidFile), 0755, true);
@mkdir(dirname($logFile), 0755, true);

function is_running(string $pidFile): array {
  if (!file_exists($pidFile)) return ['running' => false, 'pid' => null];
  $pid = (int)trim((string)file_get_contents($pidFile));
  if ($pid <= 0) return ['running' => false, 'pid' => null];
  // /proc/$pid ellenőrzés – Linux-on megbízható
  $alive = is_dir("/proc/{$pid}");
  if (!$alive) @unlink($pidFile);
  return ['running' => $alive, 'pid' => $alive ? $pid : null];
}

function do_start(string $php, string $script, string $logFile, string $pidFile): array {
  // setsid: új session → leválik a web szerver process groupjától
  // A script maga írja a PID fájlt (getmypid())
  $cmd = "setsid {$php} " . escapeshellarg($script)
       . " >> " . escapeshellarg($logFile)
       . " 2>&1 &";

  exec($cmd);

  // Várunk max 4 másodpercet, hogy a script felálljon és írja a PID fájlt
  for ($i = 0; $i < 8; $i++) {
    usleep(500000); // 0.5 sec
    $st = is_running($pidFile);
    if ($st['running']) return $st;
  }

  return ['running' => false, 'pid' => null];
}

function do_stop(string $pidFile): bool {
  $st = is_running($pidFile);
  if (!$st['running']) return true;
  exec('kill ' . (int)$st['pid']);
  usleep(800000);
  @unlink($pidFile);
  return true;
}

function get_log(string $logFile, int $lines = 40): string {
  if (!file_exists($logFile)) return '(még nincs napló)';
  $all = file($logFile, FILE_IGNORE_NEW_LINES);
  if (!$all) return '(üres napló)';
  return implode("\n", array_slice($all, -$lines));
}

$action = trim($_GET['action'] ?? 'status');

switch ($action) {

  case 'status':
    $st = is_running($pidFile);
    echo json_encode([
      'running' => $st['running'],
      'pid'     => $st['pid'],
      'log'     => get_log($logFile),
    ], JSON_UNESCAPED_UNICODE);
    break;

  case 'start':
    $st = is_running($pidFile);
    if ($st['running']) {
      echo json_encode(['ok' => true, 'msg' => 'Már fut (PID: ' . $st['pid'] . ')', 'pid' => $st['pid']], JSON_UNESCAPED_UNICODE);
      break;
    }
    $st = do_start($php, $script, $logFile, $pidFile);
    if ($st['running']) {
      echo json_encode(['ok' => true, 'msg' => '▶ Elindítva (PID: ' . $st['pid'] . ')', 'pid' => $st['pid']], JSON_UNESCAPED_UNICODE);
    } else {
      // Diagnosztika: próbáljuk ellenőrizni mi a gond
      $testOut = shell_exec("setsid {$php} --version 2>&1") ?? 'n/a';
      echo json_encode(['ok' => false, 'msg' => 'Indítás sikertelen. PHP teszt: ' . trim($testOut)], JSON_UNESCAPED_UNICODE);
    }
    break;

  case 'stop':
    $st = is_running($pidFile);
    if (!$st['running']) {
      echo json_encode(['ok' => false, 'msg' => 'Nem fut.'], JSON_UNESCAPED_UNICODE);
      break;
    }
    do_stop($pidFile);
    echo json_encode(['ok' => true, 'msg' => '⏹ Leállítva.'], JSON_UNESCAPED_UNICODE);
    break;

  case 'restart':
    do_stop($pidFile);
    usleep(500000);
    $st = do_start($php, $script, $logFile, $pidFile);
    if ($st['running']) {
      echo json_encode(['ok' => true, 'msg' => '🔄 Újraindítva (PID: ' . $st['pid'] . ')', 'pid' => $st['pid']], JSON_UNESCAPED_UNICODE);
    } else {
      echo json_encode(['ok' => false, 'msg' => 'Újraindítás sikertelen.'], JSON_UNESCAPED_UNICODE);
    }
    break;

  default:
    echo json_encode(['error' => 'Ismeretlen action.'], JSON_UNESCAPED_UNICODE);
}
