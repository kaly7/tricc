<div class="d-flex align-items-center justify-content-between mb-3">
  <h3 class="m-0">Extra mezők</h3>
  <a class="btn btn-sm btn-primary" href="/fields_create">+ Új mező</a>
</div>

<?php if (!empty($success)): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="table-responsive">
<table class="table table-striped align-middle">
  <thead>
    <tr>
      <th>Név</th>
      <th>Kulcs</th>
      <th>Típus</th>
      <th>Aktív</th>
      <th class="text-end">Művelet</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach (($fields ?? []) as $f): ?>
      <tr>
        <td><?= h($f['name']) ?></td>
        <td><code><?= h($f['field_key']) ?></code></td>
        <td><?= h($f['field_type']) ?></td>
        <td><?= ((int)$f['is_active']===1) ? '<span class="badge bg-success">igen</span>' : '<span class="badge bg-secondary">nem</span>' ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="/fields_edit?id=<?= (int)$f['id'] ?>">Szerkesztés</a>
          <form method="post" action="/fields_toggle" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><?= ((int)$f['is_active']===1)?'Kikapcs':'Bekapcs' ?></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($fields)): ?>
      <tr><td colspan="5" class="text-muted">Nincs még extra mező.</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
