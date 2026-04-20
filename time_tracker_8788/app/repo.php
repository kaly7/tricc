<?php
declare(strict_types=1);

function tracker_entry_types(array $config): array {
    $pdo = tracker_app_pdo($config);
    return $pdo->query('SELECT * FROM tt_entry_types WHERE is_active = 1 ORDER BY sort_order, label')->fetchAll();
}

function tracker_entries_between(array $config, int $employeeId, string $dateFrom, string $dateTo): array {
    $pdo = tracker_app_pdo($config);
    $fetchFrom = (new DateTimeImmutable($dateFrom))->modify('-1 day')->format('Y-m-d');
    $st = $pdo->prepare('SELECT e.*, t.label AS type_label, t.color_class FROM tt_entries e LEFT JOIN tt_entry_types t ON t.code=e.entry_kind WHERE e.employee_id = ? AND e.entry_date BETWEEN ? AND ? AND e.deleted_at IS NULL ORDER BY e.entry_date, e.start_time, e.id');
    $st->execute([$employeeId, $fetchFrom, $dateTo]);
    return $st->fetchAll();
}

function tracker_day_entries(array $config, int $employeeId, string $date): array {
    return tracker_entries_between($config, $employeeId, $date, $date);
}

function tracker_day_has_entries(array $config, int $employeeId, string $date): bool {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT id FROM tt_entries WHERE employee_id = ? AND entry_date = ? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$employeeId, $date]);
    return (bool)$st->fetchColumn();
}

function tracker_absence_types(array $config, bool $activeOnly = true): array {
    $pdo = tracker_app_pdo($config);
    $sql = 'SELECT * FROM tt_absence_types';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, label';
    return $pdo->query($sql)->fetchAll();
}

function tracker_absence_type_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_absence_types WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_day_absence_find(array $config, int $employeeId, string $date): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT a.*, t.code AS type_code, t.label AS type_label, t.badge_text, t.bg_color, t.text_color FROM tt_day_absences a INNER JOIN tt_absence_types t ON t.id = a.absence_type_id WHERE a.employee_id = ? AND a.absence_date = ? AND a.deleted_at IS NULL LIMIT 1');
    $st->execute([$employeeId, $date]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_day_absences_between(array $config, int $employeeId, string $dateFrom, string $dateTo): array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT a.*, t.code AS type_code, t.label AS type_label, t.badge_text, t.bg_color, t.text_color FROM tt_day_absences a INNER JOIN tt_absence_types t ON t.id = a.absence_type_id WHERE a.employee_id = ? AND a.absence_date BETWEEN ? AND ? AND a.deleted_at IS NULL ORDER BY a.absence_date, a.id');
    $st->execute([$employeeId, $dateFrom, $dateTo]);
    return $st->fetchAll();
}

function tracker_day_absence_find_by_id(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT a.*, t.code AS type_code, t.label AS type_label, t.badge_text, t.bg_color, t.text_color FROM tt_day_absences a INNER JOIN tt_absence_types t ON t.id = a.absence_type_id WHERE a.id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_save_day_absence(array $config, array $user, array $data): int {
    $pdo = tracker_app_pdo($config);
    $employeeId = tracker_is_admin($config) && !empty($data['employee_id']) ? (int)$data['employee_id'] : (int)$user['hr_employee_id'];
    $date = (string)($data['absence_date'] ?? '');
    $absenceTypeId = (int)($data['absence_type_id'] ?? 0);
    $note = trim((string)($data['note'] ?? ''));
    if ($employeeId <= 0) {
        throw new RuntimeException('Nincs HR dolgozó hozzárendelve.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('A távollét dátuma kötelező.');
    }
    if ($absenceTypeId <= 0) {
        throw new InvalidArgumentException('A távollét típusa kötelező.');
    }
    if (tracker_is_locked($config, $date)) {
        throw new RuntimeException('A kiválasztott időszak zárolt.');
    }
    if (tracker_day_has_entries($config, $employeeId, $date)) {
        throw new RuntimeException('Erre a napra már van időalapú bejegyzés, ezért egész napos távollét nem rögzíthető.');
    }
    if (tracker_day_absence_find($config, $employeeId, $date)) {
        throw new RuntimeException('Erre a napra már van rögzített egész napos távollét.');
    }
    $st = $pdo->prepare('INSERT INTO tt_day_absences (employee_id, absence_type_id, absence_date, note, created_by_user_id, updated_by_user_id) VALUES (?,?,?,?,?,?)');
    $st->execute([$employeeId, $absenceTypeId, $date, $note !== '' ? $note : null, (int)$user['id'], (int)$user['id']]);
    $newId = (int)$pdo->lastInsertId();
    $after = tracker_day_absence_find_by_id($config, $newId);
    tracker_audit_log($config, 'absence.create', 'tt_day_absences', $newId, $employeeId, null, $after);
    return $newId;
}

function tracker_delete_day_absence(array $config, array $user, int $id): void {
    $pdo = tracker_app_pdo($config);
    $row = tracker_day_absence_find_by_id($config, $id);
    if (!$row || !empty($row['deleted_at'])) {
        return;
    }
    if (!tracker_is_admin($config) && (int)$row['employee_id'] !== (int)$user['hr_employee_id']) {
        throw new RuntimeException('Nincs jogosultság a távollét törlésére.');
    }
    if (tracker_is_locked($config, (string)$row['absence_date'])) {
        throw new RuntimeException('A kiválasztott időszak zárolt.');
    }
    $st = $pdo->prepare('UPDATE tt_day_absences SET deleted_at = NOW(), updated_by_user_id = ? WHERE id = ?');
    $st->execute([(int)$user['id'], $id]);
    tracker_audit_log($config, 'absence.delete', 'tt_day_absences', $id, (int)$row['employee_id'], $row, null);
}

function tracker_holidays_between(array $config, string $dateFrom, string $dateTo): array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_holidays WHERE is_active = 1 AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date');
    $st->execute([$dateFrom, $dateTo]);
    return $st->fetchAll();
}

function tracker_holidays_all(array $config): array {
    $pdo = tracker_app_pdo($config);
    return $pdo->query('SELECT * FROM tt_holidays ORDER BY holiday_date DESC, id DESC')->fetchAll();
}

function tracker_holiday_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_holidays WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_save_holiday(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $date = (string)($data['holiday_date'] ?? '');
    $label = trim((string)($data['label'] ?? ''));
    $badge = trim((string)($data['badge_text'] ?? ''));
    $bg = trim((string)($data['bg_color'] ?? '#fee2e2'));
    $textColor = trim((string)($data['text_color'] ?? '#991b1b'));
    $isActive = !empty($data['is_active']) ? 1 : 0;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Az ünnepnap dátuma kötelező.');
    }
    if ($label === '') {
        throw new InvalidArgumentException('Az ünnepnap megnevezése kötelező.');
    }
    if ($badge === '') {
        $badge = 'ÜN';
    }
    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        $st = $pdo->prepare('UPDATE tt_holidays SET holiday_date=?, label=?, badge_text=?, bg_color=?, text_color=?, is_active=? WHERE id=?');
        $st->execute([$date, $label, $badge, $bg, $textColor, $isActive, $id]);
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO tt_holidays (holiday_date, label, badge_text, bg_color, text_color, is_active) VALUES (?,?,?,?,?,?)');
    $st->execute([$date, $label, $badge, $bg, $textColor, $isActive]);
    return (int)$pdo->lastInsertId();
}

function tracker_delete_holiday(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('DELETE FROM tt_holidays WHERE id = ?');
    $st->execute([$id]);
}

function tracker_save_absence_type(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $code = trim((string)($data['code'] ?? ''));
    $label = trim((string)($data['label'] ?? ''));
    $badge = trim((string)($data['badge_text'] ?? ''));
    $bg = trim((string)($data['bg_color'] ?? '#dcfce7'));
    $textColor = trim((string)($data['text_color'] ?? '#166534'));
    $sort = (int)($data['sort_order'] ?? 100);
    $active = !empty($data['is_active']) ? 1 : 0;
    if ($code === '') {
        throw new InvalidArgumentException('A kód kötelező.');
    }
    if ($label === '') {
        throw new InvalidArgumentException('A megnevezés kötelező.');
    }
    if ($badge === '') {
        $badge = mb_strtoupper(mb_substr($label, 0, 3));
    }
    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        $st = $pdo->prepare('UPDATE tt_absence_types SET code=?, label=?, badge_text=?, bg_color=?, text_color=?, sort_order=?, is_active=? WHERE id=?');
        $st->execute([$code, $label, $badge, $bg, $textColor, $sort, $active, $id]);
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO tt_absence_types (code, label, badge_text, bg_color, text_color, sort_order, is_active) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$code, $label, $badge, $bg, $textColor, $sort, $active]);
    return (int)$pdo->lastInsertId();
}

function tracker_delete_absence_type(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('DELETE FROM tt_absence_types WHERE id = ?');
    $st->execute([$id]);
}

function tracker_entry_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_entries WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_is_locked(array $config, string $date): bool {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT id FROM tt_locks WHERE revoked_at IS NULL AND ? BETWEEN date_from AND date_to LIMIT 1');
    $st->execute([$date]);
    return (bool)$st->fetchColumn();
}

function tracker_active_locks(array $config, int $limit = 20): array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_locks WHERE revoked_at IS NULL ORDER BY date_from DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

function tracker_audit_log(array $config, string $action, string $entityType, ?int $entityId, ?int $targetEmployeeId, ?array $before = null, ?array $after = null, ?string $note = null): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('INSERT INTO tt_audit_log (actor_user_id, target_employee_id, action_key, entity_type, entity_id, before_json, after_json, note, ip_address) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->execute([
        tracker_current_auth_user_id(),
        $targetEmployeeId,
        $action,
        $entityType,
        $entityId,
        $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        $note,
        tracker_request_ip(),
    ]);
}

function tracker_collect_target_employee_ids(array $data, int $primaryEmployeeId): array {
    $ids = [$primaryEmployeeId];
    $coworkers = $data['coworker_employee_ids'] ?? [];
    if (!is_array($coworkers)) {
        $coworkers = [$coworkers];
    }
    foreach ($coworkers as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return array_values(array_filter($ids, static fn($v) => $v > 0));
}

function tracker_employee_label(array $config, int $employeeId): string {
    $map = tracker_employee_name_map($config);
    return (string)($map[$employeeId] ?? ('#' . $employeeId));
}

function tracker_entry_overlap(array $config, int $employeeId, string $date, ?string $start, ?string $end, ?int $excludeId = null): ?array {
    if (!$start || !$end) {
        return null;
    }
    [$newStart, $newEnd] = tracker_time_span_details($start, $end);
    $pdo = tracker_app_pdo($config);
    $dateObj = new DateTimeImmutable($date);
    $prevDate = $dateObj->modify('-1 day')->format('Y-m-d');
    $nextDate = $dateObj->modify('+1 day')->format('Y-m-d');
    $sql = 'SELECT id, entry_date, start_time, end_time, entry_kind, note, crosses_midnight FROM tt_entries WHERE employee_id = ? AND entry_date BETWEEN ? AND ? AND deleted_at IS NULL AND start_time IS NOT NULL AND end_time IS NOT NULL';
    $params = [$employeeId, $prevDate, $nextDate];
    if ($excludeId) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY entry_date, start_time, id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    while ($row = $st->fetch()) {
        $rowDate = (string)$row['entry_date'];
        [$rowStart, $rowEnd, $rowCross] = tracker_time_span_details(substr((string)$row['start_time'],0,5), substr((string)$row['end_time'],0,5));
        if ($rowDate === $prevDate) {
            if (!$rowCross) continue;
            $rowStart -= 1440;
            $rowEnd -= 1440;
        } elseif ($rowDate === $date) {
            // as is
        } elseif ($rowDate === $nextDate) {
            $rowStart += 1440;
            $rowEnd += 1440;
        } else {
            continue;
        }
        if ($newStart < $rowEnd && $newEnd > $rowStart) {
            return $row;
        }
    }
    return null;
}

function tracker_save_entry(array $config, array $user, array $data, ?int $id = null): array {
    $pdo = tracker_app_pdo($config);
    $date = (string)($data['entry_date'] ?? '');
    if ($date === '') {
        throw new InvalidArgumentException('A dátum megadása kötelező.');
    }

    $employeeId = tracker_is_admin($config) && !empty($data['employee_id']) ? (int)$data['employee_id'] : (int)$user['hr_employee_id'];
    if ($employeeId <= 0) {
        throw new RuntimeException('Nincs HR dolgozó hozzárendelve.');
    }

    $start = trim((string)($data['start_time'] ?? ''));
    if ($start === '') {
        $start = (string)(tracker_time_from_parts($data['start_hour'] ?? null, $data['start_minute'] ?? null) ?? '');
    }
    $end = trim((string)($data['end_time'] ?? ''));
    if ($end === '') {
        $end = (string)(tracker_time_from_parts($data['end_hour'] ?? null, $data['end_minute'] ?? null) ?? '');
    }
    $break = max(0, (int)($data['break_minutes'] ?? 0));
    $kind = trim((string)($data['entry_kind'] ?? 'work')) ?: 'work';
    $note = trim((string)($data['note'] ?? ''));
    $timeMode = (string)($data['time_mode'] ?? 'end');
    $vehicleId = !empty($data['vehicle_id']) ? (int)$data['vehicle_id'] : null;
    $travelKm = isset($data['travel_km']) && $data['travel_km'] !== '' ? (float)str_replace(',', '.', (string)$data['travel_km']) : null;
    if ($travelKm !== null && $travelKm < 0) {
        throw new InvalidArgumentException('A km nem lehet negatív.');
    }
    $vehicle = $vehicleId ? tracker_vehicle_find($config, $vehicleId) : null;
    if ($vehicleId && !$vehicle) {
        throw new InvalidArgumentException('A kiválasztott rendszám nem található.');
    }
    $vehiclePlate = $vehicle ? (string)$vehicle['plate_number'] : null;
    $travelMinutes = tracker_calculate_travel_minutes($travelKm, $vehicle ? (float)$vehicle['avg_speed_kmh'] : null);

    if ($kind === 'work' || $kind === 'home_office') {
        if ($start === '') {
            throw new InvalidArgumentException('A kezdési idő megadása kötelező.');
        }
        if ($timeMode === 'duration') {
            $durationHours = max(0, (int)($data['duration_hours'] ?? 0));
            $durationMinutes = max(0, (int)($data['duration_minutes'] ?? 0));
            $durationTotal = ($durationHours * 60) + $durationMinutes;
            if ($durationTotal <= 0) {
                throw new InvalidArgumentException('Az időtartam megadása kötelező.');
            }
            $end = tracker_add_minutes_to_time($start, $durationTotal + $break);
        }
        if ($end === '') {
            throw new InvalidArgumentException('A befejezési idő megadása kötelező.');
        }
        $mins = tracker_compute_minutes($start, $end, $break);
        $crossesMidnight = tracker_time_span_details($start, $end)[2];
    } else {
        $start = $start !== '' ? $start : null;
        $end = $end !== '' ? $end : null;
        $mins = tracker_compute_minutes_optional($start, $end, $break);
        $crossesMidnight = ($start && $end) ? tracker_time_span_details($start, $end)[2] : false;
    }

    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        if (($kind === 'work' || $kind === 'home_office') && tracker_day_absence_find($config, $employeeId, $date)) {
            throw new RuntimeException('Erre a napra egész napos távollét van rögzítve.');
        }
        $before = tracker_entry_find($config, $id);
        if (!$before) throw new RuntimeException('A rekord nem található.');
        if (!tracker_is_admin($config) && (int)$before['employee_id'] !== (int)$user['hr_employee_id']) {
            throw new RuntimeException('Nincs jogosultság a rekord módosítására.');
        }
        if (tracker_is_locked($config, $date)) {
            throw new RuntimeException('A kiválasztott időszak zárolt.');
        }
        $overlap = tracker_entry_overlap($config, $employeeId, $date, $start, $end, $id);
        if ($overlap) {
            throw new RuntimeException('Az időszak ütközik egy meglévő bejegyzéssel (' . substr((string)$overlap['start_time'], 0, 5) . '–' . substr((string)$overlap['end_time'], 0, 5) . ').');
        }
        $st = $pdo->prepare('UPDATE tt_entries SET employee_id=?, entry_date=?, entry_kind=?, start_time=?, end_time=?, break_minutes=?, work_minutes=?, note=?, vehicle_id=?, vehicle_plate=?, travel_km=?, travel_minutes=?, crosses_midnight=?, updated_by_user_id=? WHERE id=?');
        $st->execute([$employeeId, $date, $kind, $start ?: null, $end ?: null, $break, $mins, $note !== '' ? $note : null, $vehicleId, $vehiclePlate, $travelKm, $travelMinutes, $crossesMidnight ? 1 : 0, (int)$user['id'], $id]);
        $after = tracker_entry_find($config, $id);
        tracker_audit_log($config, 'entry.update', 'tt_entries', $id, $employeeId, $before, $after);
        return ['mode' => 'update', 'primary_id' => $id, 'created_for' => [$employeeId], 'skipped' => []];
    }

    $groupUid = tracker_uuidv4();
    $targets = tracker_collect_target_employee_ids($data, $employeeId);
    $createdFor = [];
    $skipped = [];
    $primaryId = null;

    foreach ($targets as $targetEmployeeId) {
        if (($kind === 'work' || $kind === 'home_office') && tracker_day_absence_find($config, $targetEmployeeId, $date)) {
            $message = 'Erre a napra egész napos távollét van rögzítve.';
            if ($targetEmployeeId === $employeeId) {
                throw new RuntimeException($message);
            }
            $skipped[] = ['employee_id' => $targetEmployeeId, 'label' => tracker_employee_label($config, $targetEmployeeId), 'reason' => $message];
            continue;
        }
        if (tracker_is_locked($config, $date)) {
            $message = 'A nap zárolt.';
            if ($targetEmployeeId === $employeeId) {
                throw new RuntimeException('A kiválasztott időszak zárolt.');
            }
            $skipped[] = ['employee_id' => $targetEmployeeId, 'label' => tracker_employee_label($config, $targetEmployeeId), 'reason' => $message];
            continue;
        }
        $overlap = tracker_entry_overlap($config, $targetEmployeeId, $date, $start, $end, null);
        if ($overlap) {
            $message = 'Az időszak már foglalt (' . substr((string)$overlap['start_time'], 0, 5) . '–' . substr((string)$overlap['end_time'], 0, 5) . ').';
            if ($targetEmployeeId === $employeeId) {
                throw new RuntimeException('Az időszak ütközik egy meglévő bejegyzéssel (' . substr((string)$overlap['start_time'], 0, 5) . '–' . substr((string)$overlap['end_time'], 0, 5) . ').');
            }
            $skipped[] = ['employee_id' => $targetEmployeeId, 'label' => tracker_employee_label($config, $targetEmployeeId), 'reason' => $message];
            continue;
        }

        $st = $pdo->prepare('INSERT INTO tt_entries (employee_id, entry_date, entry_kind, start_time, end_time, break_minutes, work_minutes, note, vehicle_id, vehicle_plate, travel_km, travel_minutes, crosses_midnight, created_by_user_id, updated_by_user_id, group_uid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$targetEmployeeId, $date, $kind, $start ?: null, $end ?: null, $break, $mins, $note !== '' ? $note : null, $vehicleId, $vehiclePlate, $travelKm, $travelMinutes, $crossesMidnight ? 1 : 0, (int)$user['id'], (int)$user['id'], count($targets) > 1 ? $groupUid : null]);
        $newId = (int)$pdo->lastInsertId();
        if ($targetEmployeeId === $employeeId) {
            $primaryId = $newId;
        }
        $createdFor[] = $targetEmployeeId;
        $after = tracker_entry_find($config, $newId);
        tracker_audit_log($config, 'entry.create', 'tt_entries', $newId, $targetEmployeeId, null, $after, count($targets) > 1 ? 'Közös rögzítés: ' . $groupUid : null);
    }

    if (!$primaryId) {
        throw new RuntimeException('A saját bejegyzés nem jött létre.');
    }

    return ['mode' => 'create', 'primary_id' => $primaryId, 'created_for' => $createdFor, 'skipped' => $skipped, 'group_uid' => count($targets) > 1 ? $groupUid : null];
}

function tracker_delete_entry(array $config, array $user, int $id): void {
    $pdo = tracker_app_pdo($config);
    $entry = tracker_entry_find($config, $id);
    if (!$entry || $entry['deleted_at']) {
        return;
    }
    if (tracker_is_locked($config, (string)$entry['entry_date'])) {
        throw new RuntimeException('A kiválasztott időszak zárolt.');
    }
    if (!tracker_is_admin($config) && (int)$entry['employee_id'] !== (int)$user['hr_employee_id']) {
        throw new RuntimeException('Nincs jogosultság a rekord törlésére.');
    }
    $st = $pdo->prepare('UPDATE tt_entries SET deleted_at = NOW(), updated_by_user_id = ? WHERE id = ?');
    $st->execute([(int)$user['id'], $id]);
    tracker_audit_log($config, 'entry.delete', 'tt_entries', $id, (int)$entry['employee_id'], $entry, null);
}

function tracker_compute_minutes(string $start, string $end, int $break): int {
    [$startMinutes, $endMinutes] = tracker_time_span_details($start, $end);
    $mins = (int)($endMinutes - $startMinutes) - $break;
    if ($mins < 0) {
        throw new InvalidArgumentException('A munkaidő nem lehet negatív.');
    }
    return $mins;
}

function tracker_compute_minutes_optional(?string $start, ?string $end, int $break): int {
    if ($start && $end) return tracker_compute_minutes($start, $end, $break);
    return 0;
}

function tracker_add_minutes_to_time(string $start, int $minutes): string {
    $dt = DateTimeImmutable::createFromFormat('H:i', $start);
    if (!$dt) {
        throw new InvalidArgumentException('Érvénytelen kezdési idő.');
    }
    $dt = $dt->modify('+' . $minutes . ' minutes');
    return $dt->format('H:i');
}

function tracker_move_entry_to_date(array $config, array $user, int $id, string $newDate): void {
    $entry = tracker_entry_find($config, $id);
    if (!$entry || !empty($entry['deleted_at'])) {
        throw new RuntimeException('A rekord nem található.');
    }
    if (!tracker_is_admin($config) && (int)$entry['employee_id'] !== (int)$user['hr_employee_id']) {
        throw new RuntimeException('Nincs jogosultság a rekord módosítására.');
    }
    if (tracker_is_locked($config, $newDate)) {
        throw new RuntimeException('Az új dátum zárolt.');
    }
    $overlap = tracker_entry_overlap($config, (int)$entry['employee_id'], $newDate, (string)$entry['start_time'], (string)$entry['end_time'], (int)$entry['id']);
    if ($overlap) {
        throw new RuntimeException('Az új napon az idősáv már foglalt.');
    }
    $pdo = tracker_app_pdo($config);
    $before = $entry;
    $st = $pdo->prepare('UPDATE tt_entries SET entry_date = ?, updated_by_user_id = ? WHERE id = ?');
    $st->execute([$newDate, (int)$user['id'], $id]);
    $after = tracker_entry_find($config, $id);
    tracker_audit_log($config, 'entry.drag_move', 'tt_entries', $id, (int)$entry['employee_id'], $before, $after, 'Naptár húzással áthelyezve');
}

function tracker_lock_period(array $config, string $dateFrom, string $dateTo, string $reason = ''): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('INSERT INTO tt_locks (date_from, date_to, locked_by_user_id, reason) VALUES (?,?,?,?)');
    $st->execute([$dateFrom, $dateTo, tracker_current_auth_user_id(), $reason !== '' ? $reason : null]);
    $id = (int)$pdo->lastInsertId();
    tracker_audit_log($config, 'lock.create', 'tt_locks', $id, null, null, ['date_from' => $dateFrom, 'date_to' => $dateTo, 'reason' => $reason]);
}

function tracker_unlock_period(array $config, int $lockId, string $reason = ''): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_locks WHERE id = ? LIMIT 1');
    $st->execute([$lockId]);
    $before = $st->fetch();
    if (!$before || !empty($before['revoked_at'])) return;
    $up = $pdo->prepare('UPDATE tt_locks SET revoked_at = NOW(), revoked_by_user_id = ?, revoked_reason = ? WHERE id = ?');
    $up->execute([tracker_current_auth_user_id(), $reason !== '' ? $reason : null, $lockId]);
    $st->execute([$lockId]);
    $after = $st->fetch();
    tracker_audit_log($config, 'lock.revoke', 'tt_locks', $lockId, null, $before, $after, $reason);
}

function tracker_report_rows(array $config, ?int $employeeId, string $dateFrom, string $dateTo): array {
    $pdo = tracker_app_pdo($config);
    $params = [':fetch_from' => (new DateTimeImmutable($dateFrom))->modify('-1 day')->format('Y-m-d'), ':date_to' => $dateTo];
    $employeeWhereEntries = '';
    $employeeWhereAbsences = '';
    if ($employeeId) {
        $employeeWhereEntries = ' AND e.employee_id = :employee_id';
        $employeeWhereAbsences = ' AND a.employee_id = :employee_id';
        $params[':employee_id'] = $employeeId;
    }
    $entrySql = 'SELECT e.*, t.label AS type_label, t.color_class FROM tt_entries e LEFT JOIN tt_entry_types t ON t.code=e.entry_kind WHERE e.deleted_at IS NULL AND e.entry_date BETWEEN :fetch_from AND :date_to ' . $employeeWhereEntries . ' ORDER BY e.employee_id, e.entry_date, e.start_time, e.id';
    $st = $pdo->prepare($entrySql);
    $st->execute($params);
    $entryRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($entryRows as $entry) {
        foreach (tracker_entry_day_segments($entry, $dateFrom, $dateTo) as $segment) {
            $eid = (int)$segment['employee_id'];
            $date = (string)$segment['segment_date'];
            $key = $eid . '|' . $date . '|work';
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'employee_id' => $eid,
                    'entry_date' => $date,
                    'row_kind' => 'work',
                    'total_minutes' => 0,
                    'entry_count' => 0,
                    'type_label' => null,
                    'badge_text' => null,
                    'note' => null,
                    'travel_minutes' => 0,
                    'travel_km' => 0,
                    'vehicle_plate' => null,
                    'absence_type_id' => null,
                    'work_only_minutes' => 0,
                ];
            }
            $rows[$key]['total_minutes'] += tracker_entry_segment_total_minutes($segment);
            $rows[$key]['entry_count'] += 1;
            $rows[$key]['travel_minutes'] += tracker_entry_segment_travel_minutes($segment);
            $rows[$key]['travel_km'] += tracker_entry_segment_travel_km($segment);
            $rows[$key]['work_only_minutes'] += tracker_entry_segment_work_minutes($segment);
            $note = trim((string)($segment['note'] ?? ''));
            if ($note !== '') {
                $rows[$key]['note'] = trim((string)($rows[$key]['note'] ?? '') . (($rows[$key]['note'] ?? '') !== null && $rows[$key]['note'] !== '' ? ' | ' : '') . $note);
            }
            $plate = trim((string)($segment['vehicle_plate'] ?? ''));
            if ($plate !== '') {
                $existingPlate = trim((string)($rows[$key]['vehicle_plate'] ?? ''));
                $plates = array_filter(array_unique(array_filter(array_map('trim', array_merge($existingPlate !== '' ? explode(',',$existingPlate):[], [$plate])))));
                $rows[$key]['vehicle_plate'] = implode(', ', $plates);
            }
        }
    }

    $absenceSql = 'SELECT a.*, t.label AS type_label, t.badge_text AS badge_text FROM tt_day_absences a INNER JOIN tt_absence_types t ON t.id=a.absence_type_id WHERE a.deleted_at IS NULL AND a.absence_date BETWEEN :date_from AND :date_to ' . $employeeWhereAbsences;
    $params2 = [':date_from'=>$dateFrom, ':date_to'=>$dateTo];
    if ($employeeId) $params2[':employee_id']=$employeeId;
    $st = $pdo->prepare($absenceSql);
    $st->execute($params2);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $rows[(int)$row['employee_id'].'|'.(string)$row['absence_date'].'|absence'] = [
            'employee_id'=>(int)$row['employee_id'], 'entry_date'=>(string)$row['absence_date'], 'row_kind'=>'absence', 'total_minutes'=>0, 'entry_count'=>0,
            'type_label'=>(string)$row['type_label'], 'badge_text'=>(string)$row['badge_text'], 'note'=>$row['note'], 'travel_minutes'=>0, 'travel_km'=>0,
            'vehicle_plate'=>null, 'absence_type_id'=>$row['absence_type_id'], 'work_only_minutes'=>0,
        ];
    }

    if ($employeeId) $employeeIds = [$employeeId];
    else $employeeIds = array_values(array_unique(array_map(static fn($opt)=>(int)$opt['employee_id'], tracker_employee_options($config))));
    $existing = [];
    foreach ($rows as $row) { $existing[(int)$row['employee_id']][(string)$row['entry_date']] = true; }
    $holidays = [];
    foreach (tracker_holidays_between($config,$dateFrom,$dateTo) as $h) $holidays[(string)$h['holiday_date']] = true;
    $from = new DateTimeImmutable($dateFrom); $to = new DateTimeImmutable($dateTo);
    foreach ($employeeIds as $eid) {
        for ($d=$from; $d<=$to; $d=$d->modify('+1 day')) {
            $date=$d->format('Y-m-d'); $weekday=(int)$d->format('N');
            if (isset($existing[$eid][$date])) continue;
            if ($weekday>=6 || isset($holidays[$date])) continue;
            $rows[$eid.'|'.$date.'|missing'] = ['employee_id'=>$eid,'entry_date'=>$date,'row_kind'=>'missing','total_minutes'=>0,'entry_count'=>0,'type_label'=>'Nincs rögzítve','badge_text'=>null,'note'=>null,'travel_minutes'=>0,'travel_km'=>0,'vehicle_plate'=>null,'absence_type_id'=>null,'work_only_minutes'=>0];
        }
    }
    $rows = array_values($rows);
    usort($rows, static function(array $a,array $b): int {
        $empCmp=((int)$a['employee_id'])<=>((int)$b['employee_id']); if($empCmp!==0) return $empCmp;
        $dateCmp=strcmp((string)$a['entry_date'],(string)$b['entry_date']); if($dateCmp!==0) return $dateCmp;
        $order=['absence'=>0,'work'=>1,'missing'=>2]; return ($order[(string)$a['row_kind']]??9)<=>($order[(string)$b['row_kind']]??9);
    });
    return $rows;
}

function tracker_audit_rows(array $config, int $limit = 200): array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_audit_log ORDER BY created_at DESC, id DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll();
}

function tracker_employee_options(array $config): array {
    $auth = tracker_auth_pdo($config);
    $rows = $auth->query('SELECT id, full_name, hr_employee_id, username FROM users WHERE is_active = 1 AND hr_employee_id IS NOT NULL AND hr_employee_id > 0 ORDER BY full_name, username')->fetchAll();
    $options = [];
    foreach ($rows as $row) {
        $empId = (int)$row['hr_employee_id'];
        $name = (string)$row['full_name'];
        $options[$empId] = ['employee_id' => $empId, 'label' => $name, 'auth_user_id' => (int)$row['id']];
    }
    ksort($options);
    return array_values($options);
}

function tracker_employee_name_map(array $config): array {
    $map = [];
    foreach (tracker_employee_options($config) as $opt) {
        $map[(int)$opt['employee_id']] = $opt['label'];
    }
    $hr = tracker_hr_pdo($config);
    if ($hr instanceof PDO) {
        try {
            foreach ($hr->query('SELECT id, full_name FROM employees ORDER BY full_name') as $row) {
                $map[(int)$row['id']] = (string)$row['full_name'];
            }
        } catch (Throwable $e) {
        }
    }
    return $map;
}

function tracker_day_color_rules(array $config): array {
    $pdo = tracker_app_pdo($config);
    return $pdo->query('SELECT * FROM tt_day_color_rules ORDER BY sort_order, minutes_from, id')->fetchAll();
}

function tracker_active_day_color_rules(array $config): array {
    $pdo = tracker_app_pdo($config);
    return $pdo->query('SELECT * FROM tt_day_color_rules WHERE is_active = 1 ORDER BY sort_order, minutes_from, id')->fetchAll();
}

function tracker_day_color_rule_match(array $config, int $minutes): ?array {
    foreach (tracker_active_day_color_rules($config) as $rule) {
        if ($minutes >= (int)$rule['minutes_from'] && $minutes <= (int)$rule['minutes_to']) {
            return $rule;
        }
    }
    return null;
}

function tracker_day_color_rule_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_day_color_rules WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_save_day_color_rule(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $minutesFrom = max(0, (int)($data['minutes_from'] ?? 0));
    $minutesTo = max($minutesFrom, (int)($data['minutes_to'] ?? 0));
    $label = trim((string)($data['label'] ?? ''));
    $bgColor = trim((string)($data['bg_color'] ?? ''));
    $textColor = trim((string)($data['text_color'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 0);
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $bgColor)) {
        throw new InvalidArgumentException('A háttérszín formátuma hibás.');
    }
    if ($textColor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $textColor)) {
        throw new InvalidArgumentException('A szövegszín formátuma hibás.');
    }
    if ($textColor === '') {
        $textColor = tracker_contrast_text_color($bgColor);
    }

    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        $st = $pdo->prepare('SELECT * FROM tt_day_color_rules WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $before = $st->fetch() ?: null;

        $st = $pdo->prepare('UPDATE tt_day_color_rules SET minutes_from=?, minutes_to=?, label=?, bg_color=?, text_color=?, sort_order=?, is_active=? WHERE id=?');
        $st->execute([$minutesFrom, $minutesTo, $label !== '' ? $label : null, $bgColor, $textColor, $sortOrder, $isActive, $id]);

        $st = $pdo->prepare('SELECT * FROM tt_day_color_rules WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $after = $st->fetch() ?: null;
        tracker_audit_log($config, 'day_color_rule.update', 'tt_day_color_rules', $id, null, $before, $after);
        return $id;
    }

    $st = $pdo->prepare('INSERT INTO tt_day_color_rules (minutes_from, minutes_to, label, bg_color, text_color, sort_order, is_active) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$minutesFrom, $minutesTo, $label !== '' ? $label : null, $bgColor, $textColor, $sortOrder, $isActive]);
    $newId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('SELECT * FROM tt_day_color_rules WHERE id = ? LIMIT 1');
    $st->execute([$newId]);
    $after = $st->fetch() ?: null;
    tracker_audit_log($config, 'day_color_rule.create', 'tt_day_color_rules', $newId, null, null, $after);
    return $newId;
}

function tracker_delete_day_color_rule(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_day_color_rules WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $before = $st->fetch();
    if (!$before) {
        return;
    }
    $del = $pdo->prepare('DELETE FROM tt_day_color_rules WHERE id = ?');
    $del->execute([$id]);
    tracker_audit_log($config, 'day_color_rule.delete', 'tt_day_color_rules', $id, null, $before, null);
}


function tracker_groups_all(array $config, bool $activeOnly = false): array {
    $pdo = tracker_app_pdo($config);
    $sql = 'SELECT * FROM tt_groups';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY name, id';
    return $pdo->query($sql)->fetchAll();
}

function tracker_group_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_groups WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tracker_save_group(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('A csoport neve kötelező.');
    $description = trim((string)($data['description'] ?? ''));
    $isActive = !empty($data['is_active']) ? 1 : 0;
    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        $before = tracker_group_find($config, $id);
        $st = $pdo->prepare('UPDATE tt_groups SET name=?, description=?, is_active=? WHERE id=?');
        $st->execute([$name, $description !== '' ? $description : null, $isActive, $id]);
        $after = tracker_group_find($config, $id);
        tracker_audit_log($config, 'group.update', 'tt_groups', $id, null, $before, $after);
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO tt_groups (name, description, is_active) VALUES (?,?,?)');
    $st->execute([$name, $description !== '' ? $description : null, $isActive]);
    $newId = (int)$pdo->lastInsertId();
    tracker_audit_log($config, 'group.create', 'tt_groups', $newId, null, null, tracker_group_find($config, $newId));
    return $newId;
}

function tracker_delete_group(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $before = tracker_group_find($config, $id);
    $st = $pdo->prepare('DELETE FROM tt_groups WHERE id = ?');
    $st->execute([$id]);
    tracker_audit_log($config, 'group.delete', 'tt_groups', $id, null, $before, null);
}

function tracker_group_members(array $config, ?int $groupId = null): array {
    $pdo = tracker_app_pdo($config);
    $sql = 'SELECT gm.id, gm.group_id, gm.employee_id, g.name AS group_name FROM tt_group_members gm INNER JOIN tt_groups g ON g.id = gm.group_id';
    $params = [];
    if ($groupId) { $sql .= ' WHERE gm.group_id = ?'; $params[] = $groupId; }
    $sql .= ' ORDER BY g.name, gm.employee_id';
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll();
}

function tracker_save_group_member(array $config, int $groupId, int $employeeId): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('INSERT IGNORE INTO tt_group_members (group_id, employee_id) VALUES (?,?)');
    $st->execute([$groupId, $employeeId]);
    tracker_audit_log($config, 'group_member.create', 'tt_group_members', null, $employeeId, null, ['group_id'=>$groupId,'employee_id'=>$employeeId]);
}

function tracker_delete_group_member(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_group_members WHERE id = ? LIMIT 1');
    $st->execute([$id]); $before = $st->fetch() ?: null;
    $st = $pdo->prepare('DELETE FROM tt_group_members WHERE id = ?');
    $st->execute([$id]);
    tracker_audit_log($config, 'group_member.delete', 'tt_group_members', $id, (int)($before['employee_id'] ?? 0) ?: null, $before, null);
}

function tracker_group_leaders(array $config, ?int $groupId = null): array {
    $pdo = tracker_app_pdo($config);
    $auth = tracker_auth_pdo($config);
    $users = [];
    foreach ($auth->query('SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name, username')->fetchAll() as $u) {
        $users[(int)$u['id']] = (string)($u['full_name'] ?: $u['username']);
    }
    $sql = 'SELECT gl.id, gl.group_id, gl.user_id, g.name AS group_name FROM tt_group_leaders gl INNER JOIN tt_groups g ON g.id = gl.group_id';
    $params = [];
    if ($groupId) { $sql .= ' WHERE gl.group_id = ?'; $params[] = $groupId; }
    $sql .= ' ORDER BY g.name, gl.user_id';
    $st = $pdo->prepare($sql); $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['user_name'] = $users[(int)$r['user_id']] ?? ('#'.(int)$r['user_id']); }
    return $rows;
}

function tracker_save_group_leader(array $config, int $groupId, int $userId): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('INSERT IGNORE INTO tt_group_leaders (group_id, user_id) VALUES (?,?)');
    $st->execute([$groupId, $userId]);
    tracker_audit_log($config, 'group_leader.create', 'tt_group_leaders', null, null, null, ['group_id'=>$groupId,'user_id'=>$userId]);
}

function tracker_delete_group_leader(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT * FROM tt_group_leaders WHERE id = ? LIMIT 1');
    $st->execute([$id]); $before = $st->fetch() ?: null;
    $st = $pdo->prepare('DELETE FROM tt_group_leaders WHERE id = ?');
    $st->execute([$id]);
    tracker_audit_log($config, 'group_leader.delete', 'tt_group_leaders', $id, null, $before, null);
}

function tracker_group_employee_ids(array $config, int $groupId): array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('SELECT employee_id FROM tt_group_members WHERE group_id = ? ORDER BY employee_id');
    $st->execute([$groupId]);
    return array_map('intval', array_column($st->fetchAll(), 'employee_id'));
}

function tracker_group_user_options(array $config): array {
    $auth = tracker_auth_pdo($config);
    return $auth->query('SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name, username')->fetchAll();
}

function tracker_team_dashboard_rows(array $config, int $groupId, string $dateFrom, string $dateTo): array {
    $employeeIds = tracker_group_employee_ids($config, $groupId);
    if (!$employeeIds) return [];
    $employeeMap = tracker_employee_name_map($config);
    $pdo = tracker_app_pdo($config);
    $in = implode(',', array_fill(0, count($employeeIds), '?'));
    $params = $employeeIds;
    $params[] = $dateFrom; $params[] = $dateTo;
    $entrySql = "SELECT employee_id, entry_date, SUM(work_minutes) total_minutes, COUNT(*) entry_count FROM tt_entries WHERE deleted_at IS NULL AND employee_id IN ($in) AND entry_date BETWEEN ? AND ? GROUP BY employee_id, entry_date";
    $st = $pdo->prepare($entrySql); $st->execute($params); $entryRows = $st->fetchAll();
    $entryMap = []; $entryTotals = []; $entryCounts = [];
    foreach ($entryRows as $r) { $eid=(int)$r['employee_id']; $d=$r['entry_date']; $entryMap[$eid][$d]=(int)$r['total_minutes']; $entryTotals[$eid]=($entryTotals[$eid]??0)+(int)$r['total_minutes']; $entryCounts[$eid]=($entryCounts[$eid]??0)+(int)$r['entry_count']; }
    $params = $employeeIds; $params[] = $dateFrom; $params[] = $dateTo;
    $absSql = "SELECT a.employee_id, a.absence_date, t.label type_label FROM tt_day_absences a INNER JOIN tt_absence_types t ON t.id=a.absence_type_id WHERE a.deleted_at IS NULL AND a.employee_id IN ($in) AND a.absence_date BETWEEN ? AND ?";
    $st = $pdo->prepare($absSql); $st->execute($params); $absenceRows = $st->fetchAll();
    $absenceMap=[]; $absenceCounts=[];
    foreach ($absenceRows as $r){$eid=(int)$r['employee_id'];$d=$r['absence_date'];$absenceMap[$eid][$d]=(string)$r['type_label'];$absenceCounts[$eid]=($absenceCounts[$eid]??0)+1;}
    $holidays=[]; foreach (tracker_holidays_between($config,$dateFrom,$dateTo) as $h){ $holidays[$h['holiday_date']] = true; }
    $rows=[];
    foreach ($employeeIds as $eid) {
        $recordedDays = count($entryMap[$eid] ?? []);
        $absenceDays = count($absenceMap[$eid] ?? []);
        $missingDays = 0; $lockedDays=0;
        $period = new DatePeriod(new DateTimeImmutable($dateFrom), new DateInterval('P1D'), (new DateTimeImmutable($dateTo))->modify('+1 day'));
        foreach ($period as $dt) {
            $date = $dt->format('Y-m-d');
            if ((int)$dt->format('N') >= 6) continue;
            if (!empty($holidays[$date])) continue;
            if (!empty($absenceMap[$eid][$date])) continue;
            if (!empty($entryMap[$eid][$date])) continue;
            $missingDays++;
        }
        foreach ($period as $dt) {
            $date=$dt->format('Y-m-d');
            if (tracker_is_locked($config,$date)) $lockedDays++;
        }
        $status = 'Rendben';
        if ($recordedDays===0 && $absenceDays===0) $status='Nincs rögzítés';
        elseif ($missingDays>0) $status='Hiányos';
        elseif ($absenceDays>0 && $recordedDays===0) $status='Távollét';
        $rows[] = [
            'employee_id'=>$eid,
            'employee_name'=>$employeeMap[$eid] ?? ('#'.$eid),
            'recorded_days'=>$recordedDays,
            'absence_days'=>$absenceDays,
            'missing_days'=>$missingDays,
            'total_minutes'=>(int)($entryTotals[$eid] ?? 0),
            'entry_count'=>(int)($entryCounts[$eid] ?? 0),
            'locked_days'=>$lockedDays,
            'status'=>$status,
        ];
    }
    usort($rows, fn($a,$b)=>strnatcasecmp($a['employee_name'],$b['employee_name']));
    return $rows;
}

function tracker_templates(array $config, ?string $templateType = null, bool $activeOnly = true): array {
    $pdo = tracker_app_pdo($config);
    $sql = "SELECT t.*, at.label AS absence_type_label, et.label AS entry_type_label
"
         . "FROM tt_templates t
"
         . "LEFT JOIN tt_absence_types at ON at.id = t.absence_type_id
"
         . "LEFT JOIN tt_entry_types et ON et.code = t.entry_kind
"
         . "WHERE 1=1";
    $params = [];
    if ($templateType !== null && $templateType !== '') {
        $sql .= " AND t.template_type = ?";
        $params[] = $templateType;
    }
    if ($activeOnly) {
        $sql .= " AND t.is_active = 1";
    }
    $sql .= " ORDER BY t.sort_order ASC, t.name ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
}

function tracker_template_find(array $config, int $id): ?array {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare("SELECT t.*, at.label AS absence_type_label, et.label AS entry_type_label
"
        . "FROM tt_templates t
"
        . "LEFT JOIN tt_absence_types at ON at.id = t.absence_type_id
"
        . "LEFT JOIN tt_entry_types et ON et.code = t.entry_kind
"
        . "WHERE t.id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function tracker_save_template(array $config, array $data, ?int $id = null): int {
    $pdo = tracker_app_pdo($config);
    $name = trim((string)($data['name'] ?? ''));
    $templateType = (string)($data['template_type'] ?? 'work');
    $entryKind = trim((string)($data['entry_kind'] ?? ''));
    $absenceTypeId = (int)($data['absence_type_id'] ?? 0);
    $startHour = $data['start_hour'] ?? null;
    $startMinute = $data['start_minute'] ?? null;
    $endHour = $data['end_hour'] ?? null;
    $endMinute = $data['end_minute'] ?? null;
    $startTimeRaw = trim((string)($data['start_time'] ?? ''));
    $endTimeRaw = trim((string)($data['end_time'] ?? ''));
    if ($startTimeRaw !== '' && strpos($startTimeRaw, ':') !== false) {
        [$h, $m] = array_pad(explode(':', $startTimeRaw, 2), 2, '00');
        $startHour = (int)$h;
        $startMinute = (int)$m;
    }
    if ($endTimeRaw !== '' && strpos($endTimeRaw, ':') !== false) {
        [$h, $m] = array_pad(explode(':', $endTimeRaw, 2), 2, '00');
        $endHour = (int)$h;
        $endMinute = (int)$m;
    }
    $breakMinutes = max(0, (int)($data['break_minutes'] ?? 0));
    $note = trim((string)($data['note'] ?? ''));
    $sortOrder = (int)($data['sort_order'] ?? 100);
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new InvalidArgumentException('A sablon neve kötelező.');
    }
    if (!in_array($templateType, ['work', 'absence'], true)) {
        throw new InvalidArgumentException('Érvénytelen sablontípus.');
    }

    $startTime = null;
    $endTime = null;
    $entryKindValue = null;
    $absenceTypeValue = null;

    if ($templateType === 'work') {
        if ($entryKind === '') {
            throw new InvalidArgumentException('A munkaidő típusa kötelező.');
        }
        if ($startHour === null || $startMinute === null || $endHour === null || $endMinute === null || $startHour === '' || $startMinute === '' || $endHour === '' || $endMinute === '') {
            throw new InvalidArgumentException('A kezdés és a befejezés kötelező.');
        }
        $startTime = sprintf('%02d:%02d:00', (int)$startHour, (int)$startMinute);
        $endTime = sprintf('%02d:%02d:00', (int)$endHour, (int)$endMinute);
        $entryKindValue = $entryKind;
    } else {
        if ($absenceTypeId <= 0) {
            throw new InvalidArgumentException('A távollét típusa kötelező.');
        }
        $absenceTypeValue = $absenceTypeId;
        $breakMinutes = 0;
    }

    if ($travelMinutes > $mins) {
        throw new InvalidArgumentException('Az utazási idő nem lehet több a teljes munkaidőnél.');
    }

    if ($id) {
        $st = $pdo->prepare('UPDATE tt_templates SET name=?, template_type=?, entry_kind=?, absence_type_id=?, start_time=?, end_time=?, break_minutes=?, note=?, sort_order=?, is_active=? WHERE id=?');
        $st->execute([$name, $templateType, $entryKindValue, $absenceTypeValue, $startTime, $endTime, $breakMinutes, $note !== '' ? $note : null, $sortOrder, $isActive, $id]);
        return $id;
    }

    $st = $pdo->prepare('INSERT INTO tt_templates (name, template_type, entry_kind, absence_type_id, start_time, end_time, break_minutes, note, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$name, $templateType, $entryKindValue, $absenceTypeValue, $startTime, $endTime, $breakMinutes, $note !== '' ? $note : null, $sortOrder, $isActive]);
    return (int)$pdo->lastInsertId();
}

function tracker_delete_template(array $config, int $id): void {
    $pdo = tracker_app_pdo($config);
    $st = $pdo->prepare('DELETE FROM tt_templates WHERE id = ?');
    $st->execute([$id]);
}

