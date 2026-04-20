<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
require __DIR__ . '/../app/mailer.php';
require __DIR__ . '/../app/render.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

function base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . '://' . $host;
}

$batch_id = (int)($_POST['batch_id'] ?? 0);
$partner_name = trim($_POST['partner_name'] ?? '');

if ($batch_id<=0){ die('Hibás kérés.'); }

// read batch and settings
$st=$pdo->prepare("SELECT * FROM batches WHERE id=:id");
$st->execute([':id'=>$batch_id]);
$batch=$st->fetch(PDO::FETCH_ASSOC);
if (!$batch){ die('Csomag nem található.'); }

// select items
$params=[':b'=>$batch_id];
$wherePartner='';
if ($partner_name!==''){
  $wherePartner=' AND p.megnevezes = :pn';
  $params[':pn']=$partner_name;
}
$sql = "SELECT bi.*, p.megnevezes AS partner_nev, t.name AS tpl_nev, p.id AS pid
        FROM batch_items bi
        JOIN partners p ON p.id = bi.partner_id
        JOIN templates t ON t.id = bi.template_id
        WHERE bi.batch_id = :b".$wherePartner."
        ORDER BY p.megnevezes, t.name";
$items=$pdo->prepare($sql);
$items->execute($params);
$rows = $items->fetchAll(PDO::FETCH_ASSOC);
if (!$rows){ header('Location: batch_view.php?id='.$batch_id.'&msg=empty'); exit; }

function csv_to_list($s){
  if ($s==='') return [];
  $parts = preg_split('/[;,]+/', $s);
  $out = [];
  foreach ($parts as $p){
    $p = trim($p);
    if ($p!=='') $out[] = $p;
  }
  return array_values(array_unique($out));
}
function render_text_tpl($pdo, $tpl, $template_name, $partner_id, $project_id){
  $partner=null; $project=null; $contacts=[];
  $st=$pdo->prepare("SELECT * FROM partners WHERE id=:id"); $st->execute([':id'=>$partner_id]); $partner=$st->fetch(PDO::FETCH_ASSOC);
  $st=$pdo->prepare("SELECT * FROM projects WHERE id=:id"); $st->execute([':id'=>$project_id]); $project=$st->fetch(PDO::FETCH_ASSOC);
  if ($partner){
    $st=$pdo->prepare("SELECT * FROM partner_contacts WHERE partner_id=:pid ORDER BY is_primary DESC, id ASC");
    $st->execute([':pid'=>$partner_id]); $contacts=$st->fetchAll(PDO::FETCH_ASSOC);
  }
  $map=[];
  if ($partner){
    $map['partner.megnevezes']=$partner['megnevezes'] ?? '';
    $map['partner.cim_irsz']=$partner['cim_irsz'] ?? '';
    $map['partner.cim_telepules']=$partner['cim_telepules'] ?? '';
    $map['partner.cim_utca']=$partner['cim_utca'] ?? '';
    $map['partner.cim_hazszam']=$partner['cim_hazszam'] ?? '';
    $map['partner.cim_egyeb']=$partner['cim_egyeb'] ?? '';
  }
  if ($project_id){
    $st=$pdo->prepare("SELECT * FROM projects WHERE id=:id"); $st->execute([':id'=>$project_id]); $project=$st->fetch(PDO::FETCH_ASSOC);
    if ($project){
      $map['project.megnevezes']=$project['megnevezes'] ?? '';
      $map['project.szam']=$project['szam'] ?? '';
      $map['project.cim_irsz']=$project['cim_irsz'] ?? '';
      $map['project.cim_telepules']=$project['cim_telepules'] ?? '';
      $map['project.cim_utca']=$project['cim_utca'] ?? '';
      $map['project.cim_hazszam']=$project['cim_hazszam'] ?? '';
      $map['project.cim_egyeb']=$project['cim_egyeb'] ?? '';
      $map['project.gps_lat']=$project['gps_lat'] ?? '';
      $map['project.gps_lng']=$project['gps_lng'] ?? '';
      $map['project.kezdo_datum']=$project['kezdo_datum'] ?? '';
    }
  }
  $fields=['nev','beosztas','telefon','email'];
  foreach($fields as $f){
    $val=''; if (!empty($contacts[0][$f])) $val=$contacts[0][$f];
    $map['contact.'.$f]=$val;
  }
  $map['template.name']=$template_name;
  return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\.([a-z0-9_]+)(?:\.(\d+))?\s*\}\}/i', function($m) use ($contacts, $map){
    $g1=strtolower($m[1]); $g2=strtolower($m[2]); $g3=$m[3]??null;
    if ($g1==='contact' && $g3){
      $idx=max(1,(int)$g3)-1;
      $fields=['nev','beosztas','telefon','email'];
      if (in_array($g2,$fields,true) && isset($contacts[$idx][$g2])){
        return (string)$contacts[$idx][$g2];
      }
      return '';
    }
    $key=$g1.'.'.$g2;
    return isset($map[$key]) ? (string)$map[$key] : '';
  }, (string)$tpl);
}

// recipients config
$recipient_mode = $batch['recipient_mode'] ?: 'contacts_all';
$extra_to = csv_to_list($batch['extra_to'] ?? '');
$cc_list  = csv_to_list($batch['extra_cc'] ?? '');
$bcc_list = csv_to_list($batch['extra_bcc'] ?? '');
$subject_tpl = (string)($batch['email_subject'] ?? '');
$body_tpl    = (string)($batch['email_body'] ?? '');
$email_per_partner = (int)($batch['email_per_partner'] ?? 1);

// group by partner
$byPartner = [];
foreach($rows as $r){ $byPartner[$r['pid']][] = $r; }

$sent=0;
foreach($byPartner as $pid => $list){
  // recipients
  $emails=[];
  if ($recipient_mode==='contacts_primary'){
    $st=$pdo->prepare("SELECT email FROM partner_contacts WHERE partner_id=:pid ORDER BY is_primary DESC, id ASC LIMIT 1");
    $st->execute([':pid'=>$pid]);
    $em=(string)$st->fetchColumn();
    if ($em!=='') $emails[]=$em;
  } else {
    $st=$pdo->prepare("SELECT email FROM partner_contacts WHERE partner_id=:pid AND email<>''");
    $st->execute([':pid'=>$pid]);
    foreach($st as $row){ $emails[]=(string)$row['email']; }
  }
  $to_list = array_values(array_unique(array_merge($emails, $extra_to)));
  if (empty($to_list)) continue;

  if ($email_per_partner){
    // ---- one email per partner (multiple attachments) ----
    $atts=[];
    $firstTpl='';
    foreach($list as $r){
      if (!$firstTpl) $firstTpl=$r['tpl_nev'];
      if (!empty($r['item_pdf_path'])){
        $pdfPath = __DIR__ . '/' . ltrim($r['item_pdf_path'],'/');
        if (file_exists($pdfPath)) $atts[]=['path'=>$pdfPath, 'name'=>$r['tpl_nev'].'.pdf', 'mime'=>'application/pdf'];
      }
    }
    $suffix = count($list)>1 ? ' + további '.(count($list)-1) : '';
    $subj = $subject_tpl!=='' ? render_text_tpl($pdo, $subject_tpl, $firstTpl, $pid, (int)$batch['project_id']) : ('Dokumentum: '.$firstTpl.$suffix);
    $body = $body_tpl!=='' ? render_text_tpl($pdo, $body_tpl, $firstTpl, $pid, (int)$batch['project_id']) : 'Mellékelve küldjük a dokumentumokat.';

    if (send_mail($to_list, $subj, nl2br($body), $atts, $cc_list, $bcc_list)){
      // mark all as sent
      $ids = array_column($list,'id');
      $in = implode(',', array_fill(0,count($ids),'?'));
      $st=$pdo->prepare("UPDATE batch_items SET sent_at=NOW() WHERE id IN ($in)");
      $st->execute($ids);
      $sent += count($list);
    }
  } else {
    // ---- one email per item (previous behavior) ----
    foreach($list as $r){
      $tplName = $r['tpl_nev'];
      $subj = $subject_tpl!=='' ? render_text_tpl($pdo, $subject_tpl, $tplName, $pid, (int)$batch['project_id']) : ('Dokumentum: '.$tplName);
      $body = $body_tpl!=='' ? render_text_tpl($pdo, $body_tpl, $tplName, $pid, (int)$batch['project_id']) : 'Mellékelve küldjük a dokumentumot.';
      $atts=[];
      if (!empty($r['item_pdf_path'])){
        $pdfPath = __DIR__ . '/' . ltrim($r['item_pdf_path'],'/');
        if (file_exists($pdfPath)) $atts[] = ['path' => $pdfPath, 'name' => $tplName . '.pdf', 'mime' => 'application/pdf'];
      }
      if (send_mail($to_list, $subj, nl2br($body), $atts, $cc_list, $bcc_list)){
        $pdo->prepare("UPDATE batch_items SET sent_at=NOW() WHERE id=:id")->execute([':id'=>$r['id']]);
        $sent++;
      }
    }
  }
}

header('Location: batch_view.php?id='.$batch_id.'&sent='.$sent);
