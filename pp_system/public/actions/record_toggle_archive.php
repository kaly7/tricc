<?php
require_once __DIR__.'/../../src/auth.php'; require_login_or_redirect(); check_csrf();
require_once __DIR__.'/../../src/db.php';

$id = (int)($_POST['id'] ?? 0);
if($id<=0){ header('Location: ../records.php'); exit; }

// Csak élő rekordon engedjük
$st = db()->prepare('SELECT archived FROM records WHERE id=? AND deleted_at IS NULL');
$st->execute([$id]);
$rec = $st->fetch();
if(!$rec){ header('Location: ../records.php'); exit; }

$old_arch = (int)$rec['archived'];
$new_arch = $old_arch ? 0 : 1;

// Toggle + updated_by
$u = db()->prepare('UPDATE records SET archived=?, updated_by=? WHERE id=?');
$u->execute([$new_arch, current_user()['id'], $id]);

// Napló (emberi értékek)
$ov = $old_arch ? 'igen' : 'nem';
$nv = $new_arch ? 'igen' : 'nem';
$ast = db()->prepare('INSERT INTO record_changes (record_id,changed_by,field,old_value,new_value) VALUES (?,?,?,?,?)');
$ast->execute([$id, current_user()['id'], 'archived', $ov, $nv]);

header('Location: ../records.php'); exit;