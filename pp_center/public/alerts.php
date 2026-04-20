<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\AlertService;

$service = new AlertService();
$deviceId = trim((string) ($_GET['device_id'] ?? ''));
$eventType = trim((string) ($_GET['event_type'] ?? ''));
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$fromHour = (string) ($_GET['from_hour'] ?? '0');
$fromMinute = (string) ($_GET['from_minute'] ?? '0');
$toDate = trim((string) ($_GET['to_date'] ?? ''));
$toHour = (string) ($_GET['to_hour'] ?? '23');
$toMinute = (string) ($_GET['to_minute'] ?? '50');
$fromTs = build_datetime_filter($fromDate, $fromHour, $fromMinute, false);
$toTs = build_datetime_filter($toDate, $toHour, $toMinute, true);
$page = resolve_page($_GET['page'] ?? 1);
$perPage = resolve_per_page($_GET['per_page'] ?? 20);
$filters = [
    'device_id' => $deviceId !== '' ? $deviceId : null,
    'event_type' => $eventType !== '' ? $eventType : null,
    'from_ts' => $fromTs,
    'to_ts' => $toTs,
];
$total = $service->searchCount($filters);
$alerts = $service->searchPage($page, $perPage, $filters);
$eventTypes = $service->eventTypes();
$pageTitle = 'Riasztások';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Eseménynapló</div>
        <h1>Riasztások</h1>
    </div>
    <form class="d-flex flex-wrap gap-2 align-items-end" method="get">
        <label>
            <span class="small muted d-block mb-1">Eszköz</span>
            <input class="form-control" type="text" name="device_id" value="<?= e($deviceId) ?>" placeholder="eszköz azonosító">
        </label>
        <label>
            <span class="small muted d-block mb-1">Riasztás típusa</span>
            <select class="form-select" name="event_type">
                <option value="">Összes</option>
                <?php foreach ($eventTypes as $type): ?>
                    <option value="<?= e($type) ?>" <?= $eventType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                <?php endforeach; ?>
            </select>
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
                <?php foreach (per_page_options() as $opt): ?>
                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?>/oldal</option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline-primary" type="submit">Szűrés</button>
        <?php if ($deviceId !== '' || $eventType !== '' || $fromDate !== '' || $toDate !== '' || isset($_GET['per_page']) || isset($_GET['page'])): ?><a class="btn btn-outline-secondary" href="<?= e(app_url('alerts.php')) ?>">Törlés</a><?php endif; ?>
    </form>
</div>
<section class="panel">
    <div class="table-wrap">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Szerver idő</th>
                    <th>Eszköz</th>
                    <th>Típus</th>
                    <th>Súlyosság</th>
                    <th>Üzenet</th>
                    <th>Akciók</th>
                    <th>Részletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($alerts as $alert): ?>
                <tr>
                    <td><?= e($alert['ts']) ?><?php $raw = json_decode((string) ($alert['raw_json'] ?? ''), true); if (is_array($raw) && !empty($raw['_device_ts_normalized'])): ?><div class="muted small">Eszköz idő: <?= e((string) $raw['_device_ts_normalized']) ?></div><?php endif; ?></td>
                    <td><code><?= e($alert['device_id']) ?></code></td>
                    <td><?= e($alert['event_type']) ?></td>
                    <td><span class="badge badge-<?= e($alert['severity']) ?>"><?= e($alert['severity']) ?></span></td>
                    <td><?= e($alert['message']) ?></td>
                    <td><?= e($alert['actions_taken_json'] ?: '[]') ?></td>
                    <td>
                        <details>
                            <summary>JSON</summary>
                            <pre class="json-box"><?= e(pretty_json($alert['raw_json'])) ?></pre>
                        </details>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$alerts): ?>
                    <tr><td colspan="7" class="text-center text-muted">Nincs a szűrésnek megfelelő riasztás.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $perPage, $total, 'alerts.php') ?>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
