<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

function tracker_current_auth_user_id(): int {
    $candidates = [
        $_SESSION['user_id'] ?? null,
        $_SESSION['uid'] ?? null,
        $_SESSION['auth_user_id'] ?? null,
        $_SESSION['user']['id'] ?? null,
        $_SESSION['user']['user_id'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            return (int)$candidate;
        }
    }
    return 0;
}

function tracker_current_auth_display_name(): string {
    if (!empty($_SESSION['full_name'])) return (string)$_SESSION['full_name'];
    if (!empty($_SESSION['user']['full_name'])) return (string)$_SESSION['user']['full_name'];
    if (!empty($_SESSION['username'])) return (string)$_SESSION['username'];
    if (!empty($_SESSION['user']['username'])) return (string)$_SESSION['user']['username'];
    return 'Felhasználó';
}

function tracker_is_admin(array $config): bool {
    return CentralAuth::isAdmin($config, $config['module_key']);
}

function tracker_flash_set(string $type, string $message): void {
    $_SESSION['_flash'][$type] = $message;
}

function tracker_flash_get(string $type): string {
    $value = (string)($_SESSION['_flash'][$type] ?? '');
    unset($_SESSION['_flash'][$type]);
    return $value;
}

function tracker_redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function tracker_month_bounds(?string $ym = null): array {
    $dt = $ym ? DateTimeImmutable::createFromFormat('Y-m', $ym) : new DateTimeImmutable('first day of this month');
    if (!$dt) $dt = new DateTimeImmutable('first day of this month');
    $start = $dt->modify('first day of this month');
    $end = $dt->modify('last day of this month');
    return [$start->format('Y-m-d'), $end->format('Y-m-d'), $start];
}

function tracker_minutes_to_hhmm(int $minutes): string {
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
}

function tracker_request_ip(): ?string {
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? substr($ip, 0, 64) : null;
}

function tracker_current_user(array $config): array {
    $uid = tracker_current_auth_user_id();
    $name = tracker_current_auth_display_name();
    $pdo = tracker_auth_pdo($config);
    $st = $pdo->prepare('SELECT id, username, full_name, email, hr_employee_id FROM users WHERE id = ? LIMIT 1');
    $st->execute([$uid]);
    $row = $st->fetch() ?: [];
    $row['id'] = (int)($row['id'] ?? $uid);
    $row['full_name'] = (string)($row['full_name'] ?? $name);
    $row['hr_employee_id'] = (int)($row['hr_employee_id'] ?? 0);
    $row['resolved_employee_name'] = $row['full_name'];

    if ($row['hr_employee_id'] > 0) {
        $hr = tracker_hr_pdo($config);
        if ($hr instanceof PDO) {
            try {
                $emp = $hr->prepare('SELECT id, full_name FROM employees WHERE id = ? LIMIT 1');
                $emp->execute([$row['hr_employee_id']]);
                $e = $emp->fetch();
                if ($e && !empty($e['full_name'])) {
                    $row['resolved_employee_name'] = (string)$e['full_name'];
                }
            } catch (Throwable $e) {
                // HR lookup optional
            }
        }
    }

    return $row;
}

function tracker_require_employee(array $user): void {
    if ((int)($user['hr_employee_id'] ?? 0) <= 0) {
        http_response_code(403);
        echo '<!doctype html><html lang="hu"><meta charset="utf-8"><body style="font-family:system-ui;padding:2rem">';
        echo '<h2>Nincs HR dolgozó hozzárendelve</h2>';
        echo '<p>Ehhez a felhasználóhoz nincs HR dolgozó rekord társítva az Auth Centerben.</p>';
        echo '</body></html>';
        exit;
    }
}


function tracker_hu_month_name(int $month): string {
    $months = [
        1 => 'január', 2 => 'február', 3 => 'március', 4 => 'április',
        5 => 'május', 6 => 'június', 7 => 'július', 8 => 'augusztus',
        9 => 'szeptember', 10 => 'október', 11 => 'november', 12 => 'december',
    ];
    return $months[$month] ?? '';
}

function tracker_hu_month_title(DateTimeImmutable $date): string {
    return $date->format('Y') . '. ' . tracker_hu_month_name((int)$date->format('n'));
}

function tracker_expected_workdays_between(array $config, string $dateFrom, string $dateTo): int {
    $start = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    if ($end < $start) return 0;
    $holidayMap = [];
    foreach (tracker_holidays_between($config, $dateFrom, $dateTo) as $holiday) {
        $holidayMap[(string)$holiday['holiday_date']] = true;
    }
    $workdays = 0;
    $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    foreach ($period as $dt) {
        if ((int)$dt->format('N') >= 6) continue;
        $date = $dt->format('Y-m-d');
        if (!empty($holidayMap[$date])) continue;
        $workdays++;
    }
    return $workdays;
}

function tracker_expected_minutes_between(array $config, string $dateFrom, string $dateTo, int $minutesPerWorkday = 480): int {
    return tracker_expected_workdays_between($config, $dateFrom, $dateTo) * max(0, $minutesPerWorkday);
}

function tracker_progress_percent(int $actualMinutes, int $expectedMinutes): ?float {
    if ($expectedMinutes <= 0) return null;
    return round(($actualMinutes / $expectedMinutes) * 100, 1);
}

function tracker_minutes_to_hours_compact(int $minutes): string {
    $hours = $minutes / 60;
    if (abs($hours - round($hours)) < 0.00001) {
        return (string)((int)round($hours)) . ' ó';
    }
    return str_replace('.', ',', number_format($hours, 1, '.', '')) . ' ó';
}

function tracker_percent_label(?float $percent): string {
    if ($percent === null) {
        return '—';
    }
    $rounded = round($percent, 1);
    if (abs($rounded - round($rounded)) < 0.00001) {
        return (string)((int)round($rounded)) . '%';
    }
    return str_replace('.', ',', number_format($rounded, 1, '.', '')) . '%';
}

function tracker_week_key_bounds(string $weekKey): ?array {
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) {
        return null;
    }
    $year = (int)$m[1];
    $week = (int)$m[2];
    $start = (new DateTimeImmutable())->setISODate($year, $week)->setTime(0, 0, 0);
    $end = $start->modify('+6 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function tracker_month_key_bounds(string $monthKey): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $m)) {
        return null;
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', (int)$m[1], (int)$m[2]));
    if (!$start) {
        return null;
    }
    $end = $start->modify('last day of this month');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function tracker_date_range_overlap(string $fromA, string $toA, string $fromB, string $toB): ?array {
    $start = max($fromA, $fromB);
    $end = min($toA, $toB);
    if ($start > $end) {
        return null;
    }
    return [$start, $end];
}

function tracker_weekday_labels_hu_short(): array {
    return ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
}

function tracker_weekday_labels_hu_full(): array {
    return ['Hétfő', 'Kedd', 'Szerda', 'Csütörtök', 'Péntek', 'Szombat', 'Vasárnap'];
}


function tracker_hour_options(): array {
    return range(0, 23);
}

function tracker_minute_options(): array {
    return [0, 10, 20, 30, 40, 50];
}

function tracker_split_time(?string $time): array {
    $time = (string)$time;
    if ($time === '' || strpos($time, ':') === false) {
        return [null, null];
    }
    [$h, $m] = explode(':', substr($time, 0, 5));
    return [(int)$h, (int)$m];
}

function tracker_pick_closest_step(int $minute, int $step = 10): int {
    $minute = max(0, min(59, $minute));
    $rounded = (int)(round($minute / $step) * $step);
    if ($rounded >= 60) $rounded = 50;
    return $rounded;
}

function tracker_time_from_parts($hour, $minute): ?string {
    if ($hour === '' || $minute === '' || $hour === null || $minute === null) {
        return null;
    }
    return sprintf('%02d:%02d', (int)$hour, (int)$minute);
}

function tracker_entry_count_label(int $count): string {
    return $count . ' bejegyzés';
}


function tracker_minutes_human(int $minutes): string {
    if ($minutes <= 0) {
        return '0 ó 0 p';
    }
    return intdiv($minutes, 60) . ' ó ' . ($minutes % 60) . ' p';
}

function tracker_contrast_text_color(string $hex): string {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) == 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#1f2937';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luma = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
    return $luma >= 160 ? '#1f2937' : '#ffffff';
}



function tracker_vehicle_options(array $config, bool $activeOnly = true): array {
    $pdo = tracker_app_pdo($config);
    $sql = 'SELECT * FROM tt_vehicles';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY plate_number';
    return $pdo->query($sql)->fetchAll() ?: [];
}

function tracker_vehicle_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_vehicles WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tracker_save_vehicle(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $plate = strtoupper(trim((string)($data['plate_number'] ?? '')));
    if ($plate === '') throw new InvalidArgumentException('A rendszám megadása kötelező.');
    $label = trim((string)($data['label'] ?? ''));
    $avg = (float)($data['avg_speed_kmh'] ?? 0);
    if ($avg <= 0) throw new InvalidArgumentException('Az átlagsebességnek 0-nál nagyobbnak kell lennie.');
    $active = !empty($data['is_active']) ? 1 : 0;
    if ($id) {
        $before = tracker_vehicle_find($config, $id);
        $st = $pdo->prepare('UPDATE tt_vehicles SET plate_number=?, label=?, avg_speed_kmh=?, is_active=? WHERE id=?');
        $st->execute([$plate, $label !== '' ? $label : null, $avg, $active, $id]);
        $after = tracker_vehicle_find($config, $id);
        tracker_audit_log($config, 'vehicle.update', 'tt_vehicles', $id, null, $before, $after);
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO tt_vehicles (plate_number, label, avg_speed_kmh, is_active) VALUES (?,?,?,?)');
    $st->execute([$plate, $label !== '' ? $label : null, $avg, $active]);
    $newId = (int)$pdo->lastInsertId();
    $after = tracker_vehicle_find($config, $newId);
    tracker_audit_log($config, 'vehicle.create', 'tt_vehicles', $newId, null, null, $after);
    return $newId;
}

function tracker_delete_vehicle(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $before = tracker_vehicle_find($config, $id);
    if (!$before) return;
    $st = $pdo->prepare('DELETE FROM tt_vehicles WHERE id = ?');
    $st->execute([$id]);
    tracker_audit_log($config, 'vehicle.delete', 'tt_vehicles', $id, null, $before, null);
}

function tracker_calculate_travel_minutes(?float $km, ?float $avgSpeedKmh): int {
    $km = (float)($km ?? 0);
    $avgSpeedKmh = (float)($avgSpeedKmh ?? 0);
    if ($km <= 0 || $avgSpeedKmh <= 0) return 0;
    return (int)round(($km / $avgSpeedKmh) * 60);
}

function tracker_time_span_details(string $start, string $end): array {
    $a = DateTimeImmutable::createFromFormat('H:i', substr($start, 0, 5));
    $b = DateTimeImmutable::createFromFormat('H:i', substr($end, 0, 5));
    if (!$a || !$b) {
        throw new InvalidArgumentException('Érvénytelen időformátum.');
    }
    $startMinutes = ((int)$a->format('H')) * 60 + (int)$a->format('i');
    $endMinutes = ((int)$b->format('H')) * 60 + (int)$b->format('i');
    $crosses = $endMinutes < $startMinutes;
    if ($crosses) $endMinutes += 1440;
    return [$startMinutes, $endMinutes, $crosses];
}

function tracker_time_display_with_offset(?string $time, bool $crossesMidnight = false, bool $isEnd = false): string {
    $base = substr((string)$time, 0, 5);
    if ($base === '') return '—';
    if ($crossesMidnight && $isEnd) return $base . ' (+1 nap)';
    return $base;
}


function tracker_entry_day_segments(array $entry, ?string $fromDate = null, ?string $toDate = null): array {
    $date = (string)($entry['entry_date'] ?? '');
    $start = substr((string)($entry['start_time'] ?? ''), 0, 5);
    $end = substr((string)($entry['end_time'] ?? ''), 0, 5);
    if ($date === '' || $start === '' || $end === '') {
        return [$entry];
    }
    [$startMinutes, $endMinutes, $crosses] = tracker_time_span_details($start, $end);
    $totalMinutes = (int)($entry['work_minutes'] ?? 0);
    $travelMinutes = (int)($entry['travel_minutes'] ?? 0);
    $travelKm = (float)($entry['travel_km'] ?? 0);
    if (!$crosses) {
        $entry['segment_date'] = $date;
        $entry['display_start_time'] = $start;
        $entry['display_end_time'] = $end;
        $entry['display_crosses_midnight'] = false;
        $entry['segment_total_minutes'] = $totalMinutes;
        $entry['segment_travel_minutes'] = $travelMinutes;
        $entry['segment_work_minutes'] = max(0, $totalMinutes - $travelMinutes);
        $entry['segment_travel_km'] = $travelKm;
        return [$entry];
    }
    $grossFirst = 1440 - $startMinutes;
    $grossSecond = $endMinutes - 1440;
    $grossTotal = max(1, $grossFirst + $grossSecond);
    $firstRatio = $grossFirst / $grossTotal;
    $firstTotal = (int)round($totalMinutes * $firstRatio);
    $secondTotal = max(0, $totalMinutes - $firstTotal);
    $firstTravel = min($firstTotal, (int)round($travelMinutes * $firstRatio));
    $secondTravel = max(0, min($secondTotal, $travelMinutes - $firstTravel));
    $firstKm = round($travelKm * $firstRatio, 1);
    $secondKm = max(0.0, round($travelKm - $firstKm, 1));
    $nextDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');

    $rows = [];
    $first = $entry;
    $first['segment_date'] = $date;
    $first['display_start_time'] = $start;
    $first['display_end_time'] = '24:00';
    $first['display_crosses_midnight'] = false;
    $first['segment_total_minutes'] = $firstTotal;
    $first['segment_travel_minutes'] = $firstTravel;
    $first['segment_work_minutes'] = max(0, $firstTotal - $firstTravel);
    $first['segment_travel_km'] = $firstKm;
    $first['segment_part'] = 1;
    $rows[] = $first;

    $second = $entry;
    $second['segment_date'] = $nextDate;
    $second['display_start_time'] = '00:00';
    $second['display_end_time'] = $end;
    $second['display_crosses_midnight'] = false;
    $second['segment_total_minutes'] = $secondTotal;
    $second['segment_travel_minutes'] = $secondTravel;
    $second['segment_work_minutes'] = max(0, $secondTotal - $secondTravel);
    $second['segment_travel_km'] = $secondKm;
    $second['segment_part'] = 2;
    $rows[] = $second;

    if ($fromDate || $toDate) {
        $rows = array_values(array_filter($rows, static function(array $row) use ($fromDate, $toDate): bool {
            $d = (string)$row['segment_date'];
            if ($fromDate && $d < $fromDate) return false;
            if ($toDate && $d > $toDate) return false;
            return true;
        }));
    }
    return $rows;
}

function tracker_entry_segment_work_minutes(array $entry): int {
    if (isset($entry['segment_work_minutes'])) return (int)$entry['segment_work_minutes'];
    return max(0, (int)($entry['work_minutes'] ?? 0) - (int)($entry['travel_minutes'] ?? 0));
}

function tracker_entry_segment_total_minutes(array $entry): int {
    return isset($entry['segment_total_minutes']) ? (int)$entry['segment_total_minutes'] : (int)($entry['work_minutes'] ?? 0);
}

function tracker_entry_segment_travel_minutes(array $entry): int {
    return isset($entry['segment_travel_minutes']) ? (int)$entry['segment_travel_minutes'] : (int)($entry['travel_minutes'] ?? 0);
}

function tracker_entry_segment_travel_km(array $entry): float {
    return isset($entry['segment_travel_km']) ? (float)$entry['segment_travel_km'] : (float)($entry['travel_km'] ?? 0);
}

function tracker_uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}



function tracker_report_grouped(array $rows, array $employeeMap): array {
    $groups = [];
    foreach ($rows as $row) {
        $employeeId = (int)$row['employee_id'];
        $date = (string)$row['entry_date'];
        $minutes = (int)$row['total_minutes'];
        $entryCount = (int)$row['entry_count'];
        $rowKind = (string)($row['row_kind'] ?? 'work');
        $typeLabel = trim((string)($row['type_label'] ?? ''));
        $badgeText = trim((string)($row['badge_text'] ?? ''));
        $note = (string)($row['note'] ?? '');
        $travelMinutes = (int)($row['travel_minutes'] ?? 0);
        $travelKm = (float)($row['travel_km'] ?? 0);
        $vehiclePlate = (string)($row['vehicle_plate'] ?? '');
        if (!isset($groups[$employeeId])) {
            $groups[$employeeId] = [
                'employee_id' => $employeeId,
                'employee_name' => (string)($employeeMap[$employeeId] ?? ('#' . $employeeId)),
                'days' => [],
                'weekly' => [],
                'monthly' => [],
                'weekly_travel' => [],
                'weekly_work_only' => [],
                'monthly_travel' => [],
                'monthly_work_only' => [],
                'total_minutes' => 0,
                'entry_count' => 0,
                'worked_days' => 0,
                'absence_days' => 0,
                'absence_by_type' => [],
                'travel_minutes' => 0,
                'travel_km' => 0.0,
                'work_only_minutes' => 0,
            ];
        }
        $weekKey = (new DateTimeImmutable($date))->format('o-\WW');
        $monthKey = substr($date, 0, 7);
        $groups[$employeeId]['days'][] = [
            'entry_date' => $date,
            'row_kind' => $rowKind,
            'total_minutes' => $minutes,
            'entry_count' => $entryCount,
            'type_label' => $typeLabel,
            'badge_text' => $badgeText,
            'note' => $note,
            'travel_minutes' => $travelMinutes,
            'travel_km' => $travelKm,
            'vehicle_plate' => $vehiclePlate,
            'work_only_minutes' => max(0, $minutes - $travelMinutes),
        ];
        $groups[$employeeId]['weekly'][$weekKey] = (int)($groups[$employeeId]['weekly'][$weekKey] ?? 0);
        $groups[$employeeId]['monthly'][$monthKey] = (int)($groups[$employeeId]['monthly'][$monthKey] ?? 0);
        $groups[$employeeId]['weekly_travel'][$weekKey] = (int)($groups[$employeeId]['weekly_travel'][$weekKey] ?? 0);
        $groups[$employeeId]['weekly_work_only'][$weekKey] = (int)($groups[$employeeId]['weekly_work_only'][$weekKey] ?? 0);
        $groups[$employeeId]['monthly_travel'][$monthKey] = (int)($groups[$employeeId]['monthly_travel'][$monthKey] ?? 0);
        $groups[$employeeId]['monthly_work_only'][$monthKey] = (int)($groups[$employeeId]['monthly_work_only'][$monthKey] ?? 0);
        if ($rowKind === 'work') {
            $groups[$employeeId]['weekly'][$weekKey] += $minutes;
            $groups[$employeeId]['monthly'][$monthKey] += $minutes;
            $groups[$employeeId]['weekly_travel'][$weekKey] += $travelMinutes;
            $groups[$employeeId]['weekly_work_only'][$weekKey] += max(0, $minutes - $travelMinutes);
            $groups[$employeeId]['monthly_travel'][$monthKey] += $travelMinutes;
            $groups[$employeeId]['monthly_work_only'][$monthKey] += max(0, $minutes - $travelMinutes);
            $groups[$employeeId]['total_minutes'] += $minutes;
            $groups[$employeeId]['entry_count'] += $entryCount;
            $groups[$employeeId]['travel_minutes'] += $travelMinutes;
            $groups[$employeeId]['travel_km'] += $travelKm;
            $groups[$employeeId]['work_only_minutes'] += max(0, $minutes - $travelMinutes);
            if ($minutes > 0) {
                $groups[$employeeId]['worked_days']++;
            }
        } elseif ($rowKind === 'absence') {
            $groups[$employeeId]['absence_days']++;
            $typeKey = $typeLabel !== '' ? $typeLabel : 'Távollét';
            $groups[$employeeId]['absence_by_type'][$typeKey] = (int)($groups[$employeeId]['absence_by_type'][$typeKey] ?? 0) + 1;
        }
    }
    foreach ($groups as &$group) {
        ksort($group['weekly']);
        ksort($group['monthly']);
        ksort($group['weekly_travel']);
        ksort($group['weekly_work_only']);
        ksort($group['monthly_travel']);
        ksort($group['monthly_work_only']);
        ksort($group['absence_by_type'], SORT_NATURAL | SORT_FLAG_CASE);
        usort($group['days'], static function ($a, $b) {
            $dateCmp = strcmp((string)$a['entry_date'], (string)$b['entry_date']);
            if ($dateCmp !== 0) return $dateCmp;
            return strcmp((string)$a['row_kind'], (string)$b['row_kind']);
        });
        $weekCount = max(1, count($group['weekly']));
        $monthCount = max(1, count($group['monthly']));
        $group['weekly_average_minutes'] = (int)round($group['total_minutes'] / $weekCount);
        $group['monthly_average_minutes'] = (int)round($group['total_minutes'] / $monthCount);
    }
    unset($group);
    uasort($groups, static fn($a, $b) => strcasecmp((string)$a['employee_name'], (string)$b['employee_name']));
    return $groups;
}

function tracker_report_overall_summary(array $groups): array {
    $summary = [
        'employee_count' => count($groups),
        'total_minutes' => 0,
        'entry_count' => 0,
        'worked_days' => 0,
        'absence_days' => 0,
        'absence_by_type' => [],
        'travel_minutes' => 0,
        'travel_km' => 0.0,
    ];
    foreach ($groups as $group) {
        $summary['total_minutes'] += (int)$group['total_minutes'];
        $summary['entry_count'] += (int)$group['entry_count'];
        $summary['worked_days'] += (int)$group['worked_days'];
        $summary['absence_days'] += (int)($group['absence_days'] ?? 0);
        $summary['travel_minutes'] += (int)($group['travel_minutes'] ?? 0);
        $summary['travel_km'] += (float)($group['travel_km'] ?? 0);
        $summary['work_only_minutes'] = (int)($summary['work_only_minutes'] ?? 0) + (int)($group['work_only_minutes'] ?? 0);
        foreach (($group['absence_by_type'] ?? []) as $label => $count) {
            $summary['absence_by_type'][$label] = (int)($summary['absence_by_type'][$label] ?? 0) + (int)$count;
        }
    }
    ksort($summary['absence_by_type'], SORT_NATURAL | SORT_FLAG_CASE);
    return $summary;
}



function tracker_report_day_detail(array $day): string {
    $isAbsence = (string)($day['row_kind'] ?? 'work') === 'absence';
    $isMissing = (string)($day['row_kind'] ?? 'work') === 'missing';
    if ($isAbsence) return 'Egész nap';
    if ($isMissing) return '—';
    $parts = [((int)($day['entry_count'] ?? 0)) . ' bejegyzés'];
    $vehicle = trim((string)($day['vehicle_plate'] ?? ''));
    $km = (float)($day['travel_km'] ?? 0);
    $travelMinutes = (int)($day['travel_minutes'] ?? 0);
    if ($vehicle !== '' || $km > 0 || $travelMinutes > 0) {
        $travel = [];
        if ($vehicle !== '') $travel[] = $vehicle;
        if ($km > 0) $travel[] = rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.') . ' km';
        if ($travelMinutes > 0) $travel[] = 'utazás: ' . tracker_minutes_to_hhmm($travelMinutes);
        if ($travel) $parts[] = implode(' · ', $travel);
    }
    $workOnly = (int)($day['work_only_minutes'] ?? max(0, (int)($day['total_minutes'] ?? 0) - (int)($day['travel_minutes'] ?? 0)));
    $parts[] = 'munka: ' . tracker_minutes_to_hhmm($workOnly);
    return implode(' · ', $parts);
}

function tracker_hu_week_label_from_key(string $weekKey): string {
    if (!preg_match('/^(\d{4})-W(\d{2})$/', $weekKey, $m)) {
        return $weekKey;
    }
    return $m[1] . ' / ' . (int)$m[2] . '. hét';
}

function tracker_report_days_grouped_by_week(array $days): array {
    $weeks = [];
    foreach ($days as $day) {
        $date = (string)($day['entry_date'] ?? '');
        if ($date === '') {
            continue;
        }
        $weekKey = (new DateTimeImmutable($date))->format('o-\WW');
        if (!isset($weeks[$weekKey])) {
            $weeks[$weekKey] = [
                'week_key' => $weekKey,
                'week_label' => tracker_hu_week_label_from_key($weekKey),
                'days' => [],
                'total_minutes' => 0,
                'entry_count' => 0,
            ];
        }
        $weeks[$weekKey]['days'][] = $day;
        $weeks[$weekKey]['total_minutes'] += (int)($day['total_minutes'] ?? 0);
        $weeks[$weekKey]['entry_count'] += (int)($day['entry_count'] ?? 0);
    }
    return $weeks;
}

function tracker_hu_month_label_from_key(string $monthKey): string {
    if (!preg_match('/^(\d{4})-(\d{2})$/', $monthKey, $m)) {
        return $monthKey;
    }
    return $m[1] . '. ' . tracker_hu_month_name((int)$m[2]);
}

function tracker_ascii_text(string $text): string {
    $map = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ő'=>'o','ú'=>'u','ü'=>'u','ű'=>'u',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ö'=>'O','Ő'=>'O','Ú'=>'U','Ü'=>'U','Ű'=>'U',
        '–'=>'-','—'=>'-','„'=>'"','”'=>'"','’'=>"'",
    ];
    $text = strtr($text, $map);
    $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    return $conv !== false ? $conv : $text;
}

function tracker_pdf_escape(string $text): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function tracker_simple_pdf_from_lines(array $lines, string $title = 'Report'): string {
    $maxLinesPerPage = 46;
    $pages = array_chunk($lines, $maxLinesPerPage);
    if (!$pages) {
        $pages = [['']];
    }
    $objects = [];
    $add = function(string $content) use (&$objects): int {
        $objects[] = $content;
        return count($objects);
    };
    $fontId = $add("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
    $kids = [];
    foreach ($pages as $pageLines) {
        $stream = "BT\n/F1 9 Tf\n14 TL\n50 800 Td\n";
        foreach ($pageLines as $idx => $line) {
            $safe = tracker_pdf_escape(tracker_ascii_text($line));
            if ($idx === 0) {
                $stream .= "(" . $safe . ") Tj\n";
            } else {
                $stream .= "T* (" . $safe . ") Tj\n";
            }
        }
        $stream .= "ET";
        $contentId = $add("<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream");
        $pageId = $add("<< /Type /Page /Parent PAGES_ID 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontId} 0 R >> >> /Contents {$contentId} 0 R >>");
        $kids[] = $pageId;
    }
    $kidsRefs = implode(' ', array_map(fn($id) => "{$id} 0 R", $kids));
    $pagesId = $add("<< /Type /Pages /Kids [ {$kidsRefs} ] /Count " . count($kids) . " >>");
    foreach ($kids as $kid) {
        $objects[$kid - 1] = str_replace('PAGES_ID', (string)$pagesId, $objects[$kid - 1]);
    }
    $titleSafe = tracker_pdf_escape(tracker_ascii_text($title));
    $catalogId = $add("<< /Type /Catalog /Pages {$pagesId} 0 R >>");
    $infoId = $add("<< /Title ({$titleSafe}) /Producer (Time Tracker) >>");

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $obj . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
    return $pdf;
}

function tracker_report_week_markers(array $days): array {
    $weeks = tracker_report_days_grouped_by_week($days);
    $markers = [];
    foreach ($weeks as $week) {
        $firstDate = $week['days'][0]['entry_date'] ?? null;
        if ($firstDate) {
            $markers[(string)$firstDate] = (string)$week['week_label'];
        }
    }
    return $markers;
}

function tracker_pdf_vendor_autoload_candidates(): array {
    return [
        __DIR__ . '/../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        '/var/www/html/time_tracker_8788/vendor/autoload.php',
        '/var/www/html/warehousemgr/vendor/autoload.php',
        '/var/www/html/auth_center/vendor/autoload.php',
        '/usr/share/php/vendor/autoload.php',
    ];
}

function tracker_pdf_vendor_autoload(): ?string {
    foreach (tracker_pdf_vendor_autoload_candidates() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function tracker_pdf_make_mpdf() {
    $autoload = tracker_pdf_vendor_autoload();
    if ($autoload === null) {
        return null;
    }
    require_once $autoload;
    if (!class_exists('\Mpdf\Mpdf')) {
        return null;
    }

    $tmp = __DIR__ . '/../storage/mpdf';
    if (!is_dir($tmp) && !@mkdir($tmp, 0775, true) && !is_dir($tmp)) {
        throw new RuntimeException('Nem sikerült létrehozni az mPDF temp könyvtárat: ' . $tmp);
    }

    return new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'default_font' => 'dejavusans',
        'tempDir' => $tmp,
        'margin_top' => 28,
        'margin_bottom' => 20,
        'margin_left' => 12,
        'margin_right' => 12,
    ]);
}

function tracker_pdf_logo_abs(): ?string {
    $candidates = [
        __DIR__ . '/../public/assets/perfect-phone-logo.png',
        __DIR__ . '/../public/assets/perfect-phone-logo.jpg',
        '/var/www/html/warehousemgr/public/assets/perfect-phone-logo.png',
        '/var/www/html/warehousemgr/public/assets/perfect-phone-logo.jpg',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function tracker_pdf_image_tag(?string $src, int $maxWidthMm = 42, int $maxHeightMm = 18, string $class = 'logoimg'): string {
    $src = trim((string)$src);
    if ($src === '' || !is_file($src)) {
        return '';
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
    $b64 = base64_encode((string)file_get_contents($src));
    return '<img class="' . $class . '" style="max-width:' . $maxWidthMm . 'mm;max-height:' . $maxHeightMm . 'mm;" src="data:' . $mime . ';base64,' . $b64 . '">';
}

function tracker_report_pdf_template_path(): string {
    return __DIR__ . '/../templates/pdf/report.html';
}

function tracker_render_template(string $tplAbs, array $vars): string {
    if (!is_file($tplAbs)) {
        throw new RuntimeException('PDF sablon nem található: ' . $tplAbs);
    }
    $html = (string)file_get_contents($tplAbs);
    foreach ($vars as $k => $v) {
        $html = str_replace('{{' . $k . '}}', (string)$v, $html);
    }
    return (string)preg_replace('/\{\{[A-Z0-9_]+\}\}/', '', $html);
}

function tracker_report_pdf_html(array $groups, array $overall, string $dateFrom, string $dateTo): string {
    $sections = '';
    $groupCount = count($groups);
    $groupIndex = 0;

    foreach ($groups as $group) {
        $groupIndex++;
        $weekMarkers = tracker_report_week_markers($group['days']);
        $dayRows = '';
        foreach ($group['days'] as $day) {
            $date = (string)$day['entry_date'];
            if (isset($weekMarkers[$date])) {
                $dayRows .= '<tr class="week-marker"><td colspan="5">' . h($weekMarkers[$date]) . '</td></tr>';
            }
            $rowKind = (string)($day['row_kind'] ?? 'work');
            $isAbsence = ($rowKind === 'absence');
            $isMissing = ($rowKind === 'missing');
            $dayRows .= '<tr>'
                . '<td>' . h($date) . '</td>'
                . '<td>' . h($isAbsence ? ((string)($day['type_label'] ?: 'Távollét')) : ($isMissing ? 'Nincs rögzítve' : 'Munkaidő')) . '</td>'
                . '<td style="text-align:center;">' . h(tracker_report_day_detail($day)) . '</td>'
                . '<td style="text-align:right;">' . h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['travel_minutes'] ?? 0))) . '</td>'
                . '<td style="text-align:right;">' . h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)($day['work_only_minutes'] ?? max(0,(int)$day['total_minutes']-(int)($day['travel_minutes'] ?? 0))))) . '</td>'
                . '<td style="text-align:right;">' . h(($isAbsence || $isMissing) ? '—' : tracker_minutes_to_hhmm((int)$day['total_minutes'])) . '</td>'
                . '<td>' . h((string)($day['note'] ?? '')) . '</td>'
                . '</tr>';
        }

        $weeklyRows = '';
        foreach ($group['weekly'] as $weekKey => $minutes) {
            $expected = (int)($group['weekly_expected'][$weekKey] ?? 0);
            $percent = tracker_percent_label($group['weekly_percent'][$weekKey] ?? null);
            $weeklyRows .= '<tr><td>' . h(tracker_hu_week_label_from_key((string)$weekKey)) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)($group['weekly_travel'][$weekKey] ?? 0))) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)($group['weekly_work_only'][$weekKey] ?? max(0, (int)$minutes - (int)($group['weekly_travel'][$weekKey] ?? 0))))) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)$minutes)) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact($expected)) . '</td><td style="text-align:right;">' . h($percent) . '</td></tr>';
        }

        $monthlyRows = '';
        foreach ($group['monthly'] as $monthKey => $minutes) {
            $expected = (int)($group['monthly_expected'][$monthKey] ?? 0);
            $percent = tracker_percent_label($group['monthly_percent'][$monthKey] ?? null);
            $monthlyRows .= '<tr><td>' . h(tracker_hu_month_label_from_key((string)$monthKey)) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)($group['monthly_travel'][$monthKey] ?? 0))) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)($group['monthly_work_only'][$monthKey] ?? max(0, (int)$minutes - (int)($group['monthly_travel'][$monthKey] ?? 0))))) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact((int)$minutes)) . '</td><td style="text-align:right;">' . h(tracker_minutes_to_hours_compact($expected)) . '</td><td style="text-align:right;">' . h($percent) . '</td></tr>';
        }

        $absenceRows = '';
        $summary['travel_minutes'] += (int)($group['travel_minutes'] ?? 0);
        $summary['travel_km'] += (float)($group['travel_km'] ?? 0);
        $summary['work_only_minutes'] = (int)($summary['work_only_minutes'] ?? 0) + (int)($group['work_only_minutes'] ?? 0);
        foreach (($group['absence_by_type'] ?? []) as $label => $count) {
            $absenceRows .= '<tr><td>' . h((string)$label) . '</td><td style="text-align:right;">' . (int)$count . ' nap</td></tr>';
        }
        if ($absenceRows === '') {
            $absenceRows = '<tr><td colspan="2" style="color:#6b7280;">Nincs távollét a kiválasztott időszakban.</td></tr>';
        }

        $classes = 'employee-block';
        if ($groupIndex < $groupCount) {
            $classes .= ' page-break';
        }

        $sections .= '<div class="' . $classes . '">'
            . '<div class="employee-head">'
            . '<div class="employee-name">' . h($group['employee_name']) . '</div>'
            . '<div class="employee-meta">Utazás: ' . h(tracker_minutes_to_hhmm((int)$group['weekly_average_minutes'])) . ' · Havi átlag: ' . h(tracker_minutes_to_hhmm((int)$group['monthly_average_minutes'])) . ' · Időszak összesen: ' . h(tracker_minutes_to_hhmm((int)$group['total_minutes'])) . ' · Távollét: ' . (int)($group['absence_days'] ?? 0) . ' nap</div>'
            . '</div>'
            . '<table class="tbl"><thead><tr><th>Dátum</th><th>Jelleg</th><th>Részlet</th><th>Utazás</th><th>Munka</th><th>Összesen</th><th>Megjegyzés</th></tr></thead><tbody>' . $dayRows . '</tbody></table>'
            . '<div class="split">'
            . '<div><div class="subhead">Heti összesítés</div><table class="tbl compact"><thead><tr><th>Hét</th><th>Utazás</th><th>Munka</th><th>Összesen</th><th>Elvárt</th><th>%</th></tr></thead><tbody>' . $weeklyRows . '</tbody></table></div>'
            . '<div><div class="subhead">Havi összesítés</div><table class="tbl compact" style="margin-bottom:2.5mm;"><thead><tr><th>Hónap</th><th>Utazás</th><th>Munka</th><th>Összesen</th><th>Elvárt</th><th>%</th></tr></thead><tbody>' . $monthlyRows . '</tbody></table><div class="subhead">Távollét összesítés</div><table class="tbl compact"><thead><tr><th>Típus</th><th>Napok száma</th></tr></thead><tbody>' . $absenceRows . '</tbody></table></div>'
            . '</div>'
            . '</div>';
    }

    $summaryBlock = '';
    if (count($groups) === 1) {
        $summaryBlock = '<div class="summary"><table><tr>'
            . '<td><div class="label">Dolgozók</div><div class="value">' . (int)$overall['employee_count'] . '</div></td>'
            . '<td><div class="label">Bejegyzések</div><div class="value">' . (int)$overall['entry_count'] . '</div></td>'
            . '<td><div class="label">Időszak összesen</div><div class="value">' . h(tracker_minutes_to_hhmm((int)$overall['total_minutes'])) . '</div></td>'
            . '</tr></table></div>';
    }

    return tracker_render_template(tracker_report_pdf_template_path(), [
        'DATE_FROM' => h($dateFrom),
        'DATE_TO' => h($dateTo),
        'SUMMARY_BLOCK' => $summaryBlock,
        'SECTIONS' => $sections,
    ]);
}

function tracker_report_pdf_bytes(array $groups, array $overall, string $dateFrom, string $dateTo): string {
    $mpdf = tracker_pdf_make_mpdf();
    if ($mpdf !== null) {
        $html = tracker_report_pdf_html($groups, $overall, $dateFrom, $dateTo);
        $headerHtml = '<div style="width:100%;font-size:9pt;color:#4b5563;border-bottom:1px solid #d1d5db;padding-bottom:3mm;">'
            . '<table width="100%" style="border-collapse:collapse;"><tr>'
            . '<td style="width:40%;vertical-align:middle;">' . tracker_pdf_image_tag(tracker_pdf_logo_abs(), 34, 14, 'logoimg') . '</td>'
            . '<td style="width:60%;text-align:right;vertical-align:middle;"><div style="font-weight:700;font-size:11.5pt;color:#111827;">Munkaidő nyilvántartás</div>'
            . '<div>Időszak: ' . h($dateFrom) . ' – ' . h($dateTo) . '</div></td>'
            . '</tr></table></div>';
        $footerHtml = '<div style="width:100%;font-size:8pt;color:#6b7280;border-top:1px solid #d1d5db;padding-top:2mm;">'
            . '<table width="100%"><tr><td>' . h('(c) Perfect-Phone 2026 - Elektronikus úton készült dokumentum - Munkaidő nyilvántartás') . '</td><td style="text-align:right;">{PAGENO}</td></tr></table>'
            . '</div>';
        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }

    $lines = [];
    $lines[] = 'Munkaido riport';
    $lines[] = 'Idoszak: ' . $dateFrom . ' - ' . $dateTo;
    $lines[] = 'Dolgozok: ' . $overall['employee_count'] . ' | Bejegyzesek: ' . $overall['entry_count'] . ' | Osszes ido: ' . tracker_minutes_to_hhmm((int)$overall['total_minutes']);
    $lines[] = str_repeat('=', 100);
    foreach ($groups as $group) {
        $lines[] = $group['employee_name'];
        $lines[] = 'Idoszak osszesen: ' . tracker_minutes_to_hhmm((int)$group['total_minutes']) . ' | Heti atlag: ' . tracker_minutes_to_hhmm((int)$group['weekly_average_minutes']) . ' | Havi atlag: ' . tracker_minutes_to_hhmm((int)$group['monthly_average_minutes']);
        foreach (tracker_report_week_markers($group['days']) as $date => $label) {
            // markers referenced in loop below
        }
        $weekMarkers = tracker_report_week_markers($group['days']);
        $lines[] = sprintf('%-12s | %-11s | %-8s', 'Datum', 'Munkaido', 'Db');
        $lines[] = str_repeat('-', 42);
        foreach ($group['days'] as $day) {
            if (isset($weekMarkers[$day['entry_date']])) {
                $lines[] = '[' . $weekMarkers[$day['entry_date']] . ']';
            }
            $lines[] = sprintf('%-12s | %-11s | %-8d', $day['entry_date'], tracker_minutes_to_hhmm((int)$day['total_minutes']), (int)$day['entry_count']);
        }
        if ($group['weekly']) {
            $lines[] = 'Heti osszesites:';
            foreach ($group['weekly'] as $weekKey => $minutes) {
                $lines[] = '  ' . tracker_hu_week_label_from_key((string)$weekKey) . ': ' . tracker_minutes_to_hhmm((int)$minutes);
            }
        }
        if ($group['monthly']) {
            $lines[] = 'Havi osszesites:';
            foreach ($group['monthly'] as $monthKey => $minutes) {
                $lines[] = '  ' . tracker_hu_month_label_from_key((string)$monthKey) . ': ' . tracker_minutes_to_hhmm((int)$minutes);
            }
        }
        $lines[] = str_repeat('=', 100);
    }
    return tracker_simple_pdf_from_lines($lines, 'Munkaido riport');
}

function tracker_send_email_with_attachment(string $to, string $subject, string $body, string $filename, string $content, string $mime = 'application/pdf'): bool {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Érvényes e-mail cím szükséges.');
    }

    if (function_exists('tracker_send_html_mail_with_attachment')) {
        $tmpDir = __DIR__ . '/../storage/mail';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Nem sikerült létrehozni a levél melléklet temp könyvtárat: ' . $tmpDir);
        }

        $safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'melleklet.pdf';
        $tmpFile = $tmpDir . '/' . uniqid('att_', true) . '_' . $safeFilename;
        if (@file_put_contents($tmpFile, $content) === false) {
            throw new RuntimeException('Nem sikerült létrehozni az ideiglenes melléklet fájlt.');
        }

        $htmlBody = '<div style="font-family:Arial,sans-serif; color:#111827; font-size:14px; line-height:1.6;">'
            . '<div style="margin-bottom:12px;">'
            . '<img src="cid:companylogo" alt="Perfect-Phone" style="width:120px; height:auto; display:block;">'
            . '</div>'
            . '<div style="margin:0 0 12px; font-size:18px; font-weight:700;">Munkaidő riport</div>'
            . '<div style="margin:0 0 12px;">' . nl2br(h($body)) . '</div>'
            . '<div style="margin-top:18px; font-size:12px; color:#6b7280;">(c) Perfect-Phone 2026 - Elektronikus úton készült dokumentum - Munkaidő nyilvántartás</div>'
            . '</div>';

        try {
            tracker_send_html_mail_with_attachment($to, $subject, $htmlBody, $body, $tmpFile, $safeFilename);
        } finally {
            @unlink($tmpFile);
        }
        return true;
    }

    $boundary = 'tt_' . bin2hex(random_bytes(12));
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $headers[] = 'From: noreply@localhost';

    $message  = "--{$boundary}
";
    $message .= "Content-Type: text/plain; charset=UTF-8
";
    $message .= "Content-Transfer-Encoding: 8bit

";
    $message .= $body . "

";
    $message .= "--{$boundary}
";
    $message .= "Content-Type: {$mime}; name=\"{$filename}\"
";
    $message .= "Content-Transfer-Encoding: base64
";
    $message .= "Content-Disposition: attachment; filename=\"{$filename}\"

";
    $message .= chunk_split(base64_encode($content));
    $message .= "
--{$boundary}--
";

    return mail($to, $subject, $message, implode("
", $headers));
}


function tracker_is_group_leader(array $config): bool {
    return !empty(tracker_group_leader_group_ids($config));
}

function tracker_group_leader_group_ids(array $config, ?int $userId = null): array {
    $uid = $userId ?? tracker_current_auth_user_id();
    if ($uid <= 0) return [];
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT group_id FROM tt_group_leaders WHERE user_id = ?');
    $st->execute([$uid]);
    return array_map('intval', array_column($st->fetchAll(), 'group_id'));
}

function tracker_group_member_employee_ids(array $config, ?int $userId = null): array {
    $groupIds = tracker_group_leader_group_ids($config, $userId);
    if (!$groupIds) return [];
    $pdo = tracker_app_pdo($config);
    $in = implode(',', array_fill(0, count($groupIds), '?'));
    $st = $pdo->prepare("SELECT DISTINCT employee_id FROM tt_group_members WHERE group_id IN ($in)");
    $st->execute($groupIds);
    return array_map('intval', array_column($st->fetchAll(), 'employee_id'));
}

function tracker_can_view_employee(array $config, array $user, int $employeeId): bool {
    if ($employeeId <= 0) return false;
    if (tracker_is_admin($config)) return true;
    if ((int)($user['hr_employee_id'] ?? 0) === $employeeId) return true;
    return in_array($employeeId, tracker_group_member_employee_ids($config), true);
}
