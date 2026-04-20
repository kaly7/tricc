<?php
require_once __DIR__ . '/../functions.php';
require_login();

$viewer = current_user();
$selected_user_id = $viewer['id'];
$selected_user_display = $viewer['full_name'] ?? $viewer['username'];
$view_only = true;
$allow_edit = false;

if (is_admin()) {
    $q_uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $viewer['id'];
    $st = db()->prepare("SELECT id, COALESCE(full_name, username) AS display_name FROM users WHERE id=:id");
    $st->execute([':id'=>$q_uid]);
    if ($row = $st->fetch()) {
        $selected_user_id = (int)$row['id'];
        $selected_user_display = $row['display_name'];
    }
    $editFlag = isset($_GET['edit']) && $_GET['edit']==='1';
    $view_only = ($selected_user_id !== $viewer['id']) && !$editFlag;
    $allow_edit = ($selected_user_id === $viewer['id']) || ($selected_user_id !== $viewer['id'] && $editFlag);
} else {
    $view_only = false; $allow_edit = true;
}

$error=null; $ok=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    check_csrf();
    $action=$_POST['action']??'';
    if ($action==='delete') {
        $date=parse_date($_POST['work_date']??'');
        if (!$date) { $error='Érvénytelen dátum.'; }
        else {
            if (!is_admin() && is_locked($date)) {
                $error='Lezárt nap bejegyzése nem törölhető.';
            } else {
                $id=(int)($_POST['id']??0);
                db()->prepare("DELETE FROM timesheets WHERE id=:id AND user_id=:uid")->execute([':id'=>$id, ':uid'=>$selected_user_id]);
                $ok='Bejegyzés törölve.';
            }
        }
    } elseif ($action==='update') {
        $id=(int)($_POST['id']??0);
        $date=parse_date($_POST['work_date']??'');
        $pid=(int)($_POST['project_id']??0);
        $hours=(float)($_POST['hours']??0);
        $note=trim($_POST['note']??'');
        if (!$date){ $error='Érvénytelen dátum.'; }
        elseif ($pid<=0 || $hours<=0){ $error='Projekt és óraszám kötelező.'; }
        elseif (!is_admin() && is_locked($date)){ $error='A megadott nap lezárt. Módosítás nem engedélyezett.'; }
        else {
            try{
                $stmt=db()->prepare("UPDATE timesheets SET project_id=:pid, work_date=:d, hours=:h, note=:n WHERE id=:id AND user_id=:uid");
                $stmt->execute([':pid'=>$pid, ':d'=>$date, ':h'=>$hours, ':n'=>$note, ':id'=>$id, ':uid'=>$selected_user_id]);
                $ok='Bejegyzés frissítve.';
            } catch (PDOException $ex) {
                // duplikált (user_id, project_id, work_date) eset
                if ($ex->getCode()==='23000') { $error='Már van bejegyzés ezen a napon ehhez a projekthez.'; }
                else { $error='Hiba történt: '.h($ex->getMessage()); }
            }
        }
    } elseif ($allow_edit) {
        // új rögzítés
        $date=parse_date($_POST['work_date']??'');
        $pid=(int)($_POST['project_id']??0);
        $hours=(float)($_POST['hours']??0);
        $note=trim($_POST['note']??'');
        if (!$date){ $error='Érvénytelen dátum.'; }
        elseif ($pid<=0 || $hours<=0){ $error='Projekt és óraszám kötelező.'; }
        elseif (!is_admin() && is_locked($date)){ $error='A megadott nap lezárt. Módosítás nem engedélyezett.'; }
        else {
            $stmt=db()->prepare("INSERT INTO timesheets (user_id, project_id, work_date, hours, note) VALUES (:uid,:pid,:d,:h,:n)
                                 ON DUPLICATE KEY UPDATE hours=VALUES(hours), note=VALUES(note)");
            $stmt->execute([':uid'=>$selected_user_id, ':pid'=>$pid, ':d'=>$date, ':h'=>$hours, ':n'=>$note]);
            $ok='Rögzítve.';
        }
    }
}

$ym=$_GET['month']??date('Y-m'); if(!preg_match('/^\d{4}-\d{2}$/',$ym)) $ym=date('Y-m');
$first=$ym.'-01'; $firstTs=strtotime($first); $daysInMonth=(int)date('t',$firstTs); $startDow=(int)date('N',$firstTs);
$selectedDate=parse_date($_GET['date']??date('Y-m-d'));

$projects=db()->query("SELECT id, name FROM projects WHERE active=1 ORDER BY name")->fetchAll();
$entriesStmt=db()->prepare("SELECT ts.*, p.name AS project_name FROM timesheets ts JOIN projects p ON p.id=ts.project_id WHERE ts.user_id=:uid AND ts.work_date BETWEEN :s AND :e ORDER BY ts.work_date, p.name");
$entriesStmt->execute([':uid'=>$selected_user_id, ':s'=>$first, ':e'=>date('Y-m-t',$firstTs)]);
$entriesByDate=[]; foreach ($entriesStmt as $row){ $entriesByDate[$row['work_date']][]=$row; }

$all_users=is_admin()?db()->query("SELECT id, COALESCE(full_name, username) AS label FROM users ORDER BY username")->fetchAll():[];

include __DIR__ . '/common_header.php';
?>
<style>
.ts-note{margin-left:6px}
.ts-editable{cursor:pointer}
.ts-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.38);display:none;align-items:center;justify-content:center;z-index:1000}
.ts-modal{background:#fff;color:#111;border-radius:12px;max-width:520px;width:min(94vw,520px);box-shadow:0 18px 50px rgba(0,0,0,.25);border:1px solid rgba(0,0,0,.08)}
.ts-modal header{padding:12px 16px;border-bottom:1px solid rgba(0,0,0,.08);font-weight:700}
.ts-modal .body{padding:14px 16px}
.ts-modal footer{padding:12px 16px;border-top:1px solid rgba(0,0,0,.08);display:flex;gap:10px;justify-content:flex-end}
@media (prefers-color-scheme:dark){
  .ts-modal{background:#151a1f;color:#e5e7eb;border-color:#263241}
  .ts-modal header,.ts-modal footer{border-color:#263241}
}
.ts-icon-del{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border:1px solid currentColor;border-radius:6px;font-size:.8rem;line-height:1;opacity:.75;margin-left:6px;cursor:pointer}
.ts-icon-del:hover{opacity:1}
.ts-inline-actions{display:inline-flex;align-items:center}
</style>

<div class="card">
  <div class="spread">
    <h2 class="m0">Naptár</h2>
    <div class="small">Megtekintés: <strong><?= h($selected_user_display) ?></strong></div>
  </div>
  <?php if (is_admin()): ?>
    <form method="get" class="flex wrap mt10" style="align-items:flex-end;">
      <div>
        <label>Felhasználó</label>
        <select name="user_id">
          <?php foreach ($all_users as $au): ?>
            <option value="<?= (int)$au['id'] ?>" <?= ($au['id']==$selected_user_id?'selected':'') ?>><?= h($au['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Hónap</label><input type="month" name="month" value="<?= h($ym) ?>"></div>
      <div><button class="secondary" style="align-self:end">Ugrás</button></div>
    </form>
  <?php endif; ?>

  <?php if ($error): ?><div class="error mt10"><?= h($error) ?></div><?php endif; ?>
  <?php if ($ok): ?><div class="notice mt10"><?= h($ok) ?></div><?php endif; ?>

  <div class="flex" style="justify-content:space-between; align-items:center; margin-top:10px">
    <div>
      <?php $prev=date('Y-m',strtotime('-1 month',$firstTs)); $next=date('Y-m',strtotime('+1 month',$firstTs)); $qs=is_admin()?('&user_id='.$selected_user_id):''; ?>
      <a class="btn secondary" href="/calendar.php?month=<?= $prev ?><?= $qs ?>">◀ Előző</a>
      <a class="btn secondary" href="/calendar.php?month=<?= $next ?><?= $qs ?>">Következő ▶</a>
    </div>
    <div><strong><?= date('Y. F',$firstTs) ?></strong></div>
  </div>

  <div class="calendar mt10">
    <div class="head"><?php for($i=1;$i<=7;$i++): ?><div><?= day_name($i) ?></div><?php endfor; ?></div>
    <?php
      $cells=[]; for($i=1;$i<$startDow;$i++) $cells[]='';
      for($d=1;$d<=$daysInMonth;$d++) $cells[] = sprintf('%04d-%02d-%02d',(int)date('Y',$firstTs),(int)date('m',$firstTs),$d);
      while(count($cells)%7!==0) $cells[]='';
      for($i=0;$i<count($cells);$i+=7):
    ?>
      <div class="row">
        <?php for($j=0;$j<7;$j++):
          $date=$cells[$i+$j];
          $locked = $date && is_locked($date);
          $cls='cell'.($locked?' locked':'');
          $canInlineDelete = is_admin() || (!$locked && $selected_user_id === $viewer['id']);
          $canInlineEdit   = is_admin() || (!$locked && $selected_user_id === $viewer['id']);
        ?>
          <div class="<?= $cls ?>">
            <?php if ($date): ?>
              <div class="date"><a href="/calendar.php?month=<?= $ym ?>&date=<?= $date ?><?= is_admin()?('&user_id='.$selected_user_id):'' ?>"><?= h($date) ?></a>
                <?php if ($locked): ?><span class="badge">zárt</span><?php endif; ?>
              </div>
              <?php if (!empty($entriesByDate[$date])): ?>
                <ul style="padding-left:16px; margin:6px 0;">
                  <?php foreach ($entriesByDate[$date] as $e): ?>
                    <li>
                      <span class="ts-inline-actions <?= $canInlineEdit ? 'ts-editable' : '' ?>"
                            <?php if ($canInlineEdit): ?>
                              data-edit-id="<?= (int)$e['id'] ?>"
                              data-edit-date="<?= h($date) ?>"
                              data-edit-project="<?= (int)$e['project_id'] ?>"
                              data-edit-hours="<?= h((string)$e['hours']) ?>"
                              data-edit-note="<?= h($e['note']) ?>"
                            <?php endif; ?>
                            title="<?= $e['note'] ? h($e['note']) : '' ?>">
                        <?= h($e['project_name']) ?>: <strong><?= h((string)$e['hours']) ?> óra</strong>
                        <?php if (!empty($e['note'])): ?><span class="ts-note" aria-label="Megjegyzés">📝</span><?php endif; ?>
                        <?php if ($canInlineDelete): ?>
                          <button class="ts-icon-del" type="button"
                                  title="Törlés"
                                  data-delete-id="<?= (int)$e['id'] ?>"
                                  data-delete-date="<?= h($date) ?>">×</button>
                        <?php endif; ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <div class="spread"><h3 class="m0">Rögzítés egy napra</h3></div>
  <?php if (!$allow_edit && !is_admin()): ?>
    <p class="small">Másik felhasználó naptárát nézed – <strong>csak olvasás</strong>.</p>
  <?php else: ?>
    <form method="post" class="grid cols-3">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <div><label>Dátum</label><input type="date" name="work_date" value="<?= h($selectedDate ?? date('Y-m-d')) ?>" required></div>
      <div><label>Projekt</label><select name="project_id" required>
        <option value="">– válassz –</option>
        <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label>Órák</label><input type="number" name="hours" min="0" max="24" step="0.25" required></div>
      <div style="grid-column:1/-1"><label>Megjegyzés (opcionális)</label><input type="text" name="note"></div>
      <div><button>Mentés</button></div>
    </form>
  <?php endif; ?>
</div>

<!-- Delete modal -->
<div class="ts-modal-backdrop" id="tsDelBackdrop" role="dialog" aria-modal="true" aria-labelledby="tsDelTitle">
  <div class="ts-modal">
    <header id="tsDelTitle">Bejegyzés törlése</header>
    <div class="body">Biztosan törlöd ezt a bejegyzést?</div>
    <footer>
      <form method="post" id="tsDelForm">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="tsDelId" value="">
        <input type="hidden" name="work_date" id="tsDelDate" value="">
        <button type="button" class="btn secondary" data-close> Mégse </button>
        <button type="submit" class="btn"> Törlés </button>
      </form>
    </footer>
  </div>
</div>

<!-- Edit modal -->
<div class="ts-modal-backdrop" id="tsEditBackdrop" role="dialog" aria-modal="true" aria-labelledby="tsEditTitle">
  <div class="ts-modal">
    <header id="tsEditTitle">Bejegyzés szerkesztése</header>
    <div class="body">
      <form method="post" id="tsEditForm" class="grid cols-2">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="tsEditId" value="">
        <div>
          <label>Dátum</label>
          <input type="date" name="work_date" id="tsEditDate" required>
        </div>
        <div>
          <label>Projekt</label>
          <select name="project_id" id="tsEditProject" required>
            <option value="">– válassz –</option>
            <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Órák</label>
          <input type="number" name="hours" id="tsEditHours" min="0" max="24" step="0.25" required>
        </div>
        <div style="grid-column:1/-1">
          <label>Megjegyzés</label>
          <input type="text" name="note" id="tsEditNote">
        </div>
      </form>
    </div>
    <footer>
      <button type="button" class="btn secondary" data-close> Mégse </button>
      <button type="submit" class="btn" form="tsEditForm"> Mentés </button>
    </footer>
  </div>
</div>

<?php include __DIR__ . '/common_footer.php'; ?>
