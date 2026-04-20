<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$fontsDir = __DIR__ . '/fonts';
$file = $_POST['file'] ?? '';
if ($file===''){ header('Location: fonts.php?err=param'); exit; }

$real = realpath($fontsDir . '/' . $file);
$realBase = realpath($fontsDir);
if (!$real || strpos($real, $realBase) !== 0 || !is_file($real)){
  header('Location: fonts.php?err=notfound'); exit;
}
unlink($real);
header('Location: fonts.php?ok=deleted'); exit;
