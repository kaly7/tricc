<?php
require_once __DIR__ . '/../../vendor/autoload.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path   = preg_replace('#^/tricc/api#', '', $path);
$path   = rtrim($path, '/') ?: '/';
$segs   = explode('/', ltrim($path, '/'));

use Tricc\Response;
use Tricc\Controllers\{AuthController, RoomController, MessageController, UploadController, PushController, AdminController};

try {
    match(true) {
        // Auth
        $method === 'POST' && $path === '/auth/register'     => AuthController::register(),
        $method === 'POST' && $path === '/auth/login'        => AuthController::login(),
        $method === 'GET'  && $path === '/auth/me'           => AuthController::me(),
        $method === 'PUT'  && $path === '/auth/profile'      => AuthController::updateProfile(),
        $method === 'GET'  && $path === '/users'             => AuthController::users(),

        // Rooms
        $method === 'GET'  && $path === '/rooms'             => RoomController::list(),
        $method === 'POST' && $path === '/rooms'             => RoomController::create(),
        $method === 'GET'  && preg_match('#^/rooms/(\d+)$#', $path, $m) > 0
                                                             => RoomController::get((int)$m[1]),
        $method === 'POST' && preg_match('#^/rooms/(\d+)/members$#', $path, $m) > 0
                                                             => RoomController::addMember((int)$m[1]),
        $method === 'DELETE' && preg_match('#^/rooms/(\d+)/members/(\d+)$#', $path, $m) > 0
                                                             => RoomController::removeMember((int)$m[1], (int)$m[2]),
        $method === 'POST'   && preg_match('#^/rooms/(\d+)/pin$#', $path, $m) > 0
                                                             => RoomController::pin((int)$m[1]),
        $method === 'DELETE' && preg_match('#^/rooms/(\d+)/pin$#', $path, $m) > 0
                                                             => RoomController::unpin((int)$m[1]),

        // Messages
        $method === 'GET'  && preg_match('#^/rooms/(\d+)/messages$#', $path, $m) > 0
                                                             => MessageController::list((int)$m[1]),
        $method === 'POST' && preg_match('#^/rooms/(\d+)/messages$#', $path, $m) > 0
                                                             => MessageController::send((int)$m[1]),
        $method === 'DELETE' && preg_match('#^/rooms/(\d+)/messages/(\d+)$#', $path, $m) > 0
                                                             => MessageController::delete((int)$m[1], (int)$m[2]),

        // Upload
        $method === 'POST' && $path === '/upload'            => UploadController::upload(),
        $method === 'POST' && $path === '/upload/avatar'     => UploadController::avatar(),

        // Push tokens
        $method === 'POST'   && $path === '/push/register'   => PushController::register(),
        $method === 'DELETE' && $path === '/push/register'   => PushController::unregister(),

        // Admin
        $method === 'GET'    && $path === '/admin/users'                                 => AdminController::users(),
        $method === 'PUT'    && preg_match('#^/admin/users/(\d+)/active$#', $path, $m) > 0
                                                                                         => AdminController::setActive((int)$m[1]),
        $method === 'PUT'    && preg_match('#^/admin/users/(\d+)/admin$#', $path, $m) > 0
                                                                                         => AdminController::setAdmin((int)$m[1]),
        $method === 'GET'    && $path === '/admin/invites'                               => AdminController::invites(),
        $method === 'POST'   && $path === '/admin/invites'                               => AdminController::createInvite(),
        $method === 'DELETE' && preg_match('#^/admin/invites/(\d+)$#', $path, $m) > 0
                                                                                         => AdminController::deleteInvite((int)$m[1]),

        default => Response::abort(404, 'Végpont nem található: ' . $method . ' ' . $path),
    };
} catch (\Throwable $e) {
    error_log('[Tricc API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Response::abort(500, 'Szerverhiba.');
}
