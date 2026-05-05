<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/helpers.php';
require_once __DIR__.'/../src/records_filter.php';

$db = db();

// Rendezési irány – mindkét útvonalhoz
$_allowedSort = ['issued_at','due_at','pp_status','eventus','city'];
$_sort = $_REQUEST['sort'] ?? 'issued_at';
if (!in_array($_sort, $_allowedSort, true)) $_sort = 'issued_at';
$_dir  = !empty($_REQUEST['desc']) ? 'DESC' : 'ASC';
$_orderBy = ($_sort === 'pp_status') ? "ps.name $_dir"
          : (($_sort === 'city')     ? "c.name $_dir"
          : "r.$_sort $_dir");
$_sortArrow = $_dir === 'DESC' ? ' ▼' : ' ▲';
function _ph(string $col, string $label, string $cs, string $arrow): string {
    return $label . ($col === $cs ? '<span style="font-size:.8em;opacity:.6;margin-left:.2em">'.$arrow.'</span>' : '');
}

// 1) jöttek-e konkrétan kijelölt ID-k?
$printIds = $_REQUEST['print_ids'] ?? [];
$isByIds  = is_array($printIds) && count($printIds) > 0;

if ($isByIds) {
    // csak a kijelölteket kérjük le
    // szűrjük az ID-ket int-re
    $ids = array_map('intval', $printIds);
    $ids = array_values(array_unique(array_filter($ids, fn($v)=>$v>0)));

    if (empty($ids)) {
        // ha valamiért üres lett, essünk vissza a normál szűrésre
        $isByIds = false;
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT r.id, r.eventus, r.issued_at, r.due_at, r.address, r.operation, r.archived,
                       ps.name AS pp_name, c.name AS city_name
                FROM records r
                JOIN pp_status ps ON ps.id=r.pp_status_id
                JOIN cities c ON c.id=r.city_id
                WHERE r.id IN ($placeholders)
                ORDER BY $_orderBy, r.id";
        $st = $db->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!$isByIds) {
    // 2) ha nincs kijelölt lista, megy a régi útvonal: szűrés + rendezés
    $params = [];
    $f = build_records_filter($params, $_REQUEST);

    $sql = "SELECT r.id, r.eventus, r.issued_at, r.due_at, r.address, r.operation, r.archived,
                   ps.name AS pp_name, c.name AS city_name
            FROM records r
            JOIN pp_status ps ON ps.id=r.pp_status_id
            JOIN cities c ON c.id=r.city_id
            WHERE {$f['where']}
            ORDER BY $_orderBy, r.id";
    $st = $db->prepare($sql); 
    $st->execute($params); 
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<title>Nyomtatás – PP tételek</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @media print { @page { size: A4 landscape; margin: 12mm; } .no-print { display:none !important; } }
  body { font-family: Arial, sans-serif; font-size: 12px; color:#222; }
  .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
  h1 { font-size: 18px; margin:0; }
  .small { color:#666; margin-bottom: 8px; }
  table { width:100%; border-collapse: collapse; }
  th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
  th { background:#f2f2f2; text-align:left; position: sticky; top: 0; }
  tbody tr:nth-child(even) td { background:#fafafa; }
  .op { white-space: pre-wrap; word-break: break-word; }
  .eventus { font-weight: 600; }
  .row-archived td { color:#666; }
  .badge-arch { display:inline-block; padding:2px 6px; border-radius:10px; 
                font-size:10px; background:#6c757d; color:#fff; }
  .flag {
    font-size: 12px;
    margin-left: 4px;
  }
</style>
</head>
<body>
<div class="topbar no-print">
  <h1>PP tételek – nyomtatás</h1>
  <div>
    <button onclick="window.print()">🖨 Nyomtatás</button>
    <button onclick="window.close()">✖ Bezár</button>
  </div>
</div>

<?php
// --- Szűrés szöveghez ---
$A = $_REQUEST;

if ($isByIds) {
    // ha konkrét listát nyomtatunk, akkor nincs értelme a hosszú "Szűrés: ..." soroknak
    echo '<div class="small">Kijelölt tételek nyomtatása (' . count($rows) . ' db) – ' . date('Y-m-d H:i') . '</div>';
} else {
    $ppIds   = (array)($A['pp_status_id'] ?? []);
    $ppMode  = ($A['pp_mode'] ?? (($A['pp_exc'] ?? null) ? 'exclude' : 'include'));
    $cityIds = (array)($A['city_id'] ?? []);
    $cityMode= ($A['city_mode'] ?? (($A['city_exc'] ?? null) ? 'exclude' : 'include'));

    if (!$ppIds)   $ppIds   = (array)($A['pp_inc'] ?? []);
    if (!$cityIds) $cityIds = (array)($A['city_inc'] ?? []);

    $ppNames   = names_for_ids('pp_status', $ppIds);
    $cityNames = names_for_ids('cities',    $cityIds);

    $archText = !empty($A['include_arch']) ? 'Archív is' : 'Csak aktív tételek';
    $sortText = match($A['sort'] ?? 'issued_at') {
      'pp_status' => 'Rendezés: PP-státusz szerint',
      'eventus'   => 'Rendezés: Eventus szerint',
      'due_at'    => 'Rendezés: +38 nap szerint',
      'city'      => 'Rendezés: Város szerint',
      default     => 'Rendezés: Kiadva szerint'
    };
    if (!empty($A['desc'])) $sortText .= ' (csökkenő)';
    $filtersText = [];

    if (!empty($A['q']))        $filtersText[] = "Keresés: '".h($A['q'])."'";
    if ($ppNames)               $filtersText[] = "PP-státusz ".($ppMode==='exclude'?'kivéve: ':'csak: ').h(implode(', ', $ppNames));
    if ($cityNames)             $filtersText[] = "Város ".($cityMode==='exclude'?'kivéve: ':'csak: ').h(implode(', ', $cityNames));
    $filtersText[] = $archText;
    $filtersText[] = $sortText;

    // due-szűrő átvétele, ha küldtél ilyet
    if (!empty($A['due_filter'])) {
        $filtersText[] = "Lejárat szűrő: " . h($A['due_filter']);
    }

    echo '<div class="small">Dátum: '.date('Y-m-d H:i').'<br>Szűrés: '.implode(' | ', $filtersText).'</div>';
}
?>

<table>
  <thead>
    <tr>
      <th><?= _ph('eventus',   'Eventus',    $_sort, $_sortArrow) ?></th>
      <th><?= _ph('pp_status', 'PP-státusz', $_sort, $_sortArrow) ?></th>
      <th><?= _ph('issued_at', 'Kiadva',     $_sort, $_sortArrow) ?></th>
      <th><?= _ph('due_at',    '+38 nap',    $_sort, $_sortArrow) ?></th>
      <th><?= _ph('city',      'Város',      $_sort, $_sortArrow) ?></th>
      <th>Cím</th><th>Elvégzendő művelet</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['archived'] ? ' class="row-archived"' : '' ?>>
        <td class="eventus">
          <?=h($r['eventus'])?>
          <?php if ($r['archived']): ?>
            <span class="flag">&#9873;</span>
          <?php endif; ?>
        </td>
        <td><?=h($r['pp_name'])?></td>
        <td><?=h($r['issued_at'])?></td>
        <td><?=h($r['due_at'])?></td>
        <td><?=h($r['city_name'])?></td>
        <td><?=h($r['address'])?></td>
        <td class="op"><?=h($r['operation'])?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body></html>