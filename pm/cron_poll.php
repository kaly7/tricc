<?php
/**
 * Cron script: FM job és robot státusz lekérdezés + DB frissítés
 * Cron entry: * * * * * /bin/bash -c 'for i in 1 2 3 4; do php /var/www/html/pm/cron_poll.php >> /var/www/html/pm/tmp/cron_poll.log 2>&1; sleep 14; done'
 */
date_default_timezone_set('Europe/Budapest');

$lock_file   = '/var/www/html/pm/tmp/query.lock';
$result_file = '/var/www/html/pm/tmp/query_result.json';
$log_file    = '/var/www/html/pm/tmp/cron_poll.log';
$max_log     = 256 * 1024;

$ts = date('Y-m-d H:i:s');

// Ha lock fut (< 30mp), kihagyjuk
if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 30) {
    _log($ts, "busy (lock fut)");
    exit(0);
}

$conn = new mysqli('localhost', 'robot', 'abrakadabra', 'Robot');
if ($conn->connect_error) {
    _log($ts, "DB hiba: " . $conn->connect_error);
    exit(1);
}

// --- Robot státuszfájlok → Robots tábla szinkron ---
// tmp/GYURI, tmp/MARCI stb. → availability + fm_status DB mezők
$rres = $conn->query("SELECT Robot_name FROM Robots WHERE Active != 'N'");
if (!$rres || $rres->num_rows === 0) {
    _log($ts, "ROBOT SZINKRON: Robots tábla üres vagy nem olvasható");
} else {
    $upd = $conn->prepare(
        "UPDATE Robots SET availability=?, fm_status=?, frissitve=NOW() WHERE Robot_name=?"
    );
    $synced = 0;
    while ($rrow = $rres->fetch_assoc()) {
        $robot_name = $rrow['Robot_name'];
        $last = strrchr($robot_name, '_');
        if ($last === false) {
            _log($ts, "ROBOT SZINKRON: '$robot_name' – nincs aláhúzás a névben, kihagyva");
            continue;
        }
        $fname   = strtoupper(substr($last, 1));
        $fpath   = '/var/www/html/pm/tmp/' . $fname;
        if (!file_exists($fpath)) {
            _log($ts, "ROBOT SZINKRON: '$robot_name' → $fpath – FÁJL NEM LÉTEZIK");
            continue;
        }
        if (!is_readable($fpath)) {
            _log($ts, "ROBOT SZINKRON: '$robot_name' → $fpath – FÁJL NEM OLVASHATÓ (jogosultság?)");
            continue;
        }
        $content = trim(file_get_contents($fpath));
        if (!$content) {
            _log($ts, "ROBOT SZINKRON: '$robot_name' → $fpath – FÁJL ÜRES");
            continue;
        }
        $parts = explode(' ', $content, 2);
        $avail = $parts[0];
        $fmst  = $parts[1] ?? '';
        $upd->bind_param('sss', $avail, $fmst, $robot_name);
        $upd->execute();
        $synced++;
        _log($ts, "ROBOT SZINKRON: '$robot_name' → \"$content\" → DB frissítve");
    }
    $upd->close();
    _log($ts, "ROBOT SZINKRON kész: $synced/" . $rres->num_rows . " robot írva");
}

// Aktív job ID-k
$res = $conn->query("SELECT DISTINCT Megjegyzes FROM Button_Goals WHERE akcio='aktiv'");
$active_jobs = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $active_jobs[] = $row['Megjegyzes'];
    }
}

// query_multi.pl futtatása szinkronban (queueShowRobot mindig fut, queueQuery ha van aktív job)
$args = implode(' ', array_map('escapeshellarg', $active_jobs));
exec("perl /var/www/html/pm/query_multi.pl $args 2>&1");

// Eredmény feldolgozása
$completed = [];
if (file_exists($result_file) && (time() - filemtime($result_file)) < 60) {
    $raw  = file_get_contents($result_file);
    $data = json_decode($raw, true);

    if ($data) {
        // Robot állapotok mentése DB-be (query_result.json alapján)
        if (!empty($data['robots'])) {
            foreach ($data['robots'] as $r) {
                $name  = $conn->real_escape_string($r['name']  ?? '');
                $avail = $conn->real_escape_string($r['availability'] ?? '');
                $fmst  = $conn->real_escape_string($r['fm_status']    ?? '');
                $ok = $conn->query(
                    "UPDATE Robots SET availability='$avail', fm_status='$fmst',
                     frissitve=NOW() WHERE Robot_name='$name'"
                );
                if (!$ok) {
                    _log($ts, "ROBOT DB HIBA '$name': " . $conn->error . " (Hiányzik az availability/fm_status oszlop? Futtasd: migrate_check.php)");
                } elseif ($conn->affected_rows === 0) {
                    _log($ts, "ROBOT DB FIGYELEM '$name': 0 sor frissült – a Robot_name nem egyezik a DB-ben lévővel (JSON: '$name')");
                } else {
                    _log($ts, "ROBOT DB OK '$name': $avail $fmst");
                }
            }
        } else {
            _log($ts, "ROBOT DB: query_result.json-ban nincs robots adat");
        }

        // Job pickup státuszok mentése
        if (!empty($data['results'])) {
            foreach ($data['results'] as $r) {
                $jid = $conn->real_escape_string($r['job_id']);

                if (!empty($r['pickups'])) {
                    foreach ($r['pickups'] as $p) {
                        $pickup_id = $conn->real_escape_string($p['pickup_id']);
                        $pstatus   = $conn->real_escape_string($p['status']);
                        $goal      = $conn->real_escape_string($p['goal']);
                        $robot     = $conn->real_escape_string($p['robot']);
                        $kezdes    = $conn->real_escape_string($p['kezdes']);
                        $vegzes    = $conn->real_escape_string($p['vegzes']);
                        $conn->query(
                            "UPDATE Button_Goals
                             SET pickup_id='$pickup_id', pickup_status='$pstatus',
                                 robot_nev='$robot', fm_kezdes='$kezdes', fm_vegzes='$vegzes'
                             WHERE Megjegyzes='$jid' AND Goal_name='$goal'
                               AND (pickup_id IS NULL OR pickup_id='$pickup_id')
                             ORDER BY Index_ LIMIT 1"
                        );
                    }
                }

                // Job lezárás: FM job-szintű státusz alapján (query_multi.pl _worse_status összesíti)
                // completed csak akkor, ha MINDEN pickup completed; failed/cancelled/interrupted azonnali lezárás
                $fm_status = strtolower($r['status'] ?? '');
                if (in_array($fm_status, ['completed', 'cancelled', 'failed', 'interrupted'])) {
                    $conn->query("UPDATE Button_Goals SET akcio='deleted' WHERE Megjegyzes='$jid'");
                    $completed[] = $r['job_id'];
                    _log($ts, "Job lezárva (FM: " . ($r['status'] ?? '?') . "): " . $r['job_id']);
                }
            }
            if ($completed) {
                _log($ts, "Lezárt jobok összesen: " . implode(', ', $completed));
            }
        }

        // --- fm_jobs_live szinkron: összes aktív FM pickup ---
        $done_statuses = ['completed', 'cancelled', 'failed', 'interrupted'];
        $current_pickups = [];

        if (isset($data['fm_jobs']) && is_array($data['fm_jobs'])) {
            $upsert = $conn->prepare(
                "INSERT INTO fm_jobs_live (pickup_id, job_id, goal, robot, status, fm_kezdes)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   job_id=VALUES(job_id), goal=VALUES(goal), robot=VALUES(robot),
                   status=VALUES(status), fm_kezdes=VALUES(fm_kezdes)"
            );
            foreach ($data['fm_jobs'] as $p) {
                if (in_array(strtolower($p['status'] ?? ''), $done_statuses)) continue;
                $pid    = $p['pickup_id'];
                $jid    = $p['job_id'];
                $goal   = $p['goal']   ?? '';
                $robot  = $p['robot']  ?? '';
                $status = $p['status'] ?? '';
                $kezdes = !empty($p['kezdes']) ? $p['kezdes'] : null;
                $upsert->bind_param('ssssss', $pid, $jid, $goal, $robot, $status, $kezdes);
                $upsert->execute();
                $current_pickups[] = $pid;
                _log($ts, "fm_jobs_live upsert: $pid | $jid | $goal | $robot | $status");
            }
            $upsert->close();
        } else {
            _log($ts, "fm_jobs_live: fm_jobs kulcs hiányzik a result JSON-ból");
        }

        // Töröljük azokat a pickupokat, amik már nem aktívak az FM-ben
        if (!empty($current_pickups)) {
            $ph  = implode(',', array_fill(0, count($current_pickups), '?'));
            $del = $conn->prepare("DELETE FROM fm_jobs_live WHERE pickup_id NOT IN ($ph)");
            $del->bind_param(str_repeat('s', count($current_pickups)), ...$current_pickups);
            $del->execute();
            $deleted = $del->affected_rows;
            $del->close();
            if ($deleted > 0) _log($ts, "fm_jobs_live: $deleted elavult pickup törölve");
        } else {
            // Ha queueShow üres → az FM queue is üres
            $chk = $conn->query("SELECT COUNT(*) AS cnt FROM fm_jobs_live");
            $cnt = $chk ? (int)$chk->fetch_assoc()['cnt'] : 0;
            $conn->query("DELETE FROM fm_jobs_live");
            if ($cnt > 0) _log($ts, "fm_jobs_live: FM queue üres, $cnt pickup törölve");
        }

        // Általunk indított jobok lezárása: ha eltűnt az FM queue-ból
        $our_res = $conn->query("SELECT DISTINCT Megjegyzes FROM Button_Goals WHERE akcio='aktiv'");
        if ($our_res) {
            while ($our_row = $our_res->fetch_assoc()) {
                $jid = $conn->real_escape_string($our_row['Megjegyzes']);
                $chk = $conn->query("SELECT COUNT(*) AS cnt FROM fm_jobs_live WHERE job_id='$jid'");
                if ($chk && (int)$chk->fetch_assoc()['cnt'] === 0) {
                    $conn->query("UPDATE Button_Goals SET akcio='deleted' WHERE Megjegyzes='$jid'");
                    _log($ts, "Job lezárva (eltűnt FM-ből): " . $our_row['Megjegyzes']);
                }
            }
        }

        _log($ts, "fm_jobs_live szinkron kész: " . count($current_pickups) . " aktív pickup");
    }
    unlink($result_file);
} else {
    _log($ts, "Nincs feldolgozható eredmény (FM elérhetetlen?)");
}

$conn->close();

// --- Segédfüggvény ---
function _log($ts, $msg) {
    global $log_file, $max_log;
    if (file_exists($log_file) && filesize($log_file) > $max_log) {
        $lines = file($log_file);
        $keep  = array_slice($lines, (int)(count($lines) * 0.4));
        file_put_contents($log_file, implode('', $keep));
    }
    file_put_contents($log_file, "[$ts] $msg\n", FILE_APPEND);
}
