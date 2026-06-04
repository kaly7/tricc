<?php
require '_db.php';
tricc_auth();

$counts = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    $db = tricc_db();
    $db->exec("DELETE FROM messages");
    $db->exec("DELETE FROM room_members");
    $db->exec("DELETE FROM rooms");
    $db->exec("ALTER TABLE messages AUTO_INCREMENT = 1");
    $db->exec("ALTER TABLE rooms AUTO_INCREMENT = 1");
    flash('Összes szoba, üzenet és tagság törölve. Tiszta az adatbázis.');
    header('Location: reset.php'); exit;
}

$db     = tricc_db();
$rooms  = (int)$db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$msgs   = (int)$db->query("SELECT COUNT(*) FROM messages")->fetchColumn();
$users  = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

$title = 'Teszt reset'; $active_page = 'reset';
require '_layout.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="bi bi-trash2"></i> Teszt adatbázis reset</h5>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="card shadow-sm text-center py-3">
      <div class="display-6 fw-bold"><?= $rooms ?></div>
      <div class="text-muted small">Szoba</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card shadow-sm text-center py-3">
      <div class="display-6 fw-bold"><?= $msgs ?></div>
      <div class="text-muted small">Üzenet</div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="card shadow-sm text-center py-3">
      <div class="display-6 fw-bold text-success"><?= $users ?></div>
      <div class="text-muted small">Felhasználó (nem törlődik)</div>
    </div>
  </div>
</div>

<div class="card shadow-sm border-danger">
  <div class="card-body">
    <h6 class="text-danger"><i class="bi bi-exclamation-triangle-fill"></i> Figyelem</h6>
    <p class="mb-3">Ez a művelet törli az összes <strong>szobát</strong>, <strong>üzenetet</strong> és <strong>szobatagsági rekordot</strong>. A felhasználók megmaradnak.</p>
    <form method="post" onsubmit="return confirm('Biztosan törölsz mindent?')">
      <input type="hidden" name="confirm" value="yes">
      <button type="submit" class="btn btn-danger">
        <i class="bi bi-trash2-fill"></i> Mindent törlök
      </button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</div>
</body>
</html>
