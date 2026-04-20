<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\LogService;

$contains = trim((string) ($_GET['contains'] ?? ''));
$lines = max(20, min(1000, (int) ($_GET['lines'] ?? cfg('ui.log_tail_lines', 200))));
$service = new LogService();
$rows = $service->tail($lines, $contains !== '' ? $contains : null);
$pageTitle = 'Logok';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Naplók</div>
        <h1>Bridge és worker logok</h1>
    </div>
    <form class="d-flex gap-2 flex-wrap" method="get">
        <input class="form-control" type="text" name="contains" value="<?= e($contains) ?>" placeholder="szűrés pl: request_id vagy esp001">
        <input class="form-control" style="max-width:120px" type="number" name="lines" min="20" max="1000" value="<?= e((string) $lines) ?>">
        <button class="btn btn-outline-primary" type="submit">Frissítés</button>
    </form>
</div>
<section class="panel">
    <pre class="log-view mb-0"><?php foreach ($rows as $line) { echo e($line) . "\n"; } ?></pre>
    <?php if (!$rows): ?><p class="text-muted mb-0">Nincs megjeleníthető log sor.</p><?php endif; ?>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
