<?php
function agv_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('AGVMGR_SESS');
        session_start();
    }
}

function agv_require_login(): void {
    agv_session_start();
    if (empty($_SESSION['agv_user'])) {
        header('Location: login.php');
        exit;
    }
}

function agv_require_admin(): void {
    agv_require_login();
    if (empty($_SESSION['agv_admin'])) {
        http_response_code(403);
        die('Hozzáférés megtagadva.');
    }
}

function agv_logged_in(): bool {
    agv_session_start();
    return !empty($_SESSION['agv_user']);
}
