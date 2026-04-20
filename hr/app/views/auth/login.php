<div class="row justify-content-center">
  <div class="col-12 col-sm-10 col-md-6 col-lg-4">

    <div class="card">
      <div class="card-body">
        <h4 class="mb-3">Belépés</h4>

        <?php if (!empty($error)): ?>
          <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/login">
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" autocomplete="username" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Jelszó</label>
            <input type="password" name="password" class="form-control" autocomplete="current-password" required>
          </div>

          <button class="btn btn-primary w-100" type="submit">Belépés</button>
        </form>
      </div>
    </div>

  </div>
</div>
