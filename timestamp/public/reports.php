<?php
require_once __DIR__ . '/../functions.php';
require_login(); require_admin();

$start=parse_date($_GET['start']??date('Y-m-01'));
$end=parse_date($_GET['end']??date('Y-m-t'));
$export=isset($_GET['export']);

// user x project
$sql1="SELECT u.username, COALESCE(u.full_name,u.username) AS display_name, p.name AS project, SUM(ts.hours) AS total_hours
       FROM timesheets ts JOIN users u ON u.id=ts.user_id JOIN projects p ON p.id=ts.project_id
       WHERE ts.work_date BETWEEN :s AND :e
       GROUP BY u.username, display_name, p.name
       ORDER BY display_name, p.name";
$st1=db()->prepare($sql1); $st1->execute([':s'=>$start, ':e'=>$end]); $data1=$st1->fetchAll();

// project totals
$sql2="SELECT p.name AS project, SUM(ts.hours) AS total_hours
       FROM timesheets ts JOIN projects p ON p.id=ts.project_id
       WHERE ts.work_date BETWEEN :s AND :e
       GROUP BY p.name ORDER BY p.name";
$st2=db()->prepare($sql2); $st2->execute([':s'=>$start, ':e'=>$end]); $data2=$st2->fetchAll();

// user totals
$sql3="SELECT u.username, COALESCE(u.full_name,u.username) AS display_name, SUM(ts.hours) AS total_hours
       FROM timesheets ts JOIN users u ON u.id=ts.user_id
       WHERE ts.work_date BETWEEN :s AND :e
       GROUP BY u.username, display_name ORDER BY display_name";
$st3=db()->prepare($sql3); $st3->execute([':s'=>$start, ':e'=>$end]); $data3=$st3->fetchAll();

if ($export){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=riport_' . $start . '_to_' . $end . '.csv');
  $out=fopen('php://output','w');
  fputcsv($out,['Felhasználó','Projekt','Összes óra']);
  foreach($data1 as $r) fputcsv($out, [($r['display_name']?:$r['username']), $r['project'], $r['total_hours']]);
  fputcsv($out,[]);
  fputcsv($out,['Projekt','Összes óra']);
  foreach($data2 as $r) fputcsv($out, [$r['project'],$r['total_hours']]);
  fputcsv($out,[]);
  fputcsv($out,['Felhasználó (összes projekten)','Összes óra']);
  foreach($data3 as $r) fputcsv($out, [($r['display_name']?:$r['username']), $r['total_hours']]);
  fclose($out); exit;
}

include __DIR__ . '/common_header.php';
?>
<div class="card">
  <h2>Riportok</h2>
  <form method="get" class="flex" style="align-items:flex-end;">
    <div><label>Kezdet</label><input type="date" name="start" value="<?= h($start) ?>"></div>
    <div><label>Vég</label><input type="date" name="end" value="<?= h($end) ?>"></div>
    <div><button>Keresés</button> <a class="btn secondary" href="?start=<?= h($start) ?>&end=<?= h($end) ?>&export=1">CSV export</a></div>
  </form>
  <div class="grid cols-2">
    <div>
      <h3>Összesítés felhasználó/projekt</h3>
      <div class="table-container"><table class="table">
        <thead><tr><th>Felhasználó</th><th>Projekt</th><th>Összes óra</th></tr></thead>
        <tbody>
          <?php foreach($data1 as $r): ?>
            <tr>
              <td data-label="Felhasználó"><?= h($r['display_name']?:$r['username']) ?></td>
              <td data-label="Projekt"><?= h($r['project']) ?></td>
              <td data-label="Összes óra"><?= h((string)$r['total_hours']) ?></td>
            </tr>
          <?php endforeach; if(!$data1): ?><tr><td colspan="3">Nincs adat.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <div>
      <h3>Projektek összesített ideje</h3>
      <div class="table-container"><table class="table">
        <thead><tr><th>Projekt</th><th>Összes óra</th></tr></thead>
        <tbody>
          <?php foreach($data2 as $r): ?>
            <tr>
              <td data-label="Projekt"><?= h($r['project']) ?></td>
              <td data-label="Összes óra"><?= h((string)$r['total_hours']) ?></td>
            </tr>
          <?php endforeach; if(!$data2): ?><tr><td colspan="2">Nincs adat.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <div class="mt16">
    <h3>Felhasználók összesen (minden projekt együtt)</h3>
    <div class="table-container"><table class="table">
      <thead><tr><th>Felhasználó</th><th>Összes óra</th></tr></thead>
      <tbody>
        <?php foreach($data3 as $r): ?>
          <tr>
            <td data-label="Felhasználó"><?= h($r['display_name']?:$r['username']) ?></td>
            <td data-label="Összes óra"><?= h((string)$r['total_hours']) ?></td>
          </tr>
        <?php endforeach; if(!$data3): ?><tr><td colspan="2">Nincs adat.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php include __DIR__ . '/common_footer.php'; ?>
