<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
require dirname(__DIR__).'/app/Csrf.php';
require dirname(__DIR__).'/app/Helpers.php';
require dirname(__DIR__).'/app/Activity.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';

use App\Auth; use App\Middleware; use App\Db; use App\Helpers; use App\Activity;

Auth::start(); Middleware::requireAuth();
$pdo = Db::pdo();

$project_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$project = null;
$title = 'Globális üzenőfal';
$back = '/pm_projects.php';
if ($project_id) {
  $st = $pdo->prepare('SELECT id, number, name FROM projects WHERE id=?');
  $st->execute([$project_id]);
  $project = $st->fetch(PDO::FETCH_ASSOC);
  if (!$project) { http_response_code(404); exit('Projekt nem található'); }
  $title = 'Projekt üzenőfal – '.htmlspecialchars($project['number'].' — '.$project['name']);
  $back = '/pm_project_edit.php?id='.(int)$project_id;
}
$user = Auth::user();
$user_id = (int)$user['id'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h5"><?= $title ?></h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($back) ?>">Vissza</a>
    <?php if ($project_id): ?>
      <a class="btn btn-outline-primary" href="/pm_project_log.php?id=<?= (int)$project_id ?>">Projekt napló</a>
      <a class="btn btn-outline-success" href="/pm_files.php?id=<?= (int)$project_id ?>">Fájlok</a>
    <?php else: ?>
      <a class="btn btn-outline-primary" href="/pm_projects.php">Projektek</a>
    <?php endif; ?>
  </div>
</div>

<div class="card p-3 mb-3">
  <form method="post" action="/chat_post.php" class="row g-2" enctype="multipart/form-data">
    <?= \App\Csrf::field() ?>
    <input type="hidden" name="project_id" value="<?= (int)($project_id ?: 0) ?>">
    <input type="hidden" id="parent_id" name="parent_id" value="">
    <div class="col-12">
      <textarea id="chat_body" name="body" class="form-control" rows="3" maxlength="4000" placeholder="Írj üzenetet… (@név említés működik)" required></textarea>
    </div>
    <div class="col-md-8">
      <input type="file" name="file" class="form-control" form="chat_upload_form" />
    </div>
    <div class="col-md-4 d-flex justify-content-between align-items-center">
      <div class="small text-muted">Bejelentkezve: <?= htmlspecialchars($user['name'] ?? $user['email'] ?? 'felhasználó') ?></div>
      <button class="btn btn-primary">Küldés</button>
    </div>
  </form>
</div>

<div id="chat" class="card p-0" style="max-height: 65vh; overflow:auto;">
  <div id="chat-body">
    <div class="p-3 text-muted">Betöltés…</div>
  </div>
</div>

<form id="chat_upload_form" method="post" action="/chat_upload.php" enctype="multipart/form-data" class="d-none">
  <?= \App\Csrf::field() ?>
  <input type="hidden" name="message_id" id="upload_message_id" value="">
  <input type="file" name="file">
</form>

<script>
(function(){
  const chatBody = document.getElementById('chat-body');
  const params = new URLSearchParams(window.location.search);
  let sinceId = 0;
  let isAtBottom = true;
  let stream;

  function scrollToBottom() { chatBody.scrollTop = chatBody.scrollHeight; }
  function renderHtml(html, append=false) {
    if (!append) chatBody.innerHTML = html; else chatBody.insertAdjacentHTML('beforeend', html);
    if (isAtBottom) scrollToBottom();
  }

  function updateSeen() {
    if (sinceId>0) {
      const fd = new FormData();
      fd.set('id', params.get('id') || '');
      fd.set('last_id', String(sinceId));
      fetch('/chat_seen.php', {method:'POST', body: fd, credentials:'same-origin'});
    }
  }

  function openSSE(initialLoadHtml){
    try {
      const url = new URL('/chat_stream.php', window.location.origin);
      if (params.get('id')) url.searchParams.set('id', params.get('id'));
      if (sinceId>0) url.searchParams.set('since_id', String(sinceId));
      stream = new EventSource(url.toString(), { withCredentials: true });
      stream.addEventListener('messages', evt => {
        const data = JSON.parse(evt.data);
        sinceId = data.since_id || sinceId;
        // request fresh HTML for the delta via feed
        fetchFeed(false);
        updateSeen();
      });
      stream.addEventListener('ping', ()=>{});
      stream.onerror = () => { try { stream.close(); } catch(e){}; setTimeout(()=>openSSE(), 5000); };
    } catch(e){ setTimeout(()=>openSSE(), 5000); }
  }

  function fetchFeed(initial=false) {
    const url = new URL('/chat_feed.php', window.location.origin);
    if (params.get('id')) url.searchParams.set('id', params.get('id'));
    if (initial) url.searchParams.set('limit', '50');
    else if (sinceId>0) url.searchParams.set('since_id', String(sinceId));
    fetch(url.toString(), {credentials:'same-origin'}).then(r=>r.json()).then(data=>{
      if (!data || data.error) return;
      if (initial) renderHtml(data.html || '');
      else if (data.html) renderHtml(data.html, true);
      if (data.max_id && data.max_id > sinceId) sinceId = data.max_id;
      updateSeen();
    }).catch(()=>{});
  }

  // Track scroll to bottom
  chatBody.addEventListener('scroll', function(){
    const threshold = 40;
    isAtBottom = (chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight) < threshold;
  });

  // Initial load + start SSE
  fetchFeed(true);
  openSSE();

  // Reply shortcut (event delegation)
  chatBody.addEventListener('click', function(e){
    const t = e.target;
    if (t.matches('[data-reply]')) {
      document.getElementById('parent_id').value = t.getAttribute('data-id');
      document.getElementById('chat_body').focus();
    }
    if (t.matches('[data-edit]')) {
      const body = t.getAttribute('data-body') || '';
      document.getElementById('chat_body').value = body;
      document.getElementById('parent_id').value = ''; // editing doesn't change parent
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = '/chat_edit.php';
      form.innerHTML = `<?= str_replace("`", "\`", \App\Csrf::field()) ?>` + 
        '<input type="hidden" name="id" value="'+t.getAttribute('data-id')+'">' +
        '<input type="hidden" name="body" id="edit_body_hidden">';
      document.body.appendChild(form);
      // On next submit of main form, intercept and post to edit
      const mainForm = document.querySelector('form[action="/chat_post.php"]');
      const oldSubmit = mainForm.onsubmit;
      mainForm.onsubmit = function(ev){
        ev.preventDefault();
        document.getElementById('edit_body_hidden').value = document.getElementById('chat_body').value;
        form.submit();
      };
    }
  });
})();
</script>
