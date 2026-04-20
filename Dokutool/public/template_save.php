<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$content = $_POST['content_html'] ?? '';

if ($id<=0 || $name==='') { die("Hiányzó paraméter."); }

// keep slug unchanged
$stmt = $pdo->prepare("UPDATE templates SET name=:n, content_html=:c WHERE id=:id");
$stmt->execute([':n'=>$name, ':c'=>$content, ':id'=>$id]);

header("Location: template_edit.php?id=".$id."&ok=1");
