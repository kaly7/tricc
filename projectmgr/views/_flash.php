<?php
if (!class_exists('\App\Helpers')) {
  require dirname(__DIR__) . '/app/Helpers.php';
}
use App\Helpers;
if ($m = Helpers::flash('ok')): ?>
  <div class="alert alert-success"><?= htmlspecialchars($m) ?></div>
<?php endif; if ($m = Helpers::flash('err')): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($m) ?></div>
<?php endif; ?>
