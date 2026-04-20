<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Ideiglenes azonosító beolvasási gyűjtőoldal.
 * A még anyaghoz nem rendelt kódok itt gyűlnek össze, majd később hozzárendelhetők.
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Ideiglenes azonosító beolvasás';
$loggedIn = true;

$identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
$stagingFeatureReady = warehouse_identifier_staging_feature_ready($config);
$materials = warehouse_identified_materials_all($config, false);
$manageableWarehouses = warehouse_manageable_warehouses($config, false);

// Visszairányítás segéd: a listaoldal szűrőit próbáljuk megőrizni a műveletek után is.
$redirectWithFilters = static function (array $query = []): void {
    $params = array_filter($query, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    });
    header('Location: /identifier_staging.php' . ($params ? '?' . http_build_query($params) : ''));
    exit;
};

// A staging oldal a végleges rögzítés előtti gyűjtőhely.
// Itt még nem az anyaghoz rögzítünk, csak előkészítjük és ellenőrizzük a kódokat.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $returnQuery = [
        'status' => (string)($_POST['return_status'] ?? 'pending'),
        'q' => (string)($_POST['return_q'] ?? ''),
        'page' => (int)($_POST['return_page'] ?? 1),
    ];

    try {
        if (!$identifierFeatureReady) {
            throw new RuntimeException('Az egyedi azonosítós bővítés adatbázis része még nincs telepítve.');
        }
        if (!$stagingFeatureReady) {
            throw new RuntimeException('Az ideiglenes azonosító beolvasó adatbázis része még nincs telepítve.');
        }

        if ($action === 'capture_bulk') {
            $result = warehouse_identifier_staging_capture_bulk(
                $config,
                (string)($_POST['identifier_lines'] ?? ''),
                (string)($_POST['capture_source'] ?? ''),
                (string)($_POST['capture_note'] ?? ''),
                (string)($_POST['scan_mode'] ?? 'single')
            );
            flash_set('msg', 'Ideiglenes beolvasás kész. Sorok: ' . (int)$result['total_rows'] . ', új: ' . (int)$result['inserted_rows'] . ', hibás: ' . (int)$result['error_rows'] . '.');
            if (!empty($result['errors'])) {
                $_SESSION['_flash_identifier_staging_errors'] = array_values((array)$result['errors']);
            }
            $redirectWithFilters($returnQuery);
        }

        if ($action === 'assign_selected') {
            $result = warehouse_identifier_staging_assign(
                $config,
                (int)($_POST['material_id'] ?? 0),
                (int)($_POST['warehouse_id'] ?? 0),
                (array)($_POST['entry_ids'] ?? []),
                (string)($_POST['assignment_note'] ?? '')
            );
            flash_set('msg', 'Hozzárendelés kész. Kijelölt: ' . (int)$result['selected_count'] . ', sikeres: ' . (int)$result['assigned_count'] . ', hibás: ' . (int)$result['error_count'] . '.');
            if (!empty($result['assigned'])) {
                $_SESSION['_flash_identifier_staging_assigned'] = array_values((array)$result['assigned']);
            }
            if (!empty($result['errors'])) {
                $_SESSION['_flash_identifier_staging_assign_errors'] = array_values((array)$result['errors']);
            }
            $redirectWithFilters($returnQuery);
        }

        if ($action === 'discard_selected') {
            $result = warehouse_identifier_staging_discard($config, (array)($_POST['entry_ids'] ?? []));
            flash_set('msg', 'Az ideiglenes listából elvetve: ' . (int)$result['discarded_count'] . ' sor.');
            $redirectWithFilters($returnQuery);
        }

        throw new RuntimeException('Ismeretlen művelet.');
    } catch (Throwable $e) {
        flash_set('err', $e->getMessage());
        $redirectWithFilters($returnQuery);
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$captureErrors = $_SESSION['_flash_identifier_staging_errors'] ?? [];
unset($_SESSION['_flash_identifier_staging_errors']);
$assignErrors = $_SESSION['_flash_identifier_staging_assign_errors'] ?? [];
unset($_SESSION['_flash_identifier_staging_assign_errors']);
$assignedValues = $_SESSION['_flash_identifier_staging_assigned'] ?? [];
unset($_SESSION['_flash_identifier_staging_assigned']);

if (!is_array($captureErrors)) {
    $captureErrors = [];
}
if (!is_array($assignErrors)) {
    $assignErrors = [];
}
if (!is_array($assignedValues)) {
    $assignedValues = [];
}

// A lenti táblázat függő / hozzárendelt / elvetett státusz szerint is szűrhető.
$list = warehouse_identifier_staging_list($config, $_GET);
$queryBase = [
    'status' => $list['status'] ?? 'pending',
    'q' => $list['q'] ?? '',
    'page' => $list['page'] ?? 1,
];
$buildQuery = static function (array $overrides = []) use ($queryBase): string {
    $params = array_merge($queryBase, $overrides);
    return http_build_query(array_filter($params, static function ($value): bool {
        return !($value === '' || $value === null || $value === 0 || $value === '0');
    }));
};

require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 gap-3 flex-wrap">
  <div>
    <h1 class="h4 m-0">Ideiglenes azonosító beolvasás</h1>
    <div class="text-secondary small">Közös ideiglenes lista külső beolvasáshoz. A kódok csak hozzárendelés után kerülnek be a rendes anyagazonosító nyilvántartásba.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="/material_identifiers.php">Azonosítók</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(warehouse_mobile_scanner_url('/identifier_staging_mobile.php')) ?>">Mobil szkenner</a>
    <a class="btn btn-sm btn-outline-secondary" href="/transfers.php">Átadások</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if (!empty($assignedValues)): ?>
<div class="alert alert-success">
  <div class="fw-semibold mb-2">Sikeresen hozzárendelve</div>
  <div class="small"><?= h(implode(', ', array_map('strval', $assignedValues))) ?></div>
</div>
<?php endif; ?>
<?php if (!empty($captureErrors)): ?>
<div class="alert alert-warning">
  <div class="fw-semibold mb-2">Hibalista az ideiglenes beolvasáshoz</div>
  <ul class="mb-0 small">
    <?php foreach ($captureErrors as $item): ?>
    <li><?= h((string)$item) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php if (!empty($assignErrors)): ?>
<div class="alert alert-warning">
  <div class="fw-semibold mb-2">Hibalista a hozzárendeléshez</div>
  <ul class="mb-0 small">
    <?php foreach ($assignErrors as $item): ?>
    <li><?= h((string)$item) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php if (!$identifierFeatureReady): ?>
<div class="alert alert-warning">Az egyedi azonosítós bővítés adatbázis része még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step12_material_identifiers.sql</code> fájlt.</div>
<?php elseif (!$stagingFeatureReady): ?>
<div class="alert alert-warning">Az ideiglenes azonosító beolvasó még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step14_identifier_staging.sql</code> fájlt.</div>
<?php else: ?>

<div class="row g-4">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div>
          <div class="fw-semibold">Új ideiglenes beolvasás</div>
          <div class="text-secondary small">Külső vonalkódolvasóval is használható. Egyszeres és páros kódbeolvasás is támogatott.</div>
        </div>
        <button class="btn btn-sm btn-outline-secondary wm-panel-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#identifier-staging-capture-panel" data-open-label="Elrejtés" data-closed-label="Megnyitás" aria-expanded="false">
          <span class="wm-panel-toggle-label">Megnyitás</span>
        </button>
      </div>
      <div id="identifier-staging-capture-panel" class="collapse" data-wm-panel="1" data-panel-key="identifier-staging-capture" data-default-open="1">
        <div class="card-body">
          <form method="post" class="row g-3" id="identifier-staging-capture-form">
            <input type="hidden" name="action" value="capture_bulk">
            <input type="hidden" name="return_status" value="<?= h((string)$list['status']) ?>">
            <input type="hidden" name="return_q" value="<?= h((string)$list['q']) ?>">
            <input type="hidden" name="return_page" value="<?= (int)$list['page'] ?>">

            <div class="col-12">
              <label class="form-label">Forrás / eszköz neve</label>
              <input class="form-control" name="capture_source" placeholder="pl. mobil / Android scanner / raktár 2 beolvasás">
            </div>
            <div class="col-12">
              <label class="form-label">Megjegyzés</label>
              <input class="form-control" name="capture_note" placeholder="opcionális megjegyzés a teljes listához">
            </div>
            <div class="col-md-6">
              <label class="form-label">Beolvasási mód</label>
              <select class="form-select" name="scan_mode" id="identifier-staging-scan-mode">
                <option value="single" selected>Egyszeres kód</option>
                <option value="pair">Páros kód (külső + belső)</option>
              </select>
              <div class="form-text">Páros módban egy rekordhoz két kód tartozik. A rekord csak a második Enter után kerül a listába.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Gyors scanner bemenet</label>
              <input class="form-control" type="text" id="identifier-staging-scan-input" autocomplete="off" placeholder="ide olvasson a vonalkódolvasó">
              <div class="form-text" id="identifier-staging-scan-help">Egyszeres módban minden Enter után új sor készül. Páros módban az első olvasás pufferbe kerül, a második után lesz új rekord.</div>
            </div>
            <div class="col-12">
              <div class="small text-secondary mb-2" id="identifier-staging-scan-pending">Páros módban még nincs függő első kód.</div>
            </div>
            <div class="col-12">
              <label class="form-label">Ideiglenes lista</label>
              <textarea class="form-control" name="identifier_lines" id="identifier-staging-lines" rows="12" placeholder="egyszeres mód: minden sor egy kód&#10;páros mód: egy sor = külső kód[TAB]belső kód" required></textarea>
              <div class="form-text">A rendszer nem veszi fel újra azt, ami már szerepel az adatbázisban vagy az ideiglenes listában. Páros módban a beillesztett lista lehet két soronkénti vagy egy sorban, tabbal elválasztott kódpár is.</div>
            </div>

            <div class="col-12">
              <div class="border rounded p-3 bg-light-subtle">
                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                  <div>
                    <div class="fw-semibold">Telefonos kamera szkenner</div>
                    <div class="small text-secondary">A sikeres beolvasások azonnal az ideiglenes adatbázisba kerülnek. A kamera hagyható nyitva, így folyamatosan gyűjthetők a kódok.</div>
                  </div>
                  <span class="badge text-bg-secondary" id="identifier-staging-camera-support">Ellenőrzés...</span>
                </div>

                <div class="small text-secondary mb-2" id="identifier-staging-camera-help">
                  A kamera használatához a böngészőnek kamerahasználatot kell engednie. iPhone-on és több mobilböngészőben HTTPS szükséges. A szövegmező alatti „Ideiglenes mentés” gomb csak a kézi listához kell, a kamerás találatok automatikusan mentődnek.
                </div>

                <div id="identifier-staging-camera-reader" class="border rounded bg-white mb-2" style="width:100%; max-width:460px; min-height:320px;"></div>

                <div class="d-flex gap-2 flex-wrap mb-2">
                  <button class="btn btn-outline-primary" type="button" id="identifier-staging-camera-start">Kamera indítása</button>
                  <button class="btn btn-outline-secondary" type="button" id="identifier-staging-camera-stop" disabled>Kamera leállítása</button>
                  <button class="btn btn-outline-primary" type="button" id="identifier-staging-camera-test">Teszt: getUserMedia</button>
                  <label class="btn btn-outline-dark mb-0" for="identifier-staging-camera-file">Fotó készítése / feltöltése</label>
                  <input class="d-none" type="file" accept="image/*" capture="environment" id="identifier-staging-camera-file">
                </div>

                <div class="small mb-2" id="identifier-staging-camera-status">Még nincs mentett beolvasás.</div>
                <div class="small text-secondary mb-2">Tipp: ugyanazt a kódot a rendszer rövid ideig nem menti újra, hogy a folyamatos kamera ne hozzon létre sok duplikátumot.</div>

                <div class="border rounded bg-white p-2 mb-2">
                  <div class="fw-semibold small mb-1">Diagnosztika</div>
                  <div class="small text-secondary font-monospace" id="identifier-staging-camera-diag">Betöltés...</div>
                </div>

                <div class="border rounded bg-white p-2">
                  <div class="d-flex justify-content-between align-items-center gap-2 mb-1 flex-wrap">
                    <div class="fw-semibold small">Beolvasási napló (aktuális oldal)</div>
                    <div class="small text-secondary">Sikeres mentések: <strong id="identifier-staging-camera-saved-count">0</strong></div>
                  </div>
                  <div id="identifier-staging-camera-log" class="small text-secondary">Még nincs naplózott beolvasás.</div>
                </div>
              </div>
            </div>

            <div class="col-12 d-flex gap-2 flex-wrap">
              <button class="btn btn-primary" type="submit">Ideiglenes mentés</button>
              <button class="btn btn-outline-secondary" type="button" id="identifier-staging-clear-btn">Mező ürítése</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8">
    <div class="card shadow-sm mb-4">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div>
          <div class="fw-semibold">Ideiglenes lista</div>
          <div class="text-secondary small">A kijelölt függő sorok hozzárendelhetők egy raktárhoz és anyaghoz, mintha ott lettek volna beolvasva.</div>
        </div>
        <div class="small text-secondary">Összes sor: <strong><?= (int)$list['total'] ?></strong></div>
      </div>
      <div class="card-body">
        <form method="get" class="row g-2 align-items-end mb-3">
          <div class="col-md-5">
            <label class="form-label">Keresés</label>
            <input class="form-control" name="q" value="<?= h((string)$list['q']) ?>" placeholder="azonosító, forrás, megjegyzés">
          </div>
          <div class="col-md-3">
            <label class="form-label">Állapot</label>
            <select class="form-select" name="status">
              <option value="pending" <?= (string)$list['status'] === 'pending' ? 'selected' : '' ?>>Függő</option>
              <option value="assigned" <?= (string)$list['status'] === 'assigned' ? 'selected' : '' ?>>Hozzárendelt</option>
              <option value="discarded" <?= (string)$list['status'] === 'discarded' ? 'selected' : '' ?>>Elvetett</option>
              <option value="all" <?= (string)$list['status'] === 'all' ? 'selected' : '' ?>>Összes</option>
            </select>
          </div>
          <div class="col-md-4 d-flex gap-2">
            <button class="btn btn-outline-secondary" type="submit">Szűrés</button>
            <a class="btn btn-outline-light border" href="/identifier_staging.php">Alaphelyzet</a>
          </div>
        </form>

        <form method="post" id="identifier-staging-assign-form">
          <input type="hidden" name="return_status" value="<?= h((string)$list['status']) ?>">
          <input type="hidden" name="return_q" value="<?= h((string)$list['q']) ?>">
          <input type="hidden" name="return_page" value="<?= (int)$list['page'] ?>">

          <div class="row g-2 align-items-end mb-3">
            <div class="col-lg-4">
              <label class="form-label">Anyag</label>
              <select class="form-select" name="material_id" id="identifier-staging-material-select">
                <option value="">— válassz anyagot —</option>
                <?php foreach ($materials as $material): ?>
                <option value="<?= (int)$material['id'] ?>"><?= h((string)$material['sku']) ?> · <?= h((string)$material['name']) ?><?= !empty($material['identifier_label']) ? ' (' . h((string)$material['identifier_label']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Raktár</label>
              <select class="form-select" name="warehouse_id" id="identifier-staging-warehouse-select">
                <option value="">— válassz raktárat —</option>
                <?php foreach ($manageableWarehouses as $warehouse): ?>
                <option value="<?= (int)$warehouse['id'] ?>"><?= h((string)$warehouse['name']) ?><?php if (!empty($warehouse['code'])): ?> (<?= h((string)$warehouse['code']) ?>)<?php endif; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-4">
              <label class="form-label">Közös megjegyzés</label>
              <input class="form-control" name="assignment_note" placeholder="opcionális, minden sikeres hozzárendeléshez">
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
            <button class="btn btn-primary" type="submit" name="action" value="assign_selected">Kijelöltek hozzárendelése</button>
            <button class="btn btn-outline-danger" type="submit" name="action" value="discard_selected" onclick="return confirm('Biztosan elveted a kijelölt ideiglenes sorokat?');">Kijelöltek elvetése</button>
            <span class="small text-secondary">Kijelölve: <strong id="identifier-staging-selected-count">0</strong></span>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th style="width: 40px;"><input type="checkbox" id="identifier-staging-check-all"></th>
                  <th>Kód(ok)</th>
                  <th>Forrás</th>
                  <th>Létrehozta</th>
                  <th>Állapot</th>
                  <th>Hozzárendelve / eredmény</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($list['rows'] ?? []) as $row): ?>
                <?php $isPending = (string)($row['status'] ?? '') === 'pending'; ?>
                <tr>
                  <td>
                    <?php if ($isPending): ?>
                    <input type="checkbox" class="identifier-staging-check" name="entry_ids[]" value="<?= (int)$row['id'] ?>">
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="fw-semibold"><code><?= h((string)$row['identifier_value']) ?></code><?php if (!empty($row['secondary_identifier_value'])): ?> <span class="text-secondary">↔</span> <code><?= h((string)$row['secondary_identifier_value']) ?></code><?php endif; ?></div>
                    <div class="small text-secondary">#<?= (int)$row['id'] ?> · <?= h((string)$row['created_at']) ?> · <?= (string)($row['scan_mode'] ?? 'single') === 'pair' ? 'Páros' : 'Egyszeres' ?></div>
                  </td>
                  <td>
                    <div><?= h((string)($row['capture_source'] ?? '—')) ?></div>
                    <?php if (!empty($row['note'])): ?><div class="small text-secondary"><?= nl2br(h((string)$row['note'])) ?></div><?php endif; ?>
                  </td>
                  <td>
                    <div><?= h((string)($row['created_by_name'] ?? '—')) ?></div>
                    <?php if (!empty($row['assigned_by_name'])): ?><div class="small text-secondary">utoljára: <?= h((string)$row['assigned_by_name']) ?></div><?php endif; ?>
                  </td>
                  <td>
                    <?php if ((string)$row['status'] === 'assigned'): ?>
                      <span class="badge bg-success">Hozzárendelt</span>
                    <?php elseif ((string)$row['status'] === 'discarded'): ?>
                      <span class="badge bg-secondary">Elvetett</span>
                    <?php else: ?>
                      <span class="badge text-bg-warning">Függő</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((string)$row['status'] === 'assigned'): ?>
                      <div class="small"><code><?= h((string)($row['assigned_material_sku'] ?? '')) ?></code> <?= h((string)($row['assigned_material_name'] ?? '')) ?></div>
                      <div class="small text-secondary">Raktár: <?= h((string)($row['assigned_warehouse_name'] ?? '')) ?><?php if (!empty($row['assigned_warehouse_code'])): ?> (<?= h((string)$row['assigned_warehouse_code']) ?>)<?php endif; ?></div>
                      <?php if (!empty($row['assigned_at'])): ?><div class="small text-secondary"><?= h((string)$row['assigned_at']) ?></div><?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($row['result_message'])): ?><div class="small mt-1 <?= (string)$row['status'] === 'pending' ? 'text-danger' : 'text-secondary' ?>"><?= nl2br(h((string)$row['result_message'])) ?></div><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($list['rows'])): ?>
                <tr><td colspan="6" class="text-center text-secondary py-4">Nincs megjeleníthető ideiglenes azonosító.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </form>

        <?php if (($list['pages'] ?? 1) > 1): ?>
        <nav aria-label="Ideiglenes azonosítók lapozása" class="mt-3">
          <ul class="pagination pagination-sm mb-0 flex-wrap">
            <?php for ($page = 1; $page <= (int)$list['pages']; $page++): ?>
            <li class="page-item <?= $page === (int)$list['page'] ? 'active' : '' ?>">
              <a class="page-link" href="/identifier_staging.php?<?= h($buildQuery(['page' => $page])) ?>"><?= $page ?></a>
            </li>
            <?php endfor; ?>
          </ul>
        </nav>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
<script>
(() => {
  const modeSelect = document.getElementById('identifier-staging-scan-mode');
  const scanInput = document.getElementById('identifier-staging-scan-input');
  const lines = document.getElementById('identifier-staging-lines');
  const pendingNode = document.getElementById('identifier-staging-scan-pending');
  const clearBtn = document.getElementById('identifier-staging-clear-btn');
  const scanHelp = document.getElementById('identifier-staging-scan-help');
  if (!modeSelect || !scanInput || !lines || !pendingNode) {
    return;
  }

  let pendingFirst = '';

  function normalize(value) {
    return (value || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase();
  }

  function appendLine(value) {
    const line = (value || '').trim();
    if (!line) {
      return;
    }
    const current = (lines.value || '').replace(/\s+$/u, '');
    lines.value = current ? current + "\n" + line : line;
    lines.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function refreshPending() {
    if (modeSelect.value === 'pair') {
      if (pendingFirst) {
        pendingNode.textContent = 'Páros mód: 1/2 kód beolvasva – várja a második kódot (' + pendingFirst + ').';
        pendingNode.className = 'small text-warning mb-2';
      } else {
        pendingNode.textContent = 'Páros módban még nincs függő első kód.';
        pendingNode.className = 'small text-secondary mb-2';
      }
      if (scanHelp) {
        scanHelp.textContent = 'Páros módban az első beolvasás még nem hoz létre sort. A második Enter után kerül be a pár a listába.';
      }
    } else {
      pendingNode.textContent = 'Egyszeres módban minden Enter után azonnal új sor készül.';
      pendingNode.className = 'small text-secondary mb-2';
      if (scanHelp) {
        scanHelp.textContent = 'Egyszeres módban minden Enter után új sor készül az ideiglenes listában.';
      }
    }
  }

  modeSelect.addEventListener('change', () => {
    pendingFirst = '';
    refreshPending();
    scanInput.focus();
  });

  scanInput.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') {
      return;
    }
    event.preventDefault();
    const value = (scanInput.value || '').trim();
    if (!value) {
      return;
    }

    if (modeSelect.value === 'pair') {
      if (!pendingFirst) {
        pendingFirst = value;
      } else {
        if (normalize(pendingFirst) === normalize(value)) {
          pendingNode.textContent = 'Hiba: a két kód nem lehet azonos (' + value + ').';
          pendingNode.className = 'small text-danger mb-2';
        } else {
          appendLine(pendingFirst + "	" + value);
          pendingFirst = '';
        }
      }
    } else {
      appendLine(value);
    }

    scanInput.value = '';
    refreshPending();
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', () => {
      pendingFirst = '';
      if (scanInput) {
        scanInput.value = '';
      }
      refreshPending();
    });
  }

  refreshPending();
})();
</script>

<script src="/assets/vendor/html5qrcode/html5-qrcode.min.js"></script>
<script>
(() => {
  const clearBtn = document.getElementById('identifier-staging-clear-btn');
  const lines = document.getElementById('identifier-staging-lines');
  const captureSourceInput = document.querySelector('input[name="capture_source"]');
  const captureNoteInput = document.querySelector('input[name="capture_note"]');
  const cameraSupport = document.getElementById('identifier-staging-camera-support');
  const cameraHelp = document.getElementById('identifier-staging-camera-help');
  const cameraStatus = document.getElementById('identifier-staging-camera-status');
  const cameraStartBtn = document.getElementById('identifier-staging-camera-start');
  const cameraStopBtn = document.getElementById('identifier-staging-camera-stop');
  const cameraTestBtn = document.getElementById('identifier-staging-camera-test');
  const cameraFileInput = document.getElementById('identifier-staging-camera-file');
  const cameraDiag = document.getElementById('identifier-staging-camera-diag');
  const cameraLog = document.getElementById('identifier-staging-camera-log');
  const savedCountNode = document.getElementById('identifier-staging-camera-saved-count');

  let html5Qr = null;
  let running = false;
  let primed = false;
  let savedCount = 0;
  const recentDetections = new Map();

  function setCameraStatus(message, mode = 'muted') {
    if (!cameraStatus) {
      return;
    }
    cameraStatus.classList.remove('text-secondary', 'text-success', 'text-danger', 'text-warning');
    if (mode === 'success') {
      cameraStatus.classList.add('text-success');
    } else if (mode === 'danger') {
      cameraStatus.classList.add('text-danger');
    } else if (mode === 'warning') {
      cameraStatus.classList.add('text-warning');
    } else {
      cameraStatus.classList.add('text-secondary');
    }
    cameraStatus.textContent = message;
  }

  function updateCameraSupportLabel(message, badgeClass) {
    if (!cameraSupport) {
      return;
    }
    cameraSupport.className = 'badge ' + badgeClass;
    cameraSupport.textContent = message;
  }

  function refreshSavedCount() {
    if (savedCountNode) {
      savedCountNode.textContent = String(savedCount);
    }
  }

  function appendLog(value, message, mode = 'secondary') {
    if (!cameraLog) {
      return;
    }
    if (cameraLog.dataset.empty !== '0') {
      cameraLog.innerHTML = '';
      cameraLog.dataset.empty = '0';
    }
    const row = document.createElement('div');
    row.className = 'py-1 border-top';
    const badgeClass = mode === 'success'
      ? 'text-bg-success'
      : (mode === 'danger' ? 'text-bg-danger' : (mode === 'warning' ? 'text-bg-warning' : 'text-bg-secondary'));
    row.innerHTML = '<span class="badge ' + badgeClass + ' me-2">' + escapeHtml(value || '—') + '</span>'
      + '<span>' + escapeHtml(message || '') + '</span>'
      + '<div class="text-secondary" style="font-size:12px;">' + new Date().toLocaleTimeString('hu-HU') + '</div>';
    cameraLog.prepend(row);
    while (cameraLog.childElementCount > 12) {
      cameraLog.removeChild(cameraLog.lastElementChild);
    }
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function refreshDiag(devices) {
    if (!cameraDiag) {
      return;
    }
    const ua = navigator.userAgent || '';
    const isSecure = (typeof window.isSecureContext !== 'undefined') ? window.isSecureContext : 'n/a';
    const hasMD = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const hasHtml5Qr = (typeof window.Html5Qrcode !== 'undefined');
    let devInfo = '';
    if (Array.isArray(devices) && devices.length > 0) {
      devInfo = devices.map((d, i) => '#' + (i + 1) + ' id=' + d.id + ' label=' + (d.label || '')).join(' | ');
    }
    cameraDiag.innerHTML = ''
      + '<div>URL: <strong>' + escapeHtml(location.origin) + '</strong></div>'
      + '<div>isSecureContext: <strong>' + escapeHtml(String(isSecure)) + '</strong></div>'
      + '<div>mediaDevices.getUserMedia: <strong>' + (hasMD ? 'OK' : 'NINCS') + '</strong></div>'
      + '<div>Html5Qrcode betöltve: <strong>' + (hasHtml5Qr ? 'OK' : 'NEM') + '</strong></div>'
      + (devInfo ? '<div>Kamerák: <strong>' + escapeHtml(devInfo) + '</strong></div>' : '')
      + '<div>UA: <strong>' + escapeHtml(ua) + '</strong></div>';
  }

  async function testGetUserMedia() {
    try {
      if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
        throw new Error('Nincs getUserMedia támogatás.');
      }
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      stream.getTracks().forEach((track) => track.stop());
      setCameraStatus('getUserMedia OK – a kamera hozzáférés működik.', 'success');
    } catch (error) {
      setCameraStatus('getUserMedia hiba: ' + (error?.message || 'ismeretlen hiba'), 'danger');
    }
  }

  async function primeIOS() {
    if (primed) {
      return;
    }
    primed = true;
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      stream.getTracks().forEach((track) => track.stop());
    } catch (error) {
    }
  }

  function chooseBackCameraId(devices) {
    if (!devices || !devices.length) {
      return null;
    }
    const back = devices.find((device) => /back|rear|environment/i.test(device.label || ''));
    if (back) {
      return back.id;
    }
    if (devices.length >= 2) {
      return devices[devices.length - 1].id;
    }
    return devices[0].id;
  }

  function buildConfig() {
    const reader = document.getElementById('identifier-staging-camera-reader');
    const width = Math.max(220, reader && reader.clientWidth ? reader.clientWidth : 320);
    const size = Math.min(280, Math.floor(width * 0.82));
    return {
      fps: 8,
      disableFlip: true,
      qrbox: { width: size, height: size },
      formatsToSupport: [
        Html5QrcodeSupportedFormats.QR_CODE,
        Html5QrcodeSupportedFormats.CODE_128,
        Html5QrcodeSupportedFormats.CODE_39,
        Html5QrcodeSupportedFormats.CODE_93,
        Html5QrcodeSupportedFormats.EAN_13,
        Html5QrcodeSupportedFormats.EAN_8,
        Html5QrcodeSupportedFormats.UPC_A,
        Html5QrcodeSupportedFormats.UPC_E,
        Html5QrcodeSupportedFormats.ITF,
        Html5QrcodeSupportedFormats.CODABAR,
      ],
    };
  }

  function rememberRecentDetection(value) {
    const now = Date.now();
    recentDetections.set(value, now);
    for (const [key, seenAt] of recentDetections.entries()) {
      if (now - seenAt > 3000) {
        recentDetections.delete(key);
      }
    }
  }

  function wasRecentlyDetected(value) {
    const lastSeen = recentDetections.get(value);
    return typeof lastSeen === 'number' && (Date.now() - lastSeen) < 3000;
  }

  async function saveDetectedIdentifier(value) {
    const formData = new FormData();
    formData.append('identifier_value', value);
    formData.append('capture_source', (captureSourceInput?.value || '').trim());
    formData.append('capture_note', (captureNoteInput?.value || '').trim());

    const response = await fetch('/identifier_staging_capture.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    let payload = null;
    try {
      payload = await response.json();
    } catch (error) {
      throw new Error('A mentési válasz nem értelmezhető.');
    }

    if (!response.ok || !payload?.ok) {
      throw new Error(payload?.message || 'A beolvasott azonosító mentése nem sikerült.');
    }

    return payload;
  }

  async function handleDecoded(decodedText) {
    const value = String(decodedText || '').trim();
    if (!value) {
      return;
    }
    if (wasRecentlyDetected(value)) {
      return;
    }
    rememberRecentDetection(value);
    setCameraStatus('Beolvasva, mentés folyamatban: ' + value, 'warning');

    try {
      const payload = await saveDetectedIdentifier(value);
      savedCount += 1;
      refreshSavedCount();
      setCameraStatus('Ideiglenesen mentve: ' + value, 'success');
      appendLog(value, payload?.message || 'Ideiglenesen mentve.', 'success');
      if (navigator.vibrate) {
        navigator.vibrate(120);
      }
    } catch (error) {
      setCameraStatus('Mentési hiba: ' + (error?.message || 'ismeretlen hiba'), 'danger');
      appendLog(value, error?.message || 'Mentési hiba.', 'danger');
    }
  }

  async function startCamera() {
    try {
      if (typeof window.Html5Qrcode === 'undefined') {
        throw new Error('A html5-qrcode library nem töltődött be.');
      }
      if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
        throw new Error('Nincs getUserMedia támogatás.');
      }
      if (running) {
        return;
      }
      running = true;
      cameraStartBtn.disabled = true;
      cameraStopBtn.disabled = false;

      await primeIOS();
      const devices = await Html5Qrcode.getCameras();
      refreshDiag(devices);

      const camId = chooseBackCameraId(devices);
      html5Qr = new Html5Qrcode('identifier-staging-camera-reader');
      await html5Qr.start(
        camId ? camId : { facingMode: 'environment' },
        buildConfig(),
        (decodedText) => {
          handleDecoded(decodedText);
        },
        () => {}
      );

      try {
        if (html5Qr && typeof html5Qr.applyVideoConstraints === 'function') {
          await html5Qr.applyVideoConstraints({ width: { ideal: 1280 }, height: { ideal: 720 } });
        }
      } catch (error) {
      }

      setCameraStatus('Kamera elindult, várja a vonalkódot vagy QR-kódot.', 'success');
    } catch (error) {
      setCameraStatus('Nem sikerült elindítani a kamerát: ' + (error?.message || 'ismeretlen hiba'), 'danger');
      await stopCamera();
    }
  }

  async function stopCamera() {
    running = false;
    if (cameraStartBtn) {
      cameraStartBtn.disabled = false;
    }
    if (cameraStopBtn) {
      cameraStopBtn.disabled = true;
    }

    try {
      if (html5Qr) {
        await html5Qr.stop();
        await html5Qr.clear();
      }
    } catch (error) {
    } finally {
      html5Qr = null;
      const reader = document.getElementById('identifier-staging-camera-reader');
      if (reader) {
        reader.innerHTML = '';
      }
    }
  }

  async function scanImageFile(file) {
    if (!file) {
      return;
    }
    try {
      if (typeof window.Html5Qrcode === 'undefined') {
        throw new Error('A html5-qrcode library nem töltődött be.');
      }
      const fileScanner = new Html5Qrcode('identifier-staging-camera-reader');
      const result = await fileScanner.scanFile(file, true);
      await fileScanner.clear();
      const value = String(result || '').trim();
      if (!value) {
        throw new Error('A képen nem találtam olvasható kódot.');
      }
      await handleDecoded(value);
    } catch (error) {
      setCameraStatus('A fotó feldolgozása nem sikerült: ' + (error?.message || 'ismeretlen hiba'), 'danger');
      appendLog('', error?.message || 'Fotó feldolgozási hiba.', 'danger');
    } finally {
      if (cameraFileInput) {
        cameraFileInput.value = '';
      }
    }
  }

  clearBtn?.addEventListener('click', () => {
    if (lines) {
      lines.value = '';
      lines.focus();
      setCameraStatus('A lista mező kiürítve.', 'muted');
    }
  });

  if (typeof window.Html5Qrcode !== 'undefined') {
    updateCameraSupportLabel('Támogatott', 'text-bg-success');
  } else {
    updateCameraSupportLabel('Nincs library', 'text-bg-danger');
    if (cameraHelp) {
      cameraHelp.textContent = 'A html5-qrcode JS library nem töltődött be, ezért a kamerás szkennelés nem fog működni. Ellenőrizd a /public/assets/vendor/html5qrcode/html5-qrcode.min.js fájlt.';
    }
    if (cameraStartBtn) {
      cameraStartBtn.disabled = true;
    }
    if (cameraFileInput) {
      cameraFileInput.disabled = true;
    }
    if (cameraTestBtn) {
      cameraTestBtn.disabled = true;
    }
  }

  if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
    if (cameraHelp) {
      cameraHelp.textContent = 'Ebben a böngészőben nincs elérhető kamera API, ezért az élő kamerás mód nem használható. Fotós beolvasás még ettől működhet.';
    }
    if (cameraStartBtn) {
      cameraStartBtn.disabled = true;
    }
  }

  if (!window.isSecureContext && cameraHelp) {
    cameraHelp.textContent += ' Jelenleg a kapcsolat nem biztonságos, ezért több mobilböngészőben a kamera nem fog elindulni.';
  }

  cameraLog && (cameraLog.dataset.empty = '1');
  refreshSavedCount();
  refreshDiag();

  cameraStartBtn?.addEventListener('click', startCamera);
  cameraStopBtn?.addEventListener('click', async () => {
    await stopCamera();
    setCameraStatus('A kamera leállt.', 'muted');
  });
  cameraTestBtn?.addEventListener('click', testGetUserMedia);
  cameraFileInput?.addEventListener('change', (event) => {
    const file = event.target?.files?.[0];
    scanImageFile(file);
  });

  window.addEventListener('beforeunload', stopCamera);

  const checkAll = document.getElementById('identifier-staging-check-all');
  const countNode = document.getElementById('identifier-staging-selected-count');
  const checks = Array.from(document.querySelectorAll('.identifier-staging-check'));

  function updateSelectedCount() {
    const count = checks.filter((item) => item.checked).length;
    if (countNode) {
      countNode.textContent = String(count);
    }
    if (checkAll) {
      checkAll.checked = checks.length > 0 && count === checks.length;
      checkAll.indeterminate = count > 0 && count < checks.length;
    }
  }

  checkAll?.addEventListener('change', () => {
    checks.forEach((item) => {
      item.checked = !!checkAll.checked;
    });
    updateSelectedCount();
  });

  checks.forEach((item) => item.addEventListener('change', updateSelectedCount));
  updateSelectedCount();
})();
</script>
