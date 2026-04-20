<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int)($_POST['id'] ?? 0);
$new_name = trim($_POST['new_name'] ?? '');
$content = (string)($_POST['content_html'] ?? '');
if ($id<=0 || $new_name==='') { die('Hiányzó paraméter.'); }

// derive slug from new_name
$slug = strtolower(preg_replace('/[^a-z0-9]+/','-', iconv('UTF-8','ASCII//TRANSLIT',$new_name)));
if ($slug==='') $slug = 'sablon-'.date('YmdHis');

// ensure unique slug
$base = $slug; $i=1;
while (true){
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM templates WHERE slug=:s");
  $stmt->execute([':s'=>$slug]);
  if ($stmt->fetchColumn()==0) break;
  $slug = $base . '-' . (++$i);
}

// insert new
$stmt = $pdo->prepare("INSERT INTO templates (name, slug, content_html) VALUES (:n, :s, :c)");
$stmt->execute([':n'=>$new_name, ':s'=>$slug, ':c'=>$content]);
$newId = (int)$pdo->lastInsertId();

header("Location: template_edit.php?id=".$newId."&ok=1");
