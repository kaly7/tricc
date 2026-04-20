<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/pdf_mpdf.php';
require_login();
require_role('admin');

$u = current_user();
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$sku = trim((string)($_GET['sku'] ?? ''));
$cat = (int)($_GET['category_id'] ?? 0);
$holderRaw = (string)($_GET['holder_id'] ?? '0');
$mode = (string)($_GET['mode'] ?? 'view');
if (!in_array($mode, ['view','send'], true)) {
  $mode = 'view';
}

$isEligible = str_starts_with($holderRaw, 'emp:') && $cat === 0 && $q === '' && $sku === '';
if (!$isEligible) {
  flash_set('err', 'Szerszámkönyv csak akkor nyomtatható, ha egy dolgozó van kiválasztva, a kategória összes, és a megnevezés / cikkszám mező üres.');
  header('Location: '.base_url('assets.php'));
  exit;
}

$employeeId = (int)substr($holderRaw, 4);
if ($employeeId <= 0) {
  flash_set('err', 'Érvénytelen dolgozó azonosító.');
  header('Location: '.base_url('assets.php'));
  exit;
}

$employeeName = '#'.$employeeId;
$employeeStatus = 'ismeretlen';
try {
  $hr = db_hr();
  $stEmp = $hr->prepare("SELECT full_name, is_active FROM employees WHERE id=? LIMIT 1");
  $stEmp->execute([$employeeId]);
  $emp = $stEmp->fetch(PDO::FETCH_ASSOC);
  if (!$emp) {
    flash_set('err', 'A kiválasztott dolgozó nem található a HR adatbázisban.');
    header('Location: '.base_url('assets.php'));
    exit;
  }
  $employeeName = (string)($emp['full_name'] ?? ('#'.$employeeId));
  $employeeStatus = ((int)($emp['is_active'] ?? 0) === 1) ? 'aktív' : 'inaktív';
} catch (Throwable $e) {
  flash_set('err', 'HR kapcsolat hiba: '.$e->getMessage());
  header('Location: '.base_url('assets.php'));
  exit;
}

$params = [];
$where = "a.is_deleted=0";

if ($q !== '') {
  $where .= " AND a.name LIKE :q";
  $params[':q'] = '%'.$q.'%';
}
if ($sku !== '') {
  $where .= " AND a.sku LIKE :sku";
  $params[':sku'] = '%'.$sku.'%';
}
$where .= " AND a.current_employee_id = :holder_emp";
$params[':holder_emp'] = $employeeId;

if ($cat > 0) {
  $where .= " AND EXISTS (
    SELECT 1
    FROM asset_category ac
    WHERE ac.asset_id=a.id
      AND ac.category_id IN (
        WITH RECURSIVE cat_tree AS (
          SELECT id FROM categories WHERE id=:cat AND is_deleted=0
          UNION ALL
          SELECT c.id
          FROM categories c
          JOIN cat_tree t ON c.parent_id = t.id
          WHERE c.is_deleted=0
        )
        SELECT id FROM cat_tree
      )
  )";
  $params[':cat'] = $cat;
}

$categoryLabel = '— összes —';
if ($cat > 0) {
  try {
    $stCat = $pdo->prepare("SELECT name FROM categories WHERE id=? LIMIT 1");
    $stCat->execute([$cat]);
    $categoryLabel = (string)($stCat->fetchColumn() ?: ('#'.$cat));
  } catch (Throwable $e) {
    $categoryLabel = '#'.$cat;
  }
}

// assets.note / assets.notes kompatibilitás különböző séma-verziókhoz
$assetCols = [];
try {
  foreach ($pdo->query("SHOW COLUMNS FROM assets")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $assetCols[(string)($c['Field'] ?? '')] = true;
  }
} catch (Throwable $e) {
  $assetCols = [];
}

$noteExpr = "'' AS note";
if (isset($assetCols['note'])) {
  $noteExpr = 'a.note AS note';
} elseif (isset($assetCols['notes'])) {
  $noteExpr = 'a.notes AS note';
}

$sql = "
  SELECT a.id, a.name, a.sku, a.qr_value, $noteExpr,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
       FROM asset_category ac
       JOIN categories c ON c.id=ac.category_id
      WHERE ac.asset_id=a.id AND c.is_deleted=0
    ) AS categories
  FROM assets a
  WHERE $where
  ORDER BY a.name ASC, a.id ASC
  LIMIT 5000
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$assets = [];
foreach ($rows as $r) {
  $assets[] = [
    'name'       => (string)($r['name'] ?? ''),
    'inventory'  => (string)($r['qr_value'] ?? ''),
    'serial'     => (string)($r['sku'] ?? ''),
    'categories' => (string)($r['categories'] ?? ''),
    'note'       => (string)($r['note'] ?? ''),
  ];
}

$filters = [
  'Kinél van: '.$employeeName,
  'Megnevezés: '.($q !== '' ? $q : '—'),
  'Cikkszám: '.($sku !== '' ? $sku : '—'),
  'Kategória: '.$categoryLabel,
];

$printedBy = (string)($u['full_name'] ?? $u['name'] ?? $u['username'] ?? ('#'.(int)($u['id'] ?? 0)));
$toolbookCentralEmail = trim((string)app_setting_get('toolbook_central_email', ''));

try {
  $pdfWeb = generate_employee_toolbook_pdf_html([
    'doc_date'        => date('Y-m-d H:i:s'),
    'printed_by'      => $printedBy,
    'employee'        => $employeeName,
    'employee_status' => $employeeStatus,
    'filters'         => implode("
", $filters),
    'asset_count'     => count($assets),
    'assets'          => $assets,
  ]);

  $pdfAbs = dirname(__DIR__) . $pdfWeb;
  if (!is_file($pdfAbs)) {
    throw new RuntimeException('A szerszámkönyv PDF nem található: '.$pdfAbs);
  }

  if ($mode === 'send') {
    if ($toolbookCentralEmail === '') {
      throw new RuntimeException('Nincs beállítva archív email cím a Fiók oldalon.');
    }

    require_once __DIR__.'/../app/mailer.php';
    $cfg = require __DIR__ . '/../app/config.php';
    $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
    $bcc  = (string)($cfg['mail_bcc'] ?? '');
    $subject = 'Perfect-Phone – Szerszámkönyv – ' . $employeeName;
    $safeEmployeeName = preg_replace('/[^a-z0-9_-]+/i', '_', $employeeName) ?: 'dolgozo';

    $body = "Archívumba küldött dolgozói szerszámkönyv.

"
      . "Dolgozó: {$employeeName}
"
      . "Státusz: {$employeeStatus}
"
      . "Küldte: {$printedBy}
"
      . "Készült: " . date('Y-m-d H:i:s') . "
"
      . "Eszközök száma: " . count($assets) . "
";

    $mailOk = send_mail_with_attachment(
      $toolbookCentralEmail,
      $subject,
      $body,
      $from,
      $bcc !== '' ? $bcc : null,
      $pdfAbs,
      'szerszamkonyv_' . $safeEmployeeName . '.pdf'
    );

    if (!$mailOk) {
      throw new RuntimeException('Az archív email küldés nem sikerült.');
    }

    flash_set('ok', 'A szerszámkönyv PDF elküldve az archív email címre.');
    header('Location: '.base_url('assets.php?'.http_build_query([
      'q' => $q,
      'sku' => $sku,
      'category_id' => $cat,
      'holder_id' => $holderRaw,
    ])));
    exit;
  }

  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="'.basename($pdfAbs).'"');
  header('Content-Length: '.(string)filesize($pdfAbs));
  readfile($pdfAbs);
  exit;
} catch (Throwable $e) {
  flash_set('err', 'Szerszámkönyv PDF hiba: '.$e->getMessage());
  header('Location: '.base_url('assets.php?'.http_build_query([
    'q' => $q,
    'sku' => $sku,
    'category_id' => $cat,
    'holder_id' => $holderRaw,
  ])));
  exit;
}
