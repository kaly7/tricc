<?php
require_once __DIR__ . '/../app/functions.php';
header('Content-Type: application/json');
echo json_encode([
  'last_modified'      => get_last_modified(),
  'last_modified_date' => get_last_modified_date(),
]);
