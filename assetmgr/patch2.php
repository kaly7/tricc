<?php
/**
 * AssetMgr SSO Patch v2
 * - Sets AssetMgr session_name to ASSETMGR_SESSID (avoids cookie collision with Auth Center)
 * - Adds optional SSO bridge: if Auth Center session cookie exists, AssetMgr auto-logs-in based on auth_db module permission
 * - Forces public entrypoint to index.php (not index.html)
 *
 * Run:
 *   cd /var/www/html/assetmgr
 *   php patch2.php
 */

date_default_timezone_set('Europe/Budapest');

function backup_file($path) {
  if (!file_exists($path)) return;
  $ts = date('Ymd_His');
  $bak = $path . ".bak_" . $ts;
  copy($path, $bak);
  echo "BACKUP: $bak\n";
}

function replace_in_file($path, $pattern, $replacement, $flags = 0) {
  if (!file_exists($path)) {
    echo "SKIP missing: $path\n";
    return false;
  }
  $s = file_get_contents($path);
  $new = preg_replace($pattern, $replacement, $s, -1, $count);
  if ($new === null) {
    echo "ERROR regex failed for: $path\n";
    return false;
  }
  if ($count > 0) {
    backup_file($path);
    file_put_contents($path, $new);
    echo "UPDATED: $path (replacements=$count)\n";
    return true;
  }
  echo "NOCHANGE: $path\n";
  return false;
}

$root = getcwd();
echo "AssetMgr root: $root\n";

// 1) config.php session_name -> ASSETMGR_SESSID
$config = $root . "/app/config.php";
replace_in_file(
  $config,
  "/('session_name'\\s*=>\\s*)'[^']*'/",
  "$1'ASSETMGR_SESSID'"
);

// 2) auth.php: add SSO bridge if not present + fix common parse issue in execute([...])
$auth = $root . "/app/auth.php";
if (file_exists($auth)) {
  $src = file_get_contents($auth);

  // Fix common broken array execute syntax inserted by old patch: ':uid' => (int)$uid;
  $fixed = preg_replace("/(':uid'\\s*=>\\s*\\(int\\)\\$uid)\\s*;\\s*/", "$1, ", $src, -1, $c1);
  // Also fix ':uid' => $uid;
  $fixed = preg_replace("/(':uid'\\s*=>\\s*\\$uid)\\s*;\\s*/", "$1, ", $fixed, -1, $c2);

  if (($c1 + $c2) > 0) {
    backup_file($auth);
    file_put_contents($auth, $fixed);
    echo "FIXED: $auth (execute array commas: " . ($c1+$c2) . ")\n";
    $src = $fixed;
  }

  if (strpos($src, "ASSETMGR_SSO_BRIDGE_V2") === false) {
    // Insert bridge helper after PDO auth_db connection is initialized.
    // Heuristic: find first occurrence of "$pdoAuth" assignment or function connect_auth_db().
    $needlePos = strpos($src, "$pdoAuth");
    if ($needlePos === false) {
      // fallback: insert near top after opening php tag
      $insertPos = strpos($src, "<?php");
      $insertPos = ($insertPos === false) ? 0 : $insertPos + 5;
    } else {
      // Insert shortly after first $pdoAuth mention line end
      $insertPos = strpos($src, "\n", $needlePos);
      $insertPos = ($insertPos === false) ? $needlePos : $insertPos + 1;
    }

    $bridge = <<<PHP

// === ASSETMGR_SSO_BRIDGE_V2 ===
// If user is logged into Auth Center (FEJLESZTES_SESSID), auto-login AssetMgr by reading Auth Center session
// and validating auth_db module permission for module_key='assetmgr'.
// This keeps 8787 external login independent, while allowing internal SSO from Auth Center.
function assetmgr_try_sso_from_auth_center(PDO \$pdoAuth): void
{
    if (!empty(\$_SESSION['user_id'])) return;

    \$authSessName = 'FEJLESZTES_SESSID';
    if (empty(\$_COOKIE[\$authSessName])) return;

    \$assetSessName = session_name(); // should be ASSETMGR_SESSID
    @session_write_close();

    session_name(\$authSessName);
    @session_start();

    \$uid = \$_SESSION['user_id'] ?? \$_SESSION['uid'] ?? null;
    \$fullName = \$_SESSION['full_name'] ?? \$_SESSION['username'] ?? (\$_SESSION['email'] ?? null);

    @session_write_close();

    session_name(\$assetSessName);
    @session_start();

    if (!\$uid) return;

    \$sql = "
      SELECT r.role_key
      FROM user_module_roles umr
      JOIN modules m ON m.id = umr.module_id
      JOIN roles r ON r.id = umr.role_id
      WHERE umr.user_id = :uid AND m.module_key = 'assetmgr'
      LIMIT 1
    ";
    \$st = \$pdoAuth->prepare(\$sql);
    \$st->execute([':uid' => (int)\$uid]);
    \$roleKey = \$st->fetchColumn();

    if (!\$roleKey) return;

    \$_SESSION['user_id'] = (int)\$uid;
    \$_SESSION['full_name'] = (string)(\$fullName ?: ('#'.\$uid));
    \$_SESSION['role_key'] = (string)\$roleKey; // admin|user|viewer
}
// === /ASSETMGR_SSO_BRIDGE_V2 ===

PHP;

    $newSrc = substr($src, 0, $insertPos) . $bridge . substr($src, $insertPos);
    backup_file($auth);
    file_put_contents($auth, $newSrc);
    echo "INSERTED SSO bridge into: $auth\n";
    $src = $newSrc;
  } else {
    echo "SSO bridge already present: $auth\n";
  }

  // Ensure auth guard calls the bridge before enforcing login.
  // Heuristic: replace first occurrence of "if (!is_logged_in" to call assetmgr_try_sso_from_auth_center(\$pdoAuth) just before.
  $guardPattern = "/(\\n\\s*)(if\\s*\\(\\s*!\\s*is_logged_in\\s*\\()/";
  if (preg_match($guardPattern, $src)) {
    $src2 = preg_replace($guardPattern, "$1assetmgr_try_sso_from_auth_center(\$pdoAuth);$1$2", $src, 1, $cg);
    if ($cg > 0 && $src2 !== $src) {
      backup_file($auth);
      file_put_contents($auth, $src2);
      echo "UPDATED auth guard to call SSO bridge: $auth\n";
    } else {
      echo "NOCHANGE auth guard call: $auth\n";
    }
  } else {
    echo "WARN: Could not locate login guard pattern in auth.php; you may need to manually call assetmgr_try_sso_from_auth_center(\$pdoAuth) before redirect.\n";
  }
} else {
  echo "ERROR: missing $auth\n";
}

// 3) public/.htaccess + public/index.php to prefer index.php
$ht = $root . "/public/.htaccess";
if (!file_exists($ht)) {
  @mkdir(dirname($ht), 0775, true);
  file_put_contents($ht, "DirectoryIndex index.php\n");
  echo "CREATED: $ht\n";
} else {
  replace_in_file($ht, "/^DirectoryIndex\\s+.*$/m", "DirectoryIndex index.php");
}

$index = $root . "/public/index.php";
$indexCode = <<<PHP
<?php
require_once __DIR__ . '/../app/auth.php';

// If already logged in (either local login or SSO bridge), go to assets list:
if (function_exists('is_logged_in') && is_logged_in()) {
  header('Location: /assets.php');
  exit;
}

// Otherwise go to local login:
header('Location: /login.php');
exit;
PHP;

backup_file($index);
file_put_contents($index, $indexCode);
echo "WROTE: $index\n";

echo "DONE.\n";
