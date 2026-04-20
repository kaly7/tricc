<?php
require __DIR__.'/../app/auth.php';
require_login();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM catalog_files WHERE id=? AND is_deleted=0");
$st->execute([$id]);
$f = $st->fetch();
if(!$f){ http_response_code(404); exit('Nincs ilyen fájl'); }

$path = dirname(__DIR__).'/storage/catalog_files/'.(int)$f['catalog_item_id'].'/'.(string)$f['stored_name'];
if(!is_file($path)){ http_response_code(404); exit('Fájl nem található'); }

$orig = (string)$f['original_name'];
$mime = (string)($f['mime'] ?: 'application/octet-stream');

header('Content-Type: '.$mime);
header('Content-Disposition: attachment; filename="'.str_replace('"','',$orig).'"');
header('Content-Length: '.filesize($path));
readfile($path);
exit;
