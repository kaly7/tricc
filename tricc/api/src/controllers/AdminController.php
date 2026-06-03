<?php
namespace Tricc\Controllers;

use Tricc\{DB, Auth, Response};

class AdminController {
    public static function users(): never {
        Auth::requireAdmin();
        $st = DB::get()->prepare("
            SELECT id, name, email, avatar_url, is_admin, is_active, created_at
            FROM users ORDER BY id DESC
        ");
        $st->execute();
        Response::ok($st->fetchAll());
    }

    public static function setActive(int $user_id): never {
        Auth::requireAdmin();
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $active = isset($body['is_active']) ? (int)(bool)$body['is_active'] : null;
        if ($active === null) Response::abort(400, 'is_active mező szükséges.');
        DB::get()->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$active, $user_id]);
        Response::ok();
    }

    public static function setAdmin(int $user_id): never {
        Auth::requireAdmin();
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $isAdmin = isset($body['is_admin']) ? (int)(bool)$body['is_admin'] : null;
        if ($isAdmin === null) Response::abort(400, 'is_admin mező szükséges.');
        DB::get()->prepare("UPDATE users SET is_admin=? WHERE id=?")->execute([$isAdmin, $user_id]);
        Response::ok();
    }

    public static function invites(): never {
        Auth::requireAdmin();
        $st = DB::get()->prepare("
            SELECT ic.id, ic.code, ic.created_by, u.name AS created_by_name,
                   ic.used_by, u2.name AS used_by_name, ic.used_at, ic.expires_at, ic.created_at
            FROM invite_codes ic
            LEFT JOIN users u  ON u.id  = ic.created_by
            LEFT JOIN users u2 ON u2.id = ic.used_by
            ORDER BY ic.id DESC
        ");
        $st->execute();
        Response::ok($st->fetchAll());
    }

    public static function createInvite(): never {
        $auth = Auth::requireAdmin();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $code = strtoupper(trim($body['code'] ?? ''));
        if (!$code) {
            $code = 'TRICC-' . strtoupper(bin2hex(random_bytes(4)));
        }
        $expires = $body['expires_at'] ?? null;
        DB::get()->prepare("INSERT INTO invite_codes (code, created_by, expires_at) VALUES (?,?,?)")
                 ->execute([$code, $auth['user_id'], $expires]);
        Response::ok(['code' => $code]);
    }

    public static function deleteInvite(int $id): never {
        Auth::requireAdmin();
        $st = DB::get()->prepare("SELECT used_by FROM invite_codes WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) Response::abort(404, 'Meghívó nem található.');
        if ($row['used_by']) Response::abort(409, 'Már felhasznált meghívó nem törölhető.');
        DB::get()->prepare("DELETE FROM invite_codes WHERE id=?")->execute([$id]);
        Response::ok();
    }
}
