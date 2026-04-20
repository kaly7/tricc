<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';

use App\Db; use App\Auth; use App\Middleware;

Auth::start();
Middleware::requireAuth();
$pdo = Db::pdo();

$u = Auth::user();
$isAdmin = ((int)($u['role_id'] ?? 0) === 1);

$vehicleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$vehicleId) { http_response_code(400); exit('Hibás jármű ID'); }

//$st = $pdo->prepare("SELECT v.*, vt.name AS type_name
//  FROM vehicles v
//  LEFT JOIN vehicle_types vt ON vt.id=v.type_id
//  WHERE v.id=?");


$st = $pdo->prepare("SELECT v.*, vt.name AS type_name
  FROM vehicles v
  LEFT JOIN vehicle_types vt ON vt.id=v.vehicle_type_id
  WHERE v.id=?");

$st->execute([$vehicleId]);
$veh = $st->fetch(PDO::FETCH_ASSOC);
if (!$veh) { http_response_code(404); exit('Nincs ilyen jármű.'); }

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_huf($n){
  if ($n === null || $n === '') return '';
  return number_format((float)$n, 0, ',', ' ') . ' Ft';
}
function fmt_num($n, $dec=2){
  if ($n === null || $n === '') return '';
  return number_format((float)$n, $dec, ',', ' ');
}

function period_rows(PDO $pdo, int $vehicleId, string $fromDate){
  // fueled_at is datetime; use >= fromDate 00:00:00
  $st = $pdo->prepare("SELECT id, fueled_at, odometer_km, fuel_product, quantity_l, gross_huf, unit_price_huf, station_name, slip_id, card_no
    FROM vehicle_fuel_entries
    WHERE vehicle_id=? AND fueled_at >= ?
    ORDER BY fueled_at DESC, id DESC");
  $st->execute([$vehicleId, $fromDate.' 00:00:00']);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function last_n_rows(PDO $pdo, int $vehicleId, int $n){
  $st = $pdo->prepare("SELECT id, fueled_at, odometer_km, fuel_product, quantity_l, gross_huf, unit_price_huf, station_name, slip_id, card_no
    FROM vehicle_fuel_entries
    WHERE vehicle_id=?
    ORDER BY fueled_at DESC, id DESC
    LIMIT ".(int)$n);
  $st->execute([$vehicleId]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function summarize(array $rows){
  $sumL = 0.0; $sumHuf = 0.0; $count = 0;
  $minDate = null; $maxDate = null;
  foreach ($rows as $r){
    $count++;
    $l = (float)($r['quantity_l'] ?? 0);
    $h = (float)($r['gross_huf'] ?? 0);
    $sumL += $l;
    $sumHuf += $h;
    $dt = (string)($r['fueled_at'] ?? '');
    if ($dt !== ''){
      if ($minDate === null || $dt < $minDate) $minDate = $dt;
      if ($maxDate === null || $dt > $maxDate) $maxDate = $dt;
    }
  }
  $avgPrice = ($sumL > 0) ? ($sumHuf / $sumL) : null;
  return [
    'count'=>$count,
    'liters'=>$sumL,
    'huf'=>$sumHuf,
    'avg_price'=>$avgPrice,
    'min_date'=>$minDate,
    'max_date'=>$maxDate,
  ];
}

function consumption_from_rows(array $rows_desc){
  // Need chronological order for intervals
  $rows = array_reverse($rows_desc);
  $sumDist = 0.0; $sumLit = 0.0;
  $intervals = [];
  $prev = null;
  foreach ($rows as $r){
    if (!$prev){ $prev = $r; continue; }
    $km1 = $prev['odometer_km']; $km2 = $r['odometer_km'];
    $l2 = (float)($r['quantity_l'] ?? 0);
    if ($km1 !== null && $km2 !== null){
      $d = (float)$km2 - (float)$km1;
      if ($d > 0.1){
        $sumDist += $d;
        $sumLit += $l2;
        $cons = ($l2 > 0) ? ($l2 / $d * 100.0) : null;
        $cost = (float)($r['gross_huf'] ?? 0);
        $cPerKm = ($cost > 0) ? ($cost / $d) : null;
        $intervals[] = [
          'from'=>(string)($prev['fueled_at'] ?? ''),
          'to'=>(string)($r['fueled_at'] ?? ''),
          'dist'=>$d,
          'liters'=>$l2,
          'cons'=>$cons,
          'gross'=>$cost,
          'cost_per_km'=>$cPerKm,
          'km_from'=>$km1,
          'km_to'=>$km2,
        ];
      }
    }
    $prev = $r;
  }
  $avgCons = ($sumDist > 0.1) ? ($sumLit / $sumDist * 100.0) : null;
  $avgLitPer100 = $avgCons;
  return ['avg_cons'=>$avgLitPer100, 'sum_dist'=>$sumDist, 'sum_lit'=>$sumLit, 'intervals'=>$intervals];
}

// Data
$today = new DateTime('now');
$from30 = (clone $today)->modify('-30 days')->format('Y-m-d');
$from365 = (clone $today)->modify('-365 days')->format('Y-m-d');

$rows30 = period_rows($pdo, $vehicleId, $from30);
$rows365 = period_rows($pdo, $vehicleId, $from365);
$rows10 = last_n_rows($pdo, $vehicleId, 10);

$sum30 = summarize($rows30);
$sum365 = summarize($rows365);
$sum10 = summarize($rows10);

$cons30 = consumption_from_rows($rows30);
$cons365 = consumption_from_rows($rows365);
$cons10 = consumption_from_rows($rows10);

// For simple chart: use last 10 (chronological)
$chartRows = array_reverse($rows10);
$chart = [];
$idx=0;
foreach ($chartRows as $r){
  $idx++;
  $chart[] = [
    'label' => substr((string)($r['fueled_at'] ?? ''), 0, 10),
    'liters' => (float)($r['quantity_l'] ?? 0),
    'gross' => (float)($r['gross_huf'] ?? 0),
  ];
}
$chartJson = json_encode($chart, JSON_UNESCAPED_UNICODE);

require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h1 class="h5 mb-0">Üzemanyag statisztika</h1>
    <div class="text-muted small">
      <?= h($veh['license_plate'] ?? $veh['plate'] ?? '') ?> · <?= h($veh['type_name'] ?? '') ?> <?= h($veh['brand'] ?? '') ?> <?= h($veh['model'] ?? '') ?>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="/vehicle.php?id=<?= (int)$vehicleId ?>&tab=fuel#tab_fuel">Vissza a járműhöz</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-12 col-xl-4">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <strong>Utolsó 30 nap</strong>
        <span class="badge text-bg-secondary"><?= (int)$sum30['count'] ?> tankolás</span>
      </div>
      <div class="mt-2 small">
        <div>Összes liter: <strong><?= fmt_num($sum30['liters'], 2) ?></strong></div>
        <div>Összes költség: <strong><?= fmt_huf($sum30['huf']) ?></strong></div>
        <div>Átlagár: <strong><?= $sum30['avg_price']!==null ? fmt_num($sum30['avg_price'], 1).' Ft/L' : '—' ?></strong></div>
        <div>Fogyasztás (km alapján): <strong><?= $cons30['avg_cons']!==null ? fmt_num($cons30['avg_cons'], 2).' L/100km' : '—' ?></strong></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <strong>Utolsó 365 nap</strong>
        <span class="badge text-bg-secondary"><?= (int)$sum365['count'] ?> tankolás</span>
      </div>
      <div class="mt-2 small">
        <div>Összes liter: <strong><?= fmt_num($sum365['liters'], 2) ?></strong></div>
        <div>Összes költség: <strong><?= fmt_huf($sum365['huf']) ?></strong></div>
        <div>Átlagár: <strong><?= $sum365['avg_price']!==null ? fmt_num($sum365['avg_price'], 1).' Ft/L' : '—' ?></strong></div>
        <div>Fogyasztás (km alapján): <strong><?= $cons365['avg_cons']!==null ? fmt_num($cons365['avg_cons'], 2).' L/100km' : '—' ?></strong></div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center">
        <strong>Utolsó 10 tankolás</strong>
        <span class="badge text-bg-secondary"><?= (int)$sum10['count'] ?> tétel</span>
      </div>
      <div class="mt-2 small">
        <div>Összes liter: <strong><?= fmt_num($sum10['liters'], 2) ?></strong></div>
        <div>Összes költség: <strong><?= fmt_huf($sum10['huf']) ?></strong></div>
        <div>Átlagár: <strong><?= $sum10['avg_price']!==null ? fmt_num($sum10['avg_price'], 1).' Ft/L' : '—' ?></strong></div>
        <div>Fogyasztás (km alapján): <strong><?= $cons10['avg_cons']!==null ? fmt_num($cons10['avg_cons'], 2).' L/100km' : '—' ?></strong></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-xl-6">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong>Grafikon (utolsó 10 tankolás)</strong>
        <span class="text-muted small">liter + összeg</span>
      </div>
      <canvas id="fuelChart" height="220"></canvas>
      <div class="small text-muted mt-2">
        Megjegyzés: egyszerű beépített grafikon (nincs külső chart library).
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-6">
    <div class="card p-0">
      <div class="card-header"><strong>Intervallumok (km alapú fogyasztás)</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-striped m-0 align-middle">
          <thead>
            <tr>
              <th>Időszak</th>
              <th class="text-end">Táv (km)</th>
              <th class="text-end">Liter</th>
              <th class="text-end">L/100km</th>
              <th class="text-end">Költség</th>
              <th class="text-end">Ft/km</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($cons10['intervals'])): ?>
              <tr><td colspan="6" class="text-muted small">Nincs elég km adat a fogyasztás számításához (legalább 2 tankolásnál kell növekvő km).</td></tr>
            <?php else: foreach (array_reverse($cons10['intervals']) as $it): ?>
              <tr>
                <td class="small"><?= h(substr($it['from'],0,10)) ?> → <?= h(substr($it['to'],0,10)) ?></td>
                <td class="text-end"><?= fmt_num($it['dist'], 1) ?></td>
                <td class="text-end"><?= fmt_num($it['liters'], 2) ?></td>
                <td class="text-end"><?= $it['cons']!==null ? fmt_num($it['cons'], 2) : '—' ?></td>
                <td class="text-end"><?= fmt_huf($it['gross']) ?></td>
                <td class="text-end"><?= $it['cost_per_km']!==null ? fmt_num($it['cost_per_km'], 0) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">
        A fogyasztás az egymást követő tankolások km különbségéből és a második tankolás literéből számol (súlyozott átlag).
      </div>
    </div>
  </div>
</div>

<div class="card p-0 mt-3">
  <div class="card-header"><strong>Utolsó 10 tankolás – részletek</strong></div>
  <div class="table-responsive">
    <table class="table table-sm table-striped m-0 align-middle">
      <thead>
        <tr>
          <th>Dátum</th>
          <th>Termék</th>
          <th class="text-end">Km</th>
          <th class="text-end">Liter</th>
          <th class="text-end">Ft/L</th>
          <th class="text-end">Összeg</th>
          <th>Kút</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows10): ?>
          <tr><td colspan="7" class="text-muted small">Nincs üzemanyag tétel.</td></tr>
        <?php else: foreach ($rows10 as $r): ?>
          <tr>
            <td class="text-nowrap"><?= h(substr((string)$r['fueled_at'],0,16)) ?></td>
            <td><?= h($r['fuel_product'] ?? '') ?></td>
            <td class="text-end"><?= h($r['odometer_km'] ?? '') ?></td>
            <td class="text-end"><?= fmt_num($r['quantity_l'] ?? 0, 2) ?></td>
            <td class="text-end">
              <?php
                $upl = $r['unit_price_huf'] ?? null;
                if ($upl===null || $upl==='') {
                  $l = (float)($r['quantity_l'] ?? 0);
                  $g = (float)($r['gross_huf'] ?? 0);
                  $upl = ($l>0) ? ($g/$l) : null;
                }
                echo ($upl!==null) ? fmt_num($upl, 1) : '—';
              ?>
            </td>
            <td class="text-end"><?= fmt_huf($r['gross_huf'] ?? 0) ?></td>
            <td><?= h($r['station_name'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const data = <?= $chartJson ?: '[]' ?>;
  const c = document.getElementById('fuelChart');
  if (!c || !c.getContext) return;
  const ctx = c.getContext('2d');

  function draw(){
    const W = c.width = c.clientWidth;
    const H = c.height = 220;
    ctx.clearRect(0,0,W,H);

    if (!data.length){
      ctx.font = '14px sans-serif';
      ctx.fillText('Nincs adat a grafikonhoz.', 10, 30);
      return;
    }

    // two series: liters (left axis) and gross (right axis)
    const maxLit = Math.max.apply(null, data.map(d=>d.liters||0)) || 1;
    const maxGross = Math.max.apply(null, data.map(d=>d.gross||0)) || 1;

    const padL = 40, padR = 55, padT = 12, padB = 28;
    const x0 = padL, y0 = padT, x1 = W - padR, y1 = H - padB;

    // axes
    ctx.strokeStyle = '#999';
    ctx.beginPath();
    ctx.moveTo(x0, y0); ctx.lineTo(x0, y1); ctx.lineTo(x1, y1);
    ctx.stroke();

    // labels
    ctx.fillStyle = '#666';
    ctx.font = '11px sans-serif';
    ctx.fillText('L', 6, 18);
    ctx.fillText('Ft', W-28, 18);

    const n = data.length;
    const dx = (x1 - x0) / Math.max(1, n-1);

    // liters line
    ctx.strokeStyle = '#0d6efd';
    ctx.lineWidth = 2;
    ctx.beginPath();
    data.forEach((d,i)=>{
      const x = x0 + dx*i;
      const y = y1 - (d.liters/maxLit)*(y1-y0);
      if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
    });
    ctx.stroke();

    // gross line
    ctx.strokeStyle = '#198754';
    ctx.lineWidth = 2;
    ctx.beginPath();
    data.forEach((d,i)=>{
      const x = x0 + dx*i;
      const y = y1 - (d.gross/maxGross)*(y1-y0);
      if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
    });
    ctx.stroke();

    // points + x labels (dates)
    ctx.fillStyle = '#333';
    ctx.font = '10px sans-serif';
    data.forEach((d,i)=>{
      const x = x0 + dx*i;
      const yL = y1 - (d.liters/maxLit)*(y1-y0);
      const yG = y1 - (d.gross/maxGross)*(y1-y0);

      ctx.fillStyle = '#0d6efd';
      ctx.beginPath(); ctx.arc(x,yL,3,0,Math.PI*2); ctx.fill();

      ctx.fillStyle = '#198754';
      ctx.beginPath(); ctx.arc(x,yG,3,0,Math.PI*2); ctx.fill();

      ctx.fillStyle = '#555';
      const lbl = (d.label||'').slice(5); // MM-DD
      ctx.save();
      ctx.translate(x, y1+14);
      ctx.rotate(-Math.PI/5);
      ctx.fillText(lbl, -10, 0);
      ctx.restore();
    });

    // legends
    ctx.fillStyle = '#0d6efd'; ctx.fillRect(x0+8, y0+6, 10, 3);
    ctx.fillStyle = '#333'; ctx.fillText('Liter', x0+22, y0+10);
    ctx.fillStyle = '#198754'; ctx.fillRect(x0+70, y0+6, 10, 3);
    ctx.fillStyle = '#333'; ctx.fillText('Összeg', x0+84, y0+10);
  }

  draw();
  window.addEventListener('resize', function(){ setTimeout(draw, 50); });
})();
</script>

<?php require dirname(__DIR__).'/views/_layout_bottom.php'; ?>
