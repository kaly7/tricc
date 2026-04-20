<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/auth_bootstrap.php';

CentralAuth::requireLogin($config);

$title = 'Rendszerválasztó';
$loggedIn = true;

$mods = CentralAuth::allowedModules($config);

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h1 class="h4 m-0">Rendszerválasztó</h1>
</div>

<?php if (!$mods): ?>
  <div class="alert alert-warning">Nincs hozzárendelt moduljogod. Szólj az adminnak.</div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($mods as $m): ?>
      <?php $url = build_url((int)$m['port'], (string)($m['path'] ?: '/')); ?>
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-bold"><?= h((string)$m['module_name']) ?></div>
                <div class="text-secondary small"><?= h((string)$m['module_key']) ?> · port <?= (int)$m['port'] ?></div>
              </div>
              <span class="badge bg-secondary"><?= h((string)$m['role_key']) ?></span>
            </div>
            <div class="mt-3">
              <a class="btn btn-sm btn-outline-primary" href="<?= h($url) ?>">Megnyitás</a>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
