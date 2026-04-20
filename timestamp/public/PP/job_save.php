<?php
require __DIR__ . '/includes/init.php';
require_login($pdo);
if ($_SERVER['REQUEST_METHOD']!=='POST') redirect('jobs_list.php');
csrf_verify();
$id = (int)($_POST['id'] ?? 0);
$fields = [
  'pp_status_id' => (int)($_POST['pp_status_id'] ?? 0),
  'kiadva' => $_POST['kiadva'] ?? null,
  'eventus' => ($_POST['eventus']===''? null : (int)$_POST['eventus']),
  'city_id' => (int)($_POST['city_id'] ?? 0),
  'irsz' => trim($_POST['irsz'] ?? ''),
  'utca' => trim($_POST['utca'] ?? ''),
  'hazszam' => trim($_POST['hazszam'] ?? ''),
  'elvegzendo' => trim($_POST['elvegzendo'] ?? ''),
  'korzet' => trim($_POST['korzet'] ?? ''),
  'leiras' => trim($_POST['leiras'] ?? ''),
  'vallalt_hatarido' => ($_POST['vallalt_hatarido'] ? $_POST['vallalt_hatarido'] : null),
  'megjegyzes' => trim($_POST['megjegyzes'] ?? ''),
];
if (!$fields['pp_status_id'] || !$fields['kiadva'] || !$fields['city_id']) {
    $_SESSION['flash'] = "PP státusz, Kiadva és Település kötelező.";
    redirect('job_form.php' . ($id ? '?id='.$id : ''));
}
if ($id){
  $sql="UPDATE tasks SET pp_status_id=:pp_status_id, kiadva=:kiadva, eventus=:eventus, city_id=:city_id,
    irsz=:irsz, utca=:utca, hazszam=:hazszam, elvegzendo=:elvegzendo, korzet=:korzet, leiras=:leiras,
    vallalt_hatarido=:vallalt_hatarido, megjegyzes=:megjegyzes, updated_at=NOW() WHERE id=:id";
  $stmt=$pdo->prepare($sql); $fields['id']=$id; $stmt->execute($fields);
  $newId=(int)$pdo->lastInsertId();
  $stmt1=$pdo->prepare("SELECT * FROM tasks WHERE id=?"); $stmt1->execute([$newId]); $after=$stmt1->fetch();
  audit($pdo, current_user($pdo)['id'] ?? null, 'tasks', 'create', $newId, null, $after);
}
 else {
  $sql="INSERT INTO tasks (pp_status_id, kiadva, eventus, city_id, irsz, utca, hazszam, elvegzendo, korzet, leiras, vallalt_hatarido, megjegyzes, created_by, created_at, updated_at)
        VALUES (:pp_status_id, :kiadva, :eventus, :city_id, :irsz, :utca, :hazszam, :elvegzendo, :korzet, :leiras, :vallalt_hatarido, :megjegyzes, :created_by, NOW(), NOW())";
  $stmt=$pdo->prepare($sql); $fields['created_by']=current_user($pdo)['id']; $stmt->execute($fields);
}
redirect('jobs_list.php');
