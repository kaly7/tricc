<?php
require __DIR__.'/includes/init.php';
header('Content-Type: text/plain; charset=utf-8');

echo "DB DSN: ".($config['db']['dsn'] ?? '')."\n";
$user = current_user($pdo);
echo "Bejelentkezve: ".($user ? ($user['email'] ?? $user['name'] ?? 'ismeretlen') : 'NEM')."\n";

$tables = ['audit_log','task_field_notes','tasks','users'];
foreach ($tables as $t) {
  try {
    $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t: OK ($c sor)\n";
  } catch (Throwable $e) {
    echo "$t: HIBA - ".$e->getMessage()."\n";
  }
}

echo "\nCSRF token: ".(isset($_SESSION['csrf']) ? 'OK' : 'HIÁNYZIK')."\n";