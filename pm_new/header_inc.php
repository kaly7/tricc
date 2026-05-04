<?php
// Relatív útvonal kiszámítása a hívó fájl helyéhez képest
$_hdr_base = '';
if (isset($header_base_path)) {
    $_hdr_base = $header_base_path;
}
?>
<header class="pm-header">
  <div class="pm-header-inner">
    <div class="pm-header-logos">
      <img src="<?php echo $_hdr_base; ?>img/honeywell_logo.svg" class="logo-honeywell" alt="Honeywell">
      <img src="<?php echo $_hdr_base; ?>img/omron_logo.svg"     class="logo-omron"     alt="Omron">
    </div>
    <span class="pm-header-title">Robot Fleet Manager</span>
    <div class="pm-header-user">
      <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
        <span class="user-name">&#128100; <?php echo htmlspecialchars($_SESSION["username"]); ?></span>
        <a href="<?php echo $_hdr_base; ?>index.php" class="button_x" style="font-size:13px;padding:6px 16px;">Főmenü</a>
      <?php endif; ?>
    </div>
  </div>
</header>
