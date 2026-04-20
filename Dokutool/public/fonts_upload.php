<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$fontsDir = __DIR__ . '/fonts';
if (!is_dir($fontsDir)) mkdir($fontsDir, 0777, true);

if (!isset($_FILES['fontfile']) || $_FILES['fontfile']['error']!==UPLOAD_ERR_OK){
  header('Location: fonts.php?err=upload'); exit;
}

$name = $_FILES['fontfile']['name'] ?? 'font';
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allow = ['ttf','otf','woff','woff2'];
if (!in_array($ext, $allow, true)){
  header('Location: fonts.php?err=type'); exit;
}
$base = pathinfo($name, PATHINFO_FILENAME);
$base = preg_replace('/[^\w \-\.]+/u','', $base);
$base = trim($base);
if ($base===''){ $base = 'Font'; }

$dest = $fontsDir . '/' . $base . '.' . $ext;
$i=1;
while(file_exists($dest)){
  $dest = $fontsDir . '/' . $base . '-'.$i.'.' . $ext;
  $i++;
}

if (!move_uploaded_file($_FILES['fontfile']['tmp_name'], $dest)){
  header('Location: fonts.php?err=move'); exit;
}

header('Location: fonts.php?ok=1'); exit;
