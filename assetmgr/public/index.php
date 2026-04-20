<?php
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/auth.php';

if (current_user()) {
  header('Location: ' . base_url('assets.php'));
  exit;
}
header('Location: ' . base_url('login.php'));
exit;
