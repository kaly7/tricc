<?php
// print-friendly employee card (browser print -> PDF)
$e = $employee ?? [];
$division = $e['division_name'] ?? ($e['company_division'] ?? '');
$profile = $e['profile_image_path'] ?? '';
$imgUrl = '';
if (!empty($profile)) {
  // profile_image_path starts with /uploads/...
  $imgUrl = rtrim((string)($baseUrl ?? ''), '/') . (str_starts_with($profile, '/') ? $profile : '/' . $profile);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$today = date('Y-m-d');
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Karton PDF – <?= h($e['full_name'] ?? '') ?></title>
  <link href="/assets/bootstrap/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#fff;}
    .section{border:1px solid #dee2e6; border-radius:.5rem; padding:1rem; margin-bottom:1rem;}
    .label{color:#6c757d; width:32%;}
    .kv td,.kv th{padding:.35rem .5rem;}
    .photo{width:120px; height:120px; object-fit:cover; border-radius:.5rem; border:1px solid #dee2e6;}
    @media print{
      .no-print{display:none !important;}
      a[href]:after{content:"";}
      body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .section{page-break-inside: avoid;}
    }
  </style>
</head>
<body>
<div class="container my-3">
  <div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <div>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.close()">Bezárás</button>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-primary" onclick="window.print()">Nyomtatás / PDF mentés</button>
    </div>
  </div>

  <div class="d-flex align-items-start gap-3 mb-3">
    <?php if ($imgUrl): ?>
      <img class="photo" src="<?= h($imgUrl) ?>" alt="Profilkép">
    <?php endif; ?>
    <div class="flex-grow-1">
      <h3 class="m-0"><?= h($e['full_name'] ?? '') ?></h3>
      <div class="text-muted">Készült: <?= h($today) ?></div>
      <?php if (!empty($division)): ?>
        <div class="mt-1"><span class="badge bg-light text-dark border">Divízió: <?= h($division) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section">
    <h5 class="m-0 mb-2">Alapadatok</h5>
    <table class="table table-sm kv mb-0">
      <tbody>
        <tr><th class="label">Születési név</th><td><?= h($e['birth_name'] ?? '') ?></td></tr>
        <tr><th class="label">Anyja neve</th><td><?= h($e['mother_name'] ?? '') ?></td></tr>
        <tr><th class="label">Születési hely, idő</th><td><?= h($e['birth_place'] ?? '') ?><?= !empty($e['birth_date']) ? ' – ' . h($e['birth_date']) : '' ?></td></tr>
        <tr><th class="label">Adóazonosító</th><td><?= h($e['tax_id'] ?? '') ?></td></tr>
        <tr><th class="label">TAJ szám</th><td><?= h($e['taj'] ?? '') ?></td></tr>
        <tr><th class="label">Céges törzsszám</th><td><?= h($e['company_emp_no'] ?? '') ?></td></tr>
        <tr><th class="label">Email</th><td><?= h($e['email'] ?? '') ?></td></tr>
        <tr><th class="label">Telefon</th><td><?= h($e['phone'] ?? '') ?></td></tr>
        <tr><th class="label">Lakcím</th><td><?= h(($e['addr_zip'] ?? '').' '.($e['addr_city'] ?? '').', '.($e['addr_line'] ?? '')) ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php if (!empty($e['notes'])): ?>
  <div class="section">
    <h5 class="m-0 mb-2">Megjegyzés</h5>
    <div><?= nl2br(h($e['notes'])) ?></div>
  </div>
  <?php endif; ?>

  <?php
    $hasExtra = false;
    if (!empty($fields) && !empty($field_values)) {
      foreach ($fields as $f) {
        $fid = (int)$f['id'];
        $val = $field_values[$fid]['value'] ?? '';
        $show = (int)($field_values[$fid]['show'] ?? 1);
        if ($show === 1 && trim((string)$val) !== '') { $hasExtra = true; break; }
      }
    }
  ?>
  <?php if ($hasExtra): ?>
  <div class="section">
    <h5 class="m-0 mb-2">Egyéb adatok</h5>
    <table class="table table-sm kv mb-0">
      <tbody>
        <?php foreach (($fields ?? []) as $f): ?>
          <?php
            $fid = (int)$f['id'];
            $val = $field_values[$fid]['value'] ?? '';
            $show = (int)($field_values[$fid]['show'] ?? 1);
            if ($show !== 1) continue;
            if (trim((string)$val) === '') continue;

            $type = $f['field_type'] ?? 'text';
            $disp = $val;
            if (in_array($type, ['multiselect'], true)) {
              $arr = json_decode((string)$val, true);
              if (is_array($arr)) $disp = implode(', ', $arr);
            }
          ?>
          <tr><th class="label"><?= h($f['name'] ?? '') ?></th><td><?= nl2br(h((string)$disp)) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($docs)): ?>
  <div class="section">
    <h5 class="m-0 mb-2">Dokumentumok</h5>
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th>Megnevezés</th>
          <th>Feltöltve</th>
          <th>Lejárat</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <?php
            $exp = $d['expires_at'] ?? null;
            $cls = '';
            if (!empty($exp)) {
              $ts = strtotime((string)$exp);
              $days = (int)floor(($ts - time())/86400);
              if ($days < 0) $cls = 'table-danger';
              elseif ($days <= 30) $cls = 'table-warning';
            }
          ?>
          <tr class="<?= h($cls) ?>">
            <td><?= h($d['doc_type_name'] ?? ($d['doc_type'] ?? '')) ?><?= !empty($d['title']) ? ' – ' . h($d['title']) : '' ?></td>
            <td><?= h(substr((string)($d['created_at'] ?? ''),0,10)) ?></td>
            <td><?= h($exp ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <div class="text-muted small mb-4">
    Perfect-Phone 2026 – HR
  </div>
</div>
<script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
