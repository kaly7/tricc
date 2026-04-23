<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
if (is_worker()) { header('Location: my_om_jobs.php'); exit; }
require_once __DIR__.'/../src/db.php'; require_once __DIR__.'/../src/helpers.php';
$u = current_user();
$db = db();


// filters
$q = trim($_GET['q'] ?? '');

// Multi-select support
$pp_raw   = $_GET['pp_status_id'] ?? [];
$city_raw = $_GET['city_id'] ?? [];
if (!is_array($pp_raw))   $pp_raw = [$pp_raw];
if (!is_array($city_raw)) $city_raw = [$city_raw];
$pp_ids   = array_values(array_unique(array_filter(array_map('intval', $pp_raw), fn($v)=>$v>0)));
$city_ids = array_values(array_unique(array_filter(array_map('intval', $city_raw), fn($v)=>$v>0)));

// Mode switches
$pp_mode   = $_GET['pp_mode'] ?? 'include'; if ($pp_mode!=='exclude') $pp_mode='include';
$city_mode = $_GET['city_mode'] ?? 'include'; if ($city_mode!=='exclude') $city_mode='include';

$include_arch   = isset($_GET['include_arch']) ? 1 : 0;
$include_deleted= (isset($_GET['include_deleted']) && is_admin()) ? 1 : 0;
$sort = $_GET['sort'] ?? 'issued_at';
$allowed = ['pp_status','eventus','issued_at','due_at','city'];
if (!in_array($sort, $allowed, true)) $sort='issued_at';

$statuses = db()->query('SELECT id,name,color_hex FROM pp_status ORDER BY name')->fetchAll();
$cities   = db()->query('SELECT id,name FROM cities ORDER BY name')->fetchAll();


// ===== Színes legend számolása A JELENLEGI SZŰRÉSSEL =====
$tz = new DateTimeZone('Europe/Budapest');
$today   = new DateTime('today', $tz);
$plus2   = (clone $today)->modify('+2 days');   // ma..+2
$plus3   = (clone $today)->modify('+3 days');   // következő ablak kezdete
$plus12  = (clone $today)->modify('+12 days');  // következő ablak vége

$fmt = 'Y-m-d';
$todayStr  = $today->format($fmt);
$plus2Str  = $plus2->format($fmt);
$plus3Str  = $plus3->format($fmt);
$plus12Str = $plus12->format($fmt);


// ... a meglévő dátumváltozók után
$dueFilter = $_GET['due'] ?? null; // 'overdue' | 'today2' | 'next10' vagy null

$marvinFilter = !empty($_GET['marvin_pending']);

$where=[]; $p=[];
if (!$include_deleted || !is_admin()) $where[]='r.deleted_at IS NULL';
if (!$include_arch) $where[]='r.archived=0';
if ($marvinFilter) $where[]='r.marvin_pending=1';

if (!empty($pp_ids)){
  $place = implode(',', array_fill(0, count($pp_ids), '?'));
  $where[] = ($pp_mode==='exclude') ? "r.pp_status_id NOT IN ($place)" : "r.pp_status_id IN ($place)";
  array_push($p, ...$pp_ids);
}
if (!empty($city_ids)){
  $place = implode(',', array_fill(0, count($city_ids), '?'));
  $where[] = ($city_mode==='exclude') ? "r.city_id NOT IN ($place)" : "r.city_id IN ($place)";
  array_push($p, ...$city_ids);
}

if ($q!==''){
  foreach(preg_split('/\s+/', $q) as $t){
    if($t==='') continue;
    $like = '%'.$t.'%';
    $where[]='(r.eventus LIKE ? OR r.address LIKE ? OR r.operation LIKE ? OR r.long_desc LIKE ?)';
    array_push($p,$like,$like,$like,$like);
  }
}

// ha due=... van, akkor független a többi szűrőtől
if (in_array($dueFilter, ['overdue','today2','next10'], true)) {
    $where = [];
    $p     = [];

    $where[] = 'r.deleted_at IS NULL';
    $where[] = 'r.archived = 0';

    if ($dueFilter === 'overdue') {
        $where[] = 'r.due_at < ?';
        $p[] = $todayStr;
    } elseif ($dueFilter === 'today2') {
        $where[] = 'r.due_at BETWEEN ? AND ?';
        $p[] = $todayStr;
        $p[] = $plus2Str;
    } else { // next10
        $where[] = 'r.due_at BETWEEN ? AND ?';
        $p[] = $plus3Str;
        $p[] = $plus12Str;
    }
}

// végül:
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

// rendezés
$orderBy = ($sort==='pp_status') ? 'ps.name'
         : (($sort==='city')     ? 'c.name'
         : ('r.'.$sort));

$sql = "SELECT r.*, ps.name AS pp_name, ps.color_hex, c.name AS city_name
        FROM records r
        JOIN pp_status ps ON ps.id=r.pp_status_id
        JOIN cities c ON c.id=r.city_id
        $wsql
        ORDER BY $orderBy ASC
        LIMIT 1000";
$st = db()->prepare($sql); $st->execute($p); $rows = $st->fetchAll();


// Alap WHERE a listából (ugyanazok a feltételek)
$baseWhereSql = $where ? implode(' AND ', $where) : '1';

// kis helper a számláláshoz
$cntFn = function(string $extraSql, array $extraParams) use ($baseWhereSql, $p) {
    $sql = "SELECT COUNT(*) FROM records r WHERE ($baseWhereSql) AND ($extraSql)";
    $st  = db()->prepare($sql);
    $st->execute(array_merge($p, $extraParams));
    return (int)$st->fetchColumn();
};

// 1) Lejárt
$cntOverdue = $cntFn('r.due_at < ?', [$todayStr]);
// 2) Ma és +2 nap
$cntToday2  = $cntFn('r.due_at BETWEEN ? AND ?', [$todayStr, $plus2Str]);
// 3) Következő 10 nap (ma+3 .. ma+12)
$cntNext10  = $cntFn('r.due_at BETWEEN ? AND ?', [$plus3Str, $plus12Str]);

// Marvin pending count
$cntMarvin = (int)$db->query("SELECT COUNT(*) FROM records WHERE marvin_pending = 1 AND deleted_at IS NULL")->fetchColumn();

// sablonok a felhasználónak (admin: mind)
$all_templates = $db->query("SELECT id, name FROM email_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$tpl_perm = [];
if (!is_admin()) {
    $stp = $db->prepare("SELECT template_id FROM email_template_permissions WHERE user_id=?");
    $stp->execute([$u['id']]);
    foreach ($stp as $row) {
        $tpl_perm[(int)$row['template_id']] = true;
    }
}

$availableTplsBulk = [];
foreach ($all_templates as $t) {
    if (is_admin() || !empty($tpl_perm[(int)$t['id']])) {
        $availableTplsBulk[] = $t;
    }
}

// csak akkor jelenjen meg a form, ha van sablon + találat
$canShowBulkMailer = !empty($availableTplsBulk) && !empty($rows);
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Tételek</title>

<!-- PWA manifest -->
<!-- link rel="manifest" href="manifest.json" -->

<!-- Android / Chrome színsáv -->
<!-- meta name="theme-color" content="#212529" -->

<!-- iOS (ha egyszer onnan is használod) -->
<!-- meta name="apple-mobile-web-app-capable" content="yes" -->
<!-- meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" -->
<!-- link rel="apple-touch-icon" href="assets/icons/icon-192.png" -->

<link href="assets/css/bootstrap.min.css" rel="stylesheet" >
<style>
  table td, table th { white-space: nowrap; }
  tr.row-colored > td { 
    background-color: var(--row-color, #ffffff) !important; 
    color: inherit !important;
  }
  .col-op { min-width: 1000px; max-width: none; }
  .op-note {
    display: inline-block; margin-left: .35rem; cursor: help;
    font-size: .95em; padding: 0 .35rem; border-radius: .25rem;
    border: 1px solid rgba(0,0,0,.15); color: #555; text-decoration: none;
    background: rgba(255,255,255,.6);
  }
  .col-actions{ min-width: 420px; }
  .popover {
    max-width: none;
    width: auto;
    white-space: pre;
  }
  /* görgethető lista + ragadós fejléc */
  .table-wrap {
    max-height: calc(100dvh - 320px);
    overflow: auto;
    border: 1px solid rgba(0,0,0,.075);
    border-radius: .25rem;
    min-height: 220px;
  }
  @supports (padding: max(0px)) {
    .table-wrap { padding-bottom: max(0px, env(safe-area-inset-bottom)); }
  }
  .table-sticky thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f8f9fa;
  }
  .table-sticky tbody tr.row-colored > td {
    background-color: var(--row-color, #fff) !important;
  }
  .table-wrap .table { margin-bottom: 0; }

  .popover .popover-body {
    font-size: 1.25rem;
    line-height: 1.4;
    max-height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    max-width: 120ch;
    font-family: monospace;
  }

  /* Lejárat kiemelés */
  .due-tag {
    display: inline-block;
    padding: .15rem .45rem;
    border-radius: 999px;
    font-weight: 600;
    font-size: .9rem;
    line-height: 1;
    border: 1px solid rgba(0,0,0,.15);
    box-shadow: 0 0 0 2px rgba(255,255,255,.6);
    text-decoration: none;
    display: inline-block;
  }
  .due-tag:hover {
    filter: brightness(0.7);
  }
  .due-tag.is-active {
    outline: 2px solid rgba(0,0,0,.2);
    box-shadow: 0 0 0 2px rgba(255,255,255,.6), inset 0 0 0 999px rgba(0,0,0,.04);
  }
  .due-overdue  { background: #D32F2F; color: #fff; }
  .due-today    { background: #EF6C00; color: #fff; }
  .due-soon     { background: #1565C0; color: #fff; }

  .op-note {
    cursor: pointer;
    color: #0d6efd;
    background: transparent;
    border-radius: 10%;
    padding: 2px 6px;
    transition: all .2s;
  }
  .op-note.active {
    color: #fff;
    background: #0d6efd;
  }

  .table tbody tr:hover {
    filter: brightness(0.8);
    cursor : pointer;
    transition: filter 0.2s ease, font-weight 0.2s ease;
  }
  .table .btn-thin {
    padding: 0.1rem 0.25rem;
    font-size: 0.75rem;
    line-height: 1.2;
  }

  /* PP-státusz: a kijelölt elemek fix színe */
  select[multiple].status-colored option:checked {
    background: #0d6efd !important;
    color: #fff !important;
  }
  select[multiple].status-colored:focus option:checked {
    background: #0d6efd !important;
    color: #fff !important;
  }
  select[multiple].status-colored option:hover {
    filter: brightness(0.95);
  }

  .table .col-actions {
    display: flex;
    gap: .25rem;
    align-items: stretch;
  }
  .table .col-actions .btn {
    flex: 1 1 auto;
    height: 100%;
    padding-top: 0;
    padding-bottom: 0;
  }

  .table tbody tr.row-dark a { color: #fff; }
  .table tbody tr.row-dark .btn-outline-primary,
  .table tbody tr.row-dark .btn-outline-secondary,
  .table tbody tr.row-dark .btn-outline-light {
    color: #fff;
    border-color: #fff;
  }
  .table tbody tr.row-dark .btn-warning { color: #000; }
  .table tbody tr.row-dark .btn-success { color: #fff; }
  .table tbody tr.row-dark .op-note { color: #fff; border-color: rgba(255,255,255,.6); }
  .table tbody tr.row-dark:hover { filter: brightness(0.9); }
  .marvin-badge {
    background: #39ff14;
    color: #000;
    font-weight: 700;
    font-size: .72rem;
    padding: 2px 7px;
    border-radius: 4px 0 0 4px;
    border: 2px solid #39ff14;
    letter-spacing: .05em;
  }
  .marvin-count {
    background: #000;
    color: #39ff14;
    font-weight: 700;
    font-size: .72rem;
    padding: 2px 7px;
    border-radius: 0 4px 4px 0;
    border: 2px solid #39ff14;
    border-left: none;
  }
  .marvin-inline {
    background: #39ff14;
    color: #000;
    font-weight: 700;
    font-size: .72rem;
    padding: 1px 7px;
    border-radius: 4px;
    border: 2px solid #39ff14;
    letter-spacing: .05em;
    cursor: pointer;
    display: inline-block;
  }
  .marvin-inline:hover { filter: brightness(1.15); }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <span class="navbar-brand">PP rendszer</span>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="my_om_jobs.php">O&amp;M Munkák</a>
      <a class="btn btn-sm btn-outline-light" href="map_records.php">🗺 Térkép</a>
      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
        <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
      <?php endif; ?>

      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <?php endif; ?>

      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>

      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>

<?php if (!empty($_GET['msg']) && $_GET['msg']==='mail_bulk_done'): ?>
  <div class="alert alert-info py-2">
    E-mail küldés kész. Sikeres: <strong><?= (int)($_GET['ok'] ?? 0) ?></strong>, sikertelen: <strong><?= (int)($_GET['fail'] ?? 0) ?></strong>.
  </div>
<?php elseif (!empty($_GET['err']) && $_GET['err']==='no_recipients'): ?>
  <div class="alert alert-warning py-2">Ehhez a sablonhoz nincs beállított címzett.</div>
<?php elseif (!empty($_GET['err'])): ?>
  <div class="alert alert-warning py-2">E-mail küldés hiba: <?=h($_GET['err'])?></div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-2">
  <!-- PRINT -->
  <form method="get" action="records_print.php" target="_blank" class="d-inline">
    <?php foreach ($_GET as $key => $val): ?>
      <?php if (is_array($val)): ?>
        <?php foreach ($val as $v): ?>
          <input type="hidden" name="<?=h($key)?>[]" value="<?=h($v)?>">
        <?php endforeach; ?>
      <?php else: ?>
        <input type="hidden" name="<?=h($key)?>" value="<?=h($val)?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn btn-outline-secondary btn-sm">🖨 Nyomtatás / PDF</button>
  </form>

  <!-- CSV -->
  <form method="get" action="records_export_csv.php" class="d-inline">
    <?php foreach ($_GET as $key => $val): ?>
      <?php if (is_array($val)): ?>
        <?php foreach ($val as $v): ?>
          <input type="hidden" name="<?=h($key)?>[]" value="<?=h($v)?>">
        <?php endforeach; ?>
      <?php else: ?>
        <input type="hidden" name="<?=h($key)?>" value="<?=h($val)?>">
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn btn-outline-secondary btn-sm">⬇ CSV export</button>
  </form>

  <!-- BULK EMAIL -->
  <?php if ($canShowBulkMailer): ?>
  <form method="post" action="actions/email_send_bulk.php" class="d-flex align-items-center gap-2 me-2">
    <input type="hidden" name="_csrf" value="<?=csrf_token()?>">

    <?php foreach ($_GET as $key => $val): ?>
      <?php if (is_array($val)): ?>
        <?php foreach ($val as $v): ?>
          <input type="hidden" name="<?=h($key)?>[]" value="<?=h($v)?>">
        <?php endforeach; ?>
      <?php else: ?>
        <input type="hidden" name="<?=h($key)?>" value="<?=h($val)?>">
      <?php endif; ?>
    <?php endforeach; ?>

    <select name="template_id" class="form-select form-select-sm" style="width:auto; min-width: 220px;">
      <?php foreach ($availableTplsBulk as $t): ?>
        <option value="<?=$t['id']?>"><?=h($t['name'])?></option>
      <?php endforeach; ?>
    </select>

    <button class="btn btn-sm btn-outline-secondary">E-mail küldése</button>
  </form>
  <?php endif; ?>
<!-- /div -->

<!-- ÚJ: szűrő panel összehajtható gomb -->
<!-- div class="d-flex justify-content-start mb-2" -->
  <button class="btn btn-sm btn-outline-primary"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#filterPanel"
          aria-expanded="true"
          aria-controls="filterPanel">
    🔍 Szűrők mutatása / elrejtése
  </button>
  <?php if ($cntMarvin > 0): ?>
    <a href="?marvin_pending=1" class="text-decoration-none ms-1" title="El nem fogadott Marvin rekordok">
      <span class="marvin-badge">MARVIN</span><span class="marvin-count"><?= $cntMarvin ?></span>
    </a>
  <?php endif; ?>
</div>

<!-- Összehajtható szűrő panel -->
<div class="collapse show" id="filterPanel">
  <form class="row g-2 align-items-end mb-3" method="get">

    <div class="col-md-3 d-flex flex-column justify-content-start">
      <?php $active = $dueFilter; ?>
      <div class="container-fluid mb-2">
        <div class="d-flex flex-column" style="gap:.25rem;">
          <a class="due-tag due-overdue <?= $active==='overdue' ? 'is-active' : '' ?>"
             href="records.php?due=overdue" title="Csak a lejártakat mutasd">
            Lejárt: &lt; <?=h($todayStr)?> (<?=$cntOverdue?> db)
          </a>

          <a class="due-tag due-today <?= $active==='today2' ? 'is-active' : '' ?>"
             href="records.php?due=today2" title="Csak a ma és +2 nap közötti tételeket mutasd">
            Ma és +2 nap: <?=h($todayStr)?> – <?=h($plus2Str)?> (<?=$cntToday2?> db)
          </a>

          <a class="due-tag due-soon <?= $active==='next10' ? 'is-active' : '' ?>"
             href="records.php?due=next10" title="Csak a következő 10 nap tételeit mutasd">
            Következő 10 nap: <?=h($plus3Str)?> – <?=h($plus12Str)?> (<?=$cntNext10?> db)
          </a>
        </div>
      </div>

      <div>
        <label class="form-label">Keresés</label>
        <input name="q" class="form-control" value="<?=h($q)?>" placeholder="Eventus, cím, művelet, leírás">
      </div>
    </div>

    <div class="col-md-3">
      <label class="form-label">PP státusz (több választható)</label>
      <select name="pp_status_id[]" class="form-select status-colored" multiple size="6">
        <?php
          $pp_ids_flip = array_flip($pp_ids);
          foreach($statuses as $s):
            $sel   = isset($pp_ids_flip[$s['id']]) ? 'selected' : '';
            $bg    = $s['color_hex'];
            $fg    = getContrastYIQ($bg);
            $style = "background-color: {$bg}; color: {$fg};";
        ?>
          <option value="<?=$s['id']?>" <?=$sel?> style="<?=$style?>">
            <?=h($s['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Több kijelölés: Ctrl/Cmd + katt</div>
      <div class="mt-1">
        <label class="form-label me-2 mb-0">Logika:</label>
        <select name="pp_mode" class="form-select form-select-sm d-inline-block" style="width:auto; min-width: 180px;">
          <option value="include" <?=($pp_mode==='include')?'selected':''?>>Csak ezek</option>
          <option value="exclude" <?=($pp_mode==='exclude')?'selected':''?>>Ezek kivételével</option>
        </select>
      </div>
    </div>

    <div class="col-md-3">
      <label class="form-label">Város (több választható)</label>
      <select name="city_id[]" class="form-select" multiple size="6">
        <?php
          $city_ids_flip = array_flip($city_ids);
          foreach($cities as $c):
            $sel = isset($city_ids_flip[$c['id']]) ? 'selected' : '';
        ?>
          <option value="<?=$c['id']?>" <?=$sel?>><?=h($c['name'])?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Több kijelölés: Ctrl/Cmd + katt</div>
      <div class="mt-1">
        <label class="form-label me-2 mb-0">Logika:</label>
        <select name="city_mode" class="form-select form-select-sm d-inline-block" style="width:auto; min-width: 180px;">
          <option value="include" <?=($city_mode==='include')?'selected':''?>>Csak ezek</option>
          <option value="exclude" <?=($city_mode==='exclude')?'selected':''?>>Ezek kivételével</option>
        </select>
      </div>
    </div>

    <div class="col-md-2">
      <label class="form-label">Rendezés</label>
      <select name="sort" class="form-select">
        <option value="issued_at" <?=$sort==='issued_at'?'selected':''?>>Kiadva</option>
        <!-- option value="due_at" <?=$sort==='due_at'?'selected':''?>>+38 nap</option -->
        <option value="pp_status" <?=$sort==='pp_status'?'selected':''?>>PP státusz</option>
        <option value="eventus" <?=$sort==='eventus'?'selected':''?>>Eventus</option>
        <option value="city" <?=$sort==='city'?'selected':''?>>Város</option>
      </select>
    </div>

    <div class="col-md-3">
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="include_arch" id="arch" <?=$include_arch?'checked':''?>>
        <label class="form-check-label" for="arch">Archív tételekben is keressen</label>
      </div>
      <?php if (is_admin()): ?>
      <!--
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="include_deleted" id="del" <?=$include_deleted?'checked':''?>>
         <label class="form-check-label" for="del">Törölteket is mutassa</label>
      </div>
      -->
      <?php endif; ?>
    </div>

    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Szűrés / Frissítés</button>
      <a class="btn btn-secondary" href="records.php">Alaphelyzet</a>
      <a class="btn btn-success ms-auto" href="records_new.php">Új tétel</a>
    </div>
  </form>
</div> <!-- /filterPanel -->

<div class="table-responsive table-wrap">
  <table class="table table-sm align-middle table-sticky">
    <thead class="table-light">
      <tr>
        <th style="width:35px;">
          <input type="checkbox" id="check-all" checked>
          <button id="printIcon"
                  class="btn btn-link p-0 m-0"
                  style="text-decoration:none; color:inherit;"
                  data-bs-toggle="tooltip"
                  data-bs-placement="bottom"
                  title="Összes kijelölése / visszavonása (nyomtatáshoz)">
            🖨
          </button>
        </th>
        <th>Eventus</th>
        <th>PP státusz</th>
        <th>Kiadva</th>
        <th>+38 nap</th>
        <th>Város</th>
        <th>Cím</th>
        <th class="col-op">Elvégzendő művelet</th>
        <th>Archív</th>
        <th class="col-actions">Műveletek</th>
      </tr>
    </thead>
    <tbody>
<?php
$tz2     = new DateTimeZone('Europe/Budapest');
$today2  = new DateTimeImmutable('today', $tz2);
$plus2d  = $today2->modify('+2 days');
$plus12d = $today2->modify('+12 days');
?>
    <?php foreach($rows as $r): ?>
      <?php
        $bg = $r['color_hex'] ?: '#ffffff';
        $fg = getContrastYIQ($bg);
        $rowToneClass = ($fg === '#FFFFFF') ? 'row-dark' : 'row-light';
      ?>
      <tr class="row-colored <?=$rowToneClass?>" style="--row-color: <?=h($bg)?>; color: <?=$fg?>;">
        <td>
          <input type="checkbox" name="print_ids[]" value="<?=$r['id']?>" class="row-check" checked>
        </td>
        <td>
          <?=h($r['eventus'])?>
          <?php if (!empty($r['marvin_pending'])): ?>
            <span class="marvin-inline ms-1"
                  data-record-id="<?=(int)$r['id']?>"
                  title="Marvin által küldött – kattints az elfogadáshoz">MARVIN</span>
          <?php endif; ?>
        </td>
        <td><?=h($r['pp_name'])?></td>
        <td><?=h($r['issued_at'])?></td>
        <?php
          $due = new DateTimeImmutable($r['due_at'], $tz2);
          if ($due < $today2) {
            $dueClass = 'due-overdue';
          } elseif ($due <= $plus2d) {
            $dueClass = 'due-today';
          } elseif ($due <= $plus12d) {
            $dueClass = 'due-soon';
          } else {
            $dueClass = '';
          }
        ?>
        <td>
          <?php if ($dueClass): ?>
            <span class="due-tag <?=$dueClass?>" title="<?=$r['due_at']?>"><?=h($r['due_at'])?></span>
          <?php else: ?>
            <?=h($r['due_at'])?>
          <?php endif; ?>
        </td>
        <td><?=h($r['city_name'])?></td>
        <td><?=h($r['address'])?></td>
        <td class="col-op">
          <?php $__ld = trim((string)$r['long_desc']); if($__ld===''){ $__ld='(nincs leírás)'; } ?>
          <span class="op-note"
                tabindex="0"
                data-bs-toggle="popover"
                data-bs-html="true"
                data-bs-content="<?=str_replace(["\r\n","\r","\n"], '<br>', htmlspecialchars($__ld, ENT_QUOTES, 'UTF-8'))?>">
             &#9432;
          </span>
          <?=h($r['operation'])?>
        </td>
        <td><?= $r['archived'] ? '✔' : '' ?></td>
        <td class="d-felx gap-1">
          <a class="btn btn-sm btn-outline-primary btn-thin" href="records_edit.php?id=<?=$r['id']?>">Szerkeszt</a>

          <?php
            $stJob = $db->prepare("SELECT id FROM om_jobs WHERE record_id = ? ORDER BY id DESC LIMIT 1");
            $stJob->execute([$r['id']]);
            $omJobId = $stJob->fetchColumn();
          ?>
          <?php if ($omJobId): ?>
            <a class="btn btn-sm btn-outline-success btn-thin ms-1" href="om_job_view.php?id=<?=$omJobId?>" title="Meglévő O&amp;M munka megnyitása">Munka</a>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-dark btn-thin ms-1" href="om_job_new.php?record_id=<?=$r['id']?>" title="Új O&amp;M munka létrehozása">+ Munka</a>
          <?php endif; ?>

          <?php if(!$r['archived']): ?>
            <form method="post" action="actions/record_toggle_archive.php" class="d-inline-block ms-1">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-warning btn-thin">Archivál</button>
            </form>
          <?php else: ?>
            <form method="post" action="actions/record_toggle_archive.php" class="d-inline-block ms-1">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-success ms-1 btn-thin">Visszaállít</button>
            </form>
          <?php endif; ?>

          <a class="btn btn-sm btn-outline-secondary ms-1 btn-thin" href="changes.php?record_id=<?=$r['id']?>">Napló</a>

          <?php if (!$r['deleted_at']): ?>
            <?php /* törlés most kommentben marad */ ?>
          <?php elseif (is_admin()): ?>
            <form method="post" action="actions/record_restore.php">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-warning">Visszaállít</button>
            </form>
            <form method="post" action="actions/record_hard_delete.php" onsubmit="return confirm('Végleg törlöd? Nem visszavonható.');">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-danger">Végleg töröl</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<hr style="border: none; height: 2px; background: linear-gradient(to right, transparent, #555, transparent); margin: 40px 0;">
<p style="text-align:center; font-family: Arial; color:#666;">© Perfect Phone Munka Nyilvántartó</p>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function(){
  const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
  [...popoverTriggerList].forEach(el => {
    const pop = new bootstrap.Popover(el);

    el.addEventListener('shown.bs.popover', () => {
      el.classList.add('active');
    });
    el.addEventListener('hidden.bs.popover', () => {
      el.classList.remove('active');
    });
  });
});
</script>
<script>
(function(){
  function resizeTableWrap() {
    var el = document.querySelector('.table-wrap');
    if (!el) return;

    var top = el.getBoundingClientRect().top;
    var vh  = window.innerHeight;
    var safeBottom = (window.visualViewport && window.visualViewport.height)
                     ? (vh - window.visualViewport.height)
                     : 0;

    var bottomPadding = 16 + safeBottom;

    var h = Math.max(120, Math.floor(vh - top - bottomPadding));
    el.style.maxHeight = h + 'px';
    el.style.overflow  = 'auto';

    var old = el.style.overflow;
    el.style.overflow = 'hidden';
    void el.offsetHeight;
    el.style.overflow = old;
  }
      window.resizeTableWrap = resizeTableWrap;
  window.addEventListener('DOMContentLoaded', resizeTableWrap);
  window.addEventListener('orientationchange', function(){ setTimeout(resizeTableWrap, 150); });
  window.addEventListener('resize',            function(){ setTimeout(resizeTableWrap, 150); });
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const master = document.getElementById('check-all');
  const rowChecks = document.querySelectorAll('.row-check');

  if (master) {
    master.addEventListener('change', function() {
      rowChecks.forEach(cb => cb.checked = master.checked);
    });
  }

  const printForm = document.querySelector('form[action="records_print.php"]');
  if (printForm) {
    printForm.addEventListener('submit', function(ev) {
      [...printForm.querySelectorAll('input[name="print_ids[]"]')].forEach(el => el.remove());

      document.querySelectorAll('.row-check').forEach(cb => {
        if (cb.checked) {
          const hid = document.createElement('input');
          hid.type = 'hidden';
          hid.name = 'print_ids[]';
          hid.value = cb.value;
          printForm.appendChild(hid);
        }
      });
    });
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const printIcon = document.getElementById('printIcon');
  const master = document.getElementById('check-all');
  const rowChecks = document.querySelectorAll('.row-check');

  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

  printIcon?.addEventListener('click', function() {
    const allChecked = Array.from(rowChecks).every(cb => cb.checked);
    const newState = !allChecked;
    rowChecks.forEach(cb => cb.checked = newState);
    if (master) master.checked = newState;
  });
});
</script>
<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker.register('sw.js')
      .then(function(reg) {
        console.log('Service worker regisztrálva:', reg.scope);
      })
      .catch(function(err) {
        console.error('Service worker hiba:', err);
      });
  });
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var filterPanel = document.getElementById('filterPanel');
  if (!filterPanel || typeof window.resizeTableWrap !== 'function') return;

  // ha kinyílik vagy becsukódik a szűrő blokk, számoljuk újra a táblázat magasságát
  filterPanel.addEventListener('shown.bs.collapse', function () {
    setTimeout(window.resizeTableWrap, 50);
  });
  filterPanel.addEventListener('hidden.bs.collapse', function () {
    setTimeout(window.resizeTableWrap, 50);
  });
});
</script>


<script>
(function(){
  function resizeTableWrap() {
    var el = document.querySelector('.table-wrap');
    if (!el) return;

    var top = el.getBoundingClientRect().top;
    var vh  = window.innerHeight;
    var safeBottom = (window.visualViewport && window.visualViewport.height)
                     ? (vh - window.visualViewport.height)
                     : 0;

    var bottomPadding = 16 + safeBottom;

    var h = Math.max(120, Math.floor(vh - top - bottomPadding));
    el.style.maxHeight = h + 'px';
    el.style.overflow  = 'auto';

    var old = el.style.overflow;
    el.style.overflow = 'hidden';
    void el.offsetHeight;
    el.style.overflow = old;
  }

  // ⬅ Globálisan elérhetővé tesszük
  window.__pp_resizeTableWrap = resizeTableWrap;

  window.addEventListener('DOMContentLoaded', resizeTableWrap);
  window.addEventListener('orientationchange', function(){ setTimeout(resizeTableWrap, 150); });
  window.addEventListener('resize',            function(){ setTimeout(resizeTableWrap, 150); });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const filter    = document.getElementById('filterPanel');
  const toggleBtn = document.querySelector('[data-bs-target="#filterPanel"]');
  const STORAGE_KEY = 'pp_filter_open';

  if (!filter || !toggleBtn) {
    if (window.__pp_resizeTableWrap) window.__pp_resizeTableWrap();
    return;
  }

  // Mentett állapot
  const saved = localStorage.getItem(STORAGE_KEY);

  if (saved === '0') {
    // Legyen CSUKVA
    filter.classList.remove('show');
    toggleBtn.setAttribute('aria-expanded', 'false');
  } else if (saved === '1') {
    // Legyen NYITVA
    filter.classList.add('show');
    toggleBtn.setAttribute('aria-expanded', 'true');
  } else {
    // Ha még nincs elmentve, vegyük az aktuális DOM állapotot
    const isShown = filter.classList.contains('show');
    toggleBtn.setAttribute('aria-expanded', isShown ? 'true' : 'false');
  }

  // Méret újraszámolása az aktuális állapothoz
  if (window.__pp_resizeTableWrap) {
    window.__pp_resizeTableWrap();
  }

  // Ha kinyitják → mentsük + méretezés
  filter.addEventListener('shown.bs.collapse', function () {
    localStorage.setItem(STORAGE_KEY, '1');
    toggleBtn.setAttribute('aria-expanded', 'true');
    if (window.__pp_resizeTableWrap) {
      window.__pp_resizeTableWrap();
    }
  });

  // Ha becsukják → mentsük + méretezés
  filter.addEventListener('hidden.bs.collapse', function () {
    localStorage.setItem(STORAGE_KEY, '0');
    toggleBtn.setAttribute('aria-expanded', 'false');
    if (window.__pp_resizeTableWrap) {
      window.__pp_resizeTableWrap();
    }
  });
});
</script>

<script>
document.querySelectorAll('.marvin-inline').forEach(function(badge) {
  badge.addEventListener('click', function(e) {
    e.stopPropagation();
    var recordId = badge.dataset.recordId;
    badge.style.opacity = '0.5';
    badge.style.pointerEvents = 'none';
    var fd = new FormData();
    fd.append('record_id', recordId);
    fetch('actions/marvin_accept.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.ok) {
          badge.remove();
          var countEl = document.querySelector('.marvin-count');
          if (countEl) {
            var n = parseInt(countEl.textContent, 10) - 1;
            if (n <= 0) {
              countEl.closest('a').remove();
            } else {
              countEl.textContent = n;
            }
          }
        } else {
          badge.style.opacity = '';
          badge.style.pointerEvents = '';
          alert('Hiba: ' + (data.error || 'ismeretlen hiba'));
        }
      })
      .catch(function() {
        badge.style.opacity = '';
        badge.style.pointerEvents = '';
        alert('Hálózati hiba az elfogadás során.');
      });
  });
});
</script>
</body></html>
