<?php
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Db.php';

use App\Auth; use App\Middleware; use App\Db;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$entry_id = filter_input(INPUT_GET, 'entry_id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$entry_id) { http_response_code(400); exit('Hibás kérés'); }

$st = $pdo->prepare("SELECT vehicle_id, invoice_path, invoice_mime, invoice_orig_name FROM vehicle_service_entries WHERE id=?");
$st->execute([$entry_id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || empty($row['invoice_path'])) { http_response_code(404); exit('Nincs számla'); }

$rel = (string)$row['invoice_path'];
$full = dirname(__DIR__).'/'.$rel;
if (!is_file($full)) { http_response_code(404); exit('Fájl nem található'); }

$mime = $row['invoice_mime'] ?: 'application/octet-stream';
$fn = $row['invoice_orig_name'] ?: basename($full);

header('Content-Type: '.$mime);
header('Content-Disposition: inline; filename="'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $fn).'"');
header('X-Content-Type-Options: nosniff');
readfile($full);
