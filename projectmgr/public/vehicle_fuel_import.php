<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
use App\Db; use App\Auth; use App\Middleware; use App\Csrf; use App\Helpers;

Auth::start();
Middleware::requireAuth();
$pdo = Db::pdo();
$u = Auth::user();
$isAdmin = ((int)($u['role_id'] ?? 0) === 1);

function pm_log($msg){
  $dir = dirname(__DIR__).'/storage/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $file = $dir.'/app.log';
  @file_put_contents($file, '['.date('Y-m-d H:i:s').'] fuel_import '.$msg.PHP_EOL, FILE_APPEND);
}


if (!$isAdmin) {
  require dirname(__DIR__).'/views/_layout_top.php';
  require dirname(__DIR__).'/views/_flash.php';
  http_response_code(403);
  echo "<div class='alert alert-danger'>Nincs jogosultság.</div>";
  require dirname(__DIR__).'/views/_layout_bottom.php';
  exit;
}



// --- Delete only the stored XLS/XLSX file for an import (keep imported fuel entries) ---
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_file_id'])) {
  $delId = (int)($_POST['delete_file_id'] ?? 0);
  if ($delId > 0) {
    if (!Csrf::check($_POST['csrf_token'] ?? null)) {
      Helpers::flash('err', 'Érvénytelen űrlap token (CSRF).');
    } else {
      try {
        $cols = $pdo->query("SHOW COLUMNS FROM fuel_imports")->fetchAll(PDO::FETCH_ASSOC);
        $colset = [];
        foreach ($cols as $c) { $colset[$c['Field']] = true; }
        $fileCol = null;
        foreach (['stored_path','stored_filename'] as $c) { if (isset($colset[$c])) { $fileCol = $c; break; } }
        if (!$fileCol) throw new Exception('A fuel_imports táblában nincs stored_path/stored_filename mező.');

        $st = $pdo->prepare("SELECT id, orig_name, $fileCol AS stored_file FROM fuel_imports WHERE id=? LIMIT 1");
        $st->execute([$delId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Nincs ilyen import.');
        $storedFile = (string)($row['stored_file'] ?? '');
        if ($storedFile==='') throw new Exception('Ehhez az importhoz nincs eltárolt fájl.');

        $upDir = dirname(__DIR__).'/storage/uploads/fuel_imports';
        $path = $upDir.'/'.basename($storedFile);

        $deleted = false;
        if (is_file($path)) {
          $deleted = @unlink($path);
          if (!$deleted) throw new Exception('A fájl törlése nem sikerült (jogosultság?).');
        } else {
          // file missing on disk - still clear db pointers
          $deleted = true;
        }

        // Delete import row from DB as well (keep imported fuel entries)
        $pdo->prepare("DELETE FROM fuel_imports WHERE id=?")->execute([$delId]);

        pm_log("delete import_id=$delId user_id=".(int)($u['id'] ?? 0)." file=".basename($storedFile)." orig_name=".($row['orig_name'] ?? ''));

        Helpers::flash('ok', 'Az import fájl és az import rekord törölve lett (Import #'.$delId.'). Az importált tételek megmaradtak.');
      } catch (Throwable $e) {
        Helpers::flash('err', 'Törlési hiba: '.$e->getMessage());
      }
    }
  }
  header('Location: /vehicle_fuel_import.php');
  exit;
}

// --- Download original import file (for audit/review) ---
$downloadId = (int)($_GET['download'] ?? 0);
if ($downloadId > 0) {
  try {
    // detect stored file column (schema tolerant)
    $cols = $pdo->query("SHOW COLUMNS FROM fuel_imports")->fetchAll(PDO::FETCH_ASSOC);
    $colset = [];
    foreach ($cols as $c) { $colset[$c['Field']] = true; }
    $fileCol = null;
    foreach (['stored_path','stored_filename'] as $c) { if (isset($colset[$c])) { $fileCol = $c; break; } }

    $sel = "SELECT id, orig_name".($fileCol? ", $fileCol AS stored_file":"")." FROM fuel_imports WHERE id=? LIMIT 1";
    $st = $pdo->prepare($sel);
    $st->execute([$downloadId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['stored_file'])) throw new Exception('A fájl nincs eltárolva ehhez az importhoz.');
    $upDir = dirname(__DIR__).'/storage/uploads/fuel_imports';
    $path = $upDir.'/'.basename((string)$row['stored_file']);
    if (!is_file($path)) throw new Exception('A fájl már nem található a szerveren.');

    $origName = (string)($row['orig_name'] ?? ('fuel_import_'.$downloadId.'.xlsx'));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = ($ext==='xls') ? 'application/vnd.ms-excel'
          : (($ext==='xlsx') ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
          : 'application/octet-stream');

    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: attachment; filename="'.rawurlencode($origName).'"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  } catch (Throwable $e) {
    require dirname(__DIR__).'/views/_layout_top.php';
    require dirname(__DIR__).'/views/_flash.php';
    echo "<div class='alert alert-danger'>Letöltési hiba: ".htmlspecialchars($e->getMessage())."</div>";
    require dirname(__DIR__).'/views/_layout_bottom.php';
    exit;
  }
}


function pm_header_map(array $headers){
  $norm = function($s){
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  };
  $h = [];
  foreach ($headers as $i=>$name){ $h[$norm($name)] = $i; }

  $need = [
    'plate' => ['rendszám','rendszam','plate','license plate'],
    'dt'    => ['dátum és idő','datum es ido','dátum','datum','date time','date'],
    'km'    => ['kilométer','kilometer','km','odometer'],
    'prod'  => ['termék','termek','product','üzemanyag','uzemanyag'],
    'qty'   => ['mennyiség','mennyiseg','quantity','liter','liters'],
    'gross' => ['bruttó összeg huf','bruttó összeg (huf)','brutto osszeg huf','gross huf','bruttó','brutto'],
    'station'=>['kút név','kut nev','station','kút','kut'],
    'slip'  => ['bizonylat','slip id','slipid'],
    'card'  => ['kártyaszám','kartyaszam','card','card no'],
    'unit'  => ['kúti ár','kuti ar','unit price','ár','ar']
  ];

  $map = [];
  foreach ($need as $key=>$alts){
    $idx = null;
    foreach ($alts as $a){
      $a = $norm($a);
      if (isset($h[$a])) { $idx = $h[$a]; break; }
    }
    $map[$key] = $idx;
  }
  return $map;
}

function pm_try_parse_datetime($s){
  $s = trim((string)$s);
  if ($s==='') return null;

  // Normalize common separators
  $s = str_replace(['.', '/'], '-', $s);
  $s = preg_replace('/\s+/', ' ', $s);

  foreach (['Y-m-d H:i:s','Y-m-d H:i','Y-m-d','d-m-Y H:i:s','d-m-Y H:i','d-m-Y'] as $f){
    $dt = DateTime::createFromFormat($f, $s);
    if ($dt) return $dt->format('Y-m-d H:i:s');
  }
  $ts = strtotime($s);
  if ($ts!==false) return date('Y-m-d H:i:s', $ts);
  return null;
}

function pm_parse_num($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  // remove spaces / NBSP
  $s = str_replace(["\xc2\xa0", ' '], '', $s);
  // keep digits, comma, dot, minus
  $s = preg_replace('/[^0-9,\.\-]/', '', $s);

  // If both comma and dot exist, assume dot is thousand-sep and comma is decimal (HU), e.g. 1.234,56
  if (strpos($s, ',') !== false && strpos($s, '.') !== false){
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    // Otherwise: treat comma as decimal
    $s = str_replace(',', '.', $s);
  }
  if ($s==='' || $s==='-' || $s==='.'){ return null; }
  return (float)$s;
}

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!isset($_FILES['xls']) || $_FILES['xls']['error']!==UPLOAD_ERR_OK){
    $error = 'Nincs fájl, vagy feltöltési hiba.';
  } else {
    $upDir = dirname(__DIR__).'/storage/uploads/fuel_imports';
    if (!is_dir($upDir) && !@mkdir($upDir, 0775, true)){
      $error = 'Nem tudtam létrehozni a feltöltési mappát: '.$upDir;
    } else {
      $tmp = $_FILES['xls']['tmp_name'];
      $orig = $_FILES['xls']['name'];
      $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
      if ($ext!=='xls' && $ext!=='xlsx'){
        $error = 'Csak XLS/XLSX fájl tölthető fel.';
      } else {
        $hash = hash_file('sha256', $tmp);
        $fname = date('Ymd_His').'_'.mt_rand(100000,999999).'_'.preg_replace('/[^A-Za-z0-9_.-]+/','_', $orig);
        $stored = $upDir.'/'.$fname;
        if (!move_uploaded_file($tmp, $stored)){
          $error = 'Nem sikerült a fájlt elmenteni a szerverre.';
        } else {
          // Insert import row (schema-tolerant)
          $cols = $pdo->query("SHOW COLUMNS FROM fuel_imports")->fetchAll(PDO::FETCH_ASSOC);
          $colset = [];
          foreach ($cols as $c){ $colset[$c['Field']] = true; }

          $fields = ['uploaded_by'=> (int)$u['id'], 'orig_name'=>$orig, 'file_hash'=>$hash];
          if (isset($colset['stored_path'])) $fields['stored_path'] = $fname;
          if (isset($colset['stored_filename'])) $fields['stored_filename'] = $fname;
          if (isset($colset['mime'])) $fields['mime'] = ($_FILES['xls']['type'] ?? '');
          if (isset($colset['size'])) $fields['size'] = (int)($_FILES['xls']['size'] ?? 0);

          $sql = "INSERT INTO fuel_imports (".implode(',', array_keys($fields)).") VALUES (".implode(',', array_fill(0,count($fields),'?')).")";
          $st = $pdo->prepare($sql);

          $importId = 0;
          try {
            $st->execute(array_values($fields));
            $importId = (int)$pdo->lastInsertId();
          } catch (PDOException $e) {
            // Duplikált fájl (azonos hash)
            if ((string)$e->getCode() === '23000' && strpos($e->getMessage(), 'uq_fuel_imports_hash') !== false) {
              $st2 = $pdo->prepare("SELECT id, uploaded_at, orig_name FROM fuel_imports WHERE file_hash=? LIMIT 1");
              $st2->execute([$hash]);
              $ex = $st2->fetch(PDO::FETCH_ASSOC);
              @unlink($stored); // ne tároljunk feleslegesen még egy példányt
              $error = 'Ez a fájl már importálva volt'.($ex ? ' (Import #'.(int)$ex['id'].' – '.($ex['uploaded_at'] ?? '').')' : '').'. Ha újra akarod importálni, töröld az előző importot (később adunk rá gombot), vagy módosított fájlt tölts fel.';
            } else {
              throw $e;
            }
          }

          if (!$error && $importId>0) {
            pm_log("saved file=$fname import_id=$importId");
          }

          if (!$error && $importId>0) {

            // ---- PhpSpreadsheet import ----
            if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
              // Try common autoload locations (if you later add composer)
              $autoload1 = dirname(__DIR__).'/vendor/autoload.php';
              $autoload2 = dirname(__DIR__).'/../vendor/autoload.php';
              if (is_file($autoload1)) require_once $autoload1;
              elseif (is_file($autoload2)) require_once $autoload2;
            }

            if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
              $error = 'PhpSpreadsheet nincs telepítve. Telepítés: composer require phpoffice/phpspreadsheet (a projectmgr könyvtárban), majd próbáld újra.';
            } else {
              try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($stored);
                $sheet = $spreadsheet->getActiveSheet();

                $highestRow = (int)$sheet->getHighestDataRow();
                $highestCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

                // header row = 1
                $headers = [];
                for ($c=1; $c<=$highestCol; $c++){
                  $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)."1";
                  $val = $sheet->getCell($cellRef)->getValue();
                  $headers[] = trim((string)$val);
                }

                $map = pm_header_map($headers);
                if ($map['plate']===null || $map['dt']===null){
                  $error = 'Nem találtam a kötelező oszlopokat (Rendszám, Dátum és idő). Talált fejlécek: '.htmlspecialchars(implode(' | ', array_slice($headers,0,30)));
                } else {

                  $rowsTotal=0; $rowsOk=0; $rowsSkip=0; $rowsUnmatched=0; $dupes=0;

                  // Per-vehicle import summary for UI + audit
                  // [vehicle_id => ['plate'=>string,'count'=>int,'km_updates'=>[['from'=>int,'to'=>int]]]]
                  $vehSummary = [];

                  // Részletes log gyűjtés
                  $logUnmatched = []; // ['row'=>int, 'plate'=>string]
                  $logDupes     = []; // ['row'=>int, 'plate'=>string, 'dt'=>string]
                  $logSkipped   = []; // ['row'=>int, 'reason'=>string]

                  $ins = $pdo->prepare("INSERT INTO vehicle_fuel_entries
                    (import_id, vehicle_id, fueled_at, odometer_km, fuel_product, quantity_l, unit_price_huf, gross_huf, station_name, station_id, country, slip_id, invoice_no, card_no, raw_row_hash, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

                  // Normalizált rendszám keresés: szóközök, kötőjelek, pontok eltávolítva mindkét oldalon
                  $vehSt = $pdo->prepare("SELECT id FROM vehicles WHERE REPLACE(REPLACE(REPLACE(UPPER(license_plate),' ',''),'-',''),'.','')=? LIMIT 1");
                  $vehOdoSt = $pdo->prepare("SELECT odometer_km FROM vehicles WHERE id=? LIMIT 1");
                  $vehUpdOdoSt = $pdo->prepare("UPDATE vehicles SET odometer_km=? WHERE id=?");

                  $getCell = function(int $row, ?int $idx0) use ($sheet){
                    if ($idx0===null) return null;
                    $col = $idx0 + 1; // map is 0-based, PhpSpreadsheet is 1-based
                    $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).$row;
                    $cell = $sheet->getCell($cellRef);

                    // Use calculated value for formulas
                    $v = $cell->getCalculatedValue();

                    // Date handling
                    try{
                      if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v);
                        return $dt ? $dt->format('Y-m-d H:i:s') : null;
                      }
                    } catch (Throwable $e){}

                    if ($v===null) return null;
                    if (is_string($v)) return trim($v);
                    if (is_bool($v)) return $v ? '1' : '0';
                    // numeric
                    return (string)$v;
                  };

                  for ($r=2; $r<=$highestRow; $r++){
                    // Skip completely empty rows fast
                    $maybePlate = $getCell($r, $map['plate']);
                    $maybeDt    = $getCell($r, $map['dt']);
                    if ((string)$maybePlate==='' && (string)$maybeDt==='') continue;

                    $rowsTotal++;

                    $plate = trim((string)$maybePlate);
                    if ($plate===''){ $rowsSkip++; $logSkipped[] = ['row'=>$r, 'reason'=>'Üres rendszám']; continue; }
                    $plateNorm = strtoupper(preg_replace('/[^A-Z0-9]/','', $plate));

                    $dtRaw = $maybeDt;
                    // if cell already formatted by Date conversion, keep it; otherwise parse
                    $dt = pm_try_parse_datetime($dtRaw);
                    if (!$dt){
                      // maybe already 'Y-m-d H:i:s'
                      if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$dtRaw)) $dt = (string)$dtRaw;
                    }
                    if (!$dt){ $rowsSkip++; $logSkipped[] = ['row'=>$r, 'reason'=>'Érvénytelen dátum: '.htmlspecialchars((string)$dtRaw)]; continue; }

                    $kmVal = null;
                    $kmRaw = $getCell($r, $map['km']);
                    if ($kmRaw!==null && trim((string)$kmRaw)!==''){
                      $kmVal = (int)preg_replace('/\D+/', '', (string)$kmRaw);
                    }

                    $prod = $getCell($r, $map['prod']);

                    $qtyVal = null;
                    $qtyRaw = $getCell($r, $map['qty']);
                    if ($qtyRaw!==null && trim((string)$qtyRaw)!=='') $qtyVal = pm_parse_num($qtyRaw);

                    $grossVal = null;
                    $grossRaw = $getCell($r, $map['gross']);
                    if ($grossRaw!==null && trim((string)$grossRaw)!=='') $grossVal = pm_parse_num($grossRaw);

                    $unitVal = null;
                    $unitRaw = $getCell($r, $map['unit']);
                    if ($unitRaw!==null && trim((string)$unitRaw)!=='') $unitVal = pm_parse_num($unitRaw);

                    $station = $getCell($r, $map['station']);
                    $slip    = $getCell($r, $map['slip']);
                    $card    = $getCell($r, $map['card']);

                    $vehId = 0;
                    $vehSt->execute([$plateNorm]); $vehId = (int)($vehSt->fetchColumn() ?: 0);
                    if (!$vehId){ $rowsUnmatched++; $logUnmatched[] = ['row'=>$r, 'plate'=>$plate]; continue; }

                    $rawHash = hash('sha256', json_encode([$plateNorm,$dt,$kmVal,$prod,$qtyVal,$grossVal,$station,$slip,$card], JSON_UNESCAPED_UNICODE));

                    try{
                      $ins->execute([
                        $importId, $vehId, $dt, $kmVal, $prod, $qtyVal, $unitVal, $grossVal,
                        $station, null, null, $slip, null, $card, $rawHash, (int)$u['id']
                      ]);
                      $rowsOk++;

                      // Track per-vehicle imported rows
                      if (!isset($vehSummary[$vehId])) {
                        $vehSummary[$vehId] = ['plate'=>$plateNorm, 'count'=>0, 'km_updates'=>[]];
                      }
                      $vehSummary[$vehId]['count']++;

                      // If km is provided and not 0, overwrite vehicle odometer
                      if ($kmVal !== null && (int)$kmVal > 0) {
                        $oldKm = null;
                        try {
                          $vehOdoSt->execute([$vehId]);
                          $oldKm = $vehOdoSt->fetchColumn();
                          $oldKm = $oldKm !== false && $oldKm !== null ? (int)$oldKm : null;
                        } catch (Throwable $e) {}

                        try {
                          $vehUpdOdoSt->execute([(int)$kmVal, $vehId]);
                          $vehSummary[$vehId]['km_updates'][] = ['from'=>($oldKm===null?0:$oldKm), 'to'=>(int)$kmVal];
                        } catch (Throwable $e) {
                          pm_log("vehicle odo update failed vehicle_id=$vehId km=$kmVal err=".$e->getMessage());
                        }
                      }
                    } catch (PDOException $e){
                      if (strpos($e->getMessage(), 'Duplicate') !== false) {
                        $dupes++;
                        $logDupes[] = ['row'=>$r, 'plate'=>$plate, 'dt'=>$dt];
                      } else {
                        pm_log("insert err ".$e->getMessage());
                        $rowsSkip++;
                        $logSkipped[] = ['row'=>$r, 'reason'=>'DB hiba: '.$e->getMessage()];
                      }
                    }
                  }

                  // Update counters (schema-tolerant)
                  $cols2 = $pdo->query("SHOW COLUMNS FROM fuel_imports")->fetchAll(PDO::FETCH_ASSOC);
                  $colset2=[]; foreach($cols2 as $c){ $colset2[$c['Field']]=true; }
                  $updates=[]; $vals=[];
                  if (isset($colset2['rows_total'])) { $updates[]='rows_total=?'; $vals[]=$rowsTotal; }
                  if (isset($colset2['rows_imported'])) { $updates[]='rows_imported=?'; $vals[]=$rowsOk; }
                  if (isset($colset2['rows_ok'])) { $updates[]='rows_ok=?'; $vals[]=$rowsOk; }
                  if (isset($colset2['rows_skipped'])) { $updates[]='rows_skipped=?'; $vals[]=$rowsSkip; }
                  if (isset($colset2['rows_unmatched'])) { $updates[]='rows_unmatched=?'; $vals[]=$rowsUnmatched; }
                  if ($updates){
                    $vals[]=$importId;
                    $pdo->prepare("UPDATE fuel_imports SET ".implode(',', $updates)." WHERE id=?")->execute($vals);
                  }

                  // audit summary
                  try{
                    $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields)
                      VALUES (?,?,?,?,?)")->execute([
                        (int)$u['id'],'fuel_import',$importId,'import',
                        json_encode(['import_id'=>$importId,'file'=>$orig,'rows_total'=>$rowsTotal,'imported'=>$rowsOk,'unmatched'=>$rowsUnmatched,'skipped'=>$rowsSkip,'dupes'=>$dupes], JSON_UNESCAPED_UNICODE)
                      ]);
                  } catch (Throwable $e){}

                  // audit per vehicle: record that a fuel import affected the vehicle
                  if ($vehSummary) {
                    $vehAudit = $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields)
                      VALUES (?,?,?,?,?)");
                    foreach ($vehSummary as $vid=>$vs) {
                      try {
                        $vehAudit->execute([
                          (int)$u['id'], 'vehicle', (int)$vid, 'update',
                          json_encode([
                            'vehicle_id'=>(int)$vid,
                            'import_id'=>$importId,
                            'license_plate'=>$vs['plate'],
                            'fuel_rows'=>(int)$vs['count'],
                            'odometer_updates'=>$vs['km_updates']
                          ], JSON_UNESCAPED_UNICODE)
                        ]);
                      } catch (Throwable $e) {
                        pm_log("vehicle audit failed vehicle_id=$vid err=".$e->getMessage());
                      }
                    }
                  }

                  // Sort vehicle list by plate for nicer UI
                  $vehList = array_values($vehSummary);
                  usort($vehList, fn($a,$b)=>strcmp($a['plate'],$b['plate']));

                  // Részletes log fájl írása
                  $logLines = [];
                  $logLines[] = '=== ÜZEMANYAG IMPORT ÖSSZEGZÉS ===';
                  $logLines[] = 'Import ID : '.$importId;
                  $logLines[] = 'Fájl      : '.$orig;
                  $logLines[] = 'Feltöltő  : '.($u['name'] ?? $u['email'] ?? $u['id']);
                  $logLines[] = 'Időpont   : '.date('Y-m-d H:i:s');
                  $logLines[] = '';
                  $logLines[] = 'ÖSSZESÍTŐ:';
                  $logLines[] = '  Összes sor       : '.$rowsTotal;
                  $logLines[] = '  Sikeresen importált: '.$rowsOk;
                  $logLines[] = '  Már megvolt (dupla): '.$dupes;
                  $logLines[] = '  Ismeretlen rendszám: '.$rowsUnmatched;
                  $logLines[] = '  Egyéb kihagyott  : '.$rowsSkip;
                  $logLines[] = '';
                  if ($logUnmatched) {
                    $logLines[] = 'ISMERETLEN RENDSZÁMOK ('.count($logUnmatched).' db):';
                    $grouped = [];
                    foreach ($logUnmatched as $e) { $grouped[$e['plate']][] = $e['row']; }
                    foreach ($grouped as $pl => $rows) {
                      $logLines[] = '  '.$pl.' (sor: '.implode(', ', $rows).')';
                    }
                    $logLines[] = '';
                  }
                  if ($logDupes) {
                    $logLines[] = 'MÁR IMPORTÁLT (DUPLA) TÉTELEK ('.count($logDupes).' db):';
                    foreach ($logDupes as $e) {
                      $logLines[] = '  Sor '.$e['row'].': '.$e['plate'].' – '.$e['dt'];
                    }
                    $logLines[] = '';
                  }
                  if ($logSkipped) {
                    $logLines[] = 'KIHAGYOTT SOROK ('.count($logSkipped).' db):';
                    foreach ($logSkipped as $e) {
                      $logLines[] = '  Sor '.$e['row'].': '.$e['reason'];
                    }
                    $logLines[] = '';
                  }
                  if ($vehList) {
                    $logLines[] = 'ÉRINTETT JÁRMŰVEK:';
                    foreach ($vehList as $v) {
                      $kmInfo = '';
                      if (!empty($v['km_updates'])) {
                        $last = end($v['km_updates']);
                        $kmInfo = ' | km: '.$last['from'].' → '.$last['to'];
                      }
                      $logLines[] = '  '.$v['plate'].' – '.$v['count'].' tétel'.$kmInfo;
                    }
                  }
                  $logLines[] = '=== VÉGE ===';
                  $logContent = implode(PHP_EOL, $logLines).PHP_EOL;
                  $logFile = dirname(__DIR__).'/storage/logs/fuel_import_'.$importId.'_'.date('Ymd_His').'.log';
                  @file_put_contents($logFile, $logContent);
                  pm_log("summary written to ".basename($logFile));

                  $result = [
                    'import_id'  => $importId,
                    'file'       => $orig,
                    'rows_total' => $rowsTotal,
                    'imported'   => $rowsOk,
                    'unmatched'  => $rowsUnmatched,
                    'skipped'    => $rowsSkip,
                    'dupes'      => $dupes,
                    'vehicles'   => $vehList,
                    'log_unmatched' => $logUnmatched,
                    'log_dupes'     => $logDupes,
                    'log_skipped'   => $logSkipped,
                    'log_file'      => basename($logFile),
                  ];
                }

              } catch (Throwable $e) {
                pm_log("phpspreadsheet err ".$e->getMessage());
                $error = 'Az XLS beolvasása nem sikerült: '.htmlspecialchars($e->getMessage());
              }
            }
          }
        }
      }
    }
  }
}
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5">Üzemanyag import (XLS/XLSX)</h1>
  <a class="btn btn-outline-secondary btn-sm" href="/vehicles.php?module=vehicles">Vissza a járművekhez</a>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>
<?php if ($result):
  $hasProblems = ($result['unmatched'] > 0 || $result['skipped'] > 0 || $result['dupes'] > 0);
  $alertClass  = $hasProblems ? 'alert-warning' : 'alert-success';
?>
  <div class="alert <?= $alertClass ?> mb-3">
    <div class="fw-bold mb-1"><?= $hasProblems ? 'Import kész – figyelj a figyelmeztetésekre!' : 'Import sikeresen kész.' ?></div>
    <div class="d-flex flex-wrap gap-3 small">
      <span>Import ID: <strong><?= (int)$result['import_id'] ?></strong></span>
      <span>Összes sor: <strong><?= (int)$result['rows_total'] ?></strong></span>
      <span class="text-success">✔ Importálva: <strong><?= (int)$result['imported'] ?></strong></span>
      <?php if ($result['dupes'] > 0): ?><span class="text-secondary">⟳ Már megvolt: <strong><?= (int)$result['dupes'] ?></strong></span><?php endif; ?>
      <?php if ($result['unmatched'] > 0): ?><span class="text-danger">✘ Ismeretlen rendszám: <strong><?= (int)$result['unmatched'] ?></strong></span><?php endif; ?>
      <?php if ($result['skipped'] > 0): ?><span class="text-warning">⚠ Kihagyott: <strong><?= (int)$result['skipped'] ?></strong></span><?php endif; ?>
    </div>
    <?php if (!empty($result['log_file'])): ?>
      <div class="small text-muted mt-1">Log: <code><?= htmlspecialchars($result['log_file']) ?></code></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($result['vehicles'])): ?>
    <div class="card p-0 mb-3">
      <div class="card-header"><strong>✔ Sikeresen importált járművek</strong></div>
      <table class="table table-sm table-striped m-0 align-middle">
        <thead>
          <tr>
            <th>Rendszám</th>
            <th class="text-end">Importált tételek</th>
            <th>Km felülírás</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($result['vehicles'] as $v):
          $kmUps = $v['km_updates'] ?? [];
          $kmTxt = '—';
          if (is_array($kmUps) && count($kmUps) > 0) {
            $last = $kmUps[count($kmUps)-1];
            $kmTxt = $last['from'].' → '.$last['to'].' km';
            if (count($kmUps) > 1) $kmTxt .= ' (+'.(count($kmUps)-1).' felülírás)';
          }
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($v['plate'] ?? '') ?></strong></td>
            <td class="text-end"><?= (int)($v['count'] ?? 0) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($kmTxt) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($result['log_unmatched'])): ?>
    <?php
      // Rendszámok csoportosítva sorok listájával
      $unmGrouped = [];
      foreach ($result['log_unmatched'] as $e) { $unmGrouped[$e['plate']][] = $e['row']; }
    ?>
    <div class="card p-0 mb-3 border-danger">
      <div class="card-header bg-danger-subtle text-danger"><strong>✘ Ismeretlen rendszámok (<?= count($unmGrouped) ?> egyedi)</strong></div>
      <table class="table table-sm m-0 align-middle">
        <thead><tr><th>Rendszám az XLS-ben</th><th>Sorok</th></tr></thead>
        <tbody>
        <?php foreach ($unmGrouped as $pl => $rows): ?>
          <tr>
            <td><code><?= htmlspecialchars($pl) ?></code></td>
            <td class="small text-muted"><?= implode(', ', $rows) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="card-footer small text-muted">Ezekhez a rendszámokhoz nincs egyező jármű az adatbázisban. Ellenőrizd a rendszám helyesírását!</div>
    </div>
  <?php endif; ?>

  <?php if (!empty($result['log_dupes'])): ?>
    <div class="card p-0 mb-3 border-secondary">
      <div class="card-header bg-secondary-subtle"><strong>⟳ Már importált (duplikált) tételek (<?= count($result['log_dupes']) ?> db)</strong></div>
      <table class="table table-sm m-0 align-middle">
        <thead><tr><th>Sor</th><th>Rendszám</th><th>Dátum</th></tr></thead>
        <tbody>
        <?php foreach ($result['log_dupes'] as $e): ?>
          <tr>
            <td class="text-muted"><?= (int)$e['row'] ?></td>
            <td><code><?= htmlspecialchars($e['plate']) ?></code></td>
            <td class="small"><?= htmlspecialchars($e['dt']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="card-footer small text-muted">Ezek a tételek egy korábbi importban már szerepeltek, nem kerültek be újra.</div>
    </div>
  <?php endif; ?>

  <?php if (!empty($result['log_skipped'])): ?>
    <div class="card p-0 mb-3 border-warning">
      <div class="card-header bg-warning-subtle"><strong>⚠ Kihagyott sorok (<?= count($result['log_skipped']) ?> db)</strong></div>
      <table class="table table-sm m-0 align-middle">
        <thead><tr><th>Sor</th><th>Ok</th></tr></thead>
        <tbody>
        <?php foreach ($result['log_skipped'] as $e): ?>
          <tr>
            <td class="text-muted"><?= (int)$e['row'] ?></td>
            <td class="small"><?= htmlspecialchars($e['reason']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php endif; ?>

<div class="card p-3">
  <form method="post" enctype="multipart/form-data">
    <div class="mb-2">
      <label class="form-label">MOL XLS/XLSX fájl</label>
      <input type="file" name="xls" class="form-control" accept=".xls,.xlsx" required>
      <div class="form-text">A feltöltés után a szerver PhpSpreadsheet segítségével közvetlenül beolvassa és importálja (nincs LibreOffice/CSV konverzió).</div>
    </div>
    <button class="btn btn-primary">Feltöltés és feldolgozás</button>
  </form>
</div>

<?php
try{
  $cols = $pdo->query("SHOW COLUMNS FROM fuel_imports")->fetchAll(PDO::FETCH_ASSOC);
  $c=[]; foreach($cols as $x){ $c[$x['Field']]=true; }
  $sel = "SELECT id, uploaded_at, orig_name";
  if (isset($c['stored_path'])) $sel.=", stored_path";
  if (isset($c['stored_filename'])) $sel.=", stored_filename";
  if (isset($c['rows_total'])) $sel.=", rows_total";
  if (isset($c['rows_imported'])) $sel.=", rows_imported";
  if (isset($c['rows_ok'])) $sel.=", rows_ok";
  if (isset($c['rows_unmatched'])) $sel.=", rows_unmatched";
  if (isset($c['rows_skipped'])) $sel.=", rows_skipped";
  $sel .= " FROM fuel_imports ORDER BY id DESC LIMIT 10";
  $last = $pdo->query($sel)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e){ $last=[]; }

if ($last):
?>
<div class="card p-0 mt-3">
  <div class="card-header"><strong>Legutóbbi importok</strong></div>
  <table class="table table-striped m-0 align-middle">
    <thead><tr><th>ID</th><th>Dátum</th><th>Fájl</th><th class="text-end">Összes</th><th class="text-end">Importált</th><th class="text-end">Ismeretlen</th><th class="text-end">Kihagyott</th><th class="text-end">Művelet</th></tr></thead>
    <tbody>
    <?php foreach($last as $r):
      $imp = $r['rows_imported'] ?? ($r['rows_ok'] ?? '');
      $tot = $r['rows_total'] ?? '';
      $unm = $r['rows_unmatched'] ?? '';
      $sk  = $r['rows_skipped'] ?? '';
    ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <td><?= htmlspecialchars($r['uploaded_at'] ?? '') ?></td>
        <?php
        $storedFile = $r['stored_path'] ?? ($r['stored_filename'] ?? null);
        $hasFile = ($storedFile && is_file(dirname(__DIR__).'/storage/uploads/fuel_imports/'.basename((string)$storedFile)));
      ?>
      <td>
        <?php if ($hasFile): ?>
          <a href="/vehicle_fuel_import.php?download=<?= (int)$r['id'] ?>" class="link-primary"><?= htmlspecialchars($r['orig_name'] ?? '') ?></a>
          <span class="text-muted small">(letöltés)</span>
        <?php else: ?>
          <?= htmlspecialchars($r['orig_name'] ?? '') ?>
          <span class="text-muted small">(nincs fájl)</span>
        <?php endif; ?>
      </td>
        <td class="text-end"><?= htmlspecialchars((string)$tot) ?></td>
        <td class="text-end"><?= htmlspecialchars((string)$imp) ?></td>
        <td class="text-end"><?= htmlspecialchars((string)$unm) ?></td>
        <td class="text-end"><?= htmlspecialchars((string)$sk) ?></td>
      
  <td class="text-end">
    <?php if ($hasFile): ?>
      <form method="post" class="d-inline">
        <?= Csrf::field() ?>
        <input type="hidden" name="delete_file_id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Biztosan törlöd a feltöltött fájlt az Import #<?= (int)$r['id'] ?>-hoz?\n(A tankolási tételek megmaradnak.)')">Fájl törlése</button>
      </form>
    <?php else: ?>
      <span class="text-muted">—</span>
    <?php endif; ?>
  </td>
</tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>
