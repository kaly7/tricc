<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\TelemetryService;

$service = new TelemetryService();
$deviceId = trim((string) ($_GET['device_id'] ?? ''));
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$fromHour = (string) ($_GET['from_hour'] ?? '0');
$fromMinute = (string) ($_GET['from_minute'] ?? '0');
$toDate = trim((string) ($_GET['to_date'] ?? ''));
$toHour = (string) ($_GET['to_hour'] ?? '23');
$toMinute = (string) ($_GET['to_minute'] ?? '50');
$fromTs = build_datetime_filter($fromDate, $fromHour, $fromMinute, false);
$toTs = build_datetime_filter($toDate, $toHour, $toMinute, true);
$page = resolve_page($_GET['page'] ?? 1);
$telemetryPerPageOptions = [50, 100, 200];
$perPage = (int) ($_GET['per_page'] ?? 200);
if (!in_array($perPage, $telemetryPerPageOptions, true)) {
    $perPage = 200;
}
$filters = [
    'device_id' => $deviceId !== '' ? $deviceId : null,
    'from_ts' => $fromTs,
    'to_ts' => $toTs,
];
$total = $service->searchCount($filters);
$rows = $service->searchPage($page, $perPage, $filters);
$pageTitle = 'Telemetria';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Szenzor adatok</div>
        <h1>Telemetria</h1>
    </div>
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <label>
            <span class="small muted d-block mb-1">Eszköz</span>
            <input class="form-control" type="text" name="device_id" value="<?= e($deviceId) ?>" placeholder="eszköz azonosító">
        </label>
        <label>
            <span class="small muted d-block mb-1">Kezdő dátum</span>
            <input class="form-control" type="date" name="from_date" value="<?= e($fromDate) ?>">
        </label>
        <label>
            <span class="small muted d-block mb-1">Óra</span>
            <select class="form-select" name="from_hour"><?php foreach (time_select_hour_options() as $hour): ?><option value="<?= $hour ?>" <?= ((int) $fromHour === $hour) ? 'selected' : '' ?>><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
        </label>
        <label>
            <span class="small muted d-block mb-1">Perc</span>
            <select class="form-select" name="from_minute"><?php foreach (time_select_minute_options() as $minute): ?><option value="<?= $minute ?>" <?= ((int) $fromMinute === $minute) ? 'selected' : '' ?>><?= sprintf('%02d', $minute) ?></option><?php endforeach; ?></select>
        </label>
        <label>
            <span class="small muted d-block mb-1">Záró dátum</span>
            <input class="form-control" type="date" name="to_date" value="<?= e($toDate) ?>">
        </label>
        <label>
            <span class="small muted d-block mb-1">Óra</span>
            <select class="form-select" name="to_hour"><?php foreach (time_select_hour_options() as $hour): ?><option value="<?= $hour ?>" <?= ((int) $toHour === $hour) ? 'selected' : '' ?>><?= sprintf('%02d', $hour) ?></option><?php endforeach; ?></select>
        </label>
        <label>
            <span class="small muted d-block mb-1">Perc</span>
            <select class="form-select" name="to_minute"><?php foreach (time_select_minute_options() as $minute): ?><option value="<?= $minute ?>" <?= ((int) $toMinute === $minute) ? 'selected' : '' ?>><?= sprintf('%02d', $minute) ?></option><?php endforeach; ?></select>
        </label>
        <label>
            <span class="small muted d-block mb-1">Sor / oldal</span>
            <select class="form-select" name="per_page">
                <?php foreach ($telemetryPerPageOptions as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?>/oldal</option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline-primary" type="submit">Szűrés</button>
        <?php if ($deviceId !== '' || $fromDate !== '' || $toDate !== '' || isset($_GET['per_page']) || isset($_GET['page'])): ?><a class="btn btn-outline-secondary" href="<?= e(app_url('telemetry.php')) ?>">Törlés</a><?php endif; ?>
    </form>
</div>
<section class="panel">
    <div class="muted small mb-3">Alapértelmezésben legfeljebb az utolsó 200 mérés jelenik meg. Időintervallummal ennél régebbi adatokra is tudsz keresni.</div>
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Szerver idő</th>
                <th>Eszköz</th>
                <th>Hőm.</th>
                <th>Pára</th>
                <th>AirQ</th>
                <th>Akku</th>
                <th>Táp</th>
                <th>Kontaktok</th>
                <th>RSSI</th>
                <th>Részletek</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['ts']) ?><?php $raw = json_decode((string) ($row['raw_json'] ?? ''), true); if (is_array($raw) && !empty($raw['_device_ts_normalized'])): ?><div class="muted small">Eszköz idő: <?= e((string) $raw['_device_ts_normalized']) ?></div><?php endif; ?></td>
                    <td><code><?= e($row['device_id']) ?></code></td>
                    <td><?= e($row['temperature'] !== null ? $row['temperature'] . ' °C' : '—') ?></td>
                    <td><?= e($row['humidity'] !== null ? $row['humidity'] . ' %' : '—') ?></td>
                    <td><?= e($row['air_quality'] ?? '—') ?></td>
                    <td><?= e($row['battery_pct'] !== null ? $row['battery_pct'] . ' %' : '—') ?></td>
                    <td><?= e($row['power_mode'] ?: '—') ?></td>
                    <td class="small"><?= e(($row['contact_1'] ?? '—') . ' / ' . ($row['contact_2'] ?? '—') . ' / ' . ($row['contact_3'] ?? '—') . ' / ' . ($row['contact_4'] ?? '—')) ?></td>
                    <td><?= e($row['rssi'] ?? '—') ?></td>
                    <td>
                        <details>
                            <summary>JSON</summary>
                            <pre class="json-box"><?= e(pretty_json($row['raw_json'])) ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="text-center text-muted">Nincs még telemetria adat.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $perPage, $total, 'telemetry.php') ?>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
