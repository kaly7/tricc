<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/helpers.php';
require_once __DIR__.'/../src/records_filter.php';

$params = [];
$f = build_records_filter($params, $_REQUEST); // <-- itt a lényeg

$sql = "SELECT r.id, r.eventus, r.issued_at, r.due_at, r.address, r.operation,r.archived,
               ps.name AS pp_name, c.name AS city_name
        FROM records r
        JOIN pp_status ps ON ps.id=r.pp_status_id
        JOIN cities c ON c.id=r.city_id
        WHERE {$f['where']}
        ORDER BY {$f['order']}, r.id";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$fn = 'pp_records_'.date('Ymd_His').'.csv';


header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fn.'"');


$out = fopen('php://output','w');
fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));


// --- Szűrés leírás CSV elejére (névvel) ---
$A = $_REQUEST;
$ppIds   = (array)($A['pp_status_id'] ?? []);
$ppMode  = ($A['pp_mode'] ?? (($A['pp_exc'] ?? null) ? 'exclude' : 'include'));
$cityIds = (array)($A['city_id'] ?? []);
$cityMode= ($A['city_mode'] ?? (($A['city_exc'] ?? null) ? 'exclude' : 'include'));

if (!$ppIds)   $ppIds   = (array)($A['pp_inc'] ?? []);
if (!$cityIds) $cityIds = (array)($A['city_inc'] ?? []);

$ppNames   = names_for_ids('pp_status', $ppIds);
$cityNames = names_for_ids('cities',    $cityIds);

$archText = (($A['arch'] ?? 'no') === 'yes') ? 'Archív is' : 'Csak aktív';
$sortText = match($A['sort'] ?? 'issued_at') {
  'pp_status' => 'Rendezés: PP-státusz',
  'eventus'   => 'Rendezés: Eventus',
  'due_at'    => 'Rendezés: +38 nap',
  'city'      => 'Rendezés: Város',
  default     => 'Rendezés: Kiadva'
};

$filters = [];
if (!empty($A['q']))   $filters[] = "Keresés: ".$A['q'];
if ($ppNames)          $filters[] = 'PP-státusz '.($ppMode==='exclude'?'kivéve: ':'csak: ').implode(', ', $ppNames);
if ($cityNames)        $filters[] = 'Város '.($cityMode==='exclude'?'kivéve: ':'csak: ').implode(', ', $cityNames);
$filters[] = $archText;
$filters[] = $sortText;

// Írjuk a CSV elejére:
fputcsv($out, ["Export dátum: ".date('Y-m-d H:i')]);
fputcsv($out, ["Szűrés: ".($filters ? implode(' | ', $filters) : 'nincs')]);
fputcsv($out, []); // üres elválasztó sor







fputcsv($out, ['Eventus','PP-státusz','Kiadva','+38 nap','Város','Cím','Elvégzendő művelet','Archiv']);

foreach($rows as $r){
  fputcsv($out, [
    $r['eventus'],
    $r['pp_name'],
    $r['issued_at'],
    $r['due_at'],
    $r['city_name'],
    $r['address'],
    $r['operation'],
    $r['archived'] ? 'Archiv' : ' '   // vagy 'Igen' / 'Nem'
  ]);
}
fclose($out);