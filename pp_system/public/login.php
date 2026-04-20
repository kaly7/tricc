<?php
require_once __DIR__.'/../src/auth.php';
start_session();
if (current_user()) { header('Location: records.php'); exit; }
$err = $_GET['err'] ?? '';
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bejelentkezés</title>
<link href="assets/css/bootstrap.min.css" rel="stylesheet">
<style>
html, body {
  height: 100%;
}

body {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

main {
  flex: 1;
}


footer {
  text-align: center;
  padding: 1rem 0;
  font-size: 0.9rem;
  color: #666;
  position: relative;
}

footer::before {
  content: "";
  display: block;
  height: 2px;
  background: linear-gradient(to right, transparent, #555, transparent);
  margin: 0 0 1rem 0; /* vonal és szöveg közötti távolság */
}
</style>

</head>
<body class="bg-light">

<main class="container my-3">

<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h1 class="h4 mb-3">Bejelentkezés</h1>
          <?php if ($err): ?><div class="alert alert-danger">Hibás email vagy jelszó.</div><?php endif; ?>
          <form method="post" action="login_process.php">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="mb-3">
              <label class="form-label">Email</label>
              <input type="email" class="form-control" name="email" required>
            </div>
            <div class="mb-3">
              <label class="form-label">Jelszó</label>
              <input type="password" class="form-control" name="password" required>
            </div>
            <button class="btn btn-primary w-100">Belépés</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</main>
<footer>
© Perfect Phone Munka Nyilvántartó
</footer>

</body></html>
