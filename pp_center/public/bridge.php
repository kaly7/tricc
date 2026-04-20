<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\BridgeStatusService;
use App\Services\LogService;

$service = new BridgeStatusService();
$rows = $service->all();
$logs = (new LogService())->tail(60);
$pageTitle = 'Bridge állapot';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Háttérszolgáltatások</div>
        <h1>Bridge állapot</h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(app_url('logs.php')) ?>">Teljes log nézet</a>
    </div>
</div>
<section class="panel mb-4">
    <div class="table-wrap">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th>Worker</th>
                <th>Állapot</th>
                <th>Utolsó heartbeat</th>
                <th>Utolsó hiba</th>
                <th>Részletek</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><code><?= e($row['worker_name']) ?></code></td>
                    <td><?= bridge_status_badge((string) $row['status']) ?></td>
                    <td><?= e($row['heartbeat_at'] ?: '—') ?></td>
                    <td><?= e($row['last_error'] ?: '—') ?></td>
                    <td><pre class="json-box mb-0"><?= e(pretty_json($row['details_json'] ?: '{}')) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="5" class="text-center text-muted">Még nincs worker heartbeat adat.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="section-head">
        <h2>Legfrissebb worker log</h2>
        <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url('logs.php')) ?>">Részletes napló</a>
    </div>
    <pre class="log-view mb-0"><?php foreach ($logs as $line) { echo e($line) . "\n"; } ?></pre>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
