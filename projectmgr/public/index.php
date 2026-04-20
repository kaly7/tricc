<?php
// Entry safeguard: if user previously used Vehicles mode, force ProjectMgr mode on index entry.
if (!isset($_GET['module'])) {
  $parts = [];
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  if ($qs !== '') { parse_str($qs, $parts); }
  $parts['module'] = 'projectmgr';
  $path = strtok($_SERVER['REQUEST_URI'], '?');
  header('Location: ' . $path . '?' . http_build_query($parts));
  exit;
}

require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/views/_layout_top.php';
require dirname(__DIR__).'/views/_flash.php';
?>
<div class="card p-4">
  <h1 class="h4 mb-3">Üdv a ProjectMgr-ben</h1>
  <p>Válassz a menüből.</p>
</div>
<?php require dirname(__DIR__).'/views/_layout_bottom.php';
