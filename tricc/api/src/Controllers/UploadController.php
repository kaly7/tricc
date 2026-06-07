<?php
namespace Tricc\Controllers;

use Tricc\{Auth, Response};

class UploadController {
    private const UPLOAD_DIR  = '/var/www/html/tricc/uploads/';
    private const PUBLIC_BASE = '/tricc/uploads/';
    private const MAX_SIZE    = 20 * 1024 * 1024; // 20 MB
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/plain',
        'video/mp4', 'video/quicktime',
        'audio/mpeg', 'audio/mp4',
    ];

    public static function upload(): never {
        $auth = Auth::require();

        if (empty($_FILES['file'])) Response::abort(400, 'Fájl nem érkezett.');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) Response::abort(400, 'Feltöltési hiba: ' . $f['error']);
        if ($f['size'] > self::MAX_SIZE) Response::abort(413, 'A fájl túl nagy (max 20 MB).');

        $mime = mime_content_type($f['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) Response::abort(415, 'Nem engedélyezett fájltípus.');

        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $name = sprintf('%d_%s.%s', $auth['user_id'], bin2hex(random_bytes(8)), $ext);
        $dest = self::UPLOAD_DIR . $name;

        if (!is_dir(self::UPLOAD_DIR)) mkdir(self::UPLOAD_DIR, 0755, true);
        if (!move_uploaded_file($f['tmp_name'], $dest)) Response::abort(500, 'Mentési hiba.');

        Response::ok([
            'url'       => self::PUBLIC_BASE . $name,
            'file_name' => $f['name'],
            'mime'      => $mime,
            'size'      => $f['size'],
            'type'      => str_starts_with($mime, 'image/') ? 'image' : 'file',
        ]);
    }

    public static function avatar(): never {
        $auth = Auth::require();

        if (empty($_FILES['file'])) Response::abort(400, 'Fájl nem érkezett.');
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            error_log("[Tricc] avatar upload error code: " . $f['error'] . " user=" . $auth['user_id']);
            Response::abort(400, 'Feltöltési hiba: ' . $f['error']);
        }
        if ($f['size'] > 5 * 1024 * 1024) Response::abort(413, 'Max 5 MB.');

        $mime = mime_content_type($f['tmp_name']);
        // iOS néha image/jpg-t küld image/jpeg helyett
        if ($mime === 'image/jpg') $mime = 'image/jpeg';
        $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extMap[$mime])) {
            error_log("[Tricc] avatar tiltott MIME: $mime user=" . $auth['user_id']);
            Response::abort(415, 'Csak JPEG/PNG/WebP avatar engedélyezett. (kapott: ' . $mime . ')');
        }

        $dir  = self::UPLOAD_DIR . 'avatars/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'avatar_' . $auth['user_id'] . '.' . $extMap[$mime];
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
            error_log("[Tricc] avatar move_uploaded_file hiba: src={$f['tmp_name']} dest={$dir}{$name}");
            Response::abort(500, 'Mentési hiba.');
        }

        $url = self::PUBLIC_BASE . 'avatars/' . $name;
        \Tricc\DB::get()->prepare("UPDATE users SET avatar_url=? WHERE id=?")->execute([$url, $auth['user_id']]);
        Response::ok(['avatar_url' => $url]);
    }
}
