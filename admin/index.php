<?php
session_start();
if (!isset($_SESSION['tricc_admin'])) {
    header('Location: login.php'); exit;
}
header('Location: users.php'); exit;
