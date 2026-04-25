<?php
require_once __DIR__.'/bootstrap.php';
$title = 'MJ-Ajánlat-PKS – Kézikönyv';
require __DIR__.'/_header.php';
?>

<div class="row justify-content-center">
<div class="col-lg-9">

<h4 class="mb-4">MJ-Ajánlat-PKS – Felhasználói kézikönyv</h4>

<div class="card mb-4">
  <div class="card-header fw-semibold">Mi ez a rendszer?</div>
  <div class="card-body">
    <p class="mb-1">Az <strong>MJ-Ajánlat-PKS</strong> egy gyengeáramú kivitelezési munkák <strong>Munka3-stílusú árajánlatát</strong> készítő webes modul.</p>
    <p class="mb-0">A rendszer kezeli a szerződött egységárakat (napidíjakat), a projekt tételeit (anyag + munkadíj), csoportosítja őket, majd automatikusan kiszámolja a törtnapidíj-sorokat és exportálja az ajánlatot CSV, XLSX vagy PDF formátumban.</p>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">Munkafolyamat</div>
  <div class="card-body p-0">
    <ol class="list-group list-group-numbered list-group-flush rounded">
      <li class="list-group-item">
        <strong>Egységárak importálása</strong> –
        A <em>Egységárak</em> menüpontban add meg a szerződött napidíjakat (pl. Technikus/nap, Mérnök/nap). Ezek alkotják az ajánlat munkadíj-sorait.
      </li>
      <li class="list-group-item">
        <strong>Projekt létrehozása</strong> –
        Az <em>Projektek</em> oldalon hozz létre új projektet névvel, leírással és opcionálisan a Munka1 referencia összeggel (eltérés összehasonlításhoz).
      </li>
      <li class="list-group-item">
        <strong>Tételek felvitele</strong> –
        A projekten belül tételeket adhatsz hozzá manuálisan, katalógusból választva, vagy <strong>XLSX/CSV import</strong>tal. Minden tételnél szerepel: megnevezés, gyártó, típus, mennyiség, egység, anyagár/egység, munkadíj/egység.
      </li>
      <li class="list-group-item">
        <strong>Csoportosítás és egységár-hozzárendelés</strong> –
        Az összevonás/szétbontás eszközökkel csoportosítsd a tételeket. Minden csoporthoz rendelj egységárat (napidíjtípust). A rendszer a csoport összes tételének munkadíját összeadja és elosztja az egységár napidíjával → <em>törtnapidíj sor</em>.
      </li>
      <li class="list-group-item">
        <strong>Generált Munka3 megtekintése</strong> –
        A <em>Munka3 generálás</em> gomb megmutatja a végleges ajánlattáblázatot: anyag sorok + napidíj sorok, összesítők nettó/ÁFA/bruttó bontásban.
      </li>
      <li class="list-group-item">
        <strong>Export</strong> –
        A generált nézetből <strong>CSV</strong>, <strong>XLSX</strong> (pénznem formátummal) vagy <strong>PDF</strong> (A4 fekvő) formátumban menthető az ajánlat. A fájlnév: <code>projektnev_YYYY-MM-DD_vN.ext</code>
      </li>
    </ol>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">Fontos fogalmak</div>
  <div class="card-body">
    <dl class="row mb-0">
      <dt class="col-sm-3">Törtnapidíj</dt>
      <dd class="col-sm-9">Az adott csoport összes tételének munkadíja (Σ mennyiség × munkadíj/egység) osztva a szerződött napidíjjal. Eredmény: pl. <em>0,4250 klt</em> = 0,4250 nap technikus munka.</dd>

      <dt class="col-sm-3">Csoport</dt>
      <dd class="col-sm-9">Összetartozó tételek halmaza, amelyekre egy egységárat (munkadíj-típust) rendelünk. Egy projekten belül több csoport is lehet, akár különböző egységárakkal.</dd>

      <dt class="col-sm-3">Anyagár katalógus</dt>
      <dd class="col-sm-9">Újrafelhasználható anyagárlista. A projektből a „Katalógusba ment" gombbal tölthető fel, majd új tétel hozzáadásakor a katalógusból választható.</dd>

      <dt class="col-sm-3">Verzió</dt>
      <dd class="col-sm-9">Minden mentéskor a rendszer pillanatképet tárol a tételekről. A projekt fejlécében lévő verzióválasztóval bármely korábbi állapot visszatölthető.</dd>

      <dt class="col-sm-3">Munka1 referencia</dt>
      <dd class="col-sm-9">Ha a projektnél megadod a Munka1 összeget, a generált nézetben és a kártyákon látható az eltérés (Ft és %).</dd>
    </dl>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">Import tippek</div>
  <div class="card-body">
    <ul class="mb-0">
      <li>XLSX és CSV formátum is támogatott. CSV esetén pontosvessző (<code>;</code>) vagy vessző elválasztó automatikusan felismert.</li>
      <li>Az import előtt oszlopleképezési lépésnél ellenőrizheted, melyik oszlopból kerüljön be melyik mező.</li>
      <li>Az egységárak importja az <em>Egységárak</em> oldalon XLSX-ből is lehetséges: kiválasztod az oszlopokat (megnevezés, egység, díj).</li>
    </ul>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header fw-semibold">Billentyűkombinációk / gyorsbillentyűk</div>
  <div class="card-body">
    <ul class="mb-0">
      <li>Tétel keresőmezőben <kbd>↑</kbd> <kbd>↓</kbd> a találatok között, <kbd>Enter</kbd> a kiválasztáshoz.</li>
      <li>Inline szerkesztésnél a sárga kiemelés jelzi a mentetlen módosítást; a <em>Mentés</em> sáv automatikusan megjelenik.</li>
      <li>A jelölőnégyzetes kijelölés után az alsó eszköztárban érhetők el az összevonás / szétbontás / egységár-hozzárendelés funkciók.</li>
    </ul>
  </div>
</div>

</div><!-- /col -->
</div><!-- /row -->

<?php require __DIR__.'/_footer.php'; ?>
