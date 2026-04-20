<?php
/**
 * AssetMgr Patch4 (minimal, safe)
 * - Ensures AssetMgr uses its own session cookie name (ASSETMGR_SESSID)
 * - Forces DirectoryIndex index.php
 * - Makes public/index.php redirect to local login (or assets.php if logged in)
 *
 * Run:
 *   cd /var/www/html/assetmgr
 *   php patch4.php
 */

date_default_timezone_set('Europe/Budapest');

function backup($p){
  if(!file_exists($p)) return;
  $bak=$p.".bak_".date('Ymd_His');
  copy($p,$bak);
  echo "BACKUP $bak\n";
}

$root=getcwd();
echo "ROOT=$root\n";

// 1) config.php session_name
$cfg="$root/app/config.php";
if(file_exists($cfg)){
  $s=file_get_contents($cfg);
  $new=preg_replace("/('session_name'\\s*=>\\s*)'[^']*'/","$1'ASSETMGR_SESSID'",$s,-1,$c);
  if($new===null){ echo "ERROR regex config\n"; exit(1); }
  if($c>0){
    backup($cfg);
    file_put_contents($cfg,$new);
    echo "UPDATED config session_name -> ASSETMGR_SESSID\n";
  }else{
    echo "NOCHANGE config (could not find session_name key)\n";
  }
}else{
  echo "MISSING $cfg\n";
}

// 2) .htaccess DirectoryIndex
$ht="$root/public/.htaccess";
@mkdir(dirname($ht),0775,true);
backup($ht);
file_put_contents($ht,"DirectoryIndex index.php\n");
echo "WROTE $ht\n";

// 3) public/index.php
$idx="$root/public/index.php";
$code = <<<'PHP'
<?php
require_once __DIR__ . '/../app/auth.php';

if (function_exists('current_user') && current_user()) {
  header('Location: /assets.php');
  exit;
}
header('Location: /login.php');
exit;
PHP;

backup($idx);
file_put_contents($idx,$code);
echo "WROTE $idx\n";

echo "DONE\n";
