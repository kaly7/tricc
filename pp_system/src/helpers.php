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