<?php
require_once __DIR__.'/db.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo '[]'; exit; }

$db = db();
$s  = $db->prepare('SELECT id,megnevezes,gyarto,tipus,rendeles_szam,egyseg,anyagar_egyseg,munkadij_egyseg
  FROM anyagar_katalogus WHERE megnevezes LIKE ? ORDER BY megnevezes LIMIT 30');
$s->execute(['%'.$q.'%']);
echo json_encode($s->fetchAll(), JSON_UNESCAPED_UNICODE);
