<?php
/**
 * Aktív job lista megjelenítő include.
 *
 * Paraméterek (include előtt definiálandó):
 *   $aktiv_jobok_lathatosag  – 'semmi' | 'sajat' | 'osszes'
 *   $aktiv_jobok_tipus       – 'RI' | 'PP'  (sajat szűrésnél a job_id végéhez illesztve)
 *   $aktiv_jobok_conn        – nyitott mysqli kapcsolat
 */

if (!isset($aktiv_jobok_lathatosag) || $aktiv_jobok_lathatosag === 'semmi') {
    return;
}

$sql_aj = "SELECT Goal_name, Megjegyzes FROM Button_Goals WHERE akcio='aktiv' ORDER BY Megjegyzes";
$res_aj = $aktiv_jobok_conn->query($sql_aj);
if (!$res_aj || $res_aj->num_rows === 0) {
    return;
}

$rows_aj = [];
while ($r = $res_aj->fetch_assoc()) {
    $jid = $r['Megjegyzes'];
    if ($aktiv_jobok_lathatosag === 'sajat') {
        $suffix = '_' . $aktiv_jobok_tipus;
        if (substr($jid, -strlen($suffix)) !== $suffix) {
            continue;
        }
    }
    $rows_aj[$jid][] = $r['Goal_name'];
}

if (empty($rows_aj)) {
    return;
}
?>
<hr>
<div style="text-align:left; font-size:13px; color:#ddd;">Aktív jobok:</div>
<?php foreach ($rows_aj as $jid => $goals): ?>
<div style="margin:4px 0; font-size:13px;">
  <span style="color:#aaa; font-size:11px;"><?php echo htmlspecialchars($jid); ?></span><br>
  <?php foreach ($goals as $gn): ?>
    <button type="button" class="myButton_vh" style="font-size:12px;padding:3px 10px;margin:2px;"><?php echo htmlspecialchars($gn); ?></button>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>
