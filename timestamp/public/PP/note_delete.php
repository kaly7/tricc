<?php
require __DIR__.'/includes/init.php';
require_login($pdo);
if ($_SERVER['REQUEST_METHOD']!=='POST') redirect('jobs_list.php');
csrf_verify();
$task_id=(int)($_POST['task_id']??0);
$field=trim($_POST['field']??'');
if ($task_id && $field!=='') {
    $st=$pdo->prepare("DELETE FROM task_field_notes WHERE task_id=? AND field_name=?");
    $st->execute([$task_id,$field]);
}
redirect('jobs_list.php');
