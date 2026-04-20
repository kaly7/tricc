<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\CommandService;

$service = new CommandService();
$deviceId = trim((string) ($_GET['device_id'] ?? ''));
$page = resolve_page($_GET['page'] ?? 1);
$perPage = resolve_per_page($_GET['per_page'] ?? 20);
$total = $service->count($deviceId !== '' ? $deviceId : null);
$rows = $deviceId !== '' ? $service->recentByDevicePage($deviceId, $page, $perPage) : $service->recentPage($page, $perPage);
$pageTitle = 'Parancs sor';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Bridge queue</div>
        <h1>Parancsok és ACK-ek</h1>
        <div class="muted">Itt látszik a teljes parancsút: queue → MQTT publish → ACK / hiba.</div>
    </div>
    <form class="d-flex gap-2" method="get">
        <input class="form-control" type="text" name="device_id" value="<?= e($deviceId) ?>" placeholder="eszkoz azonosító">
        <select class="form-select" name="per_page">
            <?php foreach (per_page_options() as $opt): ?>
                <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?>/oldal</option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-primary" type="submit">Szűrés</button>
        <?php if ($deviceId !== '' || isset($_GET['per_page']) || isset($_GET['page'])): ?><a class="btn btn-outline-secondary" href="<?= e(app_url('queue.php')) ?>">Törlés</a><?php endif; ?>
    </form>
</div>
<section class="panel">
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Létrehozva</th>
                <th>Eszköz</th>
                <th>Típus</th>
                <th>Állapot</th>
                <th>Request ID</th>
                <th>Küldő</th>
                <th>Sent</th>
                <th>ACK</th>
                <th>Eredmény</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['created_at']) ?></td>
                    <td><code><?= e($row['device_id']) ?></code></td>
                    <td><?= e($row['command_type']) ?></td>
                    <td><?= command_status_badge((string) $row['status']) ?></td>
                    <td>
                        <code><?= e($row['request_id']) ?></code>
                        <details class="mt-2">
                            <summary>Payload</summary>
                            <pre class="json-box"><?= e(pretty_json($row['payload_json'])) ?></pre>
                        </details>
                    </td>
                    <td><?= e($row['created_by']) ?></td>
                    <td><?= e($row['sent_at'] ?: '—') ?></td>
                    <td><?= e($row['acked_at'] ?: '—') ?></td>
                    <td>
                        <?php if ($row['result_received_at']): ?>
                            <div class="small mb-1"><?= $row['result_ok'] ? '<span class="badge-status status-online">OK</span>' : '<span class="badge-status status-offline">Hiba</span>' ?></div>
                            <div><?= e($row['result_message'] ?: '—') ?></div>
                            <div class="muted small mt-1"><?= e($row['result_received_at']) ?></div>
                            <?php if (!empty($row['result_payload_json'])): ?>
                                <details class="mt-2">
                                    <summary>Válasz JSON</summary>
                                    <pre class="json-box"><?= e(pretty_json($row['result_payload_json'])) ?></pre>
                                </details>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted">Nincs még queue rekord.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= render_pagination($page, $perPage, $total, 'queue.php') ?>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
