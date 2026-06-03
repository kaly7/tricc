<?php
namespace Tricc;

class Response {
    public static function json(mixed $data, int $code = 200): never {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function ok(mixed $data = null): never {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function abort(int $code, string $msg): never {
        self::json(['ok' => false, 'error' => $msg], $code);
    }
}
