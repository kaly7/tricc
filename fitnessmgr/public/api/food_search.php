<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/functions.php';
require_once __DIR__ . '/../../app/Services/FoodService.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../app/auth.php';
if (!current_user()) { http_response_code(401); echo '[]'; exit; }

$q   = trim($_GET['q'] ?? '');
$svc = new FoodService();

echo json_encode($svc->search($q, 20), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
