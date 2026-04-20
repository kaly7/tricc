<?php
// teljesen standalone tesztoldal
?><!DOCTYPE html>
<html lang="hu">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Vonalkód / QR teszt</title>
  <style>
    body{
      font-family: Arial, sans-serif;
      margin:20px;
      background:#f5f6f8;
      color:#222;
    }
    .wrap{
      max-width:1000px;
      margin:0 auto;
    }
    .card{
      background:#fff;
      border:1px solid #ddd;
      border-radius:10px;
      padding:16px;
      margin-bottom:16px;
      box-shadow:0 2px 6px rgba(0,0,0,.05);
    }
    h1,h2,h3{
      margin-top:0;
    }
    .row{
      display:flex;
      gap:16px;
      flex-wrap:wrap;
    }
    .col{
      flex:1 1 420px;
      min-width:320px;
    }
    button{
      padding:10px 14px;
      border-radius:8px;
      border:1px solid #bbb;
      cursor:pointer;
      background:#fff;
    }
    button.primary{
      background:#0d6efd;
      color:#fff;
      border-color:#0d6efd;
    }
    button:disabled{
      opacity:.6;
      cursor:not-allowed;
    }
    #reader{
      width:100%;
      max-width:460px;
      min-height:320px;
      border:1px dashed #bbb;
      border-radius:8px;
      background:#fafafa;
      padding:8px;
    }
    textarea{
      width:100%;
      min-height:120px;
      resize:vertical;
      padding:10px;
      box-sizing:border-box;
      border-radius:8px;
      border:1px solid #bbb;
      font-family: monospace;
    }
    .ok{
      background:#e9f9ee;
      border:1px solid #9ad8ab;
      color:#165b2a;
      padding:10px;
      border-radius:8px;
      margin-top:10px;
    }
    .err{
      background:#fdecec;
      border:1px solid #efb5b5;
      color:#8a1f1f;
      padding:10px;
      border-radius:8px;
      margin-top:10px;
    }
    .diag{
      font-family: monospace;
      font-size: 13px;
      line-height: 1.5;
      white-space: pre-wrap;
      background:#fafafa;
      border:1px solid #e0e0e0;
      border-radius:8px;
      padding:10px;
    }
    .muted{
      color:#666;
      font-size:14px;
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Vonalkód / QR teszt</h1>
    <div class="muted">
      Standalone tesztoldal. Ha ez működik, akkor a kamera és a JS library rendben van.
    </div>
  </div>

  <div class="card">
    <h3>Diagnosztika</h3>
    <div id="diag" class="diag">Betöltés...</div>
    <div id="msg"></div>
  </div>

  <div class="row">
    <div class="col">
      <div class="card">
        <h3>Kamera</h3>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px;">
          <button id="btnStart" class="primary" type="button">Kamera indítás</button>
          <button id="btnStop" type="button" disabled>Leállítás</button>
          <button id="btnTest" type="button">Teszt getUserMedia</button>
        </div>

        <div id="reader"></div>

        <p class="muted" style="margin-top:12px;">
          iPhone/Safari alatt jellemzően HTTPS kell. HTTP-n helyi IP-ről gyakran nem indul a kamera.
        </p>
      </div>
    </div>

    <div class="col">
      <div class="card">
        <h3>Eredmény</h3>
        <label for="result">Beolvasott érték</label>
        <textarea id="result" readonly></textarea>

        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px;">
          <button id="btnCopy" type="button" disabled>Másolás</button>
          <button id="btnClear" type="button">Törlés</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Tedd a html5-qrcode.min.js fájlt ugyanebbe a mappába,
     vagy írd át az útvonalat a saját helyedre -->
<script src="./html5-qrcode.min.js"></script>

<script>
(function () {
  const diagEl   = document.getElementById('diag');
  const msgEl    = document.getElementById('msg');
  const btnStart = document.getElementById('btnStart');
  const btnStop  = document.getElementById('btnStop');
  const btnTest  = document.getElementById('btnTest');
  const btnCopy  = document.getElementById('btnCopy');
  const btnClear = document.getElementById('btnClear');
  const resultEl = document.getElementById('result');

  let qr = null;
  let running = false;
  let primed = false;

  function setMsg(text, type) {
    msgEl.innerHTML = '';
    if (!text) return;
    const div = document.createElement('div');
    div.className = type === 'ok' ? 'ok' : 'err';
    div.textContent = text;
    msgEl.appendChild(div);
  }

  function appendResult(text) {
    resultEl.value = text || '';
    btnCopy.disabled = !resultEl.value;
  }

  function refreshDiag(extra = '') {
    const lines = [];
    lines.push('URL: ' + location.href);
    lines.push('origin: ' + location.origin);
    lines.push('isSecureContext: ' + String(window.isSecureContext));
    lines.push('getUserMedia: ' + (!!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)));
    lines.push('Html5Qrcode loaded: ' + (typeof window.Html5Qrcode !== 'undefined'));
    lines.push('BarcodeDetector: ' + ('BarcodeDetector' in window));
    lines.push('userAgent: ' + navigator.userAgent);
    if (extra) lines.push(extra);
    diagEl.textContent = lines.join('\n');
  }

  async function primeIOS() {
    if (primed) return;
    primed = true;
    try {
      const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      s.getTracks().forEach(t => t.stop());
    } catch (e) {}
  }

  async function testCamera() {
    setMsg('', '');
    try {
      if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
        throw new Error('getUserMedia nem elérhető');
      }
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false
      });
      stream.getTracks().forEach(t => t.stop());
      setMsg('getUserMedia OK – a kamera elérhető.', 'ok');
    } catch (e) {
      setMsg('getUserMedia hiba: ' + (e.message || e), 'err');
    }
  }

  function chooseBackCameraId(devices) {
    if (!devices || !devices.length) return null;
    const back = devices.find(d => /back|rear|environment/i.test(d.label || ''));
    if (back) return back.id;
    return devices[devices.length - 1].id;
  }

  function buildConfig() {
    return {
      fps: 10,
      qrbox: { width: 260, height: 260 },
      disableFlip: true,
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
        Html5QrcodeSupportedFormats.CODABAR
      ]
    };
  }

  async function startScan() {
    setMsg('', '');

    if (running) return;

    if (typeof window.Html5Qrcode === 'undefined') {
      refreshDiag();
      setMsg('A html5-qrcode.min.js nincs betöltve. Ellenőrizd a script útvonalát.', 'err');
      return;
    }

    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      refreshDiag();
      setMsg('A böngésző nem támogatja a getUserMedia API-t.', 'err');
      return;
    }

    btnStart.disabled = true;
    btnStop.disabled  = false;
    running = true;

    try {
      await primeIOS();

      const devices = await Html5Qrcode.getCameras();
      refreshDiag('kamerák száma: ' + devices.length);

      if (!devices || !devices.length) {
        throw new Error('Nem található kamera');
      }

      const camId = chooseBackCameraId(devices);
      qr = new Html5Qrcode('reader');

      await qr.start(
        camId ? camId : { facingMode: 'environment' },
        buildConfig(),
        async (decodedText, decodedResult) => {
          appendResult(decodedText || '');
          setMsg('Sikeres olvasás.', 'ok');
          try {
            await stopScan();
          } catch (e) {}
        },
        function () {}
      );

      try {
        if (typeof qr.applyVideoConstraints === 'function') {
          await qr.applyVideoConstraints({
            width: { ideal: 1280 },
            height: { ideal: 720 }
          });
        }
      } catch (e) {}

      setMsg('Kamera elindult. Mutass egy QR-t vagy vonalkódot a kamerának.', 'ok');
    } catch (e) {
      setMsg('Indítási hiba: ' + (e.message || e), 'err');
      await stopScan();
    }
  }

  async function stopScan() {
    btnStart.disabled = false;
    btnStop.disabled  = true;

    if (!running) return;
    running = false;

    try {
      if (qr) {
        await qr.stop();
        await qr.clear();
      }
    } catch (e) {
    } finally {
      qr = null;
      document.getElementById('reader').innerHTML = '';
    }
  }

  btnStart.addEventListener('click', startScan);
  btnStop.addEventListener('click', stopScan);
  btnTest.addEventListener('click', testCamera);

  btnCopy.addEventListener('click', async function () {
    if (!resultEl.value) return;
    try {
      await navigator.clipboard.writeText(resultEl.value);
    } catch (e) {
      resultEl.select();
      document.execCommand('copy');
    }
  });

  btnClear.addEventListener('click', function () {
    appendResult('');
    setMsg('', '');
  });

  refreshDiag();
})();
</script>
</body>
</html>