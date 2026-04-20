<?php
require_once __DIR__.'/../src/auth.php'; require_login_or_redirect();
if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; }
require_once __DIR__.'/../src/db.php';
require_once __DIR__.'/../src/helpers.php';

$u = current_user();
$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// törzsek a pipákhoz
$statuses = db()->query('SELECT id,name FROM pp_status ORDER BY name')->fetchAll();
$users    = db()->query('SELECT id,name,email FROM users WHERE is_active=1 ORDER BY name')->fetchAll();

// sablonok
$tpls = db()->query('SELECT * FROM email_templates ORDER BY created_at DESC, name')->fetchAll();

// kapcsolati táblák betöltése egyben a meglévő sablonokhoz
$sts_map = $perm_map = $rcp_map = [];
if ($tpls) {
  $ids = array_column($tpls, 'id');
  $in  = implode(',', array_fill(0, count($ids), '?'));

  $st1 = db()->prepare("SELECT template_id, pp_status_id FROM email_template_status WHERE template_id IN ($in)");
  $st1->execute($ids);
  foreach ($st1 as $r) { $sts_map[(int)$r['template_id']][(int)$r['pp_status_id']] = true; }

  $st2 = db()->prepare("SELECT template_id, user_id FROM email_template_permissions WHERE template_id IN ($in)");
  $st2->execute($ids);
  foreach ($st2 as $r) { $perm_map[(int)$r['template_id']][(int)$r['user_id']] = true; }

  $st3 = db()->prepare("SELECT template_id, user_id FROM email_template_recipients WHERE template_id IN ($in)");
  $st3->execute($ids);
  foreach ($st3 as $r) { $rcp_map[(int)$r['template_id']][(int)$r['user_id']] = true; }
}

// mezőválaszték a sablonokhoz
$fields_all = [
  'eventus'   => 'Eventus',
  'pp_status' => 'PP státusz',
  'issued_at' => 'Kiadva',
  'due_at'    => '+38 nap',
  'city'      => 'Város',
  'address'   => 'Cím',
  'operation' => 'Elvégzendő művelet',
  'long_desc' => 'Leírás'
];
?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>E-mail sablonok – Admin</title>
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
<nav class="navbar navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="records.php">PP rendszer</a>
    <div class="d-flex align-items-center gap-2">
      <a class="btn btn-sm btn-outline-light" href="admin_users.php">Felhasználók</a>
      <a class="btn btn-sm btn-outline-light" href="admin_dicts.php">Törzsek</a>
      <a class="btn btn-sm btn-outline-light" href="admin_emails.php">E-mail sablonok</a>
      <span class="navbar-text text-white-50 small"><?=h($u['name'])?> (<?=h($u['role'])?>)</span>
      <a class="btn btn-sm btn-outline-light" href="change_password.php">Jelszó</a>
      <a class="btn btn-sm btn-outline-light" href="logout.php">Kilépés</a>
    </div>
  </div>
</nav>


<main class="container my-3">

<div class="container">
  <?php if($msg==='saved'): ?>
    <div class="alert alert-success">✅ Sablon elmentve.</div>
  <?php elseif($msg==='deleted'): ?>
    <div class="alert alert-success">✅ Sablon törölve.</div>
  <?php elseif($err==='save'): ?>
    <div class="alert alert-danger">❌ Mentési hiba történt.</div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- ÚJ sablon -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Új e-mail sablon</div>
        <div class="card-body">
          <form method="post" action="actions/email_template_save.php" class="row g-3">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="col-12">
              <label class="form-label">Megnevezés</label>
              <input name="name" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Tárgy</label>
              <input name="subject" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Szöveg (bevezető)</label>
              <textarea name="body" rows="4" class="form-control"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label">Levélbe kerülő mezők</label>
              <div class="row">
                <?php foreach($fields_all as $k=>$lab): ?>
                  <div class="col-6 col-md-4">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="fields[]" value="<?=$k?>" id="fnew_<?=$k?>">
                      <label class="form-check-label" for="fnew_<?=$k?>"><?=$lab?></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Legalább egy mezőt érdemes választani.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Érvényes PP-státuszokra</label>
              <div class="row">
                <?php foreach($statuses as $s): ?>
                  <div class="col-6 col-md-4">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="pp_status_id[]" value="<?=$s['id']?>" id="snew_<?=$s['id']?>">
                      <label class="form-check-label" for="snew_<?=$s['id']?>"><?=h($s['name'])?></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Legalább egy státuszt jelölj meg.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Ki KÜLDHETI ezt a sablont? (admin mindent küldhet)</label>
              <div class="row">
                <?php foreach($users as $usr): ?>
                  <div class="col-12 col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?=$usr['id']?>" id="unew_<?=$usr['id']?>">
                      <label class="form-check-label" for="unew_<?=$usr['id']?>"><?=h($usr['name'])?> – <span class="text-muted"><?=h($usr['email'])?></span></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Kik KAPJÁK ezt az e-mailt?</label>
              <div class="row">
                <?php foreach($users as $usr): ?>
                  <div class="col-12 col-md-6">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="user_recipients[]" value="<?=$usr['id']?>" id="rnew_<?=$usr['id']?>">
                      <label class="form-check-label" for="rnew_<?=$usr['id']?>"><?=h($usr['name'])?> – <span class="text-muted"><?=h($usr['email'])?></span></label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-text">Válassz legalább egy címzett felhasználót.</div>
            </div>

            <div class="col-12">
              <button class="btn btn-success">Sablon mentése</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Meglévő sablonok -->
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header">Meglévő sablonok</div>
        <div class="card-body">
          <?php if(!$tpls): ?>
            <div class="text-muted">Még nincs felvitt sablon.</div>
          <?php endif; ?>

          <?php foreach($tpls as $t): 
            $id = (int)$t['id'];
            $sel_fields = array_filter(explode(',', $t['fields_csv'] ?? ''));
          ?>
            <form method="post" action="actions/email_template_save.php" class="border rounded p-3 mb-3 bg-white">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$id?>">
              <div class="row g-2">
                <div class="col-12">
                  <label class="form-label">Megnevezés</label>
                  <input name="name" class="form-control" value="<?=h($t['name'])?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Tárgy</label>
                  <input name="subject" class="form-control" value="<?=h($t['subject'])?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label">Szöveg (bevezető)</label>
                  <textarea name="body" rows="3" class="form-control"><?=h($t['body'])?></textarea>
                </div>

                <div class="col-12">
                  <label class="form-label">Levélbe kerülő mezők</label>
                  <div class="row">
                    <?php foreach($fields_all as $k=>$lab): $ch = in_array($k,$sel_fields)?'checked':''; ?>
                      <div class="col-6 col-md-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="fields[]" value="<?=$k?>" id="f<?=$id?>_<?=$k?>" <?=$ch?>>
                          <label class="form-check-label" for="f<?=$id?>_<?=$k?>"><?=$lab?></label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Érvényes PP-státuszokra</label>
                  <div class="row">
                    <?php foreach($statuses as $s):
                      $checked = !empty($sts_map[$id][$s['id']]) ? 'checked' : '';
                    ?>
                      <div class="col-6 col-md-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="pp_status_id[]" value="<?=$s['id']?>" id="s<?=$id?>_<?=$s['id']?>" <?=$checked?>>
                          <label class="form-check-label" for="s<?=$id?>_<?=$s['id']?>"><?=h($s['name'])?></label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Ki KÜLDHETI?</label>
                  <div class="row">
                    <?php foreach($users as $usr):
                      $checked = !empty($perm_map[$id][$usr['id']]) ? 'checked' : '';
                    ?>
                      <div class="col-12 col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="user_ids[]" value="<?=$usr['id']?>" id="u<?=$id?>_<?=$usr['id']?>" <?=$checked?>>
                          <label class="form-check-label" for="u<?=$id?>_<?=$usr['id']?>"><?=h($usr['name'])?> – <span class="text-muted"><?=h($usr['email'])?></span></label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>

                <div class="col-12">
                  <label class="form-label">Kik KAPJÁK?</label>
                  <div class="row">
                    <?php foreach($users as $usr):
                      $checked = !empty($rcp_map[$id][$usr['id']]) ? 'checked' : '';
                    ?>
                      <div class="col-12 col-md-6">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" name="user_recipients[]" value="<?=$usr['id']?>" id="r<?=$id?>_<?=$usr['id']?>" <?=$checked?>>
                          <label class="form-check-label" for="r<?=$id?>_<?=$usr['id']?>"><?=h($usr['name'])?> – <span class="text-muted"><?=h($usr['email'])?></span></label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>


<div class="col-12 d-flex gap-2 flex-wrap">
  <button class="btn btn-primary">Mentés</button>

  <!-- TÖRLÉS: NINCS külön <form>, csak a gomb irányít másik actionre -->
  <button
    type="submit"
    class="btn btn-danger"
    formaction="actions/email_template_delete.php"
    formmethod="post"
    onclick="return confirm('Biztosan törlöd a sablont?');"
  >Törlés</button>

  <div class="ms-auto text-muted small">
    Létrehozva: <?=h($t['created_at'])?>
  </div>
</div>

              </div>
            </form>
          <?php endforeach; ?>

        </div>
      </div>
    </div>
  </div>
</div>
</body>
</main>
<footer>
© Perfect Phone Munka Nyilvántartó
</footer>

</html>