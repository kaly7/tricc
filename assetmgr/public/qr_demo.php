<?php
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/auth.php';
require_login();

$title = 'QR teszt (diag)';
$page  = 'QR teszt';
require __DIR__.'/_header.php';
?>

<div class="container" style="max-width: 980px;">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h4 class="mb-0">QR teszt</h4>
      <div class="text-secondary small">iOS kompatibilis demó: hátlapi kamera + diagnosztika.</div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">Állapot</h6>
          <div id="diagBox" class="small"></div>
          <div id="errBox" class="alert alert-danger mt-3 d-none"></div>
          <div id="okBox" class="alert alert-success mt-3 d-none"></div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">Kamera beolvasás</h6>

          <div class="d-flex gap-2 flex-wrap mb-2">
            <button id="btnStart" class="btn btn-primary btn-sm" type="button">Kamera indítás</button>
            <button id="btnStop" class="btn btn-outline-secondary btn-sm" type="button" disabled>Leállítás</button>
            <button id="btnTestCam" class="btn btn-outline-primary btn-sm" type="button">Teszt: getUserMedia</button>
          </div>

          <div id="qr-reader" style="width:100%; max-width: 420px;"></div>

          <div class="form-text mt-2">
            Ha a gomb “nem csinál semmit”, akkor jellemzően a JS library nem töltött be, vagy nem HTTPS-en vagy.
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-3">Eredmény</h6>
          <label class="form-label">Beolvasott tartalom</label>
          <textarea id="resultText" class="form-control" rows="4" readonly></textarea>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <button id="btnCopy" class="btn btn-outline-secondary btn-sm" type="button" disabled>Másolás</button>
            <button id="btnClear" class="btn btn-outline-secondary btn-sm" type="button">Törlés</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- html5-qrcode helyi fájlból (ne CDN) -->
<script src="<?= e(asset_url('assets/vendor/html5qrcode/html5-qrcode.min.js')) ?>"></script>

<script>
(function(){
  const diagBox = document.getElementById('diagBox');
  const errBox = document.getElementById('errBox');
  const okBox  = document.getElementById('okBox');

  const startBtn = document.getElementById('btnStart');
  const stopBtn  = document.getElementById('btnStop');
  const testBtn  = document.getElementById('btnTestCam');
  const resultEl = document.getElementById('resultText');
  const copyBtn  = document.getElementById('btnCopy');
  const clearBtn = document.getElementById('btnClear');

  let html5Qr = null;
  let running = false;
  let primed = false;

  function showErr(msg){
    errBox.classList.remove('d-none');
    errBox.textContent = msg;
  }
  function clearErr(){
    errBox.classList.add('d-none');
    errBox.textContent = '';
  }
  function showOk(msg){
    okBox.classList.remove('d-none');
    okBox.textContent = msg;
  }
  function clearOk(){
    okBox.classList.add('d-none');
    okBox.textContent = '';
  }
  function kv(k,v){
    return `<div><span class="text-secondary">${k}:</span> <strong>${v}</strong></div>`;
  }

  async function refreshDiag(devices){
    const ua = navigator.userAgent || '';
    const isSecure = (typeof window.isSecureContext !== 'undefined') ? window.isSecureContext : 'n/a';
    const hasMD = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    const hasHtml5Qr = (typeof window.Html5Qrcode !== 'undefined');
    const origin = location.origin;

    let devInfo = '';
    if (Array.isArray(devices)) {
      devInfo = devices.map((d,i)=> `#${i+1} id=${d.id} label=${(d.label||'')}`).join(' | ');
    }

    diagBox.innerHTML =
      kv('URL', origin) +
      kv('isSecureContext', String(isSecure)) +
      kv('mediaDevices.getUserMedia', hasMD ? 'OK' : 'NINCS') +
      kv('Html5Qrcode betöltve', hasHtml5Qr ? 'OK' : 'NEM') +
      (devInfo ? kv('Kamerák (getCameras)', devInfo) : '') +
      kv('UA', ua);

    if (!hasHtml5Qr) {
      showErr("A html5-qrcode library nem töltődött be. Ellenőrizd, hogy létezik: /public/assets/vendor/html5qrcode/html5-qrcode.min.js");
    }
    if (!hasMD) {
      showErr("Nincs getUserMedia támogatás (valószínűleg nem HTTPS-en vagy iOS-en).");
    }
  }

  async function testGetUserMedia(){
    clearErr(); clearOk();
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      await refreshDiag();
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" }, audio: false });
      stream.getTracks().forEach(t => t.stop());
      showOk("getUserMedia OK – kamera hozzáférés működik.");
    } catch (e) {
      showErr("getUserMedia hiba: " + (e && e.name ? e.name : 'Error') + " – " + (e && e.message ? e.message : String(e)));
    }
  }

  async function primeIOS(){
    if (primed) return;
    primed = true;
    try {
      const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      s.getTracks().forEach(t => t.stop());
    } catch(e) {}
  }

  function chooseBackCameraId(devices){
    if (!devices || !devices.length) return null;
    const back = devices.find(d => /back|rear|environment/i.test(d.label || ''));
    if (back) return back.id;
    if (devices.length >= 2) return devices[devices.length - 1].id;
    return devices[0].id;
  }

  function buildConfig(){
    const el = document.getElementById('qr-reader');
    const w = Math.max(220, (el && el.clientWidth ? el.clientWidth : 320));
    const s = Math.min(280, Math.floor(w * 0.82)); // biztosan kisebb, mint a container
    return { fps: 8, disableFlip: true, qrbox: { width: s, height: s } };
  }

  async function startCamera(){
    clearErr(); clearOk();

    if (typeof window.Html5Qrcode === 'undefined') {
      await refreshDiag();
      return;
    }
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      await refreshDiag();
      return;
    }

    if (running) return;
    running = true;
    startBtn.disabled = true;
    stopBtn.disabled = false;

    try {
      await primeIOS();

      const devices = await Html5Qrcode.getCameras();
      await refreshDiag(devices);

      const camId = chooseBackCameraId(devices);
      const config = buildConfig();

      html5Qr = new Html5Qrcode("qr-reader");

      // FONTOS: a library bizonyos verziói csak string cameraId-t szeretnek, nem objektumot.
      await html5Qr.start(
        camId ? camId : { facingMode: "environment" }, // fallback 1 kulcsos objektum
        config,
        async (decodedText) => {
          resultEl.value = (decodedText || '').trim();
          copyBtn.disabled = !resultEl.value;
          await stopCamera();
        },
        () => {}
      );

      // Később próbáljuk ráerőltetni a jobb felbontást (ha támogatott)
      try {
        if (html5Qr && typeof html5Qr.applyVideoConstraints === "function") {
          await html5Qr.applyVideoConstraints({ width: { ideal: 1280 }, height: { ideal: 720 } });
        }
      } catch (e) {}

      showOk("Kamera elindult (hátlapi preferált), várja a QR kódot…");
    } catch (e) {
      showErr("start hiba: " + (e && e.name ? e.name : 'Error') + " – " + (e && e.message ? e.message : String(e)));
      await stopCamera();
    }
  }

  async function stopCamera(){
    if (!running) return;
    running = false;
    startBtn.disabled = false;
    stopBtn.disabled = true;

    try {
      if (html5Qr) {
        await html5Qr.stop();
        await html5Qr.clear();
      }
    } catch (e) {
    } finally {
      html5Qr = null;
      const el = document.getElementById('qr-reader');
      if (el) el.innerHTML = "";
    }
  }

  // Biztosan felkötjük az eseményeket
  startBtn.addEventListener('click', startCamera);
  stopBtn.addEventListener('click', stopCamera);
  testBtn.addEventListener('click', testGetUserMedia);

  copyBtn.addEventListener('click', async () => {
    const t = resultEl.value || '';
    if (!t) return;
    try { await navigator.clipboard.writeText(t); }
    catch(e){ resultEl.select(); document.execCommand('copy'); }
  });

  clearBtn.addEventListener('click', () => {
    resultEl.value = '';
    copyBtn.disabled = true;
  });

  refreshDiag();
})();
</script>

<?php require __DIR__.'/_footer.php'; ?>
