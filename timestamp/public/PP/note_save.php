<?php
require __DIR__.'/includes/init.php';
require_login($pdo);
if ($_SERVER['REQUEST_METHOD']!=='POST') redirect('jobs_list.php');
csrf_verify();
$task_id=(int)($_POST['task_id']??0);
$field=trim($_POST['field']??'');
$text=trim($_POST['note_text']??'');
if ($task_id && $field!=='') {
    $st=$pdo->prepare("INSERT INTO task_field_notes (task_id, field_name, note_text, updated_by, updated_at) VALUES (?,?,?,?,NOW())
                       ON DUPLICATE KEY UPDATE note_text=VALUES(note_text), updated_by=VALUES(updated_by), updated_at=NOW()");
    $st->execute([$task_id,$field,$text,current_user($pdo)['id']??null]);
}
redirect('jobs_list.php');
