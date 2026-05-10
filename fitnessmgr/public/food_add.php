<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/functions.php';
require_once __DIR__ . '/../app/Services/FoodService.php';
require_once __DIR__ . '/../app/Services/AIService.php';
require_once __DIR__ . '/../app/Services/WeightService.php';
require_once __DIR__ . '/../app/Services/DailyService.php';

$page  = 'food';
$title = 'Étkezés rögzítése';

$u      = current_user();
$userId = (int)$u['id'];
$svc    = new FoodService();
$ai     = new AIService();

// AI gyors azonosítás (parseFoodMessage – mint a Mattermost bot)
$aiParsed     = null;
$aiParsedText = '';
$aiParsedDate = today();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_quick'])) {
  verify_csrf();
  $aiParsedText = trim($_POST['ai_text'] ?? '');
  $aiParsedDate = $_POST['eaten_at'] ?? today();
  if ($aiParsedText !== '') {
    $daily   = new DailyService();
    $wSvc    = new WeightService();
    $summary = $daily->getDaySummary($userId, $aiParsedDate);
    $profile = $wSvc->getProfile($userId) ?? [];
    $aiParsed = $ai->parseFoodMessage($aiParsedText, $summary, $profile);
  }
}

// AI gyors azonosítás eredményének mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai_parsed'])) {
  verify_csrf();
  $items = json_decode($_POST['parsed_items'] ?? '[]', true);
  $date  = $_POST['eaten_at'] ?? today();
  $saved = 0;
  foreach ((array)$items as $item) {
    try {
      $svc->addEntry($userId, [
        'custom_food_name' => $item['name']      ?? 'Ismeretlen',
        'amount_g'         => (int)($item['amount_g']  ?? 100),
        'calories'         => (int)($item['calories']  ?? 0),
        'protein_g'        => (float)($item['protein_g'] ?? 0),
        'carbs_g'          => (float)($item['carbs_g']   ?? 0),
        'fat_g'            => (float)($item['fat_g']     ?? 0),
        'meal_type'        => $_POST['meal_type'] ?? $item['meal_type'] ?? 'ebed',
        'eaten_at'         => $date,
      ]);
      $saved++;
    } catch (Throwable $e) {}
  }
  flash_set('ok', "{$saved} étel rögzítve a naplóba!");
  redirect('food_diary.php?date=' . $date);
}

// Étkezés mentése (kézi form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entry'])) {
  verify_csrf();
  try {
    $svc->addEntry($userId, $_POST);
    flash_set('ok', 'Étkezés rögzítve!');
    redirect('food_diary.php?date=' . ($_POST['eaten_at'] ?? today()));
  } catch (Throwable $e) {
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
}

// Egyéni étel mentése
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_custom'])) {
  verify_csrf();
  try {
    $newId = $svc->addCustomFood($userId, $_POST);
    flash_set('ok', 'Étel hozzáadva az adatbázishoz.');
    redirect('food_add.php?food_id=' . $newId);
  } catch (Throwable $e) {
    flash_set('err', 'Hiba: ' . $e->getMessage());
  }
}

require_once __DIR__ . '/_header.php';

$preselectedFood = null;
if (!empty($_GET['food_id'])) {
  $preselectedFood = $svc->getItem((int)$_GET['food_id']);
}
$mealDefault = $_GET['meal'] ?? 'ebed';
$dateDefault = $_GET['date'] ?? today();
?>

<?php if ($ai->isEnabled()): ?>
<div class="card mb-4 ai-card">
  <div class="card-header"><strong>🤖 AI gyors rögzítés</strong></div>
  <div class="card-body">
    <p class="small opacity-75 mb-2">Írd le mit ettél – az AI azonosítja és közvetlenül rögzíti a naplóba.</p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <div class="mb-2">
        <textarea name="ai_text" class="form-control" rows="2"
          placeholder="pl. reggeli volt pirítós szendvics párizsi felvágottal és trappista sajttal, plusz egy pohár tej"
          ><?= e($aiParsedText) ?></textarea>
      </div>
      <div class="row g-2 mb-2">
        <div class="col-6">
          <?php
            $autoMeal   = match(true) { (int)date('H')<10=>'reggeli',(int)date('H')<12=>'tizorai',(int)date('H')<15=>'ebed',(int)date('H')<18=>'uzsonna',default=>'vacsora' };
            $aiMealSel  = $_POST['meal_type'] ?? $mealDefault ?? $autoMeal;
          ?>
          <select name="meal_type" class="form-select form-select-sm">
            <?php foreach (['reggeli'=>'Reggeli','tizorai'=>'Tíz órai','ebed'=>'Ebéd','uzsonna'=>'Uzsonna','vacsora'=>'Vacsora'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $k===$aiMealSel?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6">
          <input type="date" name="eaten_at" class="form-control form-control-sm" value="<?= e($aiParsedDate) ?>">
        </div>
      </div>
      <button type="submit" name="ai_quick" value="1" class="btn btn-light btn-sm">🔍 Azonosítás</button>
    </form>

    <?php if ($aiParsed !== null): ?>
      <?php if (!$aiParsed['is_food'] || empty($aiParsed['items'])): ?>
        <div class="alert alert-warning mt-3 mb-0 py-2 small">
          Az AI nem ismert fel étkezést ebből a szövegből. Próbáld részletesebben leírni, vagy használd a kézi rögzítést lent.
        </div>
      <?php else: ?>
        <div class="mt-3 p-3 bg-white text-dark rounded border">
          <?php if (!empty($aiParsed['reply'])): ?>
            <p class="small mb-2"><?= e($aiParsed['reply']) ?></p>
          <?php endif; ?>
          <table class="table table-sm table-borderless mb-2 small">
            <thead class="text-muted"><tr><th>Étel</th><th>g</th><th>kcal</th><th>F</th><th>Sz</th><th>Zs</th></tr></thead>
            <tbody>
            <?php foreach ($aiParsed['items'] as $it): ?>
              <tr>
                <td><?= e($it['name'] ?? '') ?></td>
                <td><?= (int)($it['amount_g'] ?? 0) ?></td>
                <td><strong><?= (int)($it['calories'] ?? 0) ?></strong></td>
                <td><?= round((float)($it['protein_g'] ?? 0), 1) ?>g</td>
                <td><?= round((float)($it['carbs_g']   ?? 0), 1) ?>g</td>
                <td><?= round((float)($it['fat_g']     ?? 0), 1) ?>g</td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="save_ai_parsed" value="1">
            <input type="hidden" name="eaten_at" value="<?= e($aiParsedDate) ?>">
            <input type="hidden" name="parsed_items" value="<?= e(json_encode($aiParsed['items'], JSON_UNESCAPED_UNICODE)) ?>">
            <input type="hidden" name="meal_type" value="<?= e($aiMealSel) ?>">
            <button type="submit" class="btn btn-success btn-sm">✅ Rögzítés a naplóba</button>
          </form>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

  <!-- Bal: étel keresés -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><strong>🔍 Étel keresése</strong></div>
      <div class="card-body">
        <input type="text" id="foodSearch" class="form-control mb-3"
               placeholder="Kezdj el gépelni... pl. csirkemell, alma, rizs"
               value="<?= e($preselectedFood ? $preselectedFood['name'] : '') ?>">
        <div id="searchResults"></div>

        <?php if ($preselectedFood): ?>
          <div id="selectedFood" class="alert alert-success mt-2">
            <strong><?= e($preselectedFood['name']) ?></strong>
            <span class="badge bg-secondary ms-2"><?= e($preselectedFood['category']) ?></span><br>
            <small><?= $preselectedFood['calories_per_100g'] ?> kcal/100g |
              F:<?= $preselectedFood['protein_g'] ?>g |
              Sz:<?= $preselectedFood['carbs_g'] ?>g |
              Zs:<?= $preselectedFood['fat_g'] ?>g</small>
          </div>
        <?php else: ?>
          <div id="selectedFood" class="d-none alert alert-success mt-2"></div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Jobb: étkezés mentő form -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><strong>➕ Étkezés rögzítése</strong></div>
      <div class="card-body">
        <form method="post" id="addForm">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="save_entry" value="1">
          <input type="hidden" name="food_item_id" id="foodItemId"
                 value="<?= $preselectedFood ? $preselectedFood['id'] : '' ?>">

          <div class="mb-3">
            <label class="form-label">Étel neve *</label>
            <input type="text" name="custom_food_name" id="foodName" class="form-control"
                   placeholder="Ha nem találod a listában, gépeld be"
                   value="<?= $preselectedFood ? e($preselectedFood['name']) : '' ?>">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Mennyiség (gramm) *</label>
              <input type="number" name="amount_g" id="amountG" class="form-control"
                     min="1" max="2000" value="100" required>
            </div>
            <div class="col-6">
              <label class="form-label">Becsült kalória</label>
              <div class="form-control bg-light" id="calcCalories">
                <?= $preselectedFood ? $preselectedFood['calories_per_100g'] : '–' ?> kcal
              </div>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label">Étkezés típusa</label>
              <select name="meal_type" class="form-select">
                <?php foreach (['reggeli'=>'Reggeli','tizorai'=>'Tíz órai','ebed'=>'Ebéd','uzsonna'=>'Uzsonna','vacsora'=>'Vacsora'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $k===$mealDefault ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Dátum</label>
              <input type="date" name="eaten_at" class="form-control" value="<?= e($dateDefault) ?>">
            </div>
          </div>

          <!-- Manuális kalória ha nincs adatbázis étel -->
          <div id="manualMacros" class="<?= $preselectedFood ? 'd-none' : '' ?>">
            <div class="mb-2">
              <label class="form-label">Kalória (manuálisan, kcal)</label>
              <input type="number" name="calories" class="form-control" min="0" placeholder="pl. 350">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label small">Fehérje (g)</label>
                <input type="number" name="protein_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="0">
              </div>
              <div class="col-4">
                <label class="form-label small">Szénhidrát (g)</label>
                <input type="number" name="carbs_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="0">
              </div>
              <div class="col-4">
                <label class="form-label small">Zsír (g)</label>
                <input type="number" name="fat_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="0">
              </div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Megjegyzés (opcionális)</label>
            <input type="text" name="notes" class="form-control" placeholder="pl. házi, étteremben...">
          </div>

          <button type="submit" class="btn btn-success w-100">💾 Rögzítés</button>
        </form>
      </div>
    </div>

    <!-- Egyéni étel hozzáadása -->
    <div class="card mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong>🆕 Egyéni étel hozzáadása</strong>
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#customFoodForm">+/-</button>
      </div>
      <div class="collapse" id="customFoodForm">
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="save_custom" value="1">
            <div class="mb-2">
              <input type="text" name="name" class="form-control" placeholder="Étel neve *" required>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <input type="number" name="calories_per_100g" class="form-control" placeholder="Kalória/100g *" required min="0">
              </div>
              <div class="col-6">
                <select name="category" class="form-select">
                  <?php foreach (['hús','hal','tojás','tejtermék','pékáru','gabona','zöldség','gyümölcs','édesség','ital','zsír/olaj','főételek','főzelék/leves','gyorsétterem','egyéb'] as $cat): ?>
                    <option><?= $cat ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-4"><input type="number" name="protein_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="Fehérje g"></div>
              <div class="col-4"><input type="number" name="carbs_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="Szénhidrát g"></div>
              <div class="col-4"><input type="number" name="fat_g" class="form-control form-control-sm" step="0.1" min="0" placeholder="Zsír g"></div>
            </div>
            <button type="submit" class="btn btn-outline-success btn-sm">Mentés adatbázisba</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$cal100 = $preselectedFood ? (int)$preselectedFood['calories_per_100g'] : 0;
$extraJs = <<<JS
<script>
let selectedCal100 = {$cal100};

function updateCalc() {
  const amount = parseInt(document.getElementById('amountG').value) || 0;
  if (selectedCal100 > 0) {
    document.getElementById('calcCalories').textContent = Math.round(selectedCal100 * amount / 100) + ' kcal';
  }
}

document.getElementById('amountG').addEventListener('input', updateCalc);

let searchTimer = null;
document.getElementById('foodSearch').addEventListener('input', function() {
  clearTimeout(searchTimer);
  const q = this.value.trim();
  if (q.length < 2) { document.getElementById('searchResults').innerHTML = ''; return; }
  searchTimer = setTimeout(() => {
    fetch('api/food_search.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        const div = document.getElementById('searchResults');
        if (!data.length) { div.innerHTML = '<p class="text-muted small">Nincs találat. Gépeld be manuálisan, vagy add hozzá egyéni ételként.</p>'; return; }
        div.innerHTML = '<div class="list-group">' + data.map(item =>
          '<button type="button" class="list-group-item list-group-item-action py-1" onclick="selectFood(' + item.id + ',\'' + item.name.replace(/'/g,"\\'") + '\',' + item.calories_per_100g + ')">' +
          '<span class="fw-semibold">' + item.name + '</span>' +
          '<span class="badge bg-secondary ms-2">' + item.category + '</span>' +
          '<span class="float-end text-muted small">' + item.calories_per_100g + ' kcal/100g</span>' +
          '</button>'
        ).join('') + '</div>';
      });
  }, 300);
});

function selectFood(id, name, cal100) {
  selectedCal100 = cal100;
  document.getElementById('foodItemId').value = id;
  document.getElementById('foodName').value = name;
  document.getElementById('searchResults').innerHTML = '';
  document.getElementById('foodSearch').value = name;
  document.getElementById('selectedFood').className = 'alert alert-success mt-2';
  document.getElementById('selectedFood').innerHTML = '<strong>' + name + '</strong> – ' + cal100 + ' kcal/100g';
  document.getElementById('manualMacros').className = 'd-none';
  updateCalc();
}
</script>
JS;
?>
<?php require_once __DIR__ . '/_footer.php'; ?>
