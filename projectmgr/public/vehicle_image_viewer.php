<?php
require dirname(__DIR__).'/app/Db.php';
require dirname(__DIR__).'/app/Auth.php';
require dirname(__DIR__).'/app/Middleware.php';
use App\Db; use App\Auth; use App\Middleware;

Auth::start(); Middleware::requireAuth();

$vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
$img_id     = (int)($_GET['img_id'] ?? 0);
if ($vehicle_id<=0 || $img_id<=0) { http_response_code(400); echo "Hiányzó paraméter"; exit; }

$pdo = Db::pdo();
$st = $pdo->prepare("SELECT orig_name, mime FROM vehicle_images WHERE id=? AND vehicle_id=?");
$st->execute([$img_id,$vehicle_id]);
$img = $st->fetch(PDO::FETCH_ASSOC);
if(!$img){ http_response_code(404); echo "Kép nem található"; exit; }

$src  = "/vehicle_image_raw.php?vehicle_id=".$vehicle_id."&img_id=".$img_id;
$name = (string)($img['orig_name'] ?? 'Kép');
$back = "/vehicle.php?id=".$vehicle_id;
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($name) ?></title>
  <style>
    html,body{height:100%; margin:0;}
    body{background:#111; color:#fff; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;}
    .topbar{
      position: fixed; left: 0; right: 0; top: 0;
      padding: 10px 12px;
      display:flex; align-items:center; justify-content:space-between; gap:12px;
      background: rgba(0,0,0,.55);
      backdrop-filter: blur(6px);
      z-index: 10;
    }
    .title{font-size:14px; opacity:.95; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70vw;}
    .btn{
      appearance:none; border:0; cursor:pointer;
      padding: 8px 12px; border-radius: 8px;
      background:#fff; color:#111; font-weight:600;
    }
    .wrap{
      height: 100%;
      display:flex; align-items:center; justify-content:center;
      padding: 56px 10px 10px;
      box-sizing:border-box;
    }
    img{
      max-width: 100%;
      max-height: calc(100vh - 70px);
      width:auto; height:auto;
      object-fit: contain;
      background:#fff;
      border-radius:10px;
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="title"><?= htmlspecialchars($name) ?></div>
    <button class="btn" onclick="pmClose()">Bezár</button>
  </div>

  <div class="wrap">
    <img src="<?= htmlspecialchars($src) ?>" alt="<?= htmlspecialchars($name) ?>">
  </div>

  <script>
    function pmClose(){
      try { window.close(); } catch(e) {}
      window.location.href = <?= json_encode($back) ?>;
    }
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape') pmClose();
    });
  </script>
</body>
</html>