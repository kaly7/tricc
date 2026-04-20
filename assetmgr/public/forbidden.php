<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_login();
$u = current_user();

http_response_code(403);
$title = 'Nincs jogosultság';
$page  = $title;

require __DIR__.'/_header.php';
?>
<div class="container" style="max-width:720px">
  <div class="alert alert-danger">
    Nincs jogosultság ehhez az oldalhoz.
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-primary" href="<?= e(base_url('my_assets.php')) ?>">Nálam lévő eszközök</a>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('logout.php')) ?>">Kijelentkezés</a>
  </div>
</div>
<?php require __DIR__.'/_footer.php'; ?>
