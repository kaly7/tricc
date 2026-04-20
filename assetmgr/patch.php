<?php
declare(strict_types=1);
function fail(string $msg): void { fwrite(STDERR, "ERROR: {$msg}\n"); exit(1); }
function ok(string $msg): void { fwrite(STDOUT, "OK: {$msg}\n"); }

$assetRoot = getcwd();
if (!is_dir($assetRoot . '/app') || !is_dir($assetRoot . '/public')) {
    fail("Run this from the AssetMgr project root (where ./app and ./public exist). Current: " . $assetRoot);
}

$configPath = $assetRoot . '/app/config.php';
$authPath   = $assetRoot . '/app/auth.php';

if (!file_exists($configPath)) fail("Missing file: {$configPath}");
if (!file_exists($authPath))   fail("Missing file: {$authPath}");

/* 1) Patch app/config.php session_name */
$config = file_get_contents($configPath);
if ($config === false) fail("Cannot read {$configPath}");

$before = $config;
$config = preg_replace(
    "/('session_name'\s*=>\s*)'[^']*'/",
    "$1'ASSETMGR_SESSID'",
    $config,
    -1,
    $count
);

if ($count === 0) {
    ok("config.php: session_name key not found (no change). You may set it manually to ASSETMGR_SESSID.");
} else {
    if ($config !== $before) {
        copy($configPath, $configPath . '.bak.' . date('Ymd_His'));
        file_put_contents($configPath, $config);
        ok("config.php: session_name set to ASSETMGR_SESSID (backup created).");
    } else {
        ok("config.php: already set (no change).");
    }
}

/* 2) Patch app/auth.php: add SSO bridge + call */
$auth = file_get_contents($authPath);
if ($auth === false) fail("Cannot read {$authPath}");

if (strpos($auth, 'function assetmgr_try_sso_from_auth_center') === false) {
    $bridge = <<<'PHPBRIDGE'

/**
 * SSO bridge from Auth Center to AssetMgr.
 */
function assetmgr_try_sso_from_auth_center(PDO $pdoAuth): void
{
    if (!empty($_SESSION['user_id'])) return;

    $authSessName = 'FEJLESZTES_SESSID';
    if (empty($_COOKIE[$authSessName])) return;

    $assetSessName = session_name();

    session_write_close();

    session_name($authSessName);
    session_start();

    $uid = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
    $fullName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['email'] ?? null;

    session_write_close();

    session_name($assetSessName);
    session_start();

    if (!$uid) return;

    $sql = "
      SELECT r.role_key
      FROM user_module_roles umr
      JOIN modules m ON m.id = umr.module_id
      JOIN roles r ON r.id = umr.role_id
      WHERE umr.user_id = :uid AND m.module_key = 'assetmgr'
      LIMIT 1
    ";
    $st = $pdoAuth->prepare($sql);
    $st->execute([':uid' => (int)$uid]);
    $roleKey = $st->fetchColumn();

    if (!$roleKey) return;

    $_SESSION['user_id']   = (int)$uid;
    $_SESSION['full_name'] = (string)($fullName ?: ('#' . $uid));
    $_SESSION['role_key']  = (string)$roleKey;
}

PHPBRIDGE;

    if (preg_match('/<\?php\s*(?:declare\s*\(.*?\);\s*)?/s', $auth, $m, PREG_OFFSET_CAPTURE)) {
        $insertPos = $m[0][1] + strlen($m[0][0]);
    } else {
        $insertPos = 0;
    }
    $auth = substr($auth, 0, $insertPos) . $bridge . substr($auth, $insertPos);
    $changed = true;
} else {
    $changed = false;
}

if (strpos($auth, 'assetmgr_try_sso_from_auth_center($pdoAuth)') === false) {
    $lines = explode("\n", $auth);
    $out = [];
    $inserted = false;
    for ($i=0; $i<count($lines); $i++) {
        $out[] = $lines[$i];
        if (!$inserted && preg_match('/\bnew\s+PDO\s*\(/', $lines[$i])) {
            $out[] = "  // SSO from Auth Center (if present)";
            $out[] = "  assetmgr_try_sso_from_auth_center(\$pdoAuth);";
            $inserted = true;
        }
    }
    if (!$inserted) {
        $out[] = "";
        $out[] = "// SSO from Auth Center (fallback insert point)";
        $out[] = "if (isset(\$pdoAuth)) { assetmgr_try_sso_from_auth_center(\$pdoAuth); }";
    }
    $auth = implode("\n", $out);
    $changed = true;
}

if ($changed) {
    copy($authPath, $authPath . '.bak.' . date('Ymd_His'));
    file_put_contents($authPath, $auth);
    ok("auth.php: SSO bridge added (backup created).");
} else {
    ok("auth.php: already patched (no change).");
}

ok("Patch complete.");
