<?php
// Webhook API kulcsgeneráló CLI script
// Futtatás: php api/webhook_key_gen.php <parancs> [args]
// Parancsok:
//   create <room_id> [label]   — új kulcs generálása
//   list                        — kulcsok listázása
//   delete <id>                 — kulcs törlése

require_once __DIR__ . '/../vendor/autoload.php';

$cfg = require __DIR__ . '/../../config.php';
$pdo = new PDO(
    "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
    $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'create':
        $room_id = (int)($argv[2] ?? 0);
        $label   = $argv[3] ?? '';
        if ($room_id <= 0) {
            echo "Használat: php webhook_key_gen.php create <room_id> [label]\n";
            exit(1);
        }
        $room = $pdo->prepare("SELECT id, name FROM rooms WHERE id = ?");
        $room->execute([$room_id]);
        $r = $room->fetch();
        if (!$r) {
            echo "Hiba: a(z) $room_id szoba nem létezik.\n";
            exit(1);
        }
        $key = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO webhook_keys (api_key, room_id, label) VALUES (?,?,?)")
            ->execute([$key, $room_id, $label]);
        $id = $pdo->lastInsertId();
        echo "Kulcs létrehozva (id=$id)\n";
        echo "Szoba: [{$r['id']}] {$r['name']}\n";
        echo "Label: $label\n";
        echo "\nAPI kulcs (másold le, többet nem jelenik meg!):\n$key\n\n";
        echo "Használat:\n";
        echo "  curl -X POST https://<domain>/tricc/api/webhook/send \\\n";
        echo "    -H \"X-Webhook-Key: $key\" \\\n";
        echo "    -H \"Content-Type: application/json\" \\\n";
        echo "    -d '{\"content\": \"Teszt értesítés\"}'\n";
        break;

    case 'list':
        $st = $pdo->query("
            SELECT wk.id, wk.label, wk.room_id, r.name AS room_name,
                   CONCAT(LEFT(wk.api_key, 8), '...') AS api_key_preview,
                   wk.created_at
            FROM webhook_keys wk
            JOIN rooms r ON r.id = wk.room_id
            ORDER BY wk.id
        ");
        $rows = $st->fetchAll();
        if (!$rows) {
            echo "Nincs webhook kulcs.\n";
        } else {
            printf("%-4s %-20s %-20s %-12s %-20s\n", 'ID', 'Label', 'Szoba', 'Kulcs (eleje)', 'Létrehozva');
            echo str_repeat('-', 80) . "\n";
            foreach ($rows as $row) {
                printf("%-4s %-20s %-20s %-12s %-20s\n",
                    $row['id'], $row['label'], $row['room_name'],
                    $row['api_key_preview'], $row['created_at']);
            }
        }
        break;

    case 'delete':
        $id = (int)($argv[2] ?? 0);
        if ($id <= 0) {
            echo "Használat: php webhook_key_gen.php delete <id>\n";
            exit(1);
        }
        $st = $pdo->prepare("SELECT wk.id, wk.label, r.name AS room_name FROM webhook_keys wk JOIN rooms r ON r.id=wk.room_id WHERE wk.id=?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            echo "Hiba: a(z) $id id-jű kulcs nem létezik.\n";
            exit(1);
        }
        $pdo->prepare("DELETE FROM webhook_keys WHERE id=?")->execute([$id]);
        echo "Törölve: [{$row['id']}] {$row['label']} → {$row['room_name']}\n";
        break;

    default:
        echo "Webhook kulcsgeneráló\n\n";
        echo "Parancsok:\n";
        echo "  php webhook_key_gen.php create <room_id> [label]\n";
        echo "  php webhook_key_gen.php list\n";
        echo "  php webhook_key_gen.php delete <id>\n";
        exit(1);
}
