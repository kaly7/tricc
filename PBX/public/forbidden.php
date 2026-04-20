<?php
require __DIR__.'/../app/auth.php';
require_login();

http_response_code(403);

$title = 'Hozzáférés megtagadva';
$page  = 'Nincs jogosultság';
require __DIR__.'/_header.php';
?>

<div class="card shadow-sm">
  <div class="card-body p-4">
    <h1 class="h5 text-danger mb-2">Nincs jogosultság</h1>
    <p class="mb-3">Ehhez a művelethez nincs megfelelő jogosultságod.</p>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="javascript:history.back()">Vissza</a>
      <a class="btn btn-outline-secondary" href="<?= e(base_url('index.php')) ?>">Főoldal</a>
    </div>
  </div>
</div>

<?php require __DIR__.'/_footer.php'; ?>
