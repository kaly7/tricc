<?php
require_once __DIR__.'/db.php';

$projekt_id = intval($_GET['projekt_id'] ?? 0);
$format     = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv','pdf','xlsx'])) $format = 'csv';
if (!$projekt_id) { http_response_code(400); exit('Hiányzó projekt_id'); }

$db = db();

// ── Projekt ──────────────────────────────────────────────────────────────────
$projekt = $db->prepare('SELECT * FROM projektek WHERE id=?');
$projekt->execute([$projekt_id]);
$projekt = $projekt->fetch();
if (!$projekt) { http_response_code(404); exit('Nem található'); }

$vr = $db->prepare('SELECT COALESCE(MAX(verzio_szam),0) FROM projekt_verziok WHERE projekt_id=?');
$vr->execute([$projekt_id]);
$verzio = intval($vr->fetchColumn());

// ── Tételek betöltése ────────────────────────────────────────────────────────
$stmt = $db->prepare('
  SELECT t.*, e.megnevezes AS egysegar_nev, e.egyseg_dij, e.egyseg AS egysegar_egyseg
  FROM tetelek t LEFT JOIN egysegarak e ON e.id=t.egysegar_id
  WHERE t.projekt_id=? ORDER BY t.sorrend, t.id');
$stmt->execute([$projekt_id]);
$tetelek = $stmt->fetchAll();

// ── Csoportosítás (azonos mint generalt.php) ─────────────────────────────────
$csoportok = [];
foreach ($tetelek as $t) {
  $key = $t['csoport_id'].'_'.($t['egysegar_id'] ?? 'null');
  if (!isset($csoportok[$key])) {
    $csoportok[$key] = [
      'csoport_id'      => $t['csoport_id'],
      'egysegar_id'     => $t['egysegar_id'],
      'egysegar_nev'    => $t['egysegar_nev'],
      'egyseg_dij'      => $t['egyseg_dij'],
      'egysegar_egyseg' => $t['egysegar_egyseg'],
      'munka_osszeg'    => 0,
      'tetelek'         => [],
    ];
  }
  $csoportok[$key]['munka_osszeg'] += $t['mennyiseg'] * $t['munkadij_egyseg'];
  $csoportok[$key]['tetelek'][]    = $t;
}
uasort($csoportok, fn($a,$b) => ($a['tetelek'][0]['sorrend']??0) <=> ($b['tetelek'][0]['sorrend']??0));

$gen_sorok = [];
$prev_csoport = null;
foreach ($csoportok as $csoport) {
  $hatar = ($prev_csoport !== null && $csoport['csoport_id'] !== $prev_csoport);
  foreach ($csoport['tetelek'] as $t) {
    $gen_sorok[] = ['tipus'=>'anyag', 'hatar'=>$hatar, 'tetel'=>$t];
    $hatar = false;
  }
  if ($csoport['egysegar_id'] && $csoport['munka_osszeg'] > 0 && $csoport['egyseg_dij'] > 0) {
    $gen_sorok[] = [
      'tipus'           => 'napidij',
      'egysegar_nev'    => $csoport['egysegar_nev'],
      'egyseg_dij'      => (float)$csoport['egyseg_dij'],
      'egysegar_egyseg' => $csoport['egysegar_egyseg'] ?? 'klt',
      'tort'            => $csoport['munka_osszeg'] / $csoport['egyseg_dij'],
      'dij_osszesen'    => $csoport['munka_osszeg'],
    ];
  }
  $prev_csoport = $csoport['csoport_id'];
}

// ── Összesítők ────────────────────────────────────────────────────────────────
$anyag_total   = 0;
$napidij_total = 0;
foreach ($gen_sorok as $s) {
  if ($s['tipus']==='anyag')   $anyag_total   += $s['tetel']['mennyiseg'] * $s['tetel']['anyagar_egyseg'];
  if ($s['tipus']==='napidij') $napidij_total += $s['dij_osszesen'];
}
$vegosszeg = $anyag_total + $napidij_total;
$afa       = $vegosszeg * 0.27;
$brutto    = $vegosszeg + $afa;

// ── Fájlnév ────────────────────────────────────────────────────────────────────
function safe_fn(string $s): string {
  $s = mb_strtolower($s);
  $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ő'=>'o','ú'=>'u','ü'=>'u','ű'=>'u'];
  $s = strtr($s, $map);
  return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
}
$datum     = date('Y-m-d');
$v_str     = $verzio > 0 ? '_v'.$verzio : '';
$fajlnev   = safe_fn($projekt['nev']).'_'.$datum.$v_str;

// ── Szám formázók ──────────────────────────────────────────────────────────────
// Magyaros ezreselválasztós, Ft-tal
function ft(float $n): string { return number_format($n, 0, ',', ' ').' Ft'; }
function dec(float $n): string { return rtrim(rtrim(number_format($n, 4, ',', ' '), '0'), ','); }

/*
 * OSZLOPSZERKEZET (10 col, azonos a generalt.php-val):
 *  0:#  1:Megnevezés  2:Gyártó  3:Típus  4:Menny.  5:Egység
 *  6:Anyagár/e  7:Díj/egys.  8:Anyag Σ  9:Díj Σ
 *
 * Összesítő sorok:
 *  "Összesen nettó:"  → col 7 = label,  col 8 = anyag_total,  col 9 = napidij_total
 *  "Nettó végösszeg:" → col 7 = label,  col 8 = vegosszeg,    col 9 = ''
 *  "27% ÁFA:"         → col 7 = label,  col 8 = afa,          col 9 = ''
 *  "Bruttó végösszeg:"→ col 7 = label,  col 8 = brutto,       col 9 = ''
 */
$FEJLEC = ['#','Megnevezés','Gyártó','Típus','Menny.','Egység','Anyagár/e','Díj/egys.','Anyag Σ','Díj Σ'];

// ═══════════════════════════════════════════════════════════════════════════════
// CSV
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$fajlnev.'.csv"');
  echo "\xEF\xBB\xBF";
  $fh = fopen('php://output','w');
  fputcsv($fh, $FEJLEC, ';');

  $sorsz = 0;
  foreach ($gen_sorok as $s) {
    $sorsz++;
    if ($s['tipus']==='anyag') {
      $t = $s['tetel'];
      $ao = $t['mennyiseg'] * $t['anyagar_egyseg'];
      fputcsv($fh, [
        $sorsz,
        $t['megnevezes'], $t['gyarto'], $t['tipus'],
        dec((float)$t['mennyiseg']), $t['egyseg'],
        ft((float)$t['anyagar_egyseg']), ft(0),
        ft($ao), ft(0),
      ], ';');
    } else {
      fputcsv($fh, [
        $sorsz,
        $s['egysegar_nev'], '', '',
        dec($s['tort']), $s['egysegar_egyseg'],
        '—', ft($s['egyseg_dij']),
        '—', ft($s['dij_osszesen']),
      ], ';');
    }
  }

  fputcsv($fh, [], ';');
  // Összesítők – label a 7. oszlopban (0-indexed), értékek a 8-9. oszlopban
  fputcsv($fh, ['','','','','','','','Összesen nettó:', ft($anyag_total), ft($napidij_total)], ';');
  fputcsv($fh, ['','','','','','','','Nettó végösszeg:', ft($vegosszeg), ''], ';');
  fputcsv($fh, ['','','','','','','','27% ÁFA:', ft($afa), ''], ';');
  fputcsv($fh, ['','','','','','','','Bruttó végösszeg:', ft($brutto), ''], ';');
  fclose($fh);
  exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// XLSX
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'xlsx') {

  // ── Sor típusok ──
  // type: 'h'=fejléc, 'a'=anyag, 'n'=napidij, 's'=összesítő, 'b'=bruttó, 'u'=üres
  // Minden cella: ['v'=>érték, 't'=>'str'|'num'|'ft'|'dec']
  // 'ft'  = szám, pénznem formátum (164-es numFmt: #,##0" Ft")
  // 'dec' = szám, tizedes formátum (165-ös numFmt: 0.0000)

  $sorok = []; // [['rtype'=>..., 'cells'=>[...]]]

  // Fejléc
  $sorok[] = ['rtype'=>'h', 'cells'=> array_map(fn($v)=>['v'=>$v,'t'=>'str'], $FEJLEC)];

  $sorsz = 0;
  foreach ($gen_sorok as $s) {
    $sorsz++;
    if ($s['tipus']==='anyag') {
      $t  = $s['tetel'];
      $ao = $t['mennyiseg'] * $t['anyagar_egyseg'];
      $sorok[] = ['rtype'=>'a', 'cells'=>[
        ['v'=>$sorsz,                      't'=>'num'],
        ['v'=>$t['megnevezes'],            't'=>'str'],
        ['v'=>$t['gyarto'],               't'=>'str'],
        ['v'=>$t['tipus'],                't'=>'str'],
        ['v'=>(float)$t['mennyiseg'],     't'=>'dec'],
        ['v'=>$t['egyseg'],               't'=>'str'],
        ['v'=>(float)$t['anyagar_egyseg'],'t'=>'ft'],
        ['v'=>0.0,                         't'=>'ft'],
        ['v'=>(float)$ao,                  't'=>'ft'],
        ['v'=>0.0,                         't'=>'ft'],
      ]];
    } else {
      $sorok[] = ['rtype'=>'n', 'cells'=>[
        ['v'=>$sorsz,                  't'=>'num'],
        ['v'=>$s['egysegar_nev'],      't'=>'str'],
        ['v'=>'',                      't'=>'str'],
        ['v'=>'',                      't'=>'str'],
        ['v'=>$s['tort'],              't'=>'dec'],
        ['v'=>$s['egysegar_egyseg'],   't'=>'str'],
        ['v'=>'—',                     't'=>'str'],
        ['v'=>(float)$s['egyseg_dij'],'t'=>'ft'],
        ['v'=>'—',                     't'=>'str'],
        ['v'=>(float)$s['dij_osszesen'],'t'=>'ft'],
      ]];
    }
  }

  // Üres sor
  $sorok[] = ['rtype'=>'u', 'cells'=>array_fill(0,10,['v'=>'','t'=>'str'])];

  // Összesítők
  $mk = fn($v,$t) => ['v'=>$v,'t'=>$t];
  $sorok[] = ['rtype'=>'s','cells'=>[$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('Összesen nettó:','str'),$mk($anyag_total,'ft'),$mk($napidij_total,'ft')]];
  $sorok[] = ['rtype'=>'s','cells'=>[$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('Nettó végösszeg:','str'),$mk($vegosszeg,'ft'),$mk('','str')]];
  $sorok[] = ['rtype'=>'s','cells'=>[$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('27% ÁFA:','str'),$mk($afa,'ft'),$mk('','str')]];
  $sorok[] = ['rtype'=>'b','cells'=>[$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('','str'),$mk('Bruttó végösszeg:','str'),$mk($brutto,'ft'),$mk('','str')]];

  // ── Shared strings ──────────────────────────────────────────────────────────
  $ss = []; $ss_i = [];
  $ss_add = function(string $v) use (&$ss, &$ss_i): int {
    if (!isset($ss_i[$v])) { $ss_i[$v] = count($ss); $ss[] = $v; }
    return $ss_i[$v];
  };
  foreach ($sorok as $row) foreach ($row['cells'] as $c) if ($c['t']==='str') $ss_add((string)$c['v']);

  // ── Styles XML ──────────────────────────────────────────────────────────────
  /*
   * numFmt 164: #,##0" Ft"
   * numFmt 165: 0.0000
   *
   * xf index → (fontId, fillId, numFmtId, applyFont, applyFill, applyNumFmt)
   *  0: default
   *  1: header  (font1=white bold, fill2=dark)
   *  2: anyag   + ft      (numFmt 164)
   *  3: napidij + str     (font2=italic blue, fill3=lightblue)
   *  4: napidij + ft      (font2, fill3, numFmt 164)
   *  5: napidij + dec     (font2, fill3, numFmt 165)
   *  6: ossz    + str     (font3=bold, fill4=lightgray)
   *  7: ossz    + ft      (font3, fill4, numFmt 164)
   *  8: brutto  + str     (font4=white bold, fill5=darkblue)
   *  9: brutto  + ft      (font4, fill5, numFmt 164)
   * 10: anyag   + dec     (numFmt 165)
   * 11: anyag   + num     (default num)
   */
  $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2">
    <numFmt numFmtId="164" formatCode="# ##0&quot; Ft&quot;"/>
    <numFmt numFmtId="165" formatCode="0.0000"/>
  </numFmts>
  <fonts count="5">
    <font><sz val="9"/><name val="Calibri"/></font>
    <font><b/><sz val="9"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
    <font><i/><sz val="9"/><color rgb="FF003399"/><name val="Calibri"/></font>
    <font><b/><sz val="9"/><name val="Calibri"/></font>
    <font><b/><sz val="9"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>
  </fonts>
  <fills count="6">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD6EAFF"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/></patternFill></fill>
  </fills>
  <borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="12">
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0"   fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="0"   fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>
    <xf numFmtId="165" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>
    <xf numFmtId="0"   fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>
    <xf numFmtId="0"   fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="164" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
    <xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>
  </cellXfs>
</styleSheet>';

  // ── xf index meghatározás rtype + cella típus alapján ─────────────────────
  $xf = function(string $rtype, string $ctype): int {
    // fejléc
    if ($rtype==='h') return 1;
    // napidij sor
    if ($rtype==='n') return match($ctype) { 'ft'=>4, 'dec'=>5, default=>3 };
    // összesítő
    if ($rtype==='s') return match($ctype) { 'ft'=>7, default=>6 };
    // bruttó
    if ($rtype==='b') return match($ctype) { 'ft'=>9, default=>8 };
    // anyag / üres / default
    return match($ctype) { 'ft'=>2, 'dec'=>10, default=>0 };
  };

  // ── Sheet XML ────────────────────────────────────────────────────────────────
  $cols_abc = ['A','B','C','D','E','F','G','H','I','J'];
  $sheet_rows_xml = '';
  foreach ($sorok as $ri => $row) {
    $rn = $ri + 1;
    $sheet_rows_xml .= '<row r="'.$rn.'">';
    foreach ($row['cells'] as $ci => $c) {
      $ref  = ($cols_abc[$ci] ?? chr(65+$ci)).$rn;
      $s    = $xf($row['rtype'], $c['t']);
      $sattr = $s > 0 ? ' s="'.$s.'"' : '';
      $v = $c['v'];
      if ($c['t']==='str' || $v === '' || $v === 0.0 && $c['t']==='str') {
        $si = $ss_i[(string)$v] ?? 0;
        $sheet_rows_xml .= '<c r="'.$ref.'" t="s"'.$sattr.'><v>'.$si.'</v></c>';
      } elseif (is_numeric($v)) {
        $sheet_rows_xml .= '<c r="'.$ref.'"'.$sattr.'><v>'.(float)$v.'</v></c>';
      } else {
        $si = $ss_i[(string)$v] ?? 0;
        $sheet_rows_xml .= '<c r="'.$ref.'" t="s"'.$sattr.'><v>'.$si.'</v></c>';
      }
    }
    $sheet_rows_xml .= '</row>';
  }

  $col_widths = [5,50,15,12,10,7,14,14,14,14];
  $cols_xml   = '<cols>';
  foreach ($col_widths as $ci=>$w) { $cn=$ci+1; $cols_xml.='<col min="'.$cn.'" max="'.$cn.'" width="'.$w.'" customWidth="1"/>'; }
  $cols_xml  .= '</cols>';

  // Shared strings XML
  $ss_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $ss_xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($ss).'" uniqueCount="'.count($ss).'">';
  foreach ($ss as $sv) { $ss_xml .= '<si><t xml:space="preserve">'.htmlspecialchars($sv,ENT_XML1,'UTF-8').'</t></si>'; }
  $ss_xml .= '</sst>';

  $sheet_xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
  $sheet_xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
  $sheet_xml .= $cols_xml;
  $sheet_xml .= '<sheetData>'.$sheet_rows_xml.'</sheetData>';
  $sheet_xml .= '<pageSetup orientation="landscape" fitToPage="1" fitToWidth="1" fitToHeight="0"/>';
  $sheet_xml .= '</worksheet>';

  $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Munka3" sheetId="1" r:id="rId1"/></sheets>
</workbook>';

  $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"    Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"        Target="styles.xml"/>
</Relationships>';

  $pkg_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

  $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"           ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml"  ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/sharedStrings.xml"      ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  <Override PartName="/xl/styles.xml"             ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>';

  $tmp = tempnam(sys_get_temp_dir(), 'mj_xlsx_');
  $zip = new ZipArchive();
  $zip->open($tmp, ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml',        $ct);
  $zip->addFromString('_rels/.rels',                $pkg_rels);
  $zip->addFromString('xl/workbook.xml',            $workbook_xml);
  $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
  $zip->addFromString('xl/worksheets/sheet1.xml',   $sheet_xml);
  $zip->addFromString('xl/sharedStrings.xml',       $ss_xml);
  $zip->addFromString('xl/styles.xml',              $styles_xml);
  $zip->close();

  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$fajlnev.'.xlsx"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp); unlink($tmp);
  exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PDF
// ═══════════════════════════════════════════════════════════════════════════════
if ($format === 'pdf') {
  require_once '/var/www/html/warehousemgr/vendor/autoload.php';

  $mpdf = new \Mpdf\Mpdf([
    'mode'          => 'utf-8',
    'format'        => 'A4-L',
    'margin_top'    => 10, 'margin_bottom' => 10,
    'margin_left'   => 8,  'margin_right'  => 8,
    'default_font_size' => 7.5,
    'default_font'  => 'dejavusans',
  ]);

  ob_start(); ?>
  <style>
    body  { font-family:dejavusans; font-size:7.5pt; }
    h2    { font-size:10pt; margin:0 0 4px 0; }
    .meta { font-size:7pt; color:#555; margin-bottom:6px; }
    table { border-collapse:collapse; width:100%; }
    th    { background:#1f2937; color:#fff; padding:3px 4px; font-size:7pt; }
    th.r, td.r { text-align:right; }
    td    { padding:2px 4px; border-bottom:1px solid #e5e7eb; font-size:7pt; }
    tr.nd td   { background:#d6eaff; font-style:italic; color:#003399; }
    tr.s1 td   { background:#f3f4f6; font-weight:bold; }
    tr.br td   { background:#1e3a5f; color:#fff; font-weight:bold; }
    td.ft      { text-align:right; }
    td.lbl     { text-align:right; font-style:normal; }
  </style>

  <h2><?= htmlspecialchars($projekt['nev']) ?></h2>
  <div class="meta">
    Generálva: <?= date('Y-m-d H:i') ?>
    <?php if ($verzio>0): ?> | <?= $verzio ?>. verzió<?php endif; ?>
    <?php if ($projekt['munka1_osszeg']): ?> | Ref.: <?= ft((float)$projekt['munka1_osszeg']) ?><?php endif; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:16pt">#</th>
        <th>Megnevezés</th>
        <th style="width:48pt">Gyártó</th>
        <th style="width:48pt">Típus</th>
        <th class="r" style="width:28pt">Menny.</th>
        <th style="width:20pt">Egys.</th>
        <th class="r" style="width:52pt">Anyagár/e</th>
        <th class="r" style="width:52pt">Díj/egys.</th>
        <th class="r" style="width:52pt">Anyag Σ</th>
        <th class="r" style="width:52pt">Díj Σ</th>
      </tr>
    </thead>
    <tbody>
    <?php
    $sorsz = 0;
    foreach ($gen_sorok as $s):
      $sorsz++;
      if ($s['tipus']==='anyag'):
        $t  = $s['tetel'];
        $ao = $t['mennyiseg'] * $t['anyagar_egyseg'];
    ?>
      <tr>
        <td><?= $sorsz ?>.</td>
        <td><?= htmlspecialchars($t['megnevezes']) ?></td>
        <td><?= htmlspecialchars($t['gyarto']) ?></td>
        <td><?= htmlspecialchars($t['tipus']) ?></td>
        <td class="r"><?= dec((float)$t['mennyiseg']) ?></td>
        <td><?= htmlspecialchars($t['egyseg']) ?></td>
        <td class="ft"><?= ft((float)$t['anyagar_egyseg']) ?></td>
        <td class="ft"><?= ft(0) ?></td>
        <td class="ft"><?= ft($ao) ?></td>
        <td class="ft"><?= ft(0) ?></td>
      </tr>
    <?php elseif ($s['tipus']==='napidij'): ?>
      <tr class="nd">
        <td><?= $sorsz ?>.</td>
        <td colspan="3"><?= htmlspecialchars($s['egysegar_nev']) ?></td>
        <td class="r"><b><?= dec($s['tort']) ?></b></td>
        <td><?= htmlspecialchars($s['egysegar_egyseg']) ?></td>
        <td class="r">—</td>
        <td class="ft"><?= ft($s['egyseg_dij']) ?></td>
        <td class="r">—</td>
        <td class="ft"><b><?= ft($s['dij_osszesen']) ?></b></td>
      </tr>
    <?php endif; endforeach; ?>

      <tr class="s1">
        <td colspan="7"></td>
        <td class="lbl">Összesen nettó:</td>
        <td class="ft"><?= ft($anyag_total) ?></td>
        <td class="ft"><?= ft($napidij_total) ?></td>
      </tr>
      <tr class="s1">
        <td colspan="7"></td>
        <td class="lbl">Nettó végösszeg:</td>
        <td class="ft" colspan="2"><?= ft($vegosszeg) ?></td>
      </tr>
      <tr class="s1">
        <td colspan="7"></td>
        <td class="lbl">27% ÁFA:</td>
        <td class="ft" colspan="2"><?= ft($afa) ?></td>
      </tr>
      <tr class="br">
        <td colspan="7"></td>
        <td class="lbl">Bruttó végösszeg:</td>
        <td class="ft" colspan="2"><?= ft($brutto) ?></td>
      </tr>
    </tbody>
  </table>
  <?php
  $html = ob_get_clean();
  $mpdf->WriteHTML($html);
  $mpdf->Output($fajlnev.'.pdf', 'D');
  exit;
}
