<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth_bootstrap.php';

CentralAuth::requireLogin($authConfig);
CentralAuth::requireModuleAccess($authConfig, (string)($authConfig['module_key'] ?? 'pp_center'));

$currentUserName = current_user_name();
