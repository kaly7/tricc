<?php
$_agv_ver = file_exists(__DIR__ . '/version.txt') ? trim(file_get_contents(__DIR__ . '/version.txt')) : '';
?>
</div><!-- .agv-content -->
<footer class="pm-footer">
  <span>AGV Manager<?php if ($_agv_ver): ?> <span style="opacity:.5;font-size:11px;">v<?= e($_agv_ver) ?></span><?php endif; ?></span>
  <span>&copy; 2026 kaly</span>
</footer>
<script src="assets/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
