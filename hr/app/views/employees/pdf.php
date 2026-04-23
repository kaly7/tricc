<?php
$e        = $employee ?? [];
$division = $e['division_name'] ?? ($e['company_division'] ?? '');
$profile  = $e['profile_image_path'] ?? '';
$imgUrl   = '';
if (!empty($profile)) {
  $imgUrl = rtrim((string)($baseUrl ?? ''), '/') . (str_starts_with($profile, '/') ? $profile : '/' . $profile);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$today = date('Y-m-d');

// Extra mezők: van-e valami megjelenítendő?
$hasExtra = false;
if (!empty($fields) && !empty($field_values)) {
  foreach ($fields as $f) {
    $fid  = (int)$f['id'];
    $val  = $field_values[$fid]['value'] ?? '';
    $show = (int)($field_values[$fid]['show'] ?? 1);
    if ($show === 1 && trim((string)$val) !== '') { $hasExtra = true; break; }
  }
}
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>Karton – <?= h($e['full_name'] ?? '') ?></title>
  <style>
    /* ---- Alap ---- */
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      color: #212529;
      background: #fff;
      margin: 0;
      padding: 0;
    }
    .container { max-width: 800px; margin: 0 auto; padding: 12mm 10mm; }

    /* ---- Szekciók ---- */
    .section {
      border: 1px solid #ced4da;
      border-radius: 5px;
      padding: 10px 12px;
      margin-bottom: 10px;
    }
    .section-title {
      font-size: 8pt;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #6c757d;
      font-weight: bold;
      margin: 0 0 7px 0;
      padding-bottom: 4px;
      border-bottom: 1px solid #e9ecef;
    }

    /* ---- Fejléc ---- */
    .header-row {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      margin-bottom: 10px;
    }
    .header-photo img {
      width: 90px;
      height: 90px;
      object-fit: cover;
      border-radius: 5px;
      border: 1px solid #ced4da;
      display: block;
    }
    .header-photo .no-photo {
      width: 90px;
      height: 90px;
      background: #f8f9fa;
      border: 1px solid #ced4da;
      border-radius: 5px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #adb5bd;
      font-size: 8pt;
    }
    .header-info h2 { margin: 0 0 3px 0; font-size: 16pt; }
    .header-info .sub { color: #6c757d; font-size: 9pt; }
    .status-active   { color: #198754; font-weight: bold; }
    .status-inactive { color: #dc3545; font-weight: bold; }

    /* ---- Adattábla (kulcs-érték) ---- */
    .kv { width: 100%; border-collapse: collapse; }
    .kv tr td, .kv tr th { padding: 3px 5px; vertical-align: top; }
    .kv .lbl { width: 35%; color: #495057; font-weight: normal; }
    .kv .val { font-weight: bold; }
    .kv tr:nth-child(even) td,
    .kv tr:nth-child(even) th { background: #f8f9fa; }

    /* ---- Grid (2 oszlopos) ---- */
    .grid2 { display: flex; flex-wrap: wrap; gap: 4px 0; }
    .grid2 .cell { width: 50%; padding: 2px 5px; }
    .grid2 .cell.full { width: 100%; }
    .cell .lbl { font-size: 8.5pt; color: #6c757d; display: block; }
    .cell .val { font-weight: bold; }

    /* ---- Dokumentum táblázat ---- */
    .doc-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
    .doc-table th { background: #f8f9fa; padding: 4px 6px; text-align: left; border-bottom: 1px solid #ced4da; }
    .doc-table td { padding: 3px 6px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
    .exp-ok      { color: #6c757d; }
    .exp-soon    { color: #856404; font-weight: bold; }
    .exp-expired { color: #dc3545; font-weight: bold; }

    /* ---- Nyomtatógomb (csak képernyőn) ---- */
    .screen-only { margin-bottom: 14px; }

    /* ---- Lábléc / oldalszám ---- */
    .print-footer {
      font-size: 8pt;
      color: #6c757d;
      border-top: 1px solid #e9ecef;
      padding-top: 5px;
      margin-top: 14px;
    }

    /* ---- Nyomtatási szabályok ---- */
    @media print {
      .screen-only { display: none !important; }
      a::after { content: "" !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .section { page-break-inside: avoid; }
    }

    @page {
      size: A4 portrait;
      margin: 14mm 12mm 20mm 12mm;
      @bottom-right {
        content: counter(page) ". oldal / " counter(pages);
        font-size: 8pt;
        color: #6c757d;
      }
      @bottom-left {
        content: "<?= addslashes(h($e['full_name'] ?? '')) ?> – HR karton";
        font-size: 8pt;
        color: #6c757d;
      }
    }
  </style>
</head>
<body>
<div class="container">

  <!-- Nyomtatógombok (csak képernyőn) -->
  <div class="screen-only">
    <button onclick="window.print()" style="padding:6px 16px;font-size:10pt;cursor:pointer;">Nyomtatás / PDF mentés</button>
    <button onclick="window.close()" style="padding:6px 16px;font-size:10pt;cursor:pointer;margin-left:6px;">Bezárás</button>
  </div>

  <!-- Fejléc -->
  <div class="header-row">
    <div class="header-photo">
      <?php if ($imgUrl): ?>
        <img src="<?= h($imgUrl) ?>" alt="Profilkép">
      <?php else: ?>
        <div class="no-photo">Nincs kép</div>
      <?php endif; ?>
    </div>
    <div class="header-info">
      <h2><?= h($e['full_name'] ?? '') ?></h2>
      <div class="sub">Készült: <?= h($today) ?></div>
      <?php if (!empty($division)): ?>
        <div class="sub">Divízió: <strong><?= h($division) ?></strong></div>
      <?php endif; ?>
      <div class="sub" style="margin-top:3px;">
        Állapot:
        <?php if ((int)($e['is_active'] ?? 1) === 1): ?>
          <span class="status-active">Aktív</span>
        <?php else: ?>
          <span class="status-inactive">Inaktív</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Személyes adatok -->
  <div class="section">
    <div class="section-title">Személyes adatok</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Születési név</span><span class="val"><?= h($e['birth_name'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Anyja neve</span><span class="val"><?= h($e['mother_name'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Születési hely</span><span class="val"><?= h($e['birth_place'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Születési dátum</span><span class="val"><?= h($e['birth_date'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Céges / azonosító -->
  <div class="section">
    <div class="section-title">Céges / azonosító</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Adóazonosító</span><span class="val"><?= h($e['tax_id'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">TAJ szám</span><span class="val"><?= h($e['taj'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Céges törzsszám</span><span class="val"><?= h($e['company_emp_no'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Divízió</span><span class="val"><?= h($division ?: '—') ?></span></div>
    </div>
  </div>

  <!-- Bankszámla -->
  <div class="section">
    <div class="section-title">Bankszámla</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Bankszámlaszám</span><span class="val"><?= h($e['bank_account'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Bank neve</span><span class="val"><?= h($e['bank_name'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Munkaviszony -->
  <div class="section">
    <div class="section-title">Munkaviszony</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Belépés dátuma</span><span class="val"><?= h($e['hired_on'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Kilépés dátuma</span><span class="val"><?= h($e['left_on'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Lakcím -->
  <div class="section">
    <div class="section-title">Lakcím</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Irányítószám</span><span class="val"><?= h($e['addr_zip'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Település</span><span class="val"><?= h($e['addr_city'] ?? '—') ?></span></div>
      <div class="cell full"><span class="lbl">Cím</span><span class="val"><?= h($e['addr_line'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Kapcsolat -->
  <div class="section">
    <div class="section-title">Kapcsolat</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Email</span><span class="val"><?= h($e['email'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Telefon</span><span class="val"><?= h($e['phone'] ?? '—') ?></span></div>
      <?php if (!empty($e['notes'])): ?>
        <div class="cell full"><span class="lbl">Megjegyzés</span><span class="val"><?= nl2br(h($e['notes'])) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Extra mezők -->
  <?php if ($hasExtra): ?>
  <div class="section">
    <div class="section-title">Egyéb adatok</div>
    <table class="kv">
      <tbody>
        <?php foreach (($fields ?? []) as $f): ?>
          <?php
            $fid  = (int)$f['id'];
            $val  = $field_values[$fid]['value'] ?? '';
            $show = (int)($field_values[$fid]['show'] ?? 1);
            if ($show !== 1 || trim((string)$val) === '') continue;
            $type = $f['field_type'] ?? 'text';
            $disp = $val;
            if ($type === 'multiselect') {
              $arr = json_decode((string)$val, true);
              if (is_array($arr)) $disp = implode(', ', $arr);
            }
          ?>
          <tr>
            <td class="lbl"><?= h($f['name'] ?? '') ?></td>
            <td class="val"><?= nl2br(h((string)$disp)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Dokumentumok -->
  <?php if (!empty($docs)): ?>
  <div class="section">
    <div class="section-title">Dokumentumok</div>
    <table class="doc-table">
      <thead>
        <tr>
          <th>Típus / Megnevezés</th>
          <th>Feltöltve</th>
          <th>Lejárat</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d):
          $exp  = $d['expires_at'] ?? null;
          $expCls = 'exp-ok';
          $expVal = $exp ?? '—';
          if (!empty($exp)) {
            $ts   = strtotime((string)$exp);
            $days = (int)floor(($ts - time()) / 86400);
            if ($days < 0)      $expCls = 'exp-expired';
            elseif ($days <= 30) $expCls = 'exp-soon';
          }
          $label = ($d['doc_type_name'] ?? ($d['doc_type'] ?? ''));
          if (!empty($d['title'])) $label .= ' – ' . $d['title'];
        ?>
          <tr>
            <td><?= h($label) ?></td>
            <td><?= h(substr((string)($d['created_at'] ?? ''), 0, 10)) ?></td>
            <td class="<?= $expCls ?>"><?= h($expVal) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Lábléc -->
  <div class="print-footer">
    Perfect-Phone 2026 – HR &nbsp;|&nbsp; <?= h($e['full_name'] ?? '') ?> &nbsp;|&nbsp; Nyomtatva: <?= h($today) ?>
  </div>

</div>
</body>
</html>
