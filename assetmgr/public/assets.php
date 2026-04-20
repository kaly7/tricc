<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_login();
$u = current_user();
$isAdmin = (($u['role'] ?? '') === 'admin');

// User/Viewer: irány a saját eszközök
if (!$isAdmin) {
  header('Location: '.base_url('my_assets.php'));
  exit;
}

$pdo = db();

// --- Szűrők ---
$q = trim((string)($_GET['q'] ?? ''));                 // megnevezés (töredék)
$sku = trim((string)($_GET['sku'] ?? ''));             // cikkszám (töredék)
$cat = (int)($_GET['category_id'] ?? 0);               // kategória (fa szűrés)
$holderRaw = (string)($_GET['holder_id'] ?? '0');      // 0=összes, -1=senkinél, emp:<id>, ext:<id>, wh:<id>
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNo - 1) * $perPage;

$sort = (string)($_GET['sort'] ?? 'updated');
$dir  = strtolower((string)($_GET['dir'] ?? 'desc'));
$allowedSort = ['updated','name','sku','categories','holder'];
if (!in_array($sort, $allowedSort, true)) $sort = 'updated';
if (!in_array($dir, ['asc','desc'], true)) $dir = 'desc';

function build_query(array $over = []): string {
  $q = $_GET;
  foreach ($over as $k=>$v) {
    if ($v === null) unset($q[$k]); else $q[$k] = $v;
  }
  return http_build_query($q);
}

function sort_link(string $field, string $currentSort, string $currentDir): string {
  $nextDir = ($currentSort === $field && $currentDir === 'asc') ? 'desc' : 'asc';
  return 'assets.php?' . build_query(['sort'=>$field, 'dir'=>$nextDir, 'page'=>1]);
}

function sort_icon(string $field, string $currentSort, string $currentDir): string {
  if ($currentSort !== $field) return '';
  return $currentDir === 'asc' ? ' ▲' : ' ▼';
}

function empty_cell_markup(string $extraClass = ''): string {
  $cls = trim('empty-cell-mark '.$extraClass);
  return '<span class="'.$cls.'" aria-hidden="true"></span><span class="visually-hidden">nincs adat</span>';
}

function cell_or_mark(?string $value, string $class = ''): string {
  $value = trim((string)$value);
  if ($value === '') {
    return empty_cell_markup($class);
  }
  return e($value);
}

// Kategóriák teljes listája (fa dropdownhoz)
$catRows = $pdo->query("
  SELECT id, name, parent_id, sort_order
  FROM categories
  WHERE is_deleted=0
  ORDER BY sort_order ASC, name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Tree index
$byParent = [];
foreach ($catRows as $r) {
  $pid = (int)($r['parent_id'] ?? 0);
  if ($pid < 0) $pid = 0;
  $byParent[$pid][] = $r;
}

function render_cat_options(array $byParent, int $parentId, int $level, int $selectedId): void {
  if (empty($byParent[$parentId])) return;
  foreach ($byParent[$parentId] as $c) {
    $id = (int)$c['id'];
    $name = (string)$c['name'];
    // beljebb kezdés: NBSP + fa jel
    $indent = str_repeat("\xC2\xA0\xC2\xA0\xC2\xA0", max(0, $level)); // 3 NBSP per szint
    $prefix = ($level > 0) ? $indent."↳ " : "";
    $sel = ($id === $selectedId) ? "selected" : "";
    echo '<option value="'.$id.'" '.$sel.'>'.$prefix.e($name)."</option>\n";
    render_cat_options($byParent, $id, $level+1, $selectedId);
  }
}

// HR dolgozók lista (kinél van szűrőhöz)
$hrEmployees = [];
try {
  $hr = db_hr();
  $hrEmployees = $hr->query("SELECT id, full_name, is_active FROM employees ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $hrEmployees = [];
}

// Külsősök, akiknél aktív eszköz van
$externalHolders = [];
try {
  $externalHolders = $pdo->query("SELECT DISTINCT eh.id, eh.company_name, eh.contact_name
                                 FROM asset_external_assignments aea
                                 JOIN external_holders eh ON eh.id=aea.external_holder_id
                                 WHERE aea.status='active'
                                 ORDER BY eh.company_name, eh.contact_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $externalHolders = [];
}

// Raktárak
$warehouses = [];
try {
  $pdo->query("SELECT 1 FROM warehouses LIMIT 1");
  $warehouses = $pdo->query("SELECT id, name, is_active FROM warehouses WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $warehouses = [];
}

// Admin: külsősből visszavétel belső dolgozóhoz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'return_external') {
  $assignId = (int)($_POST['ext_assignment_id'] ?? 0);
  $toEmp = (int)($_POST['to_employee_id'] ?? 0);
  if ($assignId <= 0 || $toEmp <= 0) {
    flash_set('err', 'Hiányzó visszavételi adatok.');
    header('Location: assets.php');
    exit;
  }

  $toAbs = function(string $webOrAbs): string {
    $p = trim($webOrAbs);
    if ($p === '') return '';
    if ($p[0] === '/' && str_starts_with($p, '/storage/')) return __DIR__ . '/..' . $p;
    if ($p[0] === '/' && is_file($p)) return $p;
    $p = ltrim($p, '/');
    if (str_starts_with($p, 'storage/')) return __DIR__ . '/../' . $p;
    return $p;
  };

  $row = null;
  $hadReturnDocFlow = false;

  try {
    $pdo->beginTransaction();

    $aeaCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM asset_external_assignments")->fetchAll(PDO::FETCH_ASSOC) as $c) {
      $aeaCols[(string)$c['Field']] = true;
    }
    $hasReturnedTo = isset($aeaCols['returned_to_employee_id']);
    $hasReturnPdf  = isset($aeaCols['return_pdf_path']);
    $hasExtEmail   = isset($aeaCols['ext_email']);

    $st = $pdo->prepare("
      SELECT aea.*, eh.company_name, eh.contact_name, eh.phone
      FROM asset_external_assignments aea
      JOIN external_holders eh ON eh.id=aea.external_holder_id
      WHERE aea.id=? AND aea.status='active'
      LIMIT 1
    ");
    $st->execute([$assignId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Nincs aktív külsős átadás ezzel az azonosítóval.');

    $assetId = (int)$row['asset_id'];

    $sqlUpd = "UPDATE asset_external_assignments
               SET status='returned',
                   returned_at=NOW(),
                   returned_by_user_id=?";
    $paramsUpd = [(int)($u['id'] ?? 0)];
    if ($hasReturnedTo) {
      $sqlUpd .= ", returned_to_employee_id=?";
      $paramsUpd[] = $toEmp;
    }
    $sqlUpd .= " WHERE id=?";
    $paramsUpd[] = $assignId;
    $pdo->prepare($sqlUpd)->execute($paramsUpd);

    $pdo->prepare("UPDATE assets SET current_employee_id=? WHERE id=?")->execute([$toEmp, $assetId]);

    $pdo->commit();

    // Csak akkor készítünk visszavételi PDF/emailt, ha az eredeti külsős átadáshoz készült átadási jegyzőkönyv
    $hadReturnDocFlow = !empty($row['pdf_path']);

    if ($hadReturnDocFlow) {
      try {
        require_once __DIR__.'/../app/pdf_mpdf.php';
        require_once __DIR__.'/../app/mailer.php';

        // Auth center user map
        $authUserMap = [];
        try {
          $auth = auth_pdo();
          foreach ($auth->query("SELECT id, COALESCE(NULLIF(full_name,''), NULLIF(username,''), email) AS nm FROM users")->fetchAll(PDO::FETCH_ASSOC) as $ur) {
            $authUserMap[(int)$ur['id']] = (string)$ur['nm'];
          }
        } catch (Throwable $e) {}

        // HR employee name for destination
        $returnedTo = '#'.$toEmp;
        try {
          $hr = db_hr();
          $stTo = $hr->prepare("SELECT full_name FROM employees WHERE id=? LIMIT 1");
          $stTo->execute([$toEmp]);
          $returnedTo = (string)($stTo->fetchColumn() ?: ('#'.$toEmp));
        } catch (Throwable $e) {}

        // Asset basic data + photo
        $asset = [];
        $photoAbs = '';
        try {
          $stA = $pdo->prepare("SELECT id, name, sku, qr_value FROM assets WHERE id=? LIMIT 1");
          $stA->execute([$assetId]);
          $asset = $stA->fetch(PDO::FETCH_ASSOC) ?: [];

          $stP = $pdo->prepare("SELECT file_path FROM asset_photos WHERE asset_id=? ORDER BY is_primary DESC, id DESC LIMIT 1");
          $stP->execute([$assetId]);
          $photo = (string)($stP->fetchColumn() ?: '');
          if ($photo !== '') $photoAbs = $toAbs(photo_public_url($photo));
        } catch (Throwable $e) {}

        $sigAbs = '';
        $sigWeb = (string)($row['signature_path'] ?? '');
        if ($sigWeb !== '') $sigAbs = $toAbs($sigWeb);

        $assignedBy = $authUserMap[(int)($row['assigned_by_user_id'] ?? 0)] ?? ('#'.(int)($row['assigned_by_user_id'] ?? 0));
        $returnedBy = $authUserMap[(int)($u['id'] ?? 0)] ?? ('#'.(int)($u['id'] ?? 0));

        $stR = $pdo->prepare("SELECT assigned_at, returned_at FROM asset_external_assignments WHERE id=? LIMIT 1");
        $stR->execute([$assignId]);
        $times = $stR->fetch(PDO::FETCH_ASSOC) ?: [];

        $pdfWeb = generate_external_return_pdf_html([
          'assigned_at'  => (string)($times['assigned_at'] ?? ($row['assigned_at'] ?? '')),
          'returned_at'  => (string)($times['returned_at'] ?? ''),
          'assigned_by'  => (string)$assignedBy,
          'returned_by'  => (string)$returnedBy,
          'returned_to'  => (string)$returnedTo,
          'company'      => (string)($row['company_name'] ?? ''),
          'contact'      => (string)($row['contact_name'] ?? ''),
          'phone'        => (string)($row['phone'] ?? ''),
          'email'        => (string)($row['ext_email'] ?? ''),
          'courier_ref'  => (string)($row['courier_ref'] ?? ''),
          'note'         => (string)($row['note'] ?? ''),
          'return_note'  => '',
          'assets'       => [[
            'name'      => (string)($asset['name'] ?? ''),
            'inventory' => (string)($asset['qr_value'] ?? ''),
            'serial'    => (string)($asset['sku'] ?? ''),
            'photo_abs' => $photoAbs,
          ]],
          'signature_abs' => $sigAbs,
        ]);

        if ($hasReturnPdf) {
          $pdo->prepare("UPDATE asset_external_assignments SET return_pdf_path=? WHERE id=?")
              ->execute([$pdfWeb, $assignId]);
        }

        $to = trim((string)($row['ext_email'] ?? ''));
        if ($hasExtEmail && $to !== '') {
          $cfg = require __DIR__ . '/../app/config.php';
          $from = (string)($cfg['mail_from'] ?? 'no-reply@localhost');
          $bcc  = (string)($cfg['mail_bcc'] ?? '');
          $subj = "Perfect-Phone – Eszköz visszavéve";

          $assetLine = htmlspecialchars((string)($asset['name'] ?? ''), ENT_QUOTES, 'UTF-8');
          if (!empty($asset['qr_value'])) $assetLine .= ' | Leltár/QR: ' . htmlspecialchars((string)$asset['qr_value'], ENT_QUOTES, 'UTF-8');
          if (!empty($asset['sku'])) $assetLine .= ' | SKU/SN: ' . htmlspecialchars((string)$asset['sku'], ENT_QUOTES, 'UTF-8');

          $contactHtml = !empty($row['contact_name']) ? '<tr><td style="padding:4px 0;width:180px;"><strong>Kapcsolattartó:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$row['contact_name'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
          $phoneHtml   = !empty($row['phone']) ? '<tr><td style="padding:4px 0;"><strong>Telefon:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$row['phone'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';
          $emailHtml   = !empty($row['ext_email']) ? '<tr><td style="padding:4px 0;"><strong>Email:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)$row['ext_email'], ENT_QUOTES, 'UTF-8') . '</td></tr>' : '';

          $body = '<!doctype html><html lang="hu"><head><meta charset="utf-8"></head>'
                . '<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">'
                . '<div style="max-width:700px;margin:0 auto;padding:24px;">'
                . '<div style="background:#ffffff;border:1px solid #ddd;border-radius:10px;overflow:hidden;">'
                . '<div style="padding:24px 24px 12px 24px;">'
                . '<h2 style="margin:0 0 16px 0;font-size:22px;">Eszköz visszavételi értesítő</h2>'
                . '<p style="margin:0 0 16px 0;">Tisztelt Partner!</p>'
                . '<p style="margin:0 0 16px 0;">Az alábbi eszköz visszavételre került. A részletek a csatolt PDF-ben találhatók.</p>'
                . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px 0;">'
                . '<tr><td style="padding:4px 0;width:180px;"><strong>Átadás ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($times['assigned_at'] ?? ($row['assigned_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Átadta:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($assignedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavétel ideje:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($times['returned_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszavette:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($returnedBy, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Visszakerült:</strong></td><td style="padding:4px 0;">' . htmlspecialchars($returnedTo, ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . '<tr><td style="padding:4px 0;"><strong>Partner:</strong></td><td style="padding:4px 0;">' . htmlspecialchars((string)($row['company_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>'
                . $contactHtml . $phoneHtml . $emailHtml
                . '</table>'
                . '<div style="margin:0 0 18px 0;"><strong>Eszköz:</strong>'
                . '<div style="margin-top:8px;padding:12px;background:#fafafa;border:1px solid #e5e5e5;border-radius:8px;">' . $assetLine . '</div></div>'
                . '<p style="margin:18px 0 16px 0;">Üdvözlettel,<br><strong>Perfect-Phone</strong></p>'
                . '</div><div style="padding:16px 24px;border-top:1px solid #eee;background:#fcfcfc;text-align:left;">'
                . '<img src="cid:companylogo" alt="Perfect-Phone" style="max-height:48px;">'
                . '</div></div></div></body></html>';

          $pdfAbs = __DIR__ . '/..' . $pdfWeb;
          send_mail_with_attachment($to, $subj, $body, $from, ($bcc !== '' ? $bcc : null), $pdfAbs, basename($pdfAbs));
        }
      } catch (Throwable $e) {
        flash_set('warn', 'Külsős átadás lezárva, de a visszavételi PDF/email generálás hibázott: ' . $e->getMessage());
      }
    }

    if (!flash_get('warn')) {
      flash_set('ok', 'Külsős átadás lezárva, eszköz visszavéve.');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash_set('err', 'Visszavételi hiba: '.$e->getMessage());
  }

  header('Location: assets.php');
  exit;
}

// WHERE építés// WHERE építés
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
if ($holderRaw === '-1') {
  $where .= " AND (a.current_employee_id IS NULL OR a.current_employee_id=0)
              AND NOT EXISTS (SELECT 1 FROM asset_external_assignments x WHERE x.asset_id=a.id AND x.status='active')";
} elseif (str_starts_with($holderRaw, 'emp:')) {
  $eid = (int)substr($holderRaw, 4);
  if ($eid > 0) {
    $where .= " AND a.current_employee_id = :holder_emp";
    $params[':holder_emp'] = $eid;
  }
} elseif (str_starts_with($holderRaw, 'ext:')) {
  $xid = (int)substr($holderRaw, 4);
  if ($xid > 0) {
    $where .= " AND EXISTS (SELECT 1 FROM asset_external_assignments x WHERE x.asset_id=a.id AND x.status='active' AND x.external_holder_id=:holder_ext)";
    $params[':holder_ext'] = $xid;
  }
} elseif (str_starts_with($holderRaw, 'wh:')) {
  $wid = (int)substr($holderRaw, 3);
  if ($wid > 0) {
    $where .= " AND a.current_warehouse_id = :holder_wh";
    $params[':holder_wh'] = $wid;
  }
}
if ($cat > 0) {
  // Subtree (fa) szűrés
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

// CSV export (ugyanazzal a szűréssel)
if ((string)($_GET['export'] ?? '') === 'csv') {
  $sql = "
    SELECT a.*,
      (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
         FROM asset_category ac
         JOIN categories c ON c.id=ac.category_id
        WHERE ac.asset_id=a.id AND c.is_deleted=0
      ) AS categories,
      (SELECT eh.company_name FROM asset_external_assignments x JOIN external_holders eh ON eh.id=x.external_holder_id WHERE x.asset_id=a.id AND x.status='active' ORDER BY x.id DESC LIMIT 1) AS ext_company,
      (SELECT eh.contact_name FROM asset_external_assignments x JOIN external_holders eh ON eh.id=x.external_holder_id WHERE x.asset_id=a.id AND x.status='active' ORDER BY x.id DESC LIMIT 1) AS ext_contact
    FROM assets a
    WHERE $where
    ORDER BY a.updated_at DESC, a.id DESC
    LIMIT 5000
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // HR map egyszerre
  $empIds = [];
  $warehouseIds = [];
  foreach ($rows as $r) {
    $eid = (int)($r['current_employee_id'] ?? 0);
    $wid = (int)($r['current_warehouse_id'] ?? 0);
    if ($eid > 0) $empIds[$eid] = true;
    if ($wid > 0) $warehouseIds[$wid] = true;
  }
  $empMap = [];
  if ($empIds) {
    try {
      $hr = db_hr();
      $in = implode(',', array_fill(0, count($empIds), '?'));
      $st = $hr->prepare("SELECT id, full_name FROM employees WHERE id IN ($in)");
      $st->execute(array_keys($empIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $empMap[(int)$e['id']] = (string)$e['full_name'];
      }
    } catch (Throwable $e) {}
  }
  $warehouseMap = [];
  if ($warehouseIds) {
    try {
      $in = implode(',', array_fill(0, count($warehouseIds), '?'));
      $st = $pdo->prepare("SELECT id, name FROM warehouses WHERE id IN ($in)");
      $st->execute(array_keys($warehouseIds));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $warehouseMap[(int)$w['id']] = (string)$w['name'];
      }
    } catch (Throwable $e) {}
  }

  $fn = 'assetmgr_export_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$fn.'"');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excel)

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Megnevezés','Cikkszám','QR','Kategóriák','Kinél van','Érték','Pénznem','Megjegyzés','Létrehozva','Frissítve'], ';');

  foreach ($rows as $r) {
    $eid = (int)($r['current_employee_id'] ?? 0);
    $extCompany = trim((string)($r['ext_company'] ?? ''));
    $extContact = trim((string)($r['ext_contact'] ?? ''));
    $wid = (int)($r['current_warehouse_id'] ?? 0);
    if ($extCompany !== '' || $extContact !== '') {
      $holderName = '[KÜLSŐS] ' . trim($extCompany . ' / ' . $extContact, ' /');
    } elseif ($wid > 0) {
      $holderName = '[RAKTÁR] ' . ($warehouseMap[$wid] ?? ('#'.$wid));
    } else {
      $holderName = $eid ? ($empMap[$eid] ?? ('#'.$eid)) : '';
    }
    fputcsv($out, [
      (int)$r['id'],
      (string)($r['name'] ?? ''),
      (string)($r['sku'] ?? ''),
      (string)($r['qr_value'] ?? ''),
      (string)($r['categories'] ?? ''),
      $holderName,
      (string)($r['value_amount'] ?? ''),
      (string)($r['value_currency'] ?? ''),
      (string)($r['note'] ?? ''),
      (string)($r['created_at'] ?? ''),
      (string)($r['updated_at'] ?? ''),
    ], ';');
  }
  fclose($out);
  exit;
}

// Összes darabszám (lapozáshoz)
$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM assets a WHERE $where");
$stmtCnt->execute($params);
$total = (int)($stmtCnt->fetchColumn() ?: 0);
$totalPages = max(1, (int)ceil($total / $perPage));

// Lista
$orderExpr = "a.updated_at DESC, a.id DESC";
if ($sort === 'name') {
  $orderExpr = "a.name " . strtoupper($dir) . ", a.id DESC";
} elseif ($sort === 'sku') {
  $orderExpr = "a.sku " . strtoupper($dir) . ", a.id DESC";
} elseif ($sort === 'categories') {
  $orderExpr = "categories " . strtoupper($dir) . ", a.id DESC";
} elseif ($sort === 'holder') {
  // employee név helyett employee_id szerint rendez, a raktár és külsős név már láthatóan rendezhető
  $orderExpr = "CASE
                  WHEN ext_company IS NOT NULL AND ext_company <> '' THEN CONCAT('[KÜLSŐS] ', ext_company, ' / ', COALESCE(ext_contact,''))
                  WHEN a.current_warehouse_id IS NOT NULL AND a.current_warehouse_id > 0 THEN CONCAT('[RAKTÁR] ', COALESCE((SELECT w2.name FROM warehouses w2 WHERE w2.id=a.current_warehouse_id LIMIT 1), ''))
                  WHEN a.current_employee_id IS NOT NULL AND a.current_employee_id > 0 THEN LPAD(a.current_employee_id, 10, '0')
                  ELSE ''
                END " . strtoupper($dir) . ", a.id DESC";
}

$sql = "
  SELECT a.*,
    (SELECT p.file_path FROM asset_photos p WHERE p.asset_id=a.id AND p.is_primary=1 ORDER BY p.id DESC LIMIT 1) AS primary_photo,
    (SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ')
       FROM asset_category ac
       JOIN categories c ON c.id=ac.category_id
      WHERE ac.asset_id=a.id AND c.is_deleted=0
    ) AS categories,
    (SELECT x.id FROM asset_external_assignments x WHERE x.asset_id=a.id AND x.status='active' ORDER BY x.id DESC LIMIT 1) AS ext_assignment_id,
    (SELECT eh.company_name FROM asset_external_assignments x JOIN external_holders eh ON eh.id=x.external_holder_id WHERE x.asset_id=a.id AND x.status='active' ORDER BY x.id DESC LIMIT 1) AS ext_company,
    (SELECT eh.contact_name FROM asset_external_assignments x JOIN external_holders eh ON eh.id=x.external_holder_id WHERE x.asset_id=a.id AND x.status='active' ORDER BY x.id DESC LIMIT 1) AS ext_contact
  FROM assets a
  WHERE $where
  ORDER BY $orderExpr
  LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// HR map egyszerre a listához
$empIds = [];
$warehouseIds = [];
foreach ($rows as $r) {
  $eid = (int)($r['current_employee_id'] ?? 0);
  $wid = (int)($r['current_warehouse_id'] ?? 0);
  if ($eid > 0) $empIds[$eid] = true;
  if ($wid > 0) $warehouseIds[$wid] = true;
}
$empMap = [];
if ($empIds) {
  try {
    $hr = db_hr();
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $st = $hr->prepare("SELECT id, full_name FROM employees WHERE id IN ($in)");
    $st->execute(array_keys($empIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
      $empMap[(int)$e['id']] = (string)$e['full_name'];
    }
  } catch (Throwable $e) {}
}
$warehouseMap = [];
if ($warehouseIds) {
  try {
    $in = implode(',', array_fill(0, count($warehouseIds), '?'));
    $st = $pdo->prepare("SELECT id, name FROM warehouses WHERE id IN ($in)");
    $st->execute(array_keys($warehouseIds));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
      $warehouseMap[(int)$w['id']] = (string)$w['name'];
    }
  } catch (Throwable $e) {}
}

function photo_public_url(string $path): string {
  $p = trim($path);
  if ($p === '') return '';
  if (preg_match('~^https?://~i', $p)) return $p;
  $p = ltrim($p, '/');
  if (substr($p, 0, 8) === 'storage/') return '/'.$p;
  return '/storage/'.$p;
}

$title = 'Eszközök';
$page  = 'Eszközök';
require __DIR__.'/_header.php';
?>
<style>
  .empty-cell-mark{display:block;height:0;border-top:1px solid #adb5bd;opacity:.9;margin:.85rem 0 .7rem;width:100%;min-width:2.5rem}
  .empty-cell-mark.empty-cell-photo{max-width:52px;margin-inline:auto}
</style>
<?php

$toolbookUrl = 'toolbook_print.php?' . build_query(['page'=>null, 'mode'=>'view']);
$toolbookSendUrl = 'toolbook_print.php?' . build_query(['page'=>null, 'mode'=>'send']);
$toolbookEligible = str_starts_with($holderRaw, 'emp:') && $cat === 0 && $q === '' && $sku === '';
$toolbookDisabledReason = 'Szerszámkönyv csak akkor használható, ha egy dolgozó van kiválasztva, a kategória összes, és a megnevezés / cikkszám üres.';
$toolbookArchiveEmail = trim((string)app_setting_get('toolbook_central_email', ''));
$toolbookSendConfigured = ($toolbookArchiveEmail !== '');
$toolbookSendDisabledReason = $toolbookSendConfigured
  ? $toolbookDisabledReason
  : 'Nincs beállítva archív email cím a Fiók oldalon.';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="mb-0">Eszközök</h4>
    <div class="text-secondary small"><?= (int)$total ?> db • <?= (int)$perPage ?>/oldal</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="assets.php?<?= e(build_query(['export'=>'csv','page'=>1])) ?>">CSV export</a>
    <a class="btn btn-primary btn-sm" href="asset_create.php">+ Új eszköz</a>
  </div>
</div>

<form class="card shadow-sm mb-3" method="get">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label">Megnevezés</label>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="">
      </div>
      <div class="col-12 col-md-2">
        <label class="form-label">Cikkszám</label>
        <input class="form-control" name="sku" value="<?= e($sku) ?>" placeholder="">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Kategória</label>
        <select class="form-select" name="category_id">
          <option value="0">— összes —</option>
          <?php render_cat_options($byParent, 0, 0, $cat); ?>
        </select>
        <!-- div class="form-text">Kategória szűrésnél az alkategóriák is benne vannak.</div-->
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Kinél van</label>
        <select class="form-select" name="holder_id">
          <option value="0" <?= $holderRaw==='0'?'selected':'' ?>>— összes —</option>
          <option value="-1" <?= $holderRaw==='-1'?'selected':'' ?>>— senkinél —</option>
          <?php foreach ($hrEmployees as $he): $hid=(int)$he['id']; $hv='emp:'.$hid; ?>
            <option value="<?= e($hv) ?>" <?= $holderRaw===$hv?'selected':'' ?>>
              <?= e((string)$he['full_name']) ?>
            </option>
          <?php endforeach; ?>
          <?php foreach ($externalHolders as $xh): $xv='ext:'.(int)$xh['id']; ?>
            <option value="<?= e($xv) ?>" <?= $holderRaw===$xv?'selected':'' ?>>
              [KÜLSŐS] <?= e(trim(((string)$xh['company_name']).' / '.((string)$xh['contact_name']), ' /')) ?>
            </option>
          <?php endforeach; ?>
          <?php foreach ($warehouses as $wh): $wv='wh:'.(int)$wh['id']; ?>
            <option value="<?= e($wv) ?>" <?= $holderRaw===$wv?'selected':'' ?>>
              [RAKTÁR] <?= e((string)$wh['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-outline-primary">Szűrés</button>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <a class="btn btn-outline-secondary" href="assets.php">Töröl</a>
      </div>
    </div>
    <div class="mt-3 pt-3 border-top d-flex justify-content-end gap-2 flex-wrap">
      <?php if ($toolbookEligible): ?>
        <a class="btn btn-outline-dark btn-sm" href="<?= e($toolbookUrl) ?>" target="_blank" rel="noopener">Szerszámkönyv nyomtatása</a>
      <?php else: ?>
        <span class="btn btn-outline-dark btn-sm disabled" aria-disabled="true" title="<?= e($toolbookDisabledReason) ?>">Szerszámkönyv nyomtatása</span>
      <?php endif; ?>

      <?php if ($toolbookEligible && $toolbookSendConfigured): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($toolbookSendUrl) ?>" onclick="return confirm('Biztosan elküldöd a szerszámkönyv PDF-et az archív email címre?');">PDF küldése archívumba</a>
      <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true" title="<?= e($toolbookSendDisabledReason) ?>">PDF küldése archívumba</span>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead>
      <tr>
        <th style="width:72px">Kép</th>
        <th><a class="link-dark text-decoration-none" href="<?= e(sort_link('name', $sort, $dir)) ?>">Megnevezés<?= sort_icon('name', $sort, $dir) ?></a></th>
        <th style="width:160px"><a class="link-dark text-decoration-none" href="<?= e(sort_link('sku', $sort, $dir)) ?>">Cikkszám<?= sort_icon('sku', $sort, $dir) ?></a></th>
        <th style="width:220px"><a class="link-dark text-decoration-none" href="<?= e(sort_link('categories', $sort, $dir)) ?>">Kategória<?= sort_icon('categories', $sort, $dir) ?></a></th>
        <th style="width:220px"><a class="link-dark text-decoration-none" href="<?= e(sort_link('holder', $sort, $dir)) ?>">Kinél van<?= sort_icon('holder', $sort, $dir) ?></a></th>
        <th style="width:140px"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $photo = (string)($r['primary_photo'] ?? '');
          $photoUrl = $photo ? photo_public_url($photo) : '';
          $eid = (int)($r['current_employee_id'] ?? 0);
          $wid = (int)($r['current_warehouse_id'] ?? 0);
          $extAssignId = (int)($r['ext_assignment_id'] ?? 0);
          $extHolder = trim(((string)($r['ext_company'] ?? '')).' / '.((string)($r['ext_contact'] ?? '')), ' /');
          if ($extHolder !== '') {
            $holderName = '[KÜLSŐS] '.$extHolder;
          } elseif ($wid > 0) {
            $holderName = '[RAKTÁR] '.($warehouseMap[$wid] ?? ('#'.$wid));
          } else {
            $holderName = $eid ? ($empMap[$eid] ?? ('#'.$eid)) : '';
          }
        ?>
        <tr>
          <td>
            <?php if ($photoUrl): ?>
              <img src="<?= e($photoUrl) ?>" class="img-fluid rounded" style="max-height:48px;max-width:64px;object-fit:cover">
            <?php else: ?>
              <?= empty_cell_markup('empty-cell-photo') ?>
            <?php endif; ?>
          </td>
          <td>
            <div class="fw-semibold"><?= e($r['name'] ?? '') ?></div>
            <div class="text-secondary small">#<?= (int)$r['id'] ?></div>
          </td>
          <td><?= cell_or_mark((string)($r['sku'] ?? '')) ?></td>
          <td class="small"><?= cell_or_mark((string)($r['categories'] ?? '')) ?></td>
          <td class="small"><?= $holderName ? e($holderName) : empty_cell_markup() ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="asset_edit.php?id=<?= (int)$r['id'] ?>">Megnyit</a>
            <?php if ($extAssignId > 0): ?>
              <form method="post" class="mt-1">
                <input type="hidden" name="action" value="return_external">
                <input type="hidden" name="ext_assignment_id" value="<?= $extAssignId ?>">
                <div class="input-group input-group-sm mt-1">
                  <select class="form-select form-select-sm" name="to_employee_id" required>
                    <option value="">Vissza kinek?</option>
                    <?php foreach ($hrEmployees as $he): $hid=(int)$he['id']; ?>
                      <option value="<?= $hid ?>"><?= e((string)$he['full_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-success btn-sm" type="submit">Visszavétel</button>
                </div>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-secondary py-4">Nincs találat.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
  <nav class="d-flex justify-content-center">
    <ul class="pagination">
      <?php
        $prev = max(1, $pageNo-1);
        $next = min($totalPages, $pageNo+1);
      ?>
      <li class="page-item <?= $pageNo<=1?'disabled':'' ?>">
        <a class="page-link" href="assets.php?<?= e(build_query(['page'=>$prev])) ?>">«</a>
      </li>

      <?php
        $start = max(1, $pageNo-3);
        $end = min($totalPages, $pageNo+3);
        if ($start > 1) {
          echo '<li class="page-item"><a class="page-link" href="assets.php?'.e(build_query(['page'=>1])).'">1</a></li>';
          if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        for ($p=$start; $p<=$end; $p++) {
          $active = ($p===$pageNo) ? 'active' : '';
          echo '<li class="page-item '.$active.'"><a class="page-link" href="assets.php?'.e(build_query(['page'=>$p])).'">'.(int)$p.'</a></li>';
        }
        if ($end < $totalPages) {
          if ($end < $totalPages-1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
          echo '<li class="page-item"><a class="page-link" href="assets.php?'.e(build_query(['page'=>$totalPages])).'">'.(int)$totalPages.'</a></li>';
        }
      ?>

      <li class="page-item <?= $pageNo>=$totalPages?'disabled':'' ?>">
        <a class="page-link" href="assets.php?<?= e(build_query(['page'=>$next])) ?>">»</a>
      </li>
    </ul>
  </nav>
<?php endif; ?>

<?php require __DIR__.'/_footer.php'; ?>
