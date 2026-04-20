<!-- warehousemgr kommentelt forrás: Közös lábléc és kliens oldali panel-segédlogika. | A localStorage-s megjegyzett panelállapotok itt vannak kezelve. -->
  </div>
</main>
<footer class="pp-footer mt-0 pt-0">
  <div style="height:1px;width:100%;background:linear-gradient(to right, rgba(0,0,0,0), rgba(0,0,0,.22), rgba(0,0,0,0));"></div>
  <div class="py-3">
    <div class="container-fluid wm-shell text-center">
      <div class="fw-bold mb-1">Perfect-Phone</div>
      <div class="text-muted">&copy; 2026 / warehousemgr</div>
    </div>
  </div>
</footer>
<script src="/assets/bootstrap/bootstrap.bundle.min.js"></script>
<script>
(() => {
  // Bootstrap collapse panelek nyitott / zárt állapotának megjegyzése localStorage-ban.
  function initWarehousePanels() {
    if (typeof bootstrap === 'undefined') return;

    document.querySelectorAll('[data-wm-panel="1"]').forEach((panel) => {
      const panelId = panel.id || '';
      const panelKey = panel.dataset.panelKey || panelId;
      if (!panelId || !panelKey) return;

      const storageKey = 'wm-panel-state:' + panelKey;
      const collapse = bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false });
      const buttons = Array.from(document.querySelectorAll('.wm-panel-toggle[data-bs-target="#' + panelId + '"]'));

      const setButtonState = (isOpen) => {
        buttons.forEach((btn) => {
          const labelNode = btn.querySelector('.wm-panel-toggle-label');
          const openLabel = btn.dataset.openLabel || 'Elrejtés';
          const closedLabel = btn.dataset.closedLabel || 'Megnyitás';
          if (labelNode) {
            labelNode.textContent = isOpen ? openLabel : closedLabel;
          }
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      };

      panel.addEventListener('shown.bs.collapse', () => {
        try { localStorage.setItem(storageKey, 'open'); } catch (e) {}
        setButtonState(true);
      });

      panel.addEventListener('hidden.bs.collapse', () => {
        try { localStorage.setItem(storageKey, 'closed'); } catch (e) {}
        setButtonState(false);
      });

      let preferredState = null;
      try {
        preferredState = localStorage.getItem(storageKey);
      } catch (e) {}

      if (preferredState === null || preferredState === '') {
        preferredState = panel.dataset.defaultOpen === '1' ? 'open' : 'closed';
      }

      if (panel.dataset.forceOpen === '1') {
        preferredState = 'open';
        try { localStorage.setItem(storageKey, 'open'); } catch (e) {}
      }

      if (preferredState === 'open') {
        collapse.show();
        setButtonState(true);
      } else {
        collapse.hide();
        setButtonState(false);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWarehousePanels);
  } else {
    initWarehousePanels();
  }
})();
</script>
</body>
</html>
