<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Db;
use App\Auth;
use App\Middleware;

Auth::start();
Middleware::requireAuth();
$pdo = Db::pdo();
$u = Auth::user();
$isAdmin = ((int)($u['role_id'] ?? 0) === 1);
if (!$isAdmin) {
  http_response_code(403);
  echo "<div class='alert alert-danger'>Nincs jogosultság.</div>";
  require dirname(__DIR__).'/views/_layout_bottom.php';
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function vi_log($msg){
  $dir = dirname(__DIR__).'/storage/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir.'/app.log', '['.date('Y-m-d H:i:s').'] vehicle_import '.$msg.PHP_EOL, FILE_APPEND);
}

function vi_table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SHOW TABLES LIKE ?");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function vi_columns(PDO $pdo, string $table): array {
  $rows = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
  $out = [];
  foreach ($rows as $r) $out[$r['Field']] = $r;
  return $out;
}

function vi_has_col(array $cols, string $name): bool {
  return isset($cols[$name]);
}

function vi_limit($v, int $max): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  if (mb_strlen($v, 'UTF-8') > $max) {
    return trim(mb_substr($v, 0, $max, 'UTF-8'));
  }
  return $v;
}

function vi_norm($s): string {
  $s = trim((string)$s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s;
}

function vi_slug($s): string {
  $s = trim((string)$s);
  if ($s === '') return 'UNKNOWN';
  $map = ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ö'=>'O','Ő'=>'O','Ú'=>'U','Ü'=>'U','Ű'=>'U','á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ö'=>'O','ő'=>'O','ú'=>'U','ü'=>'U','ű'=>'U'];
  $s = strtr($s, $map);
  $s = strtoupper($s);
  $s = preg_replace('/[^A-Z0-9]+/', '_', $s);
  $s = trim($s, '_');
  return $s !== '' ? $s : 'UNKNOWN';
}

function vi_norm_plate($s): ?string {
  $raw = trim((string)$s);
  if ($raw === '' || $raw === '-' || $raw === '0') return null;
  $u = strtoupper($raw);
  preg_match_all('/[A-Z]{2,5}[\s\-]?\d{2,4}/u', $u, $m);
  if (!empty($m[0])) {
    $plate = end($m[0]);
    $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
    if ($plate !== '' && strlen($plate) <= 16) return $plate;
  }
  return null;
}

function vi_parse_int($v): ?int {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '' || $s === '-') return null;
  $s = str_replace(["\xc2\xa0", ' '], '', $s);
  $s = preg_replace('/[^0-9\-]/', '', $s);
  if ($s === '' || $s === '-') return null;
  return (int)$s;
}

function vi_parse_date($v): ?string {
  if ($v === null) return null;
  if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
  if (is_numeric($v)) {
    try {
      $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v);
      return $dt ? $dt->format('Y-m-d') : null;
    } catch (Throwable $e) {}
  }
  $s = trim((string)$v);
  if ($s === '' || $s === '-') return null;
  $s = str_replace(['.', '/'], '-', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  foreach (['Y-m-d','Y-m-d H:i:s','Y-m-d H:i','d-m-Y','d-m-Y H:i:s','d-m-Y H:i','d.m.Y','d.m.Y H:i:s'] as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $s);
    if ($dt) return $dt->format('Y-m-d');
  }
  $ts = strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}

function vi_clean_euro($s): ?string {
  $s = trim((string)$s);
  if ($s === '' || $s === '-') return null;
  $s = preg_replace('/\(.*?\)/u', '', $s);
  $s = str_ireplace(['EORO','EUIRO','EUlRO','EURO '], ['EURO','EURO','EURO','EURO'], $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  $s = trim($s, " \t\n\r\0\x0B/");
  return vi_limit($s, 32);
}

function vi_clean_type($s): string {
  $s = trim((string)$s);
  if ($s === '' || $s === '-') return 'Egyedi';
  $key = mb_strtolower($s, 'UTF-8');
  $map = [
    'tgk' => 'TGK',
    'szgk' => 'SZGK',
    'munkagép' => 'Munkagép',
    'munkagep' => 'Munkagép',
    'pótkocsi' => 'Pótkocsi',
    'potkocsi' => 'Pótkocsi',
    'utánfutó' => 'Utánfutó',
    'utanfuto' => 'Utánfutó',
    'kompresszor' => 'Kompresszor',
    'smkp' => 'SMKP',
    'oszlopszállító' => 'Oszlopszállító',
    'oszlopszallito' => 'Oszlopszállító',
    'dobszállító' => 'Dobszállító',
    'dobszallito' => 'Dobszállító',
  ];
  return vi_limit($map[$key] ?? $s, 80) ?? 'Egyedi';
}

function vi_clean_body_type($s): array {
  $raw = trim((string)$s);
  if ($raw === '' || mb_strtolower($raw, 'UTF-8') === 'kérjük kiválasztani') return [null, null];
  if (mb_strlen($raw, 'UTF-8') > 32) return [null, $raw];
  if (preg_match('/motor|henger|dizel|diesel|típusú|tipusu/ui', $raw)) return [null, $raw];
  $u = mb_strtoupper($raw, 'UTF-8');
  $map = [
    'ZÁRT/2' => 'Zárt/2',
    'ZÁRT/3' => 'Zárt/3',
    'ZÁRT/6' => 'Zárt/6',
    'ZÁRT, ABL/3' => 'Zárt, abl/3',
    'NYITOTT/2' => 'Nyitott/2',
    '2 FŐ' => '2 fő',
    '3 FŐ' => '3 fő',
    '7 FŐ' => '7 fő',
    '2' => '2 fő',
    '3' => '3 fő',
    '5' => '5 fő',
    '7' => '7 fő',
    '7/0' => null,
    'PT2' => 'PT2',
  ];
  $key = preg_replace('/\s+/u', ' ', $u);
  if (array_key_exists($key, $map)) return [$map[$key], null];
  return [vi_limit($raw, 32), null];
}

function vi_extract_seats($bodyRaw): ?int {
  $s = trim((string)$bodyRaw);
  if ($s === '') return null;
  if (preg_match('/\b(\d{1,2})\s*fő\b/ui', $s, $m)) {
    $n = (int)$m[1];
    return ($n >= 1 && $n <= 99) ? $n : null;
  }
  if (preg_match('/\/\s*(\d{1,2})\b/u', $s, $m)) {
    $n = (int)$m[1];
    return ($n >= 1 && $n <= 99) ? $n : null;
  }
  if (preg_match('/^\s*(\d{1,2})\s*$/u', $s, $m)) {
    $n = (int)$m[1];
    return ($n >= 1 && $n <= 99) ? $n : null;
  }
  return null;
}

function vi_notes(array $items): ?string {
  $lines = [];
  foreach ($items as $label => $value) {
    $value = trim((string)$value);
    if ($value === '' || $value === '-' || strtolower($value) === 'none') continue;
    $lines[] = $label.': '.$value;
  }
  return $lines ? implode(PHP_EOL, $lines) : null;
}

function vi_division_name($raw): string {
  $s = trim((string)$raw);
  if (preg_match('/\b11\b/u', $s)) return 'Pécs';
  if (preg_match('/\b12\b/u', $s)) return 'Erősáram';
  if (preg_match('/\b6\b/u', $s)) return 'Gyengeáram';
  if (preg_match('/\b10\b/u', $s)) return 'Raktár';
  return 'Egyedi';
}

function vi_lookup_id(PDO $pdo, string $table, ?string $name): ?int {
  if ($name === null) return null;
  $name = trim($name);
  if ($name === '') return null;
  $cols = vi_columns($pdo, $table);
  if (!isset($cols['name'])) return null;

  $max = 190;
  if (preg_match('/varchar\((\d+)\)/i', $cols['name']['Type'], $m)) $max = (int)$m[1];
  $name = vi_limit($name, $max);
  if ($name === null || $name === '') return null;

  $st = $pdo->prepare("SELECT id FROM `{$table}` WHERE name=? LIMIT 1");
  $st->execute([$name]);
  $id = $st->fetchColumn();
  if ($id !== false) return (int)$id;

  $fields = ['name'];
  $vals = [$name];
  foreach (['is_active' => 1, 'sort_order' => 0, 'sort' => 0] as $field => $value) {
    if (isset($cols[$field])) { $fields[] = $field; $vals[] = $value; }
  }
  $sql = "INSERT INTO `{$table}` (".implode(',', $fields).") VALUES (".implode(',', array_fill(0, count($fields), '?')).")";
  $pdo->prepare($sql)->execute($vals);
  return (int)$pdo->lastInsertId();
}

function vi_next_generated_identifier(array &$counters, string $base): string {
  $base = vi_slug($base);
  $counters[$base] = ($counters[$base] ?? 0) + 1;
  return sprintf('%s_%03d', $base, $counters[$base]);
}

$autoload1 = dirname(__DIR__).'/vendor/autoload.php';
$autoload2 = dirname(__DIR__).'/../vendor/autoload.php';
if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
  if (is_file($autoload1)) require_once $autoload1;
  elseif (is_file($autoload2)) require_once $autoload2;
}

$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!\App\Csrf::check($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    exit('CSRF hiba.');
  }

  if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    $error = 'PhpSpreadsheet nincs telepítve.';
  } elseif (!isset($_FILES['xls']) || $_FILES['xls']['error'] !== UPLOAD_ERR_OK) {
    $error = 'Nincs fájl, vagy feltöltési hiba történt.';
  } else {
    $tmp = $_FILES['xls']['tmp_name'];
    $orig = $_FILES['xls']['name'] ?? 'import.xlsx';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xls','xlsx'], true)) {
      $error = 'Csak XLS/XLSX fájl tölthető fel.';
    } else {
      $upDir = dirname(__DIR__).'/storage/uploads/vehicle_imports';
      if (!is_dir($upDir)) @mkdir($upDir, 0775, true);
      $stored = $upDir.'/'.date('Ymd_His').'_'.mt_rand(100000,999999).'_'.preg_replace('/[^A-Za-z0-9_.-]+/','_', $orig);

      if (!move_uploaded_file($tmp, $stored)) {
        $error = 'A feltöltött fájlt nem sikerült elmenteni.';
      } else {
        try {
          $vehiclesCols = vi_columns($pdo, 'vehicles');
          $reset = isset($_POST['reset_before_import']) && (string)$_POST['reset_before_import'] === '1';

          $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($stored);
          $sheet = $spreadsheet->sheetNameExists('Munka1') ? $spreadsheet->getSheetByName('Munka1') : $spreadsheet->getActiveSheet();
          $highestRow = (int)$sheet->getHighestDataRow();

          $identifierCounters = [];
          $rowsTotal = 0;
          $rowsImported = 0;
          $rowsUpdated = 0;
          $rowsSkipped = 0;
          $lookupCreated = ['divisions'=>0,'types'=>0,'euro'=>0,'body'=>0];
          $examples = [];

          $pdo->beginTransaction();

          if ($reset) {
            $tablesToTruncate = [
              'vehicle_tire_installations',
              'vehicle_axles',
              'vehicle_fuel_entries',
              'vehicle_issues',
              'vehicle_service_entries',
              'vehicle_tires',
              'fuel_imports',
              'vehicles',
            ];
            $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $existingMap = array_flip($existingTables);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($tablesToTruncate as $tbl) {
              if (isset($existingMap[$tbl])) {
                $pdo->exec("TRUNCATE `{$tbl}`");
              }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
          }

          // preload existing generated identifiers if any
          if (vi_has_col($vehiclesCols, 'vehicle_identifier')) {
            $existingIds = $pdo->query("SELECT vehicle_identifier FROM vehicles WHERE vehicle_identifier IS NOT NULL AND vehicle_identifier<>''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($existingIds as $eid) {
              if (preg_match('/^(.*)_(\d{3})$/', (string)$eid, $m)) {
                $base = $m[1];
                $num = (int)$m[2];
                $identifierCounters[$base] = max($identifierCounters[$base] ?? 0, $num);
              }
            }
          }

          $findByIdentifier = null;
          if (vi_has_col($vehiclesCols, 'vehicle_identifier')) {
            $findByIdentifier = $pdo->prepare("SELECT id FROM vehicles WHERE vehicle_identifier=? LIMIT 1");
          }
          $findByPlate = $pdo->prepare("SELECT id FROM vehicles WHERE license_plate=? LIMIT 1");

          for ($r = 4; $r <= $highestRow; $r++) {
            $vals = [];
            for ($c = 1; $c <= 24; $c++) {
              $cellRef = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
              $cell = $sheet->getCell($cellRef);
              $v = $cell->getCalculatedValue();
              $vals[$c] = $v;
            }

            // skip blank rows
            $allBlank = true;
            foreach ($vals as $v) {
              if (trim((string)$v) !== '') { $allBlank = false; break; }
            }
            if ($allBlank) continue;

            $rowsTotal++;

            $rawDivision = $vals[1] ?? null;
            $rawPlate    = $vals[2] ?? null;
            $rawRegDoc   = $vals[3] ?? null;
            $rawVin      = $vals[4] ?? null;
            $rawEngineNo = $vals[5] ?? null;
            $rawEuro     = $vals[6] ?? null;
            $rawType     = $vals[7] ?? null;
            $rawMake     = $vals[8] ?? null;
            $rawModel    = $vals[9] ?? null;
            $rawBody     = $vals[10] ?? null;
            $rawCurb     = $vals[11] ?? null;
            $rawGross    = $vals[12] ?? null;
            $rawCcm      = $vals[13] ?? null;
            $raw14       = $vals[14] ?? null;
            $raw15       = $vals[15] ?? null;
            $raw16       = $vals[16] ?? null;
            $rawPower    = $vals[17] ?? null;
            $rawYear     = $vals[18] ?? null;
            $raw19       = $vals[19] ?? null;
            $rawPurchase = $vals[20] ?? null;
            $raw21       = $vals[21] ?? null;
            $raw22       = $vals[22] ?? null;
            $raw23       = $vals[23] ?? null;
            $raw24       = $vals[24] ?? null;

            $licensePlate = vi_norm_plate($rawPlate);
            $make = vi_limit(vi_norm($rawMake), 80) ?? '';
            $model = vi_limit(vi_norm($rawModel), 80) ?? '';
            $vehicleTypeName = vi_clean_type($rawType);
            $euroName = vi_clean_euro($rawEuro);
            [$bodyTypeName, $bodyTypeNote] = vi_clean_body_type($rawBody);
            $seats = vi_extract_seats($rawBody);
            $curb = vi_parse_int($rawCurb);
            $gross = vi_parse_int($rawGross);
            $engineCcm = vi_parse_int($rawCcm);
            $powerKw = vi_parse_int($rawPower);
            $year = vi_parse_int($rawYear);
            $purchaseDate = vi_parse_date($rawPurchase);
            $registrationDoc = vi_limit(vi_norm($rawRegDoc), 64);
            $vin = vi_limit(vi_norm($rawVin), 100);
            $engineNo = vi_limit(vi_norm($rawEngineNo), 100);

            if ($make === '' && $model === '' && $licensePlate === null && $vin === null) {
              $rowsSkipped++;
              continue;
            }

            $identifier = $licensePlate;
            if ($identifier === null) {
              $identifier = vi_next_generated_identifier($identifierCounters, $make !== '' ? $make : ($vehicleTypeName !== '' ? $vehicleTypeName : 'UNKNOWN'));
            }

            $divisionName = vi_division_name($rawDivision);
            $divisionId = vi_lookup_id($pdo, 'vehicle_divisions', $divisionName);
            $vehicleTypeId = vi_lookup_id($pdo, 'vehicle_types', $vehicleTypeName);
            if (!$vehicleTypeId) $vehicleTypeId = vi_lookup_id($pdo, 'vehicle_types', 'Egyedi');
            $euroClassId = vi_lookup_id($pdo, 'vehicle_euro_classes', $euroName);
            $bodyTypeId = vi_lookup_id($pdo, 'vehicle_body_types', $bodyTypeName);

            $notes = vi_notes([
              'Eredeti rendszám mező' => ($licensePlate ? '' : (string)$rawPlate),
              'Típus kivitel (eredeti)' => $bodyTypeNote ?? '',
              'XLS oszlop 14' => $raw14,
              'XLS oszlop 15' => $raw15,
              'XLS oszlop 16' => $raw16,
              'XLS oszlop 19' => (!is_numeric($raw19) && trim((string)$raw19)!=='') ? $raw19 : '',
              'XLS oszlop 21' => $raw21,
              'XLS oszlop 22' => $raw22,
              'XLS oszlop 23' => $raw23,
              'XLS oszlop 24' => $raw24,
            ]);

            $existingId = null;
            if ($findByIdentifier) {
              $findByIdentifier->execute([$identifier]);
              $existingId = $findByIdentifier->fetchColumn();
            } elseif ($licensePlate !== null) {
              $findByPlate->execute([$licensePlate]);
              $existingId = $findByPlate->fetchColumn();
            }

            $data = [
              'license_plate' => $licensePlate,
              'registration_doc_no' => $registrationDoc,
              'make' => $make,
              'model' => $model,
              'fuel_type' => 'diesel',
              'vehicle_type_id' => $vehicleTypeId,
              'division_id' => $divisionId,
              'axle_count' => 2,
              'odometer_km' => 0,
              'oil_interval_km' => 15000,
              'service_interval_km' => null,
              'service_interval_months' => null,
              'archived' => 0,
              'euro_class_id' => $euroClassId,
              'body_type_id' => $bodyTypeId,
              'seats' => $seats,
              'curb_weight_kg' => $curb,
              'gross_weight_kg' => $gross,
              'power_kw' => $powerKw,
              'manufacture_year' => $year,
            ];
            if (vi_has_col($vehiclesCols, 'vehicle_identifier')) $data['vehicle_identifier'] = $identifier;
            if (vi_has_col($vehiclesCols, 'vin')) $data['vin'] = $vin;
            if (vi_has_col($vehiclesCols, 'engine_no')) $data['engine_no'] = $engineNo;
            if (vi_has_col($vehiclesCols, 'engine_ccm')) $data['engine_ccm'] = $engineCcm;
            if (vi_has_col($vehiclesCols, 'purchase_date')) $data['purchase_date'] = $purchaseDate;
            if (vi_has_col($vehiclesCols, 'notes')) $data['notes'] = $notes;

            // if license_plate is still required, fall back to identifier
            if ((!isset($vehiclesCols['license_plate']['Null']) || strtoupper($vehiclesCols['license_plate']['Null']) !== 'YES') && ($data['license_plate'] === null || $data['license_plate'] === '')) {
              $data['license_plate'] = $identifier;
            }

            // keep strings within DB limits
            $data['license_plate'] = vi_limit($data['license_plate'], 16);
            if ($data['license_plate'] === null && strtoupper($vehiclesCols['license_plate']['Null'] ?? 'NO') !== 'YES') {
              $data['license_plate'] = vi_limit($identifier, 16);
            }

            if ($existingId) {
              $sets = [];
              $vals2 = [];
              foreach ($data as $k => $v) {
                if (!vi_has_col($vehiclesCols, $k)) continue;
                $sets[] = "`$k`=?";
                $vals2[] = $v;
              }
              $vals2[] = (int)$existingId;
              $sql = "UPDATE vehicles SET ".implode(',', $sets)." WHERE id=?";
              $pdo->prepare($sql)->execute($vals2);
              $vehicleId = (int)$existingId;
              $rowsUpdated++;
            } else {
              $fields = [];
              $ph = [];
              $vals2 = [];
              foreach ($data as $k => $v) {
                if (!vi_has_col($vehiclesCols, $k)) continue;
                $fields[] = "`$k`";
                $ph[] = '?';
                $vals2[] = $v;
              }
              $sql = "INSERT INTO vehicles (".implode(',', $fields).") VALUES (".implode(',', $ph).")";
              $pdo->prepare($sql)->execute($vals2);
              $vehicleId = (int)$pdo->lastInsertId();
              $rowsImported++;

              if (vi_table_exists($pdo, 'vehicle_axles')) {
                $axSt = $pdo->prepare("INSERT INTO vehicle_axles (vehicle_id, axle_no, wheels_count, notes) VALUES (?,?,?,?)");
                for ($ax = 1; $ax <= 2; $ax++) {
                  $axSt->execute([$vehicleId, $ax, 2, '']);
                }
              }
            }

            if (count($examples) < 15) {
              $examples[] = [
                'identifier' => $identifier,
                'plate' => $data['license_plate'],
                'make' => $make,
                'model' => $model,
                'division' => $divisionName,
                'type' => $vehicleTypeName,
              ];
            }
          }

          $pdo->prepare("INSERT INTO audit_log (user_id, entity_type, entity_id, action, changed_fields) VALUES (?,?,?,?,?)")
            ->execute([
              (int)$u['id'],
              'vehicle_import',
              null,
              'import',
              json_encode([
                'file' => $orig,
                'sheet' => $sheet->getTitle(),
                'rows_total' => $rowsTotal,
                'inserted' => $rowsImported,
                'updated' => $rowsUpdated,
                'skipped' => $rowsSkipped,
                'reset_before_import' => $reset,
              ], JSON_UNESCAPED_UNICODE)
            ]);

          $pdo->commit();
          @unlink($stored);

          $result = [
            'file' => $orig,
            'rows_total' => $rowsTotal,
            'inserted' => $rowsImported,
            'updated' => $rowsUpdated,
            'skipped' => $rowsSkipped,
            'examples' => $examples,
          ];
          vi_log("ok file={$orig} total={$rowsTotal} inserted={$rowsImported} updated={$rowsUpdated} skipped={$rowsSkipped}");
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $error = 'Import hiba: '.$e->getMessage();
          vi_log('error '.$e->getMessage());
        }
      }
    }
  }
}
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5 mb-0">Jármű import (XLS/XLSX)</h1>
  <a class="btn btn-outline-secondary btn-sm" href="/vehicles.php?module=vehicles">Vissza a járművekhez</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
  <div class="alert alert-success">
    <div><strong>Import kész.</strong></div>
    <div class="small mt-1">
      Fájl: <?= h($result['file']) ?> • Sorok: <?= (int)$result['rows_total'] ?> • Új: <?= (int)$result['inserted'] ?> • Frissített: <?= (int)$result['updated'] ?> • Kihagyott: <?= (int)$result['skipped'] ?>
    </div>
  </div>

  <?php if (!empty($result['examples'])): ?>
    <div class="card p-0 mb-3">
      <div class="card-header"><strong>Minta az importált járművekből</strong></div>
      <table class="table table-sm table-striped m-0 align-middle">
        <thead>
          <tr>
            <th>Azonosító</th>
            <th>Rendszám</th>
            <th>Gyártmány</th>
            <th>Modell</th>
            <th>Divízió</th>
            <th>Fajta</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($result['examples'] as $x): ?>
          <tr>
            <td><strong><?= h($x['identifier']) ?></strong></td>
            <td><?= h($x['plate']) ?></td>
            <td><?= h($x['make']) ?></td>
            <td><?= h($x['model']) ?></td>
            <td><?= h($x['division']) ?></td>
            <td><?= h($x['type']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="card p-3">
  <form method="post" enctype="multipart/form-data" class="row g-3">
    <?= \App\Csrf::field() ?>
    <div class="col-12">
      <label class="form-label">Jármű XLS/XLSX fájl</label>
      <input type="file" name="xls" class="form-control" accept=".xls,.xlsx" required>
      <div class="form-text">A <strong>Munka1</strong> lapot olvassa, a fejléc a <strong>3. sor</strong>. A nem egyértelmű plusz mezők a megjegyzésbe kerülnek külön sorokban.</div>
    </div>
    <div class="col-12">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" id="reset_before_import" name="reset_before_import" value="1" checked>
        <label class="form-check-label" for="reset_before_import">A jelenlegi járműves adatokat törölje import előtt</label>
      </div>
      <div class="form-text">Törli: vehicle_fuel_entries, vehicle_issues, vehicle_service_entries, vehicle_tire_installations, vehicle_tires, fuel_imports, vehicles.</div>
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Import indítása</button>
      <a class="btn btn-outline-secondary" href="/vehicles.php?module=vehicles">Mégse</a>
    </div>
  </form>
</div>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>
