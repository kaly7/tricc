<?php
/**
 * warehousemgr kommentelt forrás
 * Mobilos, HTTPS-re kényszerített szkenner oldal.
 * Telefonos használatra készült, a sikeres találatokat azonnal a staging táblába menti.
 */
 declare(strict_types=1);
 require_once __DIR__ . '/../app/bootstrap.php';
 
 // A mobil kamerás beolvasás HTTPS alatt működik stabilan, ezért a kérést
// automatikusan a megfelelő 9444-es portra irányítjuk át.
if (!warehouse_request_is_https() || warehouse_request_port() !== 9444) {
     $target = warehouse_mobile_scanner_url((string)($_SERVER['REQUEST_URI'] ?? '/identifier_staging_mobile.php'));
     header('Location: ' . $target, true, 302);
     exit;
 }
 
 $title = 'Mobil szkenner';
 $loggedIn = true;
 
 $identifierFeatureReady = warehouse_material_identifier_feature_ready($config);
 $stagingFeatureReady = warehouse_identifier_staging_feature_ready($config);
 
 require __DIR__ . '/../app/views/layout/header.php';
 ?>
 
 <div class="d-flex align-items-center justify-content-between mb-3 gap-3 flex-wrap">
   <div>
     <h1 class="h4 m-0">Mobil szkenner</h1>
     <div class="text-secondary small">Külön telefonos oldal vonalkód és QR kód folyamatos olvasására. A sikeres találatok azonnal az ideiglenes adatbázisba mentődnek.</div>
   </div>
   <div class="d-flex gap-2 flex-wrap">
     <a class="btn btn-sm btn-outline-secondary" href="/identifier_staging.php">Ideiglenes lista</a>
     <a class="btn btn-sm btn-outline-secondary" href="/material_identifiers.php">Azonosítók</a>
   </div>
 </div>
 
 <?php if (!$identifierFeatureReady): ?>
 <div class="alert alert-warning">Az egyedi azonosítós bővítés adatbázis része még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step12_material_identifiers.sql</code> fájlt.</div>
 <?php elseif (!$stagingFeatureReady): ?>
 <div class="alert alert-warning">Az ideiglenes azonosító beolvasó még nincs telepítve. Futtasd a <code>database/warehousemgr_update_step14_identifier_staging.sql</code> fájlt.</div>
 <?php else: ?>
 
 <div class="row g-3">
   <div class="col-12">
     <div class="card shadow-sm">
       <div class="card-body">
         <div class="row g-3 align-items-end">
           <div class="col-md-6">
             <label class="form-label">Forrás / eszköz neve</label>
             <input id="captureSource" class="form-control" placeholder="pl. mobil / Android / raktár 2" value="mobil kamera">
           </div>
           <div class="col-md-6">
             <label class="form-label">Megjegyzés</label>
             <input id="captureNote" class="form-control" placeholder="opcionális megjegyzés a mentésekhez">
           </div>
         </div>
         <div class="form-text mt-2">Ezek az adatok minden újonnan mentett ideiglenes kódhoz hozzáadódnak.</div>
       </div>
     </div>
   </div>
 
   <div class="col-12">
     <div class="card shadow-sm">
       <div class="card-body">
         <h6 class="mb-3">Állapot és diagnosztika</h6>
         <div id="diagBox" class="small font-monospace"></div>
         <div id="errBox" class="alert alert-danger mt-3 d-none"></div>
         <div id="okBox" class="alert alert-success mt-3 d-none"></div>
         <div class="small text-secondary mt-2">A mobil szkenner ezen a telepítésen HTTPS-en, a 9444-es porton érhető el. Hibás elérés esetén az oldal automatikusan átirányítja magát a megfelelő címre.</div>
       </div>
     </div>
   </div>
 
   <div class="col-12 col-lg-6">
     <div class="card shadow-sm">
       <div class="card-body">
         <h6 class="mb-3">Kamera beolvasás</h6>
 
         <div class="d-flex gap-2 flex-wrap mb-2">
           <button id="btnStart" class="btn btn-primary" type="button">Kamera indítás</button>
           <button id="btnStop" class="btn btn-outline-secondary" type="button" disabled>Leállítás</button>
           <button id="btnTestCam" class="btn btn-outline-primary" type="button">Teszt: getUserMedia</button>
         </div>
 
         <div id="qr-reader" class="border rounded bg-white p-2" style="width:100%; max-width: 460px; min-height:320px;"></div>
 
         <div class="form-text mt-2">
           A kamera folyamatosan fut. Minden sikeres találat automatikusan mentődik az ideiglenes adatbázisba.
         </div>
       </div>
     </div>
   </div>
 
   <div class="col-12 col-lg-6">
     <div class="card shadow-sm">
       <div class="card-body">
         <h6 class="mb-3">Eredmény és mentési napló</h6>
         <label class="form-label">Legutóbb beolvasott érték</label>
         <textarea id="resultText" class="form-control" rows="4" readonly></textarea>
 
         <div class="d-flex gap-2 flex-wrap mt-3 mb-3">
           <button id="btnCopy" class="btn btn-outline-secondary btn-sm" type="button" disabled>Másolás</button>
           <button id="btnClear" class="btn btn-outline-secondary btn-sm" type="button">Törlés</button>
         </div>
 
         <div class="border rounded bg-light-subtle p-3 mb-3">
           <div class="fw-semibold mb-1">Aktuális session</div>
           <div class="small text-secondary">Mentett elemek: <strong id="savedCount">0</strong></div>
           <div class="small text-secondary">Utoljára: <span id="lastSavedAt">—</span></div>
         </div>
 
         <div class="border rounded p-2 bg-white" id="scanLogWrap">
           <div class="fw-semibold small mb-2">Mentési napló</div>
           <div id="scanLog" class="small text-secondary">Még nincs mentett beolvasás.</div>
         </div>
       </div>
     </div>
   </div>
 </div>
 
 <?php endif; ?>
 
 <?php require __DIR__ . '/../app/views/layout/footer.php'; ?>
 <script src="/assets/vendor/html5qrcode/html5-qrcode.min.js"></script>
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
   const captureSourceEl = document.getElementById('captureSource');
   const captureNoteEl = document.getElementById('captureNote');
   const scanLogEl = document.getElementById('scanLog');
   const savedCountEl = document.getElementById('savedCount');
   const lastSavedAtEl = document.getElementById('lastSavedAt');
 
   let html5Qr = null;
   let running = false;
   let primed = false;
   let savedCount = 0;
   const recentDetections = new Map();
   const pendingSaves = new Set();
 
   function showErr(msg){
     if (!errBox) return;
     errBox.classList.remove('d-none');
     errBox.textContent = msg;
   }
   function clearErr(){
     if (!errBox) return;
     errBox.classList.add('d-none');
     errBox.textContent = '';
   }
   function showOk(msg){
     if (!okBox) return;
     okBox.classList.remove('d-none');
     okBox.textContent = msg;
   }
   function clearOk(){
     if (!okBox) return;
     okBox.classList.add('d-none');
     okBox.textContent = '';
   }
   function kv(k,v){
     return `<div><span class="text-secondary">${escapeHtml(k)}:</span> <strong>${escapeHtml(v)}</strong></div>`;
   }
   function escapeHtml(value){
     return String(value || '')
       .replace(/&/g, '&amp;')
       .replace(/</g, '&lt;')
       .replace(/>/g, '&gt;')
       .replace(/"/g, '&quot;')
       .replace(/'/g, '&#039;');
   }
 
   function updateCounters(){
     if (savedCountEl) savedCountEl.textContent = String(savedCount);
   }
 
   function addLog(value, message, kind = 'secondary'){
     if (!scanLogEl) return;
     if (scanLogEl.dataset.empty !== '0') {
       scanLogEl.innerHTML = '';
       scanLogEl.dataset.empty = '0';
     }
     const row = document.createElement('div');
     row.className = 'border-top py-2';
     const badgeClass = kind === 'success'
       ? 'text-bg-success'
       : (kind === 'danger' ? 'text-bg-danger' : (kind === 'warning' ? 'text-bg-warning' : 'text-bg-secondary'));
     row.innerHTML = `<div><span class="badge ${badgeClass} me-2">${escapeHtml(value || '—')}</span>${escapeHtml(message || '')}</div>`
       + `<div class="text-secondary" style="font-size:12px;">${new Date().toLocaleTimeString('hu-HU')}</div>`;
     scanLogEl.prepend(row);
     while (scanLogEl.childElementCount > 15) {
       scanLogEl.removeChild(scanLogEl.lastElementChild);
     }
   }
 
   async function refreshDiag(devices){
     if (!diagBox) return;
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
       showErr('A html5-qrcode library nem töltődött be. Ellenőrizd: /public/assets/vendor/html5qrcode/html5-qrcode.min.js');
     }
     if (!hasMD) {
       showErr('Nincs getUserMedia támogatás. Valószínűleg nem HTTPS-en vagy, vagy a böngésző nem támogatja.');
     }
   }
 
   async function testGetUserMedia(){
     clearErr(); clearOk();
     if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
       await refreshDiag();
       return;
     }
     try {
       const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
       stream.getTracks().forEach(t => t.stop());
       showOk('getUserMedia OK – kamera hozzáférés működik.');
     } catch (e) {
       showErr('getUserMedia hiba: ' + (e && e.name ? e.name : 'Error') + ' – ' + (e && e.message ? e.message : String(e)));
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
     const s = Math.min(280, Math.floor(w * 0.82));
     return {
       fps: 8,
       disableFlip: true,
       qrbox: { width: s, height: s },
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
 
   function touchRecent(value){
     const now = Date.now();
     recentDetections.set(value, now);
     for (const [key, seenAt] of recentDetections.entries()) {
       if (now - seenAt > 3000) {
         recentDetections.delete(key);
       }
     }
   }
 
   function seenRecently(value){
     const lastSeen = recentDetections.get(value);
     return typeof lastSeen === 'number' && (Date.now() - lastSeen) < 3000;
   }
 
   async function saveIdentifier(value){
     const fd = new FormData();
     fd.append('identifier_value', value);
     fd.append('capture_source', (captureSourceEl?.value || '').trim());
     fd.append('capture_note', (captureNoteEl?.value || '').trim());
 
     const response = await fetch('/identifier_staging_capture.php', {
       method: 'POST',
       body: fd,
       credentials: 'same-origin',
       headers: {
         'X-Requested-With': 'XMLHttpRequest'
       }
     });
 
     let payload = null;
     try {
       payload = await response.json();
     } catch (e) {
       throw new Error('A mentési válasz nem értelmezhető.');
     }
 
     if (!response.ok || !payload?.ok) {
       throw new Error(payload?.message || 'A mentés nem sikerült.');
     }
 
     return payload;
   }
 
   async function handleDecoded(decodedText) {
     const value = String(decodedText || '').trim();
     if (!value) return;
     if (seenRecently(value) || pendingSaves.has(value)) return;
 
     touchRecent(value);
     pendingSaves.add(value);
     resultEl.value = value;
     copyBtn.disabled = !resultEl.value;
     clearErr();
     showOk('Beolvasva, mentés folyamatban: ' + value);
 
     try {
       const payload = await saveIdentifier(value);
       savedCount += 1;
       updateCounters();
       if (lastSavedAtEl) {
         lastSavedAtEl.textContent = new Date().toLocaleTimeString('hu-HU');
       }
       addLog(value, payload?.message || 'Ideiglenesen mentve.', 'success');
       showOk('Ideiglenesen mentve: ' + value);
       if (navigator.vibrate) {
         navigator.vibrate(120);
       }
     } catch (e) {
       addLog(value, e && e.message ? e.message : 'Mentési hiba.', 'danger');
       showErr('Mentési hiba: ' + (e && e.message ? e.message : String(e)));
     } finally {
       pendingSaves.delete(value);
     }
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
 
       html5Qr = new Html5Qrcode('qr-reader');
 
       await html5Qr.start(
         camId ? camId : { facingMode: 'environment' },
         config,
         (decodedText) => {
           handleDecoded(decodedText);
         },
         () => {}
       );
 
       try {
         if (html5Qr && typeof html5Qr.applyVideoConstraints === 'function') {
           await html5Qr.applyVideoConstraints({ width: { ideal: 1280 }, height: { ideal: 720 } });
         }
       } catch (e) {}
 
       showOk('Kamera elindult, várja a vonalkódot vagy QR kódot…');
     } catch (e) {
       showErr('start hiba: ' + (e && e.name ? e.name : 'Error') + ' – ' + (e && e.message ? e.message : String(e)));
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
       if (el) el.innerHTML = '';
     }
   }
 
   startBtn?.addEventListener('click', startCamera);
   stopBtn?.addEventListener('click', stopCamera);
   testBtn?.addEventListener('click', testGetUserMedia);
 
   copyBtn?.addEventListener('click', async () => {
     const t = resultEl.value || '';
     if (!t) return;
     try { await navigator.clipboard.writeText(t); }
     catch(e){ resultEl.select(); document.execCommand('copy'); }
   });
 
   clearBtn?.addEventListener('click', () => {
     resultEl.value = '';
     copyBtn.disabled = true;
     clearErr();
     clearOk();
   });
 
   window.addEventListener('beforeunload', stopCamera);
   if (scanLogEl) {
     scanLogEl.dataset.empty = '1';
   }
   updateCounters();
   refreshDiag();
 })();
 </script>
