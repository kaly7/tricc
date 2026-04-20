<?php
declare(strict_types=1);
/**
 * warehousemgr kommentelt forrás
 * Teljes rendszerürítés / nullázás.
 * Admin oldal, ami a warehousemgr teljes adatállományát kiüríti:
 * - adatbázis táblák tartalma
 * - generált PDF-ek, archívumok, ideiglenes fájlok a storage alatt
 */
require_once __DIR__ . '/../app/bootstrap.php';

$title = 'Teljes ürítés';
$loggedIn = true;

if (!warehouse_module_admin($config)) {
    http_response_code(403);
    echo '403 - Ehhez az oldalhoz warehousemgr admin jogosultság szükséges.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'reset_everything') {
        $confirmPhrase = trim((string)($_POST['confirm_phrase'] ?? ''));
        $confirmChecked = isset($_POST['confirm_reset']) && (string)$_POST['confirm_reset'] === '1';

        if (!$confirmChecked) {
            flash_set('err', 'A teljes ürítés előtt jelöld be a megerősítést.');
            header('Location: /system_reset.php');
            exit;
        }

        if ($confirmPhrase !== 'TÖRLÉS') {
            flash_set('err', 'A megerősítő szöveg nem megfelelő. Pontosan ezt írd be: TÖRLÉS');
            header('Location: /system_reset.php');
            exit;
        }

        try {
            $summary = warehouse_reset_all_data($config);
            flash_set(
                'msg',
                'A warehousemgr teljes adatállománya törölve lett. Kiürített táblák: ' . (int)$summary['table_count']
                . ', törölt fájlok: ' . (int)$summary['deleted_files']
                . ', törölt mappák: ' . (int)$summary['deleted_dirs'] . '.'
            );
        } catch (Throwable $e) {
            flash_set('err', 'Teljes ürítési hiba: ' . $e->getMessage());
        }

        header('Location: /system_reset.php');
        exit;
    }
}

$msg = flash_get('msg');
$err = flash_get('err');
$tables = warehouse_resettable_tables($config);
require __DIR__ . '/../app/views/layout/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 m-0 text-danger">Teljes ürítés</h1>
    <div class="text-secondary small">Minden adat törlése és a rendszer üres állapotba állítása.</div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="/warehouses.php">Raktárak</a>
    <a class="btn btn-sm btn-outline-secondary" href="/audit_log.php">Napló</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-12 col-xl-7">
    <div class="card border-danger shadow-sm">
      <div class="card-header bg-danger-subtle text-danger-emphasis">
        <div class="fw-semibold">Figyelem: vissza nem vonható művelet</div>
      </div>
      <div class="card-body">
        <p class="mb-3">
          Ez a művelet a <strong>warehousemgr teljes tartalmát</strong> kiüríti.
          A táblák szerkezete megmarad, de az adatok törlődnek, így a rendszer újra üresen indul.
        </p>

        <div class="alert alert-warning mb-3">
          Törlésre kerülnek többek között:
          <ul class="mb-0 mt-2">
            <li>anyagtörzs és árak</li>
            <li>anyagazonosítók és ideiglenes beolvasások</li>
            <li>raktárak, hozzáférések és partnerek</li>
            <li>készlet, készletmozgások és átadások</li>
            <li>átadási dokumentumok, készlet riport PDF-ek, archív fájlok</li>
            <li>import naplók és ideiglenes fájlok</li>
          </ul>
        </div>

        <form method="post" class="row g-3" onsubmit="return confirm('Biztosan teljesen ki akarod üríteni a warehousemgr rendszert?');">
          <input type="hidden" name="action" value="reset_everything">

          <div class="col-12">
            <label class="form-label">Megerősítés</label>
            <div class="form-text mb-2">A folytatáshoz pontosan ezt írd be: <strong>TÖRLÉS</strong></div>
            <input class="form-control" name="confirm_phrase" autocomplete="off" placeholder="TÖRLÉS">
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="confirm_reset" id="confirm_reset" value="1">
              <label class="form-check-label" for="confirm_reset">
                Tudomásul veszem, hogy ez a művelet a warehousemgr teljes adatállományát törli.
              </label>
            </div>
          </div>

          <div class="col-12 d-flex justify-content-end">
            <button class="btn btn-danger" type="submit">Teljes ürítés indítása</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card shadow-sm h-100">
      <div class="card-body">
        <h2 class="h6">Érintett adatbázis táblák</h2>
        <div class="text-secondary small mb-3">A jelenlegi adatbázisban ezek a warehousemgr táblák lesznek kiürítve.</div>
        <?php if (!$tables): ?>
          <div class="text-secondary">Nem található kiüríthető tábla.</div>
        <?php else: ?>
          <div class="border rounded p-2 bg-light" style="max-height: 420px; overflow:auto;">
            <ul class="small mb-0 ps-3">
              <?php foreach ($tables as $table): ?>
                <li><code><?= h($table) ?></code></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
