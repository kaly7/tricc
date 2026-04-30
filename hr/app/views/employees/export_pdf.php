<?php
// $employees   = [{...}]
// $extraFields = [{id, name, field_type, ...}]
// $extraValues = [emp_id => [field_id => value]]
// $baseUrl     = 'http://...'

function hpdf($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$today = date('Y-m-d');
$total = count($employees ?? []);
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <title>HR adatlapok – <?= hpdf($today) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10pt;
      color: #212529;
      background: #fff;
      margin: 0; padding: 0;
    }
    .screen-only { padding: 10px 16px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
    .screen-only button { padding: 5px 14px; font-size: 10pt; cursor: pointer; margin-right: 6px; }

    /* Egy ember = egy oldal */
    .emp-page {
      max-width: 800px;
      margin: 0 auto;
      padding: 12mm 10mm;
      page-break-after: always;
    }
    .emp-page:last-child { page-break-after: avoid; }

    .section { border: 1px solid #ced4da; border-radius: 5px; padding: 10px 12px; margin-bottom: 10px; }
    .section-title {
      font-size: 8pt; text-transform: uppercase; letter-spacing: .05em;
      color: #6c757d; font-weight: bold;
      margin: 0 0 7px 0; padding-bottom: 4px; border-bottom: 1px solid #e9ecef;
    }
    .header-row { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 10px; }
    .header-photo img {
      width: 90px; height: 90px; object-fit: cover;
      border-radius: 5px; border: 1px solid #ced4da; display: block;
    }
    .header-photo .no-photo {
      width: 90px; height: 90px; background: #f8f9fa;
      border: 1px solid #ced4da; border-radius: 5px;
      display: flex; align-items: center; justify-content: center;
      color: #adb5bd; font-size: 8pt;
    }
    .header-info h2 { margin: 0 0 3px 0; font-size: 16pt; }
    .header-info .sub { color: #6c757d; font-size: 9pt; }
    .status-active   { color: #198754; font-weight: bold; }
    .status-inactive { color: #dc3545; font-weight: bold; }
    .grid2 { display: flex; flex-wrap: wrap; gap: 4px 0; }
    .grid2 .cell { width: 50%; padding: 2px 5px; }
    .grid2 .cell.full { width: 100%; }
    .cell .lbl { font-size: 8.5pt; color: #6c757d; display: block; }
    .cell .val { font-weight: bold; }
    .kv { width: 100%; border-collapse: collapse; }
    .kv tr td { padding: 3px 5px; vertical-align: top; }
    .kv .lbl { width: 35%; color: #495057; font-weight: normal; }
    .kv .val { font-weight: bold; }
    .kv tr:nth-child(even) td { background: #f8f9fa; }
    .print-footer {
      font-size: 8pt; color: #6c757d;
      border-top: 1px solid #e9ecef; padding-top: 5px; margin-top: 14px;
    }
    .page-num { color: #adb5bd; font-size: 8pt; float: right; }

    @media print {
      .screen-only { display: none !important; }
      a::after { content: "" !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .section { page-break-inside: avoid; }
    }
    @page {
      size: A4 portrait;
      margin: 14mm 12mm 20mm 12mm;
    }
  </style>
</head>
<body>

<div class="screen-only">
  <button onclick="window.print()">Nyomtatás / PDF mentés</button>
  <button onclick="window.close()">Bezárás</button>
  <span style="color:#6c757d; font-size:9pt"><?= $total ?> dolgozó adatlapja</span>
</div>

<?php foreach ($employees as $idx => $e):
  $division = $e['division_name'] ?? '';
  $profile  = $e['profile_image_path'] ?? '';
  $imgUrl   = '';
  if (!empty($profile)) {
    $imgUrl = rtrim((string)($baseUrl ?? ''), '/') . (str_starts_with($profile, '/') ? $profile : '/' . $profile);
  }

  // Extra mezők ehhez az emberhez
  $empExtraValues = $extraValues[(int)$e['id']] ?? [];
  $hasExtra = false;
  foreach (($extraFields ?? []) as $f) {
    $val  = $empExtraValues[(int)$f['id']] ?? '';
    if (trim((string)$val) !== '') { $hasExtra = true; break; }
  }
?>
<div class="emp-page">

  <!-- Sorszám / lapszám -->
  <div class="page-num"><?= ($idx+1) ?> / <?= $total ?></div>

  <!-- Fejléc -->
  <div class="header-row">
    <div class="header-photo">
      <?php if ($imgUrl): ?>
        <img src="<?= hpdf($imgUrl) ?>" alt="Profilkép">
      <?php else: ?>
        <div class="no-photo">Nincs kép</div>
      <?php endif; ?>
    </div>
    <div class="header-info">
      <h2><?= hpdf($e['full_name'] ?? '') ?></h2>
      <div class="sub">Generálva: <?= hpdf($today) ?></div>
      <?php if ($division): ?>
        <div class="sub">Divízió: <strong><?= hpdf($division) ?></strong></div>
      <?php endif; ?>
      <div class="sub" style="margin-top:3px">Állapot:
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
      <div class="cell"><span class="lbl">Születési név</span><span class="val"><?= hpdf($e['birth_name'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Anyja neve</span><span class="val"><?= hpdf($e['mother_name'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Születési hely</span><span class="val"><?= hpdf($e['birth_place'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Születési dátum</span><span class="val"><?= hpdf($e['birth_date'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Céges / azonosító -->
  <div class="section">
    <div class="section-title">Céges / azonosító</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Adóazonosító</span><span class="val"><?= hpdf($e['tax_id'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">TAJ szám</span><span class="val"><?= hpdf($e['taj'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Céges törzsszám</span><span class="val"><?= hpdf($e['company_emp_no'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Divízió</span><span class="val"><?= hpdf($division ?: '—') ?></span></div>
    </div>
  </div>

  <!-- Bankszámla -->
  <div class="section">
    <div class="section-title">Bankszámla</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Bankszámlaszám</span><span class="val"><?= hpdf($e['bank_account'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Bank neve</span><span class="val"><?= hpdf($e['bank_name'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Munkaviszony -->
  <div class="section">
    <div class="section-title">Munkaviszony</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Belépés dátuma</span><span class="val"><?= hpdf($e['hired_on'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Kilépés dátuma</span><span class="val"><?= hpdf($e['left_on'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Lakcím -->
  <div class="section">
    <div class="section-title">Lakcím</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Irányítószám</span><span class="val"><?= hpdf($e['addr_zip'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Település</span><span class="val"><?= hpdf($e['addr_city'] ?? '—') ?></span></div>
      <div class="cell full"><span class="lbl">Cím</span><span class="val"><?= hpdf($e['addr_line'] ?? '—') ?></span></div>
    </div>
  </div>

  <!-- Kapcsolat -->
  <div class="section">
    <div class="section-title">Kapcsolat</div>
    <div class="grid2">
      <div class="cell"><span class="lbl">Email</span><span class="val"><?= hpdf($e['email'] ?? '—') ?></span></div>
      <div class="cell"><span class="lbl">Telefon</span><span class="val"><?= hpdf($e['phone'] ?? '—') ?></span></div>
      <?php if (!empty($e['notes'])): ?>
        <div class="cell full"><span class="lbl">Megjegyzés</span><span class="val"><?= nl2br(hpdf($e['notes'])) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Extra mezők -->
  <?php if ($hasExtra): ?>
  <div class="section">
    <div class="section-title">Egyéb adatok</div>
    <table class="kv">
      <tbody>
        <?php foreach (($extraFields ?? []) as $f):
          $val  = $empExtraValues[(int)$f['id']] ?? '';
          if (trim((string)$val) === '') continue;
          $type = $f['field_type'] ?? 'text';
          $disp = $val;
          if ($type === 'multiselect') {
            $arr = json_decode((string)$val, true);
            if (is_array($arr)) $disp = implode(', ', $arr);
          }
        ?>
          <tr>
            <td class="lbl"><?= hpdf($f['name'] ?? '') ?></td>
            <td class="val"><?= nl2br(hpdf((string)$disp)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Lejáratos dokumentumok -->
  <?php
    $empDocList = ($empDocs ?? [])[(int)$e['id']] ?? [];
    if (!empty($empDocList)):
  ?>
  <div class="section">
    <div class="section-title">Dokumentumok (lejárattal)</div>
    <table class="kv">
      <tbody>
        <?php foreach ($empDocList as $doc): ?>
          <tr>
            <td class="lbl"><?= hpdf($doc['type_name'] ?? '') ?></td>
            <td class="val"><?= hpdf($doc['expires_at'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Lábléc -->
  <div class="print-footer">
    Perfect-Phone 2026 – HR &nbsp;|&nbsp; <?= hpdf($e['full_name'] ?? '') ?> &nbsp;|&nbsp; <?= hpdf($today) ?>
  </div>

</div><!-- /emp-page -->
<?php endforeach; ?>

</body>
</html>
