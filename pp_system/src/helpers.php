<?php
function calc_due(string $issued): string {
  $dt = new DateTime($issued);
  $dt->modify('+38 day');
  return $dt->format('Y-m-d');
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }


function names_for_ids(string $table, array $ids): array {
  $ids = array_values(array_filter(array_map('intval', $ids), fn($v)=>$v>0));
  if (!$ids) return [];
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = db()->prepare("SELECT id, name FROM {$table} WHERE id IN ($in)");
  $st->execute($ids);
  $map = [];
  foreach ($st as $r) { $map[(int)$r['id']] = $r['name']; }
  // eredeti sorrend megtartása
  $out = [];
  foreach ($ids as $id) if (isset($map[$id])) $out[] = $map[$id];
  return $out;
}


function getContrastYIQ($hexcolor) {
    $hexcolor = ltrim($hexcolor, '#');
    $r = hexdec(substr($hexcolor,0,2));
    $g = hexdec(substr($hexcolor,2,2));
    $b = hexdec(substr($hexcolor,4,2));
    $yiq = (($r*299)+($g*587)+($b*114))/1000;
    return ($yiq >= 128) ? '#000' : '#fff';
}



// src/helpers.php

function log_om_job_event(PDO $db, int $jobId, int $userId, string $logType, string $message): void {
    $db->prepare("INSERT INTO om_job_logs (job_id, user_id, log_type, message) VALUES (?,?,?,?)")
       ->execute([$jobId, $userId, $logType, $message]);

    $recordId = $db->prepare("SELECT record_id FROM om_jobs WHERE id = ? LIMIT 1");
    $recordId->execute([$jobId]);
    $recordId = (int)$recordId->fetchColumn();
    if ($recordId) {
        log_change($db, $recordId, $userId, 'om_job_' . $logType, '', $message);
    }
}

function geocode_address(string $address): ?array {
    if (trim($address) === '') return null;
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $ctx = stream_context_create(['http' => [
        'timeout' => 5,
        'header'  => "User-Agent: PP-System/1.0 (kalamar.janos@gmail.com)\r\n"
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (empty($data[0]['lat'])) return null;
    return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
}

function log_change(PDO $db, int $recordId, int $userId, string $field, string $old, string $new): void {
    try {
        // A te sémád: record_changes(record_id, changed_by, changed_at, field, old_value, new_value)
        $st = $db->prepare("
            INSERT INTO record_changes (record_id, changed_by, field, old_value, new_value, changed_at)
            VALUES (?,?,?,?,?, NOW())
        ");
        $st->execute([$recordId, $userId, $field, $old, $new]);
    } catch (Throwable $e) {
        // ne álljon le a folyamat, ha bármi gond van a naplózással
        // opcionálisan ide is írhatsz a storage/logs/email_bulk.log-ba
    }
}

// Címzettek a sablonhoz: recipients táblán keresztül a users.email mezőből
function get_template_recipients(PDO $db, int $templateId): array {
    $sql = "SELECT DISTINCT u.email
            FROM email_template_recipients r
            JOIN users u ON u.id = r.user_id
            WHERE r.template_id = ?
              AND u.is_active = 1
              AND u.email <> ''";
    $st = $db->prepare($sql);
    $st->execute([$templateId]);
    $emails = [];
    foreach ($st as $row) {
        $e = trim((string)$row['email']);
        if ($e !== '') $emails[$e] = true;
    }
    return array_keys($emails);
}