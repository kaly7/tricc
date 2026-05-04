<?php
$_pm_version = file_exists(__DIR__.'/version.txt') ? trim(file_get_contents(__DIR__.'/version.txt')) : '';
?>
<footer class="pm-footer">
  <span>Honeywell / Omron Fleet Manager Web<?php if ($_pm_version): ?> &nbsp;<span style="opacity:0.55;font-size:11px;">v<?php echo htmlspecialchars($_pm_version); ?></span><?php endif; ?></span>
  <span>&copy; 2026 Honeywell Nagykanizsa Divízió / kaly</span>
</footer>
