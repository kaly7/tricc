<?php
// CLI tool to create/update a user
// Usage:
//   php tools/create_user.php admin StrongPass123 admin
//   php tools/create_user.php user1 Passw0rd user
require __DIR__ . '/../bootstrap.php';

use Services\UserService;

if (php_sapi_name() !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$role = $argv[3] ?? 'user';

if ($username === '' || $password === '') {
    echo "Usage: php tools/create_user.php <username> <password> [admin|user]\n";
    exit(1);
}

UserService::createUser($username, $password, $role, 1);
echo "OK: user created/updated: $username role=$role\n";
