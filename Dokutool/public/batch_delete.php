<?php
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/guard.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);

$id = (int)($_POST['id'] ?? 0);
if ($id<=0){ header('Location: batches.php?err=param'); exit; }

$stmt = $pdo->prepare("SELECT slug FROM batches WHERE id=:id");
$stmt->execute([':id'=>$id]);
$slug = $stmt->fetchColumn();
if (!$slug){ header('Location: batches.php?err=notfound'); exit; }

// Delete DB rows first (items via ON DELETE CASCADE)
$pdo->prepare("DELETE FROM batches WHERE id=:id")->execute([':id'=>$id]);

// Delete archive directory safely under /public/archives/<slug>
$slugSafe = preg_match('/^[a-z0-9\-]+$/', $slug);
$dir = __DIR__ . '/archives/' . $slug;
if ($slugSafe && is_dir($dir)){
  // recursive deletion
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach($files as $file){
    if ($file->isDir()){ rmdir($file->getRealPath()); }
    else { unlink($file->getRealPath()); }
  }
  rmdir($dir);
}

header('Location: batches.php?ok=deleted');
