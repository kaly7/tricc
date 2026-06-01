<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

// config (pl. figyelmeztetési küszöbök)
$cfg = require dirname(__DIR__).'/config/config.php';

$u = Auth::user();
$isAdmin = isset($u['role_id']) && (int)$u['role_id']===1;

$extraOpen = $isAdmin && (($_GET['extra'] ?? '') === 'open');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) { http_response_code(400); exit('Hibás ID'); }

// Aktív fül megőrzése (szűrés / újratöltés után is)
$tab = $_GET['tab'] ?? 'issues';
$allowedTabs = ['issues','service','tires','costs','fuel','log','km'];
if (!in_array($tab, $allowedTabs, true)) { $tab = 'issues'; }

// Lapozás (fülenként külön paraméter)
$PER_PAGE = 10;
function clamp_int($v,$min,$max){ $v=(int)$v; if($v<$min) return $min; if($v>$max) return $max; return $v; }
function pager_url($overrides){
  $p = $_GET;
  foreach($overrides as $k=>$v){ if($v===null) unset($p[$k]); else $p[$k]=$v; }
  return 'vehicle.php?'.http_build_query($p);
}
function render_pager($page,$pages,$param,$tab,$navClass='mt-2'){
  if($pages<=1) return;
  echo '<nav class="'.h($navClass).'"><ul class="pagination pagination-sm mb-0">';
  $prev = ($page>1)?$page-1:1;
  $next = ($page<$pages)?$page+1:$pages;
  $disabledPrev = ($page<=1)?' disabled':'';
  $disabledNext = ($page>=$pages)?' disabled':'';
  echo '<li class="page-item'.$disabledPrev.'"><a class="page-link" href="'.h(pager_url(['tab'=>$tab,$param=>$prev])).'#tab_'.$tab.'">&laquo;</a></li>';
  $start=max(1,$page-3); $end=min($pages,$page+3);
  if($start>1){
    echo '<li class="page-item"><a class="page-link" href="'.h(pager_url(['tab'=>$tab,$param=>1])).'#tab_'.$tab.'">1</a></li>';
    if($start>2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
  }
  for($i=$start;$i<=$end;$i++){
    $act = ($i===$page)?' active':'';
    echo '<li class="page-item'.$act.'"><a class="page-link" href="'.h(pager_url(['tab'=>$tab,$param=>$i])).'#tab_'.$tab.'">'.$i.'</a></li>';
  }
  if($end<$pages){
    if($end<$pages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
    echo '<li class="page-item"><a class="page-link" href="'.h(pager_url(['tab'=>$tab,$param=>$pages])).'#tab_'.$tab.'">'.$pages.'</a></li>';
  }
  echo '<li class="page-item'.$disabledNext.'"><a class="page-link" href="'.h(pager_url(['tab'=>$tab,$param=>$next])).'#tab_'.$tab.'">&raquo;</a></li>';
  echo '</ul></nav>';
}

function render_pager_bar($page,$pages,$param,$tab,$total,$perPage){
  if($total<=0) return;
  $from = ($page-1)*$perPage + 1;
  $to = min($total, $page*$perPage);
  echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 my-2">';
  echo '<div class="text-muted small">'.h($from).'–'.h($to).' / '.h($total).' tétel</div>';
  if($pages>1) render_pager($page,$pages,$param,$tab,'m-0');
  echo '</div>';
}

$st = $pdo->prepare("SELECT v.*, vt.name AS type_name, d.name AS division_name
                     FROM vehicles v
                     JOIN vehicle_types vt ON vt.id=v.vehicle_type_id
                     LEFT JOIN vehicle_divisions d ON d.id=v.division_id
                     WHERE v.id=?");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { http_response_code(404); exit('Jármű nem található'); }

// ===== CSV export (fülönként) =====
$doExport = (($_GET['export'] ?? '') === 'csv');
if ($doExport) {
  // admin-only tabs
  if (in_array($tab, ['costs','log'], true) && !$isAdmin) {
    http_response_code(403);
    exit('Nincs jogosultság a CSV exporthoz.');
  }

  $scope = ($_GET['export_scope'] ?? 'all'); // all|page
  if (!in_array($scope, ['all','page'], true)) $scope = 'all';

  $perPage = $PER_PAGE;
  $pageMap = [
    'issues'  => 'issues_page',
    'service' => 'service_page',
    'tires'   => 'tires_page',
    'costs'   => 'costs_page',
    'fuel'    => 'fuel_page',
    'log'     => 'log_page',
    'km'      => 'km_page',
  ];
  $pageParam = $pageMap[$tab] ?? null;
  $page = 1;
  if ($scope === 'page' && $pageParam) {
    $page = max(1, (int)($_GET[$pageParam] ?? 1));
  }
  $offset = ($page-1) * $perPage;
  $limitSql = ($scope === 'page') ? " LIMIT $perPage OFFSET $offset " : "";

  // filename
  $plate = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($v['plate'] ?? ('vehicle_'.$id)));
  $ts = date('Ymd_His');
  $fn = "{$plate}_{$tab}_{$ts}.csv";

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  header('Pragma: no-cache');
  header('Expires: 0');

  // Excel-friendly UTF-8 BOM
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  $delim = ';';

  $rows = [];
  try {
    if ($tab === 'issues') {
      fputcsv($out, ['Bejelentés dátuma','Leírás','Javítva','Rögzítve'], $delim);
      $q = "SELECT reported_date, description, fixed_date, created_at
            FROM vehicle_issues
            WHERE vehicle_id=?
            ORDER BY reported_date DESC, id DESC".$limitSql;
      $st = $pdo->prepare($q);
      $st->execute([$id]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['reported_date'], $r['description'], $r['fixed_date'], $r['created_at']], $delim);
      }
    } elseif ($tab === 'service') {
      fputcsv($out, ['Dátum','Km','Szállító','Leírás','Munka','Anyag','Számla'], $delim);
      $q = "SELECT service_date, odometer_km, vendor_name, description, labor_cost, material_cost, invoice_no
            FROM vehicle_service_entries
            WHERE vehicle_id=?
            ORDER BY service_date DESC, id DESC".$limitSql;
      $st = $pdo->prepare($q);
      $st->execute([$id]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['service_date'],$r['odometer_km'],$r['vendor_name'],$r['description'],$r['labor_cost'],$r['material_cost'],$r['invoice_no']], $delim);
      }
    } elseif ($tab === 'tires') {
      fputcsv($out, ['Dátum','Km','Leírás','Rögzítve'], $delim);
      $q = "SELECT change_date, odometer_km, description, created_at
            FROM vehicle_tires
            WHERE vehicle_id=?
            ORDER BY change_date DESC, id DESC".$limitSql;
      $st = $pdo->prepare($q);
      $st->execute([$id]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['change_date'],$r['odometer_km'],$r['description'],$r['created_at']], $delim);
      }
    } elseif ($tab === 'costs') {
      $cost_from = trim((string)($_GET['cost_from'] ?? ''));
      $cost_to   = trim((string)($_GET['cost_to'] ?? ''));
      $cost_vendor = trim((string)($_GET['cost_vendor'] ?? ''));

      fputcsv($out, ['Dátum','Km','Szállító','Leírás','Munka','Anyag','Mind','Számla'], $delim);

      $where = ["vehicle_id=?"];
      $params = [$id];
      if ($cost_from !== '') { $where[] = "service_date >= ?"; $params[] = $cost_from; }
      if ($cost_to !== '') { $where[] = "service_date <= ?"; $params[] = $cost_to; }
      if ($cost_vendor !== '') { $where[] = "LOWER(vendor_name) LIKE ?"; $params[] = '%'.mb_strtolower($cost_vendor,'UTF-8').'%'; }

      $q = "SELECT service_date, odometer_km, vendor_name, description, labor_cost, material_cost, invoice_no
            FROM vehicle_service_entries
            WHERE ".implode(" AND ", $where)."
            ORDER BY service_date DESC, id DESC".$limitSql;
      $st = $pdo->prepare($q);
      $st->execute($params);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $labor = (float)($r['labor_cost'] ?? 0);
        $mat   = (float)($r['material_cost'] ?? 0);
        fputcsv($out, [$r['service_date'],$r['odometer_km'],$r['vendor_name'],$r['description'],$r['labor_cost'],$r['material_cost'],$labor+$mat,$r['invoice_no']], $delim);
      }
    } elseif ($tab === 'fuel') {
      fputcsv($out, ['Dátum','Km','Termék','Liter','Összeg','Hely','Import'], $delim);
      $maxAll = 5000;
      $q = "SELECT fueled_at, odometer_km, fuel_product, quantity_l, gross_huf, station_name, import_id
            FROM vehicle_fuel_entries
            WHERE vehicle_id=?
            ORDER BY fueled_at DESC, id DESC".
            (($scope === 'page') ? $limitSql : " LIMIT ".$maxAll);
      $st = $pdo->prepare($q);
      $st->execute([$id]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['fueled_at'],$r['odometer_km'],$r['fuel_product'],$r['quantity_l'],$r['gross_huf'],$r['station_name'],$r['import_id']], $delim);
      }
    } elseif ($tab === 'log') {
      fputcsv($out, ['Dátum','Felhasználó','Entitás','Akció','Művelet','Részletek'], $delim);
      $maxAll = 500;
      $q = "SELECT created_at, actor_name, actor_email, entity_type, action, verb, changed_fields
            FROM audit_log
            WHERE changed_fields LIKE ?
            ORDER BY created_at DESC, id DESC".
            (($scope === 'page') ? $limitSql : " LIMIT ".$maxAll);
      $st = $pdo->prepare($q);
      $st->execute(['%"vehicle_id":'.$id.'%']);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $actor = (string)($r['actor_name'] ?? '');
        if ($actor === '') $actor = (string)($r['actor_email'] ?? '');
        fputcsv($out, [$r['created_at'],$actor,$r['entity_type'],$r['action'],$r['verb'],$r['changed_fields']], $delim);
      }
    } elseif ($tab === 'km') {
      fputcsv($out, ['Dátum','Összes km','Szakaszok száma','Rögzítve'], $delim);
      $q = "SELECT km_date, total_km, trip_count, fetched_at
            FROM vehicle_daily_km
            WHERE vehicle_id=?
            ORDER BY km_date DESC".$limitSql;
      $st = $pdo->prepare($q);
      $st->execute([$id]);
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [$r['km_date'], $r['total_km'], $r['trip_count'], $r['fetched_at']], $delim);
      }
    } else {
      fputcsv($out, ['Nincs exportálható tartalom ehhez a fülhöz.'], $delim);
    }
  } catch (Throwable $e) {
    fputcsv($out, ['Hiba: '.$e->getMessage()], $delim);
  }

  fclose($out);
  exit;
}


function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtKm($n){ return number_format((int)$n,0,'.',' '); }
function fuelLabel($f){
  return match($f){
    'petrol' => 'benzin',
    'diesel' => 'diesel',
    'electric' => 'elektromos',
    'hybrid' => 'hibrid',
    default => $f
  };
}
function badge($text,$cls){ return '<span class="badge bg-'.$cls.'">'.$text.'</span>'; }

function vget($arr, $keys, $default=null) {
  foreach ($keys as $k) { if (array_key_exists($k, $arr) && $arr[$k] !== null && $arr[$k] !== '') return $arr[$k]; }
  return $default;
}
function actionHu($a){
  $a = (string)$a;
  return match($a){
    'create' => 'Létrehozás',
    'update' => 'Módosítás',
    'upload' => 'Feltöltés',
    'delete' => 'Törlés',
    default => $a
  };
}
function entityHu($t){
  $t = (string)$t;
  return match($t){
    'vehicle' => 'Jármű',
    'vehicle_service' => 'Szerviz bejegyzés',
    'vehicle_issue' => 'Hiba bejelentés',
    'vehicle_tire' => 'Gumi',
    'vehicle_tire_install' => 'Gumi csere / felszerelés',
    'vehicle_image' => 'Jármű fotó',
    'vehicle_doc_registration' => 'Forgalmi dokumentum',
    default => $t
  };
}
function boolHu($v){
  return ((int)$v===1 || $v===true || $v==='1') ? 'igen' : 'nem';
}
function kmHu($n){
  if ($n===null || $n==='') return '';
  $n = (int)$n;
  return number_format($n,0,'.',' ').' km';
}
function bytesHu($n){
  if ($n===null || $n==='') return '';
  $n = (float)$n;
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  while ($n >= 1024 && $i < count($units)-1) { $n /= 1024; $i++; }
  $dec = ($i===0) ? 0 : 1;
  return number_format($n,$dec,'.',' ').' '.$units[$i];
}
function summarizeChangedFields($json){
  $out = [];
  if (!$json) return $out;
  $data = json_decode((string)$json, true);
  if (!is_array($data)) return $out;

  $map = [
    'service_date'   => 'Dátum',
    'reported_date'  => 'Bejelentés dátuma',
    'fixed_date'     => 'Javítás dátuma',
    'odometer_km'    => 'Km óra',
    'vendor_name'    => 'Szállító',
    'invoice_no'     => 'Számlaszám',
    'description'    => 'Leírás',
    'materials'      => 'Anyagok',
    'labor_cost'     => 'Munka költség',
    'material_cost'  => 'Anyag költség',
    'reset_oil'      => 'Olaj periódus reset',
    'reset_service'  => 'Szerviz periódus reset',
    'axle_no'        => 'Tengely',
    'position_no'    => 'Pozíció',
    'tire_kind'      => 'Gumi típusa',
    'tire_size'      => 'Méret',
    'brand'          => 'Márka',
    'tire_model'     => 'Modell',
    'dot_code'       => 'DOT',
    'installed_date' => 'Felszerelés dátuma',
    'installed_km'   => 'Felszerelés km',
    'removed_date'   => 'Levétel dátuma',
    'removed_km'     => 'Levétel km',
    'file'           => 'Fájl',
    'mime'           => 'Típus',
    'size'           => 'Méret',
  ];

  foreach ($map as $k=>$label){
    if (!array_key_exists($k, $data)) continue;
    $v = $data[$k];
    if ($k==='odometer_km' || str_ends_with($k,'_km')) $v = kmHu($v);
    if ($k==='size') $v = bytesHu($v);
    if ($k==='reset_oil' || $k==='reset_service') $v = boolHu($v);
    if ($k==='tire_kind') {
      $v = match((string)$v){
        'winter'=>'téli','summer'=>'nyári','allseason'=>'4 évszakos','general'=>'általános', default=>(string)$v
      };
    }
    if ($v===null || $v==='') continue;
    $out[] = $label.': '.$v;
  }
  return $out;
}


$odo = (int)($v['odometer_km'] ?? 0);

// ===== Due / alerts calculations (oil + service) =====
$oil_due_km = null; $oil_remaining_km = null;
$srv_due_km = null; $srv_remaining_km = null;
$srv_due_date = null; $srv_remaining_days = null;

$oil_interval = (int)($v['oil_interval_km'] ?? 0);
$last_oil_km = $v['last_oil_km'] ?? null;
$last_oil_km = ($last_oil_km===null || $last_oil_km==='') ? null : (int)$last_oil_km;

if ($oil_interval > 0 && $last_oil_km !== null) {
  $oil_due_km = $last_oil_km + $oil_interval;
  $oil_remaining_km = $oil_due_km - $odo;
}

// service (km)
$service_int_km = $v['service_interval_km'] ?? null;
$service_int_km = ($service_int_km===null || $service_int_km==='') ? null : (int)$service_int_km;
$last_srv_km = $v['last_service_km'] ?? null;
$last_srv_km = ($last_srv_km===null || $last_srv_km==='') ? null : (int)$last_srv_km;

if ($service_int_km !== null && $service_int_km > 0 && $last_srv_km !== null) {
  $srv_due_km = $last_srv_km + $service_int_km;
  $srv_remaining_km = $srv_due_km - $odo;
}

// service (time)
$service_int_mo = $v['service_interval_months'] ?? null;
$service_int_mo = ($service_int_mo===null || $service_int_mo==='') ? null : (int)$service_int_mo;
$last_srv_date = $v['last_service_date'] ?? null;

if ($service_int_mo !== null && $service_int_mo > 0 && !empty($last_srv_date)) {
  try {
    $d0 = new DateTimeImmutable($last_srv_date);
    $srv_due_date = $d0->modify('+'.$service_int_mo.' months')->format('Y-m-d');
    $due = new DateTimeImmutable($srv_due_date);
    $today = new DateTimeImmutable(date('Y-m-d'));
    $srv_remaining_days = (int)$today->diff($due)->format('%r%a'); // negative if overdue
  } catch (Throwable $e) {
    $srv_due_date = null; $srv_remaining_days = null;
  }
}


// Axles config
$axRows = [];
try {
  $ax = $pdo->prepare("SELECT axle_no, wheels_count FROM vehicle_axles WHERE vehicle_id=? ORDER BY axle_no");
  $ax->execute([$id]);
  $axRows = $ax->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $axRows = []; }

// ===== TIRES (safe if tables missing) =====
$tires = []; $inst = []; $tireStats = []; $seasonHint = null;
try {
  $tt = $pdo->prepare("SELECT * FROM vehicle_tires WHERE vehicle_id=? ORDER BY is_archived ASC, id DESC");
  $tt->execute([$id]);
  $tires = $tt->fetchAll(PDO::FETCH_ASSOC);

// Tires pagination (do not modify $tires; stats use full list)
$tires_total = count($tires);
$tires_pages = max(1, (int)ceil($tires_total / $PER_PAGE));
$tires_page = clamp_int($_GET['tires_page'] ?? 1, 1, $tires_pages);
$tires_page_rows = array_slice($tires, ($tires_page-1)*$PER_PAGE, $PER_PAGE);

  $it = $pdo->prepare("SELECT i.*, t.tire_kind, t.brand, t.tire_model, t.tire_size, t.dot_code, t.is_archived
                       FROM vehicle_tire_installations i
                       JOIN vehicle_tires t ON t.id=i.tire_id
                       WHERE i.vehicle_id=? AND i.removed_date IS NULL
                       ORDER BY i.axle_no, i.position_no");
  $it->execute([$id]);
  foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $inst[$r['axle_no'].'-'.$r['position_no']] = $r;
  }

  $qs = $pdo->prepare("SELECT tire_id, installed_date, installed_km, removed_date, removed_km
                       FROM vehicle_tire_installations
                       WHERE vehicle_id=?
                       ORDER BY id");
  $qs->execute([$id]);
  foreach ($qs->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tid = (int)$r['tire_id'];
    $km1 = (int)$r['installed_km'];
    $km2 = ($r['removed_km']!==null) ? (int)$r['removed_km'] : $odo;
    $d1 = $r['installed_date'];
    $d2 = $r['removed_date'] ?? date('Y-m-d');
    $km = max(0, $km2 - $km1);
    $days = 0;
    try { $days = (int)(new DateTime($d1))->diff(new DateTime($d2))->format('%a'); } catch (Throwable $e) {}
    if (!isset($tireStats[$tid])) $tireStats[$tid] = ['km'=>0,'days'=>0];
    $tireStats[$tid]['km'] += $km;
    $tireStats[$tid]['days'] += $days;
  }

  $month = (int)date('n');
  $isWinterSeason = ($month>=10 || $month<=3);
  $bad = 0; $total=0;
  foreach ($inst as $r) {
    $total++;
    $kind = $r['tire_kind'];
    if ($isWinterSeason) {
      if (!in_array($kind, ['winter','allseason','general'], true)) $bad++;
    } else {
      if ($kind==='winter') $bad++;
    }
  }
  if ($total>0 && $bad>0) {
    $seasonHint = $isWinterSeason
      ? 'Téli szezon van (okt–márc), de van olyan felszerelt gumi ami nem téli/4 évszakos.'
      : 'Nyári szezon van (ápr–szept), de van olyan felszerelt gumi ami téli.';
  }
} catch (Throwable $e) {
  // ignore
}

function tireKindHu($k){
  return match($k){
    'winter' => 'téli',
    'summer' => 'nyári',
    'allseason' => '4 évszakos',
    'general' => 'általános',
    default => $k
  };
}
function posLabel($pos, $maxPos){
  if ($maxPos===2) return $pos===1 ? 'Bal' : 'Jobb';
  return match($pos){
    1 => 'Bal külső',
    2 => 'Bal belső',
    3 => 'Jobb belső',
    4 => 'Jobb külső',
    default => 'Poz '.$pos
  };
}

// ===== ISSUES (safe) =====
$issues = [];
try {
  $is = $pdo->prepare("SELECT vi.*, u.name AS created_by_name
                       FROM vehicle_issues vi
                       LEFT JOIN users u ON u.id = vi.created_by
                       WHERE vi.vehicle_id=?
                       ORDER BY vi.reported_date DESC, vi.id DESC");
  $is->execute([$id]);
  $issues = $is->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $issues = []; }

// Issues pagination
$issues_total = count($issues);
$issues_pages = max(1, (int)ceil($issues_total / $PER_PAGE));
$issues_page = clamp_int($_GET['issues_page'] ?? 1, 1, $issues_pages);
$issues = array_slice($issues, ($issues_page-1)*$PER_PAGE, $PER_PAGE);

// ===== SERVICE (safe) =====
$services = [];
try {
  $ss = $pdo->prepare("SELECT se.*, u.name AS created_by_name
                       FROM vehicle_service_entries se
                       LEFT JOIN users u ON u.id=se.created_by
                       WHERE se.vehicle_id=?
                       ORDER BY se.service_date DESC, se.id DESC");
  $ss->execute([$id]);
  $services = $ss->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $services = []; }

// Services pagination (do not modify $services; it's used elsewhere)
$services_total = count($services);
$services_pages = max(1, (int)ceil($services_total / $PER_PAGE));
$services_page = clamp_int($_GET['service_page'] ?? 1, 1, $services_pages);
$services_page_rows = array_slice($services, ($services_page-1)*$PER_PAGE, $PER_PAGE);

// ===== FUEL (safe) =====
$fuels = [];
try {
  $fs = $pdo->prepare("SELECT f.*, u.name AS created_by_name
                       FROM vehicle_fuel_entries f
                       LEFT JOIN users u ON u.id=f.created_by
                       WHERE f.vehicle_id=?
                       ORDER BY f.fueled_at DESC, f.id DESC
                       LIMIT 500");
  $fs->execute([$id]);
  $fuels = $fs->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $fuels = []; }

// Fuel pagination
$fuels_total = count($fuels);
$fuels_pages = max(1, (int)ceil($fuels_total / $PER_PAGE));
$fuels_page = clamp_int($_GET['fuel_page'] ?? 1, 1, $fuels_pages);
$fuels = array_slice($fuels, ($fuels_page-1)*$PER_PAGE, $PER_PAGE);

// ===== Costs filters =====
$cost_from = trim((string)($_GET['cost_from'] ?? ''));
$cost_to   = trim((string)($_GET['cost_to'] ?? ''));
$cost_vendor = trim((string)($_GET['cost_vendor'] ?? ''));

$services_cost = $services;
if ($services_cost) {
  $services_cost = array_values(array_filter($services_cost, function($s) use ($cost_from,$cost_to,$cost_vendor) {
    $d = (string)($s['service_date'] ?? '');
    if ($cost_from !== '' && $d < $cost_from) return false;
    if ($cost_to !== '' && $d > $cost_to) return false;
    if ($cost_vendor !== '') {
      $vn = mb_strtolower((string)($s['vendor_name'] ?? ''), 'UTF-8');
      $cv = mb_strtolower($cost_vendor, 'UTF-8');
      if ($cv !== '' && mb_strpos($vn, $cv, 0, 'UTF-8') === false) return false;
    }
    return true;
  }));
}

// Costs (service_cost) pagination
$services_cost_total = count($services_cost);
$services_cost_pages = max(1, (int)ceil($services_cost_total / $PER_PAGE));
$services_cost_page = clamp_int($_GET['costs_page'] ?? 1, 1, $services_cost_pages);
$services_cost_page_rows = array_slice($services_cost, ($services_cost_page-1)*$PER_PAGE, $PER_PAGE);

// vendor suggestions
$vendorOptions = [];
foreach ($services as $s) {
  $vn = trim((string)($s['vendor_name'] ?? ''));
  if ($vn !== '') $vendorOptions[$vn] = true;
}
$vendorOptions = array_keys($vendorOptions);
sort($vendorOptions, SORT_NATURAL | SORT_FLAG_CASE);

// ===== Audit log (LIKE-only, matches stored JSON text reliably) =====
$logs = [];
$log_err = null;
try {
  $like = '%"vehicle_id":'.$id.'%';
  $sqlLog = "
    SELECT a.*, u.name AS user_name, u.email AS user_email
    FROM audit_log a
    LEFT JOIN users u ON u.id=a.user_id
    WHERE a.changed_fields LIKE ?
    ORDER BY a.created_at DESC
    LIMIT 1000
  ";
  $stl = $pdo->prepare($sqlLog);
  $stl->execute([$like]);
  $logs = $stl->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $log_err = $e->getMessage();
  $logs = [];
}

// Log pagination (admin)
$logs_total = count($logs);
$logs_pages = max(1, (int)ceil($logs_total / $PER_PAGE));
$logs_page = clamp_int($_GET['log_page'] ?? 1, 1, $logs_pages);
$logs_page_rows = array_slice($logs, ($logs_page-1)*$PER_PAGE, $PER_PAGE);

// ===== KM FUTÁS (Multi Alarm GPS) =====
$km_rows = []; $km_total_count = 0; $km_pages = 1; $km_page = 1;
$km_filter_from = trim((string)($_GET['km_from'] ?? ''));
$km_filter_to   = trim((string)($_GET['km_to'] ?? ''));
$km_period_label = '';
$km_period_total = 0.0;
$km_period_days  = 0;
try {
  $km_where = ['vehicle_id=?'];
  $km_params = [$id];
  if ($km_filter_from !== '') { $km_where[] = 'km_date >= ?'; $km_params[] = $km_filter_from; }
  if ($km_filter_to !== '')   { $km_where[] = 'km_date <= ?'; $km_params[] = $km_filter_to; }
  $km_where_sql = implode(' AND ', $km_where);

  $km_count_st = $pdo->prepare("SELECT COUNT(*), SUM(total_km) FROM vehicle_daily_km WHERE $km_where_sql");
  $km_count_st->execute($km_params);
  [$km_total_count, $km_period_total] = $km_count_st->fetch(PDO::FETCH_NUM);
  $km_total_count  = (int)$km_total_count;
  $km_period_total = (float)$km_period_total;
  $km_period_days  = $km_total_count;

  $km_pages = max(1, (int)ceil($km_total_count / $PER_PAGE));
  $km_page  = clamp_int($_GET['km_page'] ?? 1, 1, $km_pages);
  $km_offset = ($km_page - 1) * $PER_PAGE;

  $km_st = $pdo->prepare("SELECT km_date, total_km, trip_count, fetched_at FROM vehicle_daily_km WHERE $km_where_sql ORDER BY km_date DESC LIMIT $PER_PAGE OFFSET $km_offset");
  $km_st->execute($km_params);
  $km_rows = $km_st->fetchAll();
} catch (Throwable $e) { $km_rows = []; }

function statusClassKm($remaining){
  if ($remaining===null) return 'secondary';
  if ($remaining < 0) return 'danger';
  if ($remaining <= 500) return 'warning';
  return 'success';
}
function statusClassDays($days){
  if ($days===null) return 'secondary';
  if ($days < 0) return 'danger';
  if ($days <= 14) return 'warning';
  return 'success';
}

// ===== Műszaki vizsga esedékesség (napok) =====
$tech_valid_until = $v['tech_valid_until'] ?? null;
$tech_remaining_days = null;
$tech_warn_days = (int)($cfg['tech_warn_days'] ?? 30);
if (!empty($tech_valid_until)) {
  try {
    $d1 = new DateTime(date('Y-m-d'));
    $d2 = new DateTime((string)$tech_valid_until);
    $tech_remaining_days = (int)$d1->diff($d2)->format('%r%a');
  } catch (Throwable $e) { $tech_remaining_days = null; }
}

function statusClassTech($days, $warnDays){
  if ($days===null) return 'secondary';
  if ($days < 0) return 'danger';
  if ($days <= $warnDays) return 'warning';
  return 'success';
}



require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-0"><?= h($v['license_plate']) ?> — <?= h(trim($v['make'].' '.$v['model'])) ?></h1>
    <div class="text-muted small"><?= h($v['type_name']) ?> • <?= h(fuelLabel($v['fuel_type'])) ?> • Tengely: <?= (int)$v['axle_count'] ?></div>
    <?php if (!empty($v['division_name'])): ?>
      <span class="badge mt-1" style="background:#ffc107;color:#000;font-size:.8rem"><?= h($v['division_name']) ?></span>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="/vehicles.php">Vissza</a>
    <?php if ($isAdmin): ?><a class="btn btn-outline-primary" href="/vehicle_edit.php?id=<?= (int)$id ?>">Szerkesztés</a><?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card p-3">
      <h2 class="h6">Alapadatok</h2>
      <div class="row mb-1"><div class="col-5 text-muted">Rendszám</div><div class="col-7"><?= h($v['license_plate']) ?></div></div>
      <div class="row mb-1"><div class="col-5 text-muted">Fajta</div><div class="col-7"><?= h($v['type_name']) ?></div></div>
      <div class="row mb-1"><div class="col-5 text-muted">Üzemanyag</div><div class="col-7"><?= h(fuelLabel($v['fuel_type'])) ?></div></div>
      <div class="row mb-1"><div class="col-5 text-muted">Km óra</div><div class="col-7"><?= fmtKm($odo) ?></div></div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card p-3">
      <h2 class="h6">Riasztások / Esedékességek</h2>
      <div class="row mb-2">
        <div class="col-6 text-muted">Olajcsere</div>
        <div class="col-6">
          <?= $oil_remaining_km===null ? badge('nincs adat','secondary') :
              badge(($oil_remaining_km<0?'LEJÁRT ':'') . (abs($oil_remaining_km)).' km', statusClassKm($oil_remaining_km)); ?>
          <?php if ($oil_due_km!==null): ?><div class="small text-muted">Esedékes: <?= fmtKm($oil_due_km) ?> km</div><?php endif; ?>
        </div>
      </div>

      <div class="row mb-2">
        <div class="col-6 text-muted">Szerviz (km)</div>
        <div class="col-6">
          <?= $srv_remaining_km===null ? badge('nincs adat','secondary') :
              badge(($srv_remaining_km<0?'LEJÁRT ':'') . (abs($srv_remaining_km)).' km', statusClassKm($srv_remaining_km)); ?>
          <?php if ($srv_due_km!==null): ?><div class="small text-muted">Esedékes: <?= fmtKm($srv_due_km) ?> km</div><?php endif; ?>
        </div>
      </div>

      <div class="row">
        <div class="col-6 text-muted">Szerviz (idő)</div>
        <div class="col-6">
          <?= $srv_remaining_days===null ? badge('nincs adat','secondary') :
              badge(($srv_remaining_days<0?'LEJÁRT ':'') . (abs($srv_remaining_days)).' nap', statusClassDays($srv_remaining_days)); ?>
          <?php if ($srv_due_date!==null): ?><div class="small text-muted">Esedékes: <?= h($srv_due_date) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="row mt-2">
        <div class="col-6 text-muted">Műszaki vizsga</div>
        <div class="col-6">
          <?= $tech_remaining_days===null ? badge('nincs adat','secondary') :
              badge(($tech_remaining_days<0?'LEJÁRT ':'') . (abs($tech_remaining_days)).' nap', statusClassTech($tech_remaining_days, $tech_warn_days)); ?>
          <?php if (!empty($tech_valid_until)): ?><div class="small text-muted">Érvényes: <?= h($tech_valid_until) ?></div><?php endif; ?>
        </div>
      </div>

      <div class="small text-muted mt-2">
        Tipp: Olajcsere/szerviz reset a <b>Szerviz</b> fülön, a checkboxokkal.
      </div>
    </div>
  </div>
</div>


<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">További jármű adatok</h2>
    <?php if ($isAdmin): ?>
      <button class="btn btn-sm btn-outline-secondary" type="button"
              data-bs-toggle="collapse" data-bs-target="#vehExtraCollapse"
              aria-expanded="<?= $extraOpen ? 'true' : 'false' ?>">
        Részletek
      </button>
    <?php endif; ?>
  </div>

  <div class="collapse <?= ($extraOpen || !$isAdmin) ? 'show' : '' ?>" id="vehExtraCollapse">
    <div class="card-body">
  <?php
    $euroClasses=[]; $bodyTypes=[]; $colors=[]; $vignettes=[];
    try { $euroClasses = $pdo->query("SELECT id,name FROM vehicle_euro_classes WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $bodyTypes   = $pdo->query("SELECT id,name FROM vehicle_body_types WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $colors      = $pdo->query("SELECT id,name,hex_code FROM vehicle_colors WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    try { $vignettes   = $pdo->query("SELECT id,name FROM vehicle_vignette_types WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
  ?>

  <?php if (!$isAdmin): ?>
    <div class="row g-2">
      <div class="col-md-4"><div class="small text-muted">Forgalmi engedély száma</div><div><?= h($v['registration_doc_no'] ?? '—') ?></div></div>
      <div class="col-md-4"><div class="small text-muted">Műszaki vizsga érvényessége</div><div><?= h($v['tech_valid_until'] ?? '—') ?></div></div>
      <div class="col-md-4"><div class="small text-muted">EURO besorolás</div><div><?= h($v['euro_class_name'] ?? '—') ?></div></div>

      <div class="col-md-4"><div class="small text-muted">Felépítmény típusa</div><div><?= h($v['body_type_name'] ?? '—') ?></div></div>
      <div class="col-md-2"><div class="small text-muted">Személyek</div><div><?= h($v['seats'] ?? '—') ?></div></div>
      <div class="col-md-3"><div class="small text-muted">Saját tömeg</div><div><?= h($v['curb_weight_kg'] ?? '—') ?> kg</div></div>
      <div class="col-md-3"><div class="small text-muted">Megengedett össztömeg</div><div><?= h($v['gross_weight_kg'] ?? '—') ?> kg</div></div>

      <div class="col-md-4"><div class="small text-muted">Szín</div><div><?= h($v['color_name'] ?? '—') ?></div></div>
      <div class="col-md-2"><div class="small text-muted">Teljesítmény</div><div><?= h($v['power_kw'] ?? '—') ?> kW</div></div>
      <div class="col-md-2"><div class="small text-muted">Gyártási év</div><div><?= h($v['manufacture_year'] ?? '—') ?></div></div>

      <div class="col-md-4"><div class="small text-muted">Autópálya matrica típusa</div><div><?= h($v['vignette_type_name'] ?? '—') ?></div></div>
      <div class="col-md-4"><div class="small text-muted">Matrica érvényessége</div><div><?= h($v['vignette_valid_until'] ?? '—') ?></div></div>
      <div class="col-md-4"><div class="small text-muted">HUGO rendszer</div><div><?= ((int)($v['hugo_enabled'] ?? 0)===1)?'igen':'nem' ?></div></div>
    </div>
  <?php else: ?>
    <form method="post" action="/vehicle_extra_update.php" class="row g-2">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="tab" value="costs">
            <input type="hidden" name="tab" value="costs">
            <input type="hidden" name="tab" value="costs">
      <?= \App\Csrf::field() ?>

      <div class="col-md-4">
        <label class="form-label">Forgalmi engedély száma</label>
        <input class="form-control" name="registration_doc_no" value="<?= h($v['registration_doc_no'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Műszaki vizsga érvényessége</label>
        <input type="date" class="form-control" name="tech_valid_until" value="<?= h($v['tech_valid_until'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">EURO besorolás</label>
        <select class="form-select" name="euro_class_id">
          <option value="">—</option>
          <?php foreach($euroClasses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($v['euro_class_id'] ?? 0)===(int)$c['id'])?'selected':''; ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><a href="/vehicle_euro_classes.php">EURO lista</a></div>
      </div>

      <div class="col-md-4">
        <label class="form-label">Felépítmény típusa</label>
        <select class="form-select" name="body_type_id">
          <option value="">—</option>
          <?php foreach($bodyTypes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($v['body_type_id'] ?? 0)===(int)$c['id'])?'selected':''; ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><a href="/vehicle_body_types.php">Felépítmény lista</a></div>
      </div>

      <div class="col-md-2">
        <label class="form-label">Személyek</label>
        <input type="number" min="1" max="99" class="form-control" name="seats" value="<?= h($v['seats'] ?? '') ?>">
      </div>

      <div class="col-md-3">
        <label class="form-label">Saját tömeg (kg)</label>
        <input type="number" min="0" class="form-control" name="curb_weight_kg" value="<?= h($v['curb_weight_kg'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Megengedett össztömeg (kg)</label>
        <input type="number" min="0" class="form-control" name="gross_weight_kg" value="<?= h($v['gross_weight_kg'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Szín</label>
        <select class="form-select" name="color_id">
          <option value="">—</option>
          <?php foreach($colors as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($v['color_id'] ?? 0)===(int)$c['id'])?'selected':''; ?>>
              <?= h($c['name']) ?><?= !empty($c['hex_code']) ? ' ('.h($c['hex_code']).')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><a href="/vehicle_colors.php">Színek</a></div>
      </div>

      <div class="col-md-2">
        <label class="form-label">Teljesítmény (kW)</label>
        <input type="number" min="0" class="form-control" name="power_kw" value="<?= h($v['power_kw'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Gyártási év</label>
        <input type="number" min="1900" max="2100" class="form-control" name="manufacture_year" value="<?= h($v['manufacture_year'] ?? '') ?>">
      </div>

      <div class="col-md-4">
        <label class="form-label">Autópálya matrica típusa</label>
        <select class="form-select" name="vignette_type_id">
          <option value="">—</option>
          <?php foreach($vignettes as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)($v['vignette_type_id'] ?? 0)===(int)$c['id'])?'selected':''; ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><a href="/vehicle_vignette_types.php">Matrica típusok</a></div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Matrica érvényessége</label>
        <input type="date" class="form-control" name="vignette_valid_until" value="<?= h($v['vignette_valid_until'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">HUGO rendszer</label>
        <select class="form-select" name="hugo_enabled">
          <option value="0" <?= ((int)($v['hugo_enabled'] ?? 0)===0)?'selected':''; ?>>nem</option>
          <option value="1" <?= ((int)($v['hugo_enabled'] ?? 0)===1)?'selected':''; ?>>igen</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Multi Alarm GPS</label>
        <select class="form-select" name="multialarm_enabled">
          <option value="0" <?= ((int)($v['multialarm_enabled'] ?? 0)===0)?'selected':''; ?>>nem</option>
          <option value="1" <?= ((int)($v['multialarm_enabled'] ?? 0)===1)?'selected':''; ?>>igen</option>
        </select>
      </div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button class="btn btn-primary">Mentés</button>
      </div>
    </form>
  <?php endif; ?>
    </div>
  </div>
</div>


<?php require __DIR__.'/vehicle_registration_block.php'; ?>
<?php require __DIR__.'/vehicle_images_block.php'; ?>

<div class="card p-0 mt-3">
  

<ul class="nav nav-tabs px-3 pt-2" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='issues'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_issues" type="button" role="tab">Hibák</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='service'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_service" type="button" role="tab">Szerviz</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='tires'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_tires" type="button" role="tab">Gumik</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='costs'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_costs" type="button" role="tab">Költségek</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='fuel'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_fuel" type="button" role="tab">Üzemanyag</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='log'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_log" type="button" role="tab">Napló</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link <?= ($tab==='km'?'active':'') ?>" data-bs-toggle="tab" data-bs-target="#tab_km" type="button" role="tab">Km futás</button></li>
  </ul>

  <div class="tab-content p-3">

    <!-- ISSUES -->
    <div class="tab-pane fade <?= ($tab==='issues'?'show active':'') ?>" id="tab_issues" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h3 class="h6 mb-2">Új hiba bejelentése</h3>
            <form method="post" action="/vehicle_issue_add.php" class="row g-2">
              <?= \App\Csrf::field() ?>
              <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
              <div class="col-12">
                <label class="form-label">Dátum</label>
                <input type="date" name="reported_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="col-12">
                <label class="form-label">Hiba leírása</label>
                <textarea name="description" class="form-control" rows="4" required></textarea>
              </div>
              <div class="col-12 d-grid">
                <button class="btn btn-primary">Rögzítés</button>
              </div>
              <div class="form-text">Ezt a fület bárki használhatja.</div>
            </form>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card p-0">
<div class="d-flex justify-content-end gap-2 p-2 border-bottom">
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'issues','export'=>'csv','export_scope'=>'all','issues_page'=>1]) ?>">CSV (összes)</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'issues','export'=>'csv','export_scope'=>'page']) ?>">CSV (aktuális oldal)</a>
</div>
<?php render_pager_bar($issues_page,$issues_pages,'issues_page','issues',$issues_total,$PER_PAGE); ?>
            <table class="table table-striped m-0 align-middle">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th>Hiba</th>
                  <th>Bejelentő</th>
                  <th>Javítás ideje</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$issues): ?>
                  <tr><td colspan="4" class="text-muted">Nincs hiba rögzítve (vagy a vehicle_issues tábla még nincs).</td></tr>
                <?php else: foreach($issues as $it): ?>
                  <tr>
                    <td class="text-nowrap"><?= h($it['reported_date']) ?></td>
                    <td style="white-space: pre-wrap;"><?= h($it['description']) ?></td>
                    <td class="text-nowrap"><?= h($it['created_by_name'] ?? ('#'.$it['created_by'])) ?></td>
                    <td class="text-nowrap">
                      <form method="post" action="/vehicle_issue_fix.php" class="d-flex gap-2 align-items-center">
                        <?= \App\Csrf::field() ?>
                        <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="issue_id" value="<?= (int)$it['id'] ?>">
                        <input type="date" name="fixed_date" class="form-control form-control-sm" value="<?= h($it['fixed_date'] ?? '') ?>" style="max-width:150px">
                        <button class="btn btn-sm btn-outline-primary">Mentés</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
<?php render_pager_bar($issues_page,$issues_pages,'issues_page','issues',$issues_total,$PER_PAGE); ?>
          </div>
        </div>
      </div>
    </div>

    <!-- SERVICE -->
    <div class="tab-pane fade <?= ($tab==='service'?'show active':'') ?>" id="tab_service" role="tabpanel">
      <div class="row g-3">
        <div class="col-lg-5">
          <div class="card p-3">
            <h3 class="h6 mb-2">Új szerviz bejegyzés</h3>
            <?php if (!$isAdmin): ?>
              <div class="alert alert-secondary mb-0">Szerviz bejegyzést csak admin rögzíthet, de az adatokat mindenki láthatja.</div>
            <?php else: ?>
              <form method="post" action="/vehicle_service_add.php" enctype="multipart/form-data" class="row g-2">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                <div class="col-12">
                  <label class="form-label">Dátum</label>
                  <input type="date" name="service_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Km óra állás</label>
                  <input type="number" min="0" name="odometer_km" class="form-control" value="<?= (int)$odo ?>" required>
                </div>
                <div class="col-12 d-flex gap-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="reset_oil" id="reset_oil">
                    <label class="form-check-label" for="reset_oil">Olajcsere periódus reset</label>
                  </div>
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="reset_service" id="reset_service">
                    <label class="form-check-label" for="reset_service">Szerviz periódus reset</label>
                  </div>
                </div>
                <div class="col-12">
                  <label class="form-label">Javítás / Vásárlás leírása</label>
                  <textarea name="description" class="form-control" rows="3" required></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">Besorolt anyagok / alkatrészek (lista)</label>
                  <textarea name="materials" class="form-control" rows="3" placeholder="- olaj 5L&#10;- szűrő&#10;- ..."></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Munka költség</label>
                  <input name="labor_cost" class="form-control" placeholder="0">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Anyag költség</label>
                  <input name="material_cost" class="form-control" placeholder="0">
                </div>
                <div class="col-12">
                  <label class="form-label">Számlát kiállító cég neve</label>
                  <input name="vendor_name" class="form-control">
                </div>
                <div class="col-12">
                  <label class="form-label">Számlát kiállító cég címe</label>
                  <input name="vendor_address" class="form-control">
                </div>
                <div class="col-12">
                  <label class="form-label">Számlaszám</label>
                  <input name="invoice_no" class="form-control">
                </div>
                <div class="col-12">
                  <label class="form-label">Számla (PDF/kép)</label>
                  <input type="file" name="invoice_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*">
                </div>
                <div class="col-12 d-grid">
                  <button class="btn btn-primary">Mentés</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-lg-7">
          <div class="card p-0">
<div class="d-flex justify-content-end gap-2 p-2 border-bottom">
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'service','export'=>'csv','export_scope'=>'all','service_page'=>1]) ?>">CSV (összes)</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'service','export'=>'csv','export_scope'=>'page']) ?>">CSV (aktuális oldal)</a>
</div>
<?php render_pager_bar($services_page,$services_pages,'service_page','service',$services_total,$PER_PAGE); ?>
            <table class="table table-striped m-0 align-middle">
              <thead>
                <tr>
                  <th>Dátum</th>
                  <th>Szállító</th>
                  <th>Leírás</th>
                  <th>Km</th>
                  <th>Reset</th>
                  <th>Számla</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$services): ?>
                  <tr><td colspan="6" class="text-muted">Nincs szerviz bejegyzés (vagy a vehicle_service_entries tábla még nincs).</td></tr>
                <?php else: foreach($services_page_rows as $s): ?>
                  <tr>
                    <td class="text-nowrap"><?= h($s['service_date']) ?></td>
                    <td class="text-nowrap"><?= h($s['vendor_name'] ?? '') ?></td>
                    <td style="white-space: pre-wrap;">
                      <?= h($s['description']) ?>
                      <?php if (!empty($s['materials'])): ?><div class="small text-muted mt-1"><?= nl2br(h($s['materials'])) ?></div><?php endif; ?>
                      <div class="small text-muted mt-1">
                        <?= h($s['created_by_name'] ?? ('#'.$s['created_by'])) ?>
                        • Munka: <?= h($s['labor_cost']) ?> • Anyag: <?= h($s['material_cost']) ?>
                      </div>
                    </td>
                    <td class="text-nowrap"><?= fmtKm((int)$s['odometer_km']) ?></td>
                    <td class="text-nowrap">
                      <?= ((int)$s['reset_oil']===1)?badge('olaj','info').' ':'' ?>
                      <?= ((int)$s['reset_service']===1)?badge('szerviz','info'):'' ?>
                    </td>
                    <td class="text-nowrap">
                      <?php if (!empty($s['invoice_path'])): ?>
                        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="/vehicle_invoice.php?entry_id=<?= (int)$s['id'] ?>">Megnyit</a>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
<?php render_pager_bar($services_page,$services_pages,'service_page','service',$services_total,$PER_PAGE); ?>
          </div>
        </div>
      </div>
    </div>

    <!-- TIRES -->
    <div class="tab-pane fade <?= ($tab==='tires'?'show active':'') ?>" id="tab_tires" role="tabpanel">
      <?php if ($seasonHint): ?><div class="alert alert-warning"><?= h($seasonHint) ?></div><?php endif; ?>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card p-3">
            <h3 class="h6 mb-2">Felszerelt gumik (tengelyenként)</h3>
            <?php if (!$axRows): ?><div class="text-muted">Nincs tengely konfiguráció.</div><?php endif; ?>

            <?php foreach ($axRows as $a):
              $axle = (int)$a['axle_no'];
              $maxPos = ((int)$a['wheels_count']===4) ? 4 : 2;
            ?>
              <div class="border rounded p-2 mb-2">
                <div class="fw-semibold mb-2">Tengely <?= $axle ?> (<?= $maxPos ?> gumi)</div>
                <div class="row g-2">
                  <?php for ($pos=1; $pos<=$maxPos; $pos++):
                    $key = $axle.'-'.$pos;
                    $cur = $inst[$key] ?? null;
                  ?>
                    <div class="col-md-6">
                      <div class="border rounded p-2 h-100">
                        <div class="small text-muted"><?= h(posLabel($pos, $maxPos)) ?></div>

                        <?php if ($cur): ?>
                          <div class="fw-semibold"><?= h($cur['brand'].' '.$cur['tire_model']) ?></div>
                          <div class="small"><?= h(tireKindHu($cur['tire_kind'])) ?> • <?= h($cur['tire_size']) ?> • DOT: <?= h($cur['dot_code']) ?></div>
                          <div class="small text-muted">Felszerelve: <?= h($cur['installed_date']) ?> @ <?= fmtKm((int)$cur['installed_km']) ?> km</div>

                          <?php if ($isAdmin): ?>
                            <form method="post" action="/vehicle_tire_remove.php" class="mt-2 d-flex gap-2 align-items-end flex-wrap">
                              <?= \App\Csrf::field() ?>
                              <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                              <input type="hidden" name="installation_id" value="<?= (int)$cur['id'] ?>">
                              <div>
                                <label class="form-label form-label-sm mb-0">Levétel dátum</label>
                                <input type="date" name="removed_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                              </div>
                              <div>
                                <label class="form-label form-label-sm mb-0">Km</label>
                                <input type="number" min="0" name="removed_km" class="form-control form-control-sm" value="<?= (int)$odo ?>" required style="max-width:120px">
                              </div>
                              <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="archive_tire" id="arch_<?= (int)$cur['id'] ?>">
                                <label class="form-check-label small" for="arch_<?= (int)$cur['id'] ?>">Archív</label>
                              </div>
                              <button class="btn btn-sm btn-outline-danger mt-4">Levétel</button>
                            </form>
                          <?php endif; ?>

                        <?php else: ?>
                          <div class="text-muted">Nincs gumi megadva.</div>
                        <?php endif; ?>

                        <?php if ($isAdmin): ?>
                          <hr class="my-2">
                          <form method="post" action="/vehicle_tire_install.php" class="d-flex gap-2 align-items-end flex-wrap">
                            <?= \App\Csrf::field() ?>
                            <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="axle_no" value="<?= $axle ?>">
                            <input type="hidden" name="position_no" value="<?= $pos ?>">
                            <div style="min-width:220px;">
                              <label class="form-label form-label-sm mb-0">Csere / felhelyezés</label>
                              <select name="tire_id" class="form-select form-select-sm" required>
                                <option value="">Válassz gumit…</option>
                                <?php foreach ($tires_page_rows as $t): if ((int)$t['is_archived']===1) continue; ?>
                                  <option value="<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?> — <?= h(tireKindHu($t['tire_kind'])) ?> • <?= h($t['brand'].' '.$t['tire_model'].' '.$t['tire_size']) ?></option>
                                <?php endforeach; ?>
                              </select>
                            </div>
                            <div>
                              <label class="form-label form-label-sm mb-0">Dátum</label>
                              <input type="date" name="installed_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div>
                              <label class="form-label form-label-sm mb-0">Km</label>
                              <input type="number" min="0" name="installed_km" class="form-control form-control-sm" value="<?= (int)$odo ?>" required style="max-width:120px">
                            </div>
                            <div class="form-check mt-4">
                              <input class="form-check-input" type="checkbox" name="archive_old" id="archold_<?= $axle ?>_<?= $pos ?>">
                              <label class="form-check-label small" for="archold_<?= $axle ?>_<?= $pos ?>">régi archív</label>
                            </div>
                            <button class="btn btn-sm btn-primary mt-4">Mentés</button>
                          </form>
                        <?php endif; ?>

                      </div>
                    </div>
                  <?php endfor; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card p-3 mb-3">
            <h3 class="h6 mb-2">Gumi felvétele (készlet)</h3>

            <?php if (!$isAdmin): ?>
              <div class="alert alert-secondary mb-0">Gumikat csak admin vihet fel / cserélhet, de a listát mindenki láthatja.</div>
            <?php else: ?>
              <form method="post" action="/vehicle_tire_add.php" class="row g-2">
                <?= \App\Csrf::field() ?>
                <input type="hidden" name="vehicle_id" value="<?= (int)$id ?>">
                <div class="col-md-4">
                  <label class="form-label">Típus</label>
                  <select name="tire_kind" class="form-select">
                    <option value="winter">téli</option>
                    <option value="summer">nyári</option>
                    <option value="allseason">4 évszakos</option>
                    <option value="general">általános</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Márka *</label>
                  <input name="brand" class="form-control" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Modell</label>
                  <input name="tire_model" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Méret *</label>
                  <input name="tire_size" class="form-control" placeholder="pl. 225/55 R17" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label">DOT</label>
                  <input name="dot_code" class="form-control" placeholder="pl. 2423">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Vásárlás dátum</label>
                  <input type="date" name="purchased_date" class="form-control">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Vásárlás km</label>
                  <input type="number" min="0" name="purchased_km" class="form-control">
                </div>
                <div class="col-12">
                  <label class="form-label">Megjegyzés</label>
                  <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-12 d-grid">
                  <button class="btn btn-primary">Felvétel</button>
                </div>
              </form>
            <?php endif; ?>
          </div>

          <div class="card p-0">
            <table class="table table-striped m-0 align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Gumi</th>
                  <th>DOT</th>
                  <th>Élettartam</th>
                  <th>Státusz</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$tires): ?>
                  <tr><td colspan="5" class="text-muted">Nincs felvett gumi (vagy a táblák még nincsenek létrehozva).</td></tr>
                <?php else: foreach($tires_page_rows as $t):
                  $tid = (int)$t['id'];
                  $km = $tireStats[$tid]['km'] ?? 0;
                  $days = $tireStats[$tid]['days'] ?? 0;
                  $months = (int)floor($days/30);
                ?>
                  <tr>
                    <td class="text-nowrap">#<?= $tid ?></td>
                    <td>
                      <div class="fw-semibold"><?= h(tireKindHu($t['tire_kind'])) ?> • <?= h($t['brand'].' '.$t['tire_model']) ?></div>
                      <div class="small text-muted"><?= h($t['tire_size']) ?></div>
                    </td>
                    <td class="text-nowrap"><?= h($t['dot_code']) ?></td>
                    <td class="text-nowrap">
                      <?= fmtKm($km) ?> km<br>
                      <span class="small text-muted"><?= $months ?> hó</span>
                    </td>
                    <td class="text-nowrap">
                      <?= ((int)$t['is_archived']===1)?badge('archív','secondary'):badge('aktív','success') ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
<?php render_pager($tires_page,$tires_pages,'tires_page','tires'); ?>
          </div>
        </div>
      </div>
    </div>

    <!-- COSTS -->
    <div class="tab-pane fade <?= ($tab==='costs'?'show active':'') ?>" id="tab_costs" role="tabpanel">
      <?php if (!$isAdmin): ?>
        <div class="alert alert-secondary">A költségek listáját csak admin láthatja.</div>
      <?php else: ?>
        <div class="card p-3 mb-3">
          <form method="get" class="row g-2 align-items-end">


            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="col-md-3">
              <label class="form-label">Időszak (-tól)</label>
              <input type="date" name="cost_from" class="form-control" value="<?= h($cost_from) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Időszak (-ig)</label>
              <input type="date" name="cost_to" class="form-control" value="<?= h($cost_to) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Szállító (név részlet)</label>
              <input name="cost_vendor" class="form-control" value="<?= h($cost_vendor) ?>" list="vendorList" placeholder="pl. Unix, Bárdi, szerviz...">
              <datalist id="vendorList">
                <?php foreach($vendorOptions as $vo): ?>
                  <option value="<?= h($vo) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-2 d-grid gap-2">
              <button class="btn btn-primary">Szűrés</button>
              <a class="btn btn-outline-secondary" href="/vehicle.php?id=<?= (int)$id ?>&tab=costs#tab_costs">Törlés</a>
            </div>
          </form>
        </div>

        <?php
          $sumLabor = 0; $sumMat=0;
          foreach ($services_cost as $s) { $sumLabor += (float)$s['labor_cost']; $sumMat += (float)$s['material_cost']; }
        ?>
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="text-muted">Összesen (szűrt lista)</div>
          <div>
            <span class="badge bg-primary">Munka: <?= h(number_format($sumLabor,2,'.',' ')) ?></span>
            <span class="badge bg-primary ms-1">Anyag: <?= h(number_format($sumMat,2,'.',' ')) ?></span>
            <span class="badge bg-success ms-1">Mind: <?= h(number_format($sumLabor+$sumMat,2,'.',' ')) ?></span>
          </div>
        </div>

        <div class="card p-0">
<div class="d-flex justify-content-end gap-2 p-2 border-bottom">
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'costs','export'=>'csv','export_scope'=>'all','costs_page'=>1]) ?>">CSV (összes)</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'costs','export'=>'csv','export_scope'=>'page']) ?>">CSV (aktuális oldal)</a>
</div>
<?php render_pager_bar($services_cost_page,$services_cost_pages,'costs_page','costs',$services_cost_total,$PER_PAGE); ?>
          <table class="table table-striped m-0 align-middle">
            <thead><tr><th>Dátum</th><th>Szállító</th><th>Leírás</th><th>Munka</th><th>Anyag</th><th>Számla</th></tr></thead>
            <tbody>
              <?php if (!$services_cost): ?>
                <tr><td colspan="6" class="text-muted">Nincs adat a szűrésre.</td></tr>
              <?php else: foreach($services_cost_page_rows as $s): ?>
                <tr>
                  <td class="text-nowrap"><?= h($s['service_date']) ?></td>
                  <td class="text-nowrap"><?= h($s['vendor_name'] ?? '') ?></td>
                  <td style="white-space: pre-wrap;"><?= h($s['description']) ?></td>
                  <td class="text-nowrap"><?= h($s['labor_cost']) ?></td>
                  <td class="text-nowrap"><?= h($s['material_cost']) ?></td>
                  <td class="text-nowrap">
                    <?php if (!empty($s['invoice_path'])): ?>
                      <a class="btn btn-sm btn-outline-secondary" target="_blank" href="/vehicle_invoice.php?entry_id=<?= (int)$s['id'] ?>">Megnyit</a>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
<?php render_pager_bar($services_cost_page,$services_cost_pages,'costs_page','costs',$services_cost_total,$PER_PAGE); ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- FUEL -->
    <div class="tab-pane fade <?= ($tab==='fuel'?'show active':'') ?>" id="tab_fuel" role="tabpanel">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <div class="h6 mb-0">Üzemanyag vásárlások</div>
          <div class="text-muted small">Központi XLS importból kerülnek be. (Admin: Járművek → Üzemanyag import)</div>
        </div>


  <a class="btn btn-outline-primary btn-sm"
     href="/vehicle_fuel_stats.php?id=<?= (int)$id ?>">
     Üzemanyag statisztika
  </a>


      </div>

      <div class="card p-0">
<div class="d-flex justify-content-end gap-2 p-2 border-bottom">
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'fuel','export'=>'csv','export_scope'=>'all','fuel_page'=>1]) ?>">CSV (összes)</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'fuel','export'=>'csv','export_scope'=>'page']) ?>">CSV (aktuális oldal)</a>
</div>
<?php render_pager_bar($fuels_page,$fuels_pages,'fuel_page','fuel',$fuels_total,$PER_PAGE); ?>
        <table class="table table-striped m-0 align-middle">
          <thead><tr>
            <th>Dátum</th>
            <th>Km</th>
            <th>Termék</th>
            <th class="text-end">Liter</th>
            <th class="text-end">Összeg</th>
            <th>Hely</th>
            <th>Import</th>
          </tr></thead>
          <tbody>
            <?php if (!$fuels): ?>
              <tr><td colspan="7" class="text-muted">Nincs üzemanyag vásárlás rögzítve ehhez a járműhöz.</td></tr>
            <?php else: foreach($fuels as $f): ?>
              <tr>
                <td class="text-nowrap"><?= h(substr((string)$f['fueled_at'],0,16)) ?></td>
                <td class="text-nowrap"><?= (int)$f['odometer_km'] ?></td>
                <td><?= h($f['fuel_product']) ?></td>
                <td class="text-end"><?= $f['quantity_l']!==null ? number_format((float)$f['quantity_l'],2,',',' ') : '' ?></td>
                <td class="text-end"><?= $f['gross_huf']!==null ? number_format((float)$f['gross_huf'],0,',',' ') : '' ?></td>
                <td><?= h(trim((string)$f['station_name'].' '.$f['station_city'])) ?></td>
                <td><?= (int)$f['import_id'] ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
<?php render_pager_bar($fuels_page,$fuels_pages,'fuel_page','fuel',$fuels_total,$PER_PAGE); ?>
      </div>
    </div>

    <!-- LOG -->
    <div class="tab-pane fade <?= ($tab==='log'?'show active':'') ?>" id="tab_log" role="tabpanel">
      <?php if (!$isAdmin): ?>
        <div class="alert alert-secondary">A naplót csak admin láthatja.</div>
      <?php else: ?>
        <div class="text-muted mb-2">Legutóbbi változások (max 500)</div>
        <div class="card p-0">
<div class="d-flex justify-content-end gap-2 p-2 border-bottom">
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'log','export'=>'csv','export_scope'=>'all','log_page'=>1]) ?>">CSV (összes)</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'log','export'=>'csv','export_scope'=>'page']) ?>">CSV (aktuális oldal)</a>
</div>
<?php render_pager_bar($logs_page,$logs_pages,'log_page','log',$logs_total,$PER_PAGE); ?>
          <table class="table table-striped m-0 align-middle">
            <thead><tr><th>Dátum</th><th>Felhasználó</th><th>Esemény</th><th>Művelet</th><th>Részletek</th></tr></thead>
            <tbody>
              <?php if (!$logs): ?>
                <tr><td colspan="5" class="text-muted">Nincs naplóbejegyzés. (LOG DEBUG count: <?= (int)count($logs) ?>)</td></tr>
              <?php else: foreach($logs_page_rows as $l): ?>
                <tr>
                  <td class="text-nowrap"><?= h($l['created_at'] ?? '') ?></td>
                  <td class="text-nowrap"><?= h($l['user_name'] ?? ('#'.$l['user_id'])) ?></td>
                  <td class="text-nowrap"><?= h(entityHu($l['entity_type'] ?? '')) ?> • <?= h(actionHu($l['action'] ?? '')) ?></td>
                  <td style="white-space: pre-wrap;">
                    <?php $parts = summarizeChangedFields($l['changed_fields'] ?? ''); ?>
                    <?php if ($parts): ?>
                      <?= h(implode(' • ', $parts)) ?>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#log<?= (int)$l['id'] ?>" aria-expanded="false">Részletek</button>
                  </td>
                </tr>
                <tr class="collapse" id="log<?= (int)$l['id'] ?>">
                  <td colspan="5" class="bg-light">
                    <div class="small text-muted mb-1">Nyers adat (JSON):</div>
                    <pre class="mb-0" style="white-space: pre-wrap; font-size: 12px;"><?= h($l['changed_fields'] ?? '') ?></pre>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
<?php render_pager_bar($logs_page,$logs_pages,'log_page','log',$logs_total,$PER_PAGE); ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- KM FUTÁS -->
    <div class="tab-pane fade <?= ($tab==='km'?'show active':'') ?>" id="tab_km" role="tabpanel">

      <?php if (!(int)($v['multialarm_enabled'] ?? 0)): ?>
        <div class="alert alert-secondary">
          A Multi Alarm GPS összeköttetés ennél a járműnél nincs engedélyezve.<br>
          <?php if ($isAdmin): ?>Admin: kapcsold be a <a href="/vehicle_extra_update.php?id=<?= (int)$id ?>">jármű adatainál</a> a Multi Alarm opciót.<?php endif; ?>
        </div>
      <?php else: ?>

      <!-- Szűrő -->
      <form method="get" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="tab" value="km">
        <div class="col-md-3">
          <label class="form-label">Időszak (-tól)</label>
          <input type="date" name="km_from" class="form-control" value="<?= h($km_filter_from) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Időszak (-ig)</label>
          <input type="date" name="km_to" class="form-control" value="<?= h($km_filter_to) ?>">
        </div>
        <div class="col-md-3 d-flex gap-2 align-items-end">
          <button class="btn btn-primary">Szűrés</button>
          <a class="btn btn-outline-secondary" href="/vehicle.php?id=<?= (int)$id ?>&tab=km">Törlés</a>
        </div>
        <div class="col-md-3 text-end">
          <a class="btn btn-sm btn-outline-secondary" href="<?= pager_url(['tab'=>'km','export'=>'csv','export_scope'=>'all','km_page'=>1]) ?>">CSV (összes)</a>
        </div>
      </form>

      <!-- Összesítő kártyák -->
      <?php if ($km_period_days > 0): ?>
      <div class="row g-3 mb-3">
        <div class="col-auto">
          <div class="card text-center px-3 py-2">
            <div class="small text-muted">Napok száma</div>
            <div class="fw-bold"><?= $km_period_days ?> nap</div>
          </div>
        </div>
        <div class="col-auto">
          <div class="card text-center px-3 py-2 border-primary">
            <div class="small text-muted">Összes km (szűrt)</div>
            <div class="fw-bold text-primary"><?= number_format($km_period_total, 1, ',', ' ') ?> km</div>
          </div>
        </div>
        <div class="col-auto">
          <div class="card text-center px-3 py-2">
            <div class="small text-muted">Átlag / nap</div>
            <div class="fw-bold"><?= $km_period_days > 0 ? number_format($km_period_total / $km_period_days, 1, ',', ' ') : '0' ?> km</div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Táblázat -->
      <div class="card p-0">
        <?php render_pager_bar($km_page, $km_pages, 'km_page', 'km', $km_total_count, $PER_PAGE); ?>
        <table class="table table-striped m-0 align-middle">
          <thead>
            <tr>
              <th>Dátum</th>
              <th class="text-end">Megtett km</th>
              <th class="text-center">Szakaszok</th>
              <th class="text-muted small">Rögzítve</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$km_rows): ?>
              <tr><td colspan="5" class="text-muted">Nincs km adat ebben az időszakban.</td></tr>
            <?php else: foreach ($km_rows as $r): ?>
              <tr>
                <td class="text-nowrap fw-semibold"><?= h($r['km_date']) ?></td>
                <td class="text-end"><?= number_format((float)$r['total_km'], 1, ',', ' ') ?> km</td>
                <td class="text-center text-muted"><?= (int)$r['trip_count'] ?></td>
                <td class="text-muted small text-nowrap"><?= h(substr((string)$r['fetched_at'], 0, 16)) ?></td>
                <td class="text-nowrap">
                  <?php if ((int)$r['trip_count'] > 0): ?>
                    <a href="/vehicle_km_map.php?id=<?= (int)$id ?>&date=<?= h($r['km_date']) ?>"
                       target="_blank" class="btn btn-sm btn-outline-primary py-0">🗺 Térkép</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php render_pager_bar($km_page, $km_pages, 'km_page', 'km', $km_total_count, $PER_PAGE); ?>
      </div>

      <?php endif; ?>
    </div>

  </div>
</div>


<script>
(function() {
  function getDesiredTab() {
    try {
      var url = new URL(window.location.href);
      return (url.searchParams.get('tab') || '').trim();
    } catch (e) { return ''; }
  }

  function showTabByName(t) {
    if (!t) return false;
    var target = '#tab_' + t;
    var btn = document.querySelector('button[data-bs-toggle="tab"][data-bs-target="' + target + '"]');
    if (!btn) return false;
    if (window.bootstrap && bootstrap.Tab) {
      bootstrap.Tab.getOrCreateInstance(btn).show();
      return true;
    }
    return false;
  }

  function showFirstTabFallback() {
    var firstBtn = document.querySelector('button[data-bs-toggle="tab"]');
    if (firstBtn && window.bootstrap && bootstrap.Tab) {
      bootstrap.Tab.getOrCreateInstance(firstBtn).show();
      return true;
    }
    return false;
  }

  // 1) On load: open tab from ?tab=... (or from hash #tab_x), otherwise open the first tab.
  document.addEventListener('DOMContentLoaded', function() {
    var t = getDesiredTab();

    // If no ?tab=, try hash like #tab_costs
    if (!t && window.location.hash && window.location.hash.indexOf('#tab_') === 0) {
      t = window.location.hash.replace('#tab_', '');
    }

    if (!showTabByName(t)) {
      showFirstTabFallback();
    }

    // If we have ?tab= but no hash, add hash for nicer reload/deep link
    try {
      if (t && !window.location.hash) {
        window.location.hash = '#tab_' + t;
      }
    } catch (e) {}
  });

  // 2) On tab change: persist ?tab=... and hash.
  var tabButtons = document.querySelectorAll('button[data-bs-toggle="tab"][data-bs-target^="#tab_"]');
  tabButtons.forEach(function(btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
      try {
        var target = e.target.getAttribute('data-bs-target'); // #tab_costs
        if (!target) return;
        var t = target.replace('#tab_', '');
        var url = new URL(window.location.href);
        url.searchParams.set('tab', t);
        url.hash = target;
        history.replaceState(null, '', url.toString());
      } catch(err) {}
    });
  });
})();
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>