<?php
/** @var array<int,array<string,mixed>> $alerts */
/** @var int $alertsPage */
/** @var int $alertsPerPage */
/** @var int $alertsTotal */
?>
<div class="stack-list">
    <?php foreach ($alerts as $alert): ?>
        <article class="alert-item severity-<?= e((string) $alert['severity']) ?>">
            <div>
                <strong><?= e((string) $alert['device_id']) ?></strong>
                <div class="muted small"><?= e((string) $alert['event_type']) ?> · <?= e((string) $alert['ts']) ?></div>
            </div>
            <p><?= e((string) $alert['message']) ?></p>
        </article>
    <?php endforeach; ?>
    <?php if (!$alerts): ?>
        <p class="muted">Még nincs naplózott riasztás.</p>
    <?php endif; ?>
</div>
<?= render_pagination($alertsPage, $alertsPerPage, $alertsTotal, 'index.php', 'alerts_page') ?>
