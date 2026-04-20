<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="placeholders.csv"');

// Filters
$all_sections = ['partner_base','contacts','partner_custom','project_base','project_custom','images'];
$show = isset($_GET['show']) ? (array)$_GET['show'] : $all_sections;
$show = array_values(array_intersect($show, $all_sections));
if (empty($show)) $show = $all_sections;
$q = trim((string)($_GET['s'] ?? ''));

// Fetch
$partner_fields = in_array('partner_custom',$show,true) ? $pdo->query("SELECT name, label, type, required, active, sort_order FROM partner_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
$project_fields = in_array('project_custom',$show,true) ? $pdo->query("SELECT name, label, type, required, active, sort_order FROM project_fields ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
$images = in_array('images',$show,true) ? $pdo->query("SELECT `key`, title, stored_name, mime_type, width, height FROM images ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];

// CSV output
$out = fopen('php://output', 'w');
fputcsv($out, ['szekcio','placeholder','tabla','oszlop/azonosito','cimke/cim','tipus','kot','aktiv','extra']);

function matches($s,$q){ if($q==='')return true; return mb_stripos($s,$q)!==false; }

// partner_base
if (in_array('partner_base',$show,true)){
  $rows = [
    ['{{ partner.megnevezes }}','partners','megnevezes','Partner neve / megnevezése'],
    ['{{ partner.cim_irsz }}','partners','cim_irsz','Irányítószám'],
    ['{{ partner.cim_telepules }}','partners','cim_telepules','Település'],
    ['{{ partner.cim_utca }}','partners','cim_utca','Utca'],
    ['{{ partner.cim_hazszam }}','partners','cim_hazszam','Házszám'],
    ['{{ partner.cim_egyeb }}','partners','cim_egyeb','Egyéb cím kiegészítés'],
  ];
  foreach($rows as $r){
    $joined = implode(' ', $r);
    if (!matches($joined,$q)) continue;
    fputcsv($out, ['partner_alap',$r[0],$r[1],$r[2],$r[3],'','','','']);
  }
}

// contacts
if (in_array('contacts',$show,true)){
  $rows = [
    ['{{ contact.nev }}','partner_contacts','nev','Név (elsődleges)'],
    ['{{ contact.beosztas }}','partner_contacts','beosztas','Beosztás (elsődleges)'],
    ['{{ contact.telefon }}','partner_contacts','telefon','Telefon (elsődleges)'],
    ['{{ contact.email }}','partner_contacts','email','E-mail (elsődleges)'],
    ['{{ contact.nev.1 }}','partner_contacts','nev','1. kapcsolattartó neve'],
    ['{{ contact.nev.2 }}','partner_contacts','nev','2. kapcsolattartó neve'],
  ];
  foreach($rows as $r){
    $joined = implode(' ', $r);
    if (!matches($joined,$q)) continue;
    fputcsv($out, ['kapcsolattarto',$r[0],$r[1],$r[2],$r[3],'','','','']);
  }
}

// partner_custom
if (!empty($partner_fields)){
  foreach($partner_fields as $f){
    $joined = $f['name'].' '.$f['label'].' '.$f['type'];
    if (!matches($joined,$q)) continue;
    $ph = '{{ field.'.$f['name'].' }}';
    fputcsv($out, ['partner_egyedi',$ph,'partner_fields',$f['name'],$f['label'],$f['type'],$f['required']?'igen':'nem',$f['active']?'igen':'nem','sort='.$f['sort_order']]);
  }
}

// project_base
if (in_array('project_base',$show,true)){
  $rows = [
    ['{{ project.megnevezes }}','projects','megnevezes','Projekt neve'],
    ['{{ project.szam }}','projects','szam','Projekt száma'],
    ['{{ project.cim_irsz }}','projects','cim_irsz','Irányítószám'],
    ['{{ project.cim_telepules }}','projects','cim_telepules','Település'],
    ['{{ project.cim_utca }}','projects','cim_utca','Utca'],
    ['{{ project.cim_hazszam }}','projects','cim_hazszam','Házszám'],
    ['{{ project.cim_egyeb }}','projects','cim_egyeb','Egyéb cím kiegészítés'],
    ['{{ project.gps_lat }}','projects','gps_lat','GPS szélesség'],
    ['{{ project.gps_lng }}','projects','gps_lng','GPS hosszúság'],
    ['{{ project.kezdo_datum }}','projects','kezdo_datum','Kezdő dátum'],
  ];
  foreach($rows as $r){
    $joined = implode(' ', $r);
    if (!matches($joined,$q)) continue;
    fputcsv($out, ['projekt_alap',$r[0],$r[1],$r[2],$r[3],'','','','']);
  }
}

// project_custom
if (!empty($project_fields)){
  foreach($project_fields as $f){
    $joined = $f['name'].' '.$f['label'].' '.$f['type'];
    if (!matches($joined,$q)) continue;
    $ph = '{{ project_field.'.$f['name'].' }}';
    fputcsv($out, ['projekt_egyedi',$ph,'project_fields',$f['name'],$f['label'],$f['type'],$f['required']?'igen':'nem',$f['active']?'igen':'nem','sort='.$f['sort_order']]);
  }
}

// images
if (!empty($images)){
  foreach($images as $im){
    $joined = ($im['key']??'').' '.($im['title']??'').' '.($im['mime_type']??'');
    if (!matches($joined,$q)) continue;
    $ph = '{{ image.'.$im['key'].' }}';
    $extra = 'mime='.$im['mime_type'].'; size='.$im['width'].'x'.$im['height'];
    fputcsv($out, ['kepek',$ph,'images',$im['key'],$im['title'] ?? '','', '', '', $extra]);
  }
}

fclose($out);
