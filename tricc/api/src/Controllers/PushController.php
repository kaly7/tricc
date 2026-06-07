<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response};

class PushController {
    public static function register(): never {
        $auth  = Auth::require();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = trim($body['device_token'] ?? '');
        if (!$token) Response::abort(400, 'device_token megadása kötelező.');

        DB::get()->prepare("
            INSERT INTO push_tokens (user_id, token)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ")->execute([$auth['user_id'], $token]);

        Response::ok();
    }

    public static function unregister(): never {
        $auth  = Auth::require();
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = trim($body['device_token'] ?? '');
        if (!$token) Response::abort(400, 'device_token megadása kötelező.');

        DB::get()->prepare("DELETE FROM push_tokens WHERE user_id=? AND token=?")
                 ->execute([$auth['user_id'], $token]);
        Response::ok();
    }
}
