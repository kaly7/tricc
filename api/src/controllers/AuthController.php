<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response};

class AuthController {
    public static function register(): never {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name  = trim($body['name']  ?? '');
        $email = trim($body['email'] ?? '');
        $pass  = $body['password']   ?? '';
        $code  = trim($body['invite_code'] ?? '');

        if (!$name || !$email || strlen($pass) < 6 || !$code)
            Response::abort(400, 'Hiányzó vagy érvénytelen mezők (jelszó min. 6 kar.).');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            Response::abort(400, 'Érvénytelen email cím.');

        $db = DB::get();

        // Meghívókód ellenőrzés
        $inv = $db->prepare("SELECT id FROM invite_codes WHERE code=? AND used_by IS NULL AND (expires_at IS NULL OR expires_at > NOW())");
        $inv->execute([$code]);
        $invite = $inv->fetch();
        if (!$invite) Response::abort(400, 'Érvénytelen vagy már felhasznált meghívókód.');

        // Duplikált email
        $chk = $db->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$email]);
        if ($chk->fetch()) Response::abort(409, 'Ez az email már regisztrálva van.');

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $ins  = $db->prepare("INSERT INTO users (name, email, password, invite_code) VALUES (?,?,?,?)");
        $ins->execute([$name, $email, $hash, $code]);
        $user_id = (int)$db->lastInsertId();

        $db->prepare("UPDATE invite_codes SET used_by=?, used_at=NOW() WHERE id=?")->execute([$user_id, $invite['id']]);

        Response::ok(['token' => Auth::token($user_id, false), 'user_id' => $user_id]);
    }

    public static function login(): never {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim($body['email']    ?? '');
        $pass  = $body['password']      ?? '';

        $st = DB::get()->prepare("SELECT id, password, is_admin, is_active FROM users WHERE email=?");
        $st->execute([$email]);
        $user = $st->fetch();

        if (!$user || !password_verify($pass, $user['password']))
            Response::abort(401, 'Hibás email vagy jelszó.');
        if (!$user['is_active'])
            Response::abort(403, 'Ez a fiók le van tiltva.');

        Response::ok(['token' => Auth::token((int)$user['id'], (bool)$user['is_admin']), 'user_id' => (int)$user['id']]);
    }

    public static function me(): never {
        $auth = Auth::require();
        $st   = DB::get()->prepare("SELECT id, name, email, avatar_url, is_admin, created_at FROM users WHERE id=?");
        $st->execute([$auth['user_id']]);
        $user = $st->fetch();
        if (!$user) Response::abort(404, 'Felhasználó nem található.');
        Response::ok($user);
    }

    public static function updateProfile(): never {
        $auth = Auth::require();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($body['name'] ?? '');
        if (!$name) Response::abort(400, 'Név megadása kötelező.');
        DB::get()->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, $auth['user_id']]);
        Response::ok();
    }
}
