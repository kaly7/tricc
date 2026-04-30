// ============================================================
// PP_slave_espnow  –  ESP-NOW slave firmware (ESP32)
// ============================================================
// Célhardver: WeMos D1 Mini32 vagy bármely ESP32 lap
// Arduino board: esp32:esp32:d1_mini32  (vagy a saját boardod)
// Core: esp32:esp32 ≥ 3.x
//
// Szükséges könyvtárak (Library Manager):
//   - DHT sensor library  (Adafruit)
//   - Adafruit Unified Sensor
//   - ArduinoJson (≥ 6.x)
//   - WebServer    (ESP32 beépített)
//   - Preferences  (ESP32 beépített)
//
// Alapértelmezett bekötés:
//   DHT22 DATA  → GPIO4   – 4k7Ω pull-up VCC-re
//   Kontakt     → GPIO5   – másik lába GND-re (INPUT_PULLUP)
//
// Konfiguráció (első indítás / reset gomb):
//   1. A config gomb (GPIO0 / BOOT) 3 mp-nél hosszabb nyomásával
//      az eszköz AP módba lép: "PP-Slave-XXXX" SSID, jelszó: "ppconfig"
//   2. Csatlakozz 192.168.4.1-re, töltsd ki az űrlapot → Mentés
//   3. Az eszköz újraindul és már a beállított paraméterekkel dolgozik
//
// Protokoll (ESP-NOW → master):
//   Telemetria:  {"id":"slave01","t":"tele","temp":21.5,"hum":60,"contact":0}
//   Értesítés:   {"id":"slave01","t":"notify","ch":"sms","to":"oncall","msg":"..."}
//     contact: 0 = nyitva/normál, 1 = zárt/aktív
// ============================================================

#include <WiFi.h>
#include <esp_now.h>
#include <esp_wifi.h>
#include <esp_idf_version.h>
#include <DHT.h>
#include <ArduinoJson.h>
#include <Preferences.h>
#include <WebServer.h>

// =========================
// Hardver kiosztás
// =========================
static constexpr int DHT_PIN      = 4;    // GPIO4
static constexpr int CONTACT_PIN  = 5;    // GPIO5  (INPUT_PULLUP, GND=zárt)
static constexpr int CONFIG_BTN   = 0;    // GPIO0 BOOT gomb – config mód belépés
static constexpr int DHT_TYPE     = DHT22;

static constexpr unsigned long CONFIG_BTN_HOLD_MS = 3000UL;   // 3 mp tartás → AP mód
static constexpr unsigned long CONFIG_AP_TIMEOUT  = 300000UL; // 5 perc AP timeout

// =========================
// Alapértelmezett értékek
// =========================
static constexpr unsigned long SAMPLING_MS_DEFAULT = 30000UL;
static constexpr unsigned long DEBOUNCE_MS         = 80UL;
static constexpr int           SCAN_FAIL_THRESHOLD = 3;    // ennyi egymás utáni hiba → csatornakerés

// =========================
// Tárolt konfig kulcsok (NVS)
// =========================
static const char* NVS_NS       = "pp_slave";
static const char* NVS_DEV_ID   = "device_id";
static const char* NVS_MASTER_MAC = "master_mac";
static const char* NVS_CH       = "wifi_ch";
static const char* NVS_NOTIFY   = "notify_tgt";
static const char* NVS_SAMPLING = "sampling_ms";
static const char* NVS_CONFIGURED = "configured";

// =========================
// Runtime konfig (NVS-ből töltve)
// =========================
struct SlaveConfig {
    char     deviceId[32]    = "slave01";
    uint8_t  masterMac[6]    = {0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF};
    uint8_t  wifiChannel     = 1;
    char     notifyTarget[64] = "oncall";
    unsigned long samplingMs = SAMPLING_MS_DEFAULT;
    bool     configured      = false;
};

static SlaveConfig cfg;
static Preferences prefs;
static WebServer   webServer(80);
static DHT         dht(DHT_PIN, DHT_TYPE);

// =========================
// Állapot változók
// =========================
static bool     contactRaw        = false;
static bool     contactDebounced  = false;
static bool     contactOpen       = true;
static unsigned long contactEdgeMs   = 0;
static unsigned long lastTelemetryMs = 0;

static volatile bool espNowSendDone = false;
static volatile bool espNowSendOk   = false;
static bool     espNowReady       = false;
static int      sendFailCount     = 0;

static bool     apActive          = false;
static unsigned long lastWebActivityMs = 0;
static unsigned long cfgBtnPressMs     = 0;
static bool     cfgBtnHeld             = false;

// =========================
// NVS betöltés / mentés
// =========================
void loadConfig() {
    prefs.begin(NVS_NS, true);  // read-only
    cfg.configured = prefs.getBool(NVS_CONFIGURED, false);
    String id = prefs.getString(NVS_DEV_ID, "slave01");
    strncpy(cfg.deviceId, id.c_str(), sizeof(cfg.deviceId) - 1);
    String mac = prefs.getString(NVS_MASTER_MAC, "AA:BB:CC:DD:EE:FF");
    sscanf(mac.c_str(), "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
           &cfg.masterMac[0], &cfg.masterMac[1], &cfg.masterMac[2],
           &cfg.masterMac[3], &cfg.masterMac[4], &cfg.masterMac[5]);
    cfg.wifiChannel = (uint8_t) prefs.getUInt(NVS_CH, 1);
    String tgt = prefs.getString(NVS_NOTIFY, "oncall");
    strncpy(cfg.notifyTarget, tgt.c_str(), sizeof(cfg.notifyTarget) - 1);
    cfg.samplingMs = (unsigned long) prefs.getULong(NVS_SAMPLING, SAMPLING_MS_DEFAULT);
    prefs.end();
}

void saveConfig(const SlaveConfig& c) {
    prefs.begin(NVS_NS, false);  // read-write
    prefs.putBool(NVS_CONFIGURED, true);
    prefs.putString(NVS_DEV_ID, c.deviceId);
    char macStr[18];
    snprintf(macStr, sizeof(macStr), "%02X:%02X:%02X:%02X:%02X:%02X",
             c.masterMac[0], c.masterMac[1], c.masterMac[2],
             c.masterMac[3], c.masterMac[4], c.masterMac[5]);
    prefs.putString(NVS_MASTER_MAC, macStr);
    prefs.putUInt(NVS_CH, c.wifiChannel);
    prefs.putString(NVS_NOTIFY, c.notifyTarget);
    prefs.putULong(NVS_SAMPLING, c.samplingMs);
    prefs.end();
}

// =========================
// ESP-NOW send callback
// ESP-IDF 5.x (Arduino ESP32 core 3.x) megváltoztatta a szignaturát:
//   régi: void cb(const uint8_t* mac, esp_now_send_status_t)
//   új:   void cb(const wifi_tx_info_t* info, esp_now_send_status_t)
// =========================
#if ESP_IDF_VERSION >= ESP_IDF_VERSION_VAL(5, 0, 0)
void onSendDone(const wifi_tx_info_t* /*info*/, esp_now_send_status_t status) {
#else
void onSendDone(const uint8_t* /*mac*/, esp_now_send_status_t status) {
#endif
    espNowSendOk   = (status == ESP_NOW_SEND_SUCCESS);
    espNowSendDone = true;
}

// =========================
// ESP-NOW küldő helper
// =========================
bool espNowSend(const String& payload) {
    if (payload.length() > 250) {
        Serial.println("[ESPNOW] Payload tul hosszu");
        return false;
    }
    espNowSendDone = false;
    espNowSendOk   = false;

    esp_err_t rc = esp_now_send(cfg.masterMac,
                                (const uint8_t*) payload.c_str(),
                                payload.length());
    if (rc != ESP_OK) {
        Serial.printf("[ESPNOW] Send hiba: %s\n", esp_err_to_name(rc));
        return false;
    }

    unsigned long t = millis();
    while (!espNowSendDone && millis() - t < 1000) {
        delay(1);
    }

    if (espNowSendOk) {
        sendFailCount = 0;
    } else {
        sendFailCount++;
        Serial.printf("[ESPNOW] Hiba (%d/%d)\n", sendFailCount, SCAN_FAIL_THRESHOLD);
        if (sendFailCount >= SCAN_FAIL_THRESHOLD) {
            scanForMasterChannel();
        }
    }
    return espNowSendOk;
}

// =========================
// Telemetria küldés
// =========================
void sendTelemetry(float temp, float hum) {
    StaticJsonDocument<200> doc;
    doc["id"]      = cfg.deviceId;
    doc["t"]       = "tele";
    if (!isnan(temp)) doc["temp"]    = roundf(temp * 10.0f) / 10.0f;
    if (!isnan(hum))  doc["hum"]     = (int) roundf(hum);
    doc["contact"] = contactOpen ? 0 : 1;

    String payload;
    serializeJson(doc, payload);
    bool ok = espNowSend(payload);
    Serial.printf("[TELE] %s  =>  %s\n", payload.c_str(), ok ? "OK" : "HIBA");
}

// =========================
// Értesítés küldés
// =========================
void sendNotify(const char* ch, const char* to, const String& msg) {
    StaticJsonDocument<200> doc;
    doc["id"]  = cfg.deviceId;
    doc["t"]   = "notify";
    doc["ch"]  = ch;
    doc["to"]  = to;
    doc["msg"] = msg;

    String payload;
    serializeJson(doc, payload);
    bool ok = espNowSend(payload);
    Serial.printf("[NOTIFY] %s  =>  %s\n", payload.c_str(), ok ? "OK" : "HIBA");
}

// =========================
// Segéd: peer csatorna frissítése
// =========================
static void updatePeerChannel(uint8_t ch) {
    esp_now_del_peer(cfg.masterMac);
    esp_now_peer_info_t peer = {};
    memcpy(peer.peer_addr, cfg.masterMac, 6);
    peer.channel = ch;
    peer.encrypt = false;
    esp_now_add_peer(&peer);
}

// =========================
// Csatornakeresés (ha a master nem válaszol)
// Végigpróbálja az 1–13 csatornákat egy kis probe csomaggal.
// Ha ACK érkezik → elmenti az új csatornát NVS-be, tovább fut.
// =========================
void scanForMasterChannel() {
    Serial.println("[SCAN] Master nem valaszol – csatornakeresés (1-13)...");

    StaticJsonDocument<64> doc;
    doc["id"] = cfg.deviceId;
    doc["t"]  = "probe";
    String probe;
    serializeJson(doc, probe);
    const uint8_t* buf = (const uint8_t*) probe.c_str();
    size_t         len = probe.length();

    for (uint8_t ch = 1; ch <= 13; ch++) {
        Serial.printf("[SCAN] ch=%d...", ch);
        esp_wifi_set_channel(ch, WIFI_SECOND_CHAN_NONE);
        updatePeerChannel(ch);
        delay(20);

        espNowSendDone = false;
        espNowSendOk   = false;
        if (esp_now_send(cfg.masterMac, buf, len) == ESP_OK) {
            unsigned long t0 = millis();
            while (!espNowSendDone && millis() - t0 < 400) delay(1);
            if (espNowSendOk) {
                Serial.printf(" ACK! Master: ch=%d (regi: %d)\n", ch, cfg.wifiChannel);
                cfg.wifiChannel = ch;
                prefs.begin(NVS_NS, false);
                prefs.putUInt(NVS_CH, ch);
                prefs.end();
                sendFailCount = 0;
                return;
            }
        }
        Serial.println(" nincs valasz");
    }

    // Nem találtuk – visszaállítjuk az eredeti csatornát
    Serial.printf("[SCAN] Master nem talalhato – visszaall ch=%d\n", cfg.wifiChannel);
    esp_wifi_set_channel(cfg.wifiChannel, WIFI_SECOND_CHAN_NONE);
    updatePeerChannel(cfg.wifiChannel);
    sendFailCount = 0;  // ne szkenneljen minden küldésnél, várjunk az következő threshold-ig
}

// =========================
// ESP-NOW inicializálás
// =========================
void initEspNow() {
    esp_wifi_set_channel(cfg.wifiChannel, WIFI_SECOND_CHAN_NONE);
    Serial.printf("[WIFI] Csatorna: %d\n", cfg.wifiChannel);

    if (esp_now_init() != ESP_OK) {
        Serial.println("[ESPNOW] Init HIBA – ujraindul 5 mp mulva");
        delay(5000);
        ESP.restart();
    }
    esp_now_register_send_cb(onSendDone);

    updatePeerChannel(cfg.wifiChannel);
    Serial.printf("[ESPNOW] Master peer: %02X:%02X:%02X:%02X:%02X:%02X  ch=%d\n",
        cfg.masterMac[0], cfg.masterMac[1], cfg.masterMac[2],
        cfg.masterMac[3], cfg.masterMac[4], cfg.masterMac[5],
        cfg.wifiChannel);
    espNowReady = true;
}

// =========================
// Config weblap HTML
// =========================
static const char CONFIG_HTML[] PROGMEM = R"rawhtml(<!DOCTYPE html>
<html lang="hu">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PP Slave konfiguráció</title>
<style>
  body{font-family:sans-serif;max-width:480px;margin:2rem auto;padding:0 1rem}
  h1{font-size:1.3rem;margin-bottom:1.5rem}
  label{display:block;margin:.8rem 0 .2rem;font-weight:600}
  input,select{width:100%;padding:.45rem .6rem;box-sizing:border-box;border:1px solid #bbb;border-radius:4px;font-size:1rem}
  .hint{font-size:.82rem;color:#666;margin:.2rem 0 0}
  button{margin-top:1.2rem;width:100%;padding:.7rem;background:#2563eb;color:#fff;border:none;border-radius:5px;font-size:1rem;cursor:pointer}
  button:hover{background:#1d4ed8}
  .status{margin-top:1rem;padding:.7rem;border-radius:4px}
  .ok{background:#d1fae5;color:#065f46}
  .err{background:#fee2e2;color:#991b1b}
</style>
</head>
<body>
<h1>PP Slave – eszköz konfiguráció</h1>
<form method="POST" action="/save">
  <label>Eszköz azonosító (device ID)</label>
  <input type="text" name="device_id" value="__DEVICE_ID__" maxlength="31" required>
  <p class="hint">Egyedi azonosító – ezt vedd fel az adatbázisba is (pl. slave01)</p>

  <label>Master MAC-cím</label>
  <input type="text" name="master_mac" value="__MASTER_MAC__" placeholder="AA:BB:CC:DD:EE:FF" maxlength="17" required
         pattern="[0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5}">
  <p class="hint">A master ESP32 WiFi MAC-je – masterben Serial monitoron látható boot-kor</p>

  <label>WiFi csatorna (1–13)</label>
  <input type="number" name="wifi_ch" value="__WIFI_CH__" min="1" max="13" required>
  <p class="hint">Egyeznie kell a master aktuális WiFi csatornájával (eszköz adatlapján látható)</p>

  <label>Értesítési cél (kontakt változáskor)</label>
  <input type="text" name="notify_tgt" value="__NOTIFY_TGT__" maxlength="63" required>
  <p class="hint">Kontakt-csoport neve vagy +36... szám – záródáskor és nyitáskor egyaránt értesít</p>

  <label>Telemetria küldési időköz (mp)</label>
  <input type="number" name="sampling_sec" value="__SAMPLING_SEC__" min="10" max="3600" required>

  <button type="submit">Mentés &amp; újraindulás</button>
</form>
__STATUS__
</body>
</html>)rawhtml";

String buildConfigPage(const String& status = "") {
    char macStr[18];
    snprintf(macStr, sizeof(macStr), "%02X:%02X:%02X:%02X:%02X:%02X",
             cfg.masterMac[0], cfg.masterMac[1], cfg.masterMac[2],
             cfg.masterMac[3], cfg.masterMac[4], cfg.masterMac[5]);

    String html(FPSTR(CONFIG_HTML));
    html.replace("__DEVICE_ID__",   String(cfg.deviceId));
    html.replace("__MASTER_MAC__",  String(macStr));
    html.replace("__WIFI_CH__",     String(cfg.wifiChannel));
    html.replace("__NOTIFY_TGT__",  String(cfg.notifyTarget));
    html.replace("__SAMPLING_SEC__", String(cfg.samplingMs / 1000UL));
    html.replace("__STATUS__",      status);
    return html;
}

// =========================
// Web aktivitás jelzés (AP timeout alapja)
// =========================
void updateWebActivity() {
    lastWebActivityMs = millis();
}

// =========================
// WebServer route-ok
// =========================
void handleRoot() {
    updateWebActivity();
    webServer.send(200, "text/html; charset=UTF-8", buildConfigPage());
}

void handleSave() {
    updateWebActivity();
    if (!webServer.hasArg("device_id") || !webServer.hasArg("master_mac") ||
        !webServer.hasArg("wifi_ch")   || !webServer.hasArg("notify_tgt") ||
        !webServer.hasArg("sampling_sec")) {
        webServer.send(400, "text/plain", "Hiányzó mező");
        return;
    }

    SlaveConfig next;
    String devId = webServer.arg("device_id");
    devId.trim();
    if (devId.length() == 0 || devId.length() > 31) {
        webServer.send(200, "text/html; charset=UTF-8",
            buildConfigPage("<div class='status err'>Hibás device ID (1–31 karakter).</div>"));
        return;
    }
    strncpy(next.deviceId, devId.c_str(), sizeof(next.deviceId) - 1);

    String mac = webServer.arg("master_mac");
    mac.trim();
    int parsed = sscanf(mac.c_str(), "%hhx:%hhx:%hhx:%hhx:%hhx:%hhx",
                        &next.masterMac[0], &next.masterMac[1], &next.masterMac[2],
                        &next.masterMac[3], &next.masterMac[4], &next.masterMac[5]);
    if (parsed != 6) {
        webServer.send(200, "text/html; charset=UTF-8",
            buildConfigPage("<div class='status err'>Érvénytelen MAC-cím formátum (pl. AA:BB:CC:DD:EE:FF).</div>"));
        return;
    }

    int ch = webServer.arg("wifi_ch").toInt();
    if (ch < 1 || ch > 13) {
        webServer.send(200, "text/html; charset=UTF-8",
            buildConfigPage("<div class='status err'>A csatornának 1–13 közé kell esnie.</div>"));
        return;
    }
    next.wifiChannel = (uint8_t) ch;

    String tgt = webServer.arg("notify_tgt");
    tgt.trim();
    if (tgt.length() == 0 || tgt.length() > 63) {
        webServer.send(200, "text/html; charset=UTF-8",
            buildConfigPage("<div class='status err'>Hibás értesítési cél.</div>"));
        return;
    }
    strncpy(next.notifyTarget, tgt.c_str(), sizeof(next.notifyTarget) - 1);

    int sec = webServer.arg("sampling_sec").toInt();
    if (sec < 10) sec = 10;
    if (sec > 3600) sec = 3600;
    next.samplingMs = (unsigned long) sec * 1000UL;

    saveConfig(next);

    String okPage = buildConfigPage("<div class='status ok'>Konfiguráció mentve! Az eszköz 3 másodpercen belül újraindul.</div>");
    webServer.send(200, "text/html; charset=UTF-8", okPage);

    delay(3000);
    ESP.restart();
}

void handleNotFound() {
    webServer.sendHeader("Location", "/");
    webServer.send(302);
}

// =========================
// AP indítás / leállítás
// AP csatorna = cfg.wifiChannel (ha konfigurálva), különben 1
// ESP-NOW és az AP azonos csatornán fut (WIFI_AP_STA)
// =========================
void startAP() {
    uint8_t mac[6];
    WiFi.macAddress(mac);
    char ssid[20];
    snprintf(ssid, sizeof(ssid), "PP-Slave-%02X%02X", mac[4], mac[5]);

    uint8_t apCh = cfg.configured ? cfg.wifiChannel : 1;
    WiFi.softAP(ssid, "ppconfig", apCh);
    delay(300);

    Serial.printf("[AP] Elindult: %s  IP: %s  ch=%d\n",
                  ssid, WiFi.softAPIP().toString().c_str(), apCh);

    webServer.on("/",     HTTP_GET,  handleRoot);
    webServer.on("/save", HTTP_POST, handleSave);
    webServer.onNotFound(handleNotFound);
    webServer.begin();
    Serial.println("[AP] WebServer elindult – 192.168.4.1");

    apActive = true;
    updateWebActivity();   // timeout nullázása
}

void stopAP() {
    webServer.stop();
    WiFi.softAPdisconnect(true);
    apActive = false;
    // Az AP leállásakor a rádió csatornáját explicit visszaállítjuk,
    // hogy az ESP-NOW pontosan a konfigurált csatornán küldjön tovább.
    if (espNowReady) {
        esp_wifi_set_channel(cfg.wifiChannel, WIFI_SECOND_CHAN_NONE);
        Serial.printf("[AP] Leallitva – ESP-NOW csatorna visszaallitva: %d\n", cfg.wifiChannel);
    } else {
        Serial.println("[AP] Leallitva (5 perces inaktivitas)");
    }
}

// =========================
// Setup
// =========================
void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("\n=== PP-ESP Slave indul ===");

    // GPIO init
    pinMode(CONTACT_PIN, INPUT_PULLUP);
    pinMode(CONFIG_BTN,  INPUT_PULLUP);

    // Konfig betöltése NVS-ből
    loadConfig();
    Serial.printf("[CFG] device_id=%s  ch=%d  configured=%s\n",
                  cfg.deviceId, cfg.wifiChannel, cfg.configured ? "igen" : "nem");

    contactRaw       = (digitalRead(CONTACT_PIN) == LOW);
    contactDebounced = contactRaw;
    contactOpen      = !contactRaw;
    contactEdgeMs    = millis();
    Serial.printf("[CONTACT] Indulo allapot: %s\n", contactOpen ? "nyitva" : "zarva");

    // DHT22
    dht.begin();
    Serial.println("[DHT22] Init OK");

    // WiFi: AP+STA mód – az AP és az ESP-NOW párhuzamosan fut
    WiFi.mode(WIFI_AP_STA);
    WiFi.disconnect();
    delay(100);
    Serial.printf("[WIFI] MAC: %s\n", WiFi.macAddress().c_str());

    // AP mindig elindul – reset után is lehet konfigot javítani
    // Ha konfigurálva: 5 perc inaktivitás után leáll az AP, ESP-NOW fut tovább
    // Ha nincs konfig: AP nyitva marad (nincs timeout)
    startAP();

    // Ha van mentett konfig: ESP-NOW is elindul rögtön
    if (cfg.configured) {
        initEspNow();
        delay(2500);
        float t = dht.readTemperature();
        float h = dht.readHumidity();
        if (isnan(t) || isnan(h)) {
            Serial.println("[DHT22] Elso olvasas sikertelen – ellenorizd a bekoteseket");
        }
        sendTelemetry(t, h);
        lastTelemetryMs = millis();
    } else {
        Serial.println("[CFG] Nincs konfig – AP var, 192.168.4.1");
    }
}

// =========================
// Loop
// =========================
void loop() {

    // ── AP kezelés ───────────────────────────────────────────
    if (apActive) {
        webServer.handleClient();

        // Timeout: csak akkor zárjuk le az AP-t, ha van mentett konfig
        // (nincs konfig → AP nyitva marad, nem tudnánk mit csinálni nélküle)
        if (cfg.configured && millis() - lastWebActivityMs >= CONFIG_AP_TIMEOUT) {
            stopAP();
        }
    }

    // ── BOOT gomb: 3 mp tartás → újraindulás ─────────────────
    // (újraindulás után az AP automatikusan elindul, konfig javítható)
    if (digitalRead(CONFIG_BTN) == LOW) {
        if (!cfgBtnHeld) {
            cfgBtnHeld    = true;
            cfgBtnPressMs = millis();
        } else if (millis() - cfgBtnPressMs >= CONFIG_BTN_HOLD_MS) {
            Serial.println("[BTN] 3mp tartva – ujraindulas");
            delay(200);
            ESP.restart();
        }
    } else {
        cfgBtnHeld = false;
    }

    // ── ESP-NOW szenzor logika (csak ha van konfig és ESP-NOW kész) ──
    if (!espNowReady) {
        delay(10);
        return;
    }

    // ── Kontakt debounce ─────────────────────────────────────
    bool rawNow = (digitalRead(CONTACT_PIN) == LOW);

    if (rawNow != contactRaw) {
        contactRaw    = rawNow;
        contactEdgeMs = millis();
    } else if ((millis() - contactEdgeMs >= DEBOUNCE_MS) &&
               (contactRaw != contactDebounced)) {
        contactDebounced = contactRaw;
        bool newOpen     = !contactDebounced;

        if (newOpen != contactOpen) {
            contactOpen = newOpen;
            Serial.printf("[CONTACT] Valtozas: %s\n", contactOpen ? "nyitva" : "zarva");

            float t = dht.readTemperature();
            float h = dht.readHumidity();

            if (!contactOpen) {
                sendNotify("sms", cfg.notifyTarget,
                           String(cfg.deviceId) + ": kontakt zarva (aktiv)");
            } else {
                sendNotify("sms", cfg.notifyTarget,
                           String(cfg.deviceId) + ": kontakt nyitva (inaktiv)");
            }
            sendTelemetry(t, h);
        }
    }

    // ── Periodikus telemetria ─────────────────────────────────
    if (millis() - lastTelemetryMs >= cfg.samplingMs) {
        float t = dht.readTemperature();
        float h = dht.readHumidity();
        sendTelemetry(t, h);
        lastTelemetryMs = millis();
    }

    delay(10);
}
