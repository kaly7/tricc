<?php
namespace Tricc\Controllers;

use Tricc\{DB, Response};

class WebhookController {
    private const BOT_USER_ID = 13;

    public static function send(): never {
        $key = $_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '';
        if (!$key) Response::abort(401, 'Webhook kulcs szükséges.');

        $db = DB::get();
        $st = $db->prepare("SELECT room_id FROM webhook_keys WHERE api_key = ?");
        $st->execute([$key]);
        $wk = $st->fetch();
        if (!$wk) Response::abort(401, 'Érvénytelen webhook kulcs.');
        $room_id = (int)$wk['room_id'];

        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $content = trim($body['content'] ?? '');
        $type    = in_array($body['type'] ?? '', ['text', 'system']) ? $body['type'] : 'text';
        if (!$content) Response::abort(400, 'content mező szükséges.');

        $db->prepare("INSERT INTO messages (room_id, sender_id, type, content) VALUES (?,?,?,?)")
           ->execute([$room_id, self::BOT_USER_ID, $type, $content]);
        $msg_id = (int)$db->lastInsertId();

        $msg = $db->prepare("
            SELECT m.id, m.room_id, m.sender_id AS user_id, u.name AS user_name, u.avatar_url,
                   m.type, m.content, m.is_edited, m.file_url, m.file_name, m.file_size, m.created_at,
                   m.reply_to_id, m.reply_to_content, m.reply_to_user_name
            FROM messages m JOIN users u ON u.id = m.sender_id WHERE m.id = ?
        ");
        $msg->execute([$msg_id]);
        $row = $msg->fetch();
        $row['reply_to']   = null;
        $row['deliveries'] = [];
        $row['reactions']  = [];
        unset($row['reply_to_id'], $row['reply_to_content'], $row['reply_to_user_name']);

        $db->prepare("INSERT IGNORE INTO message_deliveries (message_id, user_id)
            SELECT ?, rm.user_id FROM room_members rm WHERE rm.room_id = ?")
           ->execute([$msg_id, $room_id]);

        $db->prepare("UPDATE room_members SET hidden_at=NULL WHERE room_id=? AND hidden_at IS NOT NULL")
           ->execute([$room_id]);
        $db->prepare("UPDATE rooms SET delete_requested_by=NULL WHERE id=? AND delete_requested_by IS NOT NULL")
           ->execute([$room_id]);

        MessageController::pushToMembers($room_id, self::BOT_USER_ID, $row, $msg_id);
        MessageController::wsBroadcast($room_id, $row);
        Response::ok($row);
    }
}
