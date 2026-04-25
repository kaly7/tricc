#include <WiFi.h>
#include <WebServer.h>
#include <PubSubClient.h>
#include <ArduinoJson.h>
#include <SPIFFS.h>
#include <Wire.h>
#include <Adafruit_BME280.h>
#include <Adafruit_NeoPixel.h>
#include <time.h>
#include <Preferences.h>
#include "sim800_modem.h"

// =========================
// Hardware config
// Adjust these to your board/wiring.
// =========================
static constexpr int I2C_SDA_PIN = 21;
static constexpr int I2C_SCL_PIN = 22;
static constexpr int LED_RING_PIN = 4;
// WS2812B-8 LED sor, bal->jobb: WiFi | MQTT | GSM | Szenzor | Kontakt | Hőm. riasztás | Táp | Akku
static constexpr uint16_t LED_RING_COUNT = 8;
static constexpr int CONTACT_PIN = 27;
static constexpr unsigned long CONTACT_DEBOUNCE_MS = 60UL;
static constexpr int MODEM_RX_PIN = 19;  // ESP32 RX  <- SIM800L TXD
static constexpr int MODEM_TX_PIN = 18;  // ESP32 TX  -> SIM800L RXD
static constexpr uint32_t MODEM_DEFAULT_BAUD = 9600;
static constexpr unsigned long MODEM_POLL_INTERVAL_MS = 60000UL;  // 1 perc (ha él a modem)
static constexpr size_t MAX_CONTACT_GROUPS = 8;
static constexpr size_t MAX_PHONES_PER_GROUP = 8;
static constexpr size_t MAX_EVENT_ROUTES = 16;
static constexpr size_t MAX_ROUTE_ACTIONS = 8;
static constexpr size_t MAX_CALL_QUEUE = 16;
static constexpr size_t MAX_RECENT_CALLS = 24;
static constexpr unsigned long CALL_RING_DURATION_MS = 20000UL;
static constexpr unsigned long CALL_GAP_MS = 5000UL;
static constexpr unsigned long CALL_COOLDOWN_MS = 120000UL;
static constexpr int USB_SENSE_PIN = 33;
static constexpr int UPS_CHARGE_PIN = 32;
static constexpr uint8_t UPS_I2C_ADDR = 0x36;
static constexpr uint16_t USB_SENSE_THRESHOLD_RAW = 1800;


// =========================
// File paths
// =========================
static const char* NETCFG_PATH = "/netcfg.json";
static const char* RUNTIMECFG_PATH = "/runtime.json";

// =========================
// Wi-Fi / AP behavior
// =========================
static const char* AP_SSID = "PP-ESP-Setup";
static const char* AP_PASS = "12345678";
static constexpr unsigned long WIFI_CONNECT_TIMEOUT_MS = 30000UL;
static constexpr unsigned long AP_MODE_DURATION_MS = 300000UL;  // 5 minutes
static constexpr unsigned long STA_RETRY_INTERVAL_MS = 30000UL;
static constexpr unsigned long MQTT_RETRY_INTERVAL_MS = 10000UL;
static constexpr unsigned long GATEWAY_SETTLE_MS = 3000UL;
static constexpr unsigned long RESCUE_ARM_WINDOW_MS = 10000UL;
static constexpr uint16_t GATEWAY_TEST_PORT = 80;  // 53 volt eredetileg
static constexpr uint32_t GATEWAY_TEST_TIMEOUT_MS = 2400UL;

// =========================
// Time
// =========================
static constexpr long GMT_OFFSET_SEC = 0;
static constexpr int DAYLIGHT_OFFSET_SEC = 0;

struct NetConfig {
  String deviceId = "esp001";
  String wifiSsid;
  String wifiPassword;
  String mqttHost = "212.16.143.141";  // prefer IP until DNS path is fully trusted
  uint16_t mqttPort = 1883;
  String mqttUser = "pp_center";
  String mqttPassword = "abrakadabra";
  String gsmApn;
  String gsmUser;
  String gsmPassword;
  String webUser = "admin";
  String webPassword;
};

struct RuntimeConfig {
  uint32_t configVersion = 0;
  uint32_t samplingSec = 30;
  uint32_t heartbeatSec = 60;
  float tempMin = 5.0f;
  float tempMax = 28.5f;
  float humidityMin = 20.0f;
  float humidityMax = 70.0f;
  uint32_t airQualityMax = 900;
  uint32_t batteryLowPct = 20;
  uint32_t batteryCriticalPct = 10;
  bool tempAlertEnabled = true;
  bool contactAlertEnabled = true;
  String contact1Mode = "nc";
  size_t contactGroupCount = 0;
  String contactGroupNames[MAX_CONTACT_GROUPS];
  size_t contactGroupPhoneCounts[MAX_CONTACT_GROUPS] = { 0 };
  String contactGroupPhones[MAX_CONTACT_GROUPS][MAX_PHONES_PER_GROUP];
  size_t routeCount = 0;
  String routeEventNames[MAX_EVENT_ROUTES];
  size_t routeActionCounts[MAX_EVENT_ROUTES] = { 0 };
  String routeActions[MAX_EVENT_ROUTES][MAX_ROUTE_ACTIONS];
  bool valid = false;
};

struct CallQueueEntry {
  String phone;
  String eventType;
  unsigned long queuedAtMs = 0;
};

struct DeviceState {
  bool wifiConnected = false;
  bool mqttConnected = false;
  bool mqttConnecting = false;
  bool apEnabled = false;
  bool sensorOk = false;
  bool runtimeConfigLoaded = false;
  bool staAttemptInProgress = false;
  bool staValidated = false;
  bool rescueApMode = false;
  bool fallbackApMode = false;
  bool apTimedMode = false;
  bool rescueArmPendingClear = false;

  bool usbPower = true;
  bool batteryCharging = false;
  int batteryPct = -1;  // -1 = nem ismert / nincs meg
  bool gsmEnabled = false;
  bool gsmInitInProgress = false;
  bool gsmReady = false;
  bool gsmRegistered = false;
  bool gsmSimReady = false;
  int gsmRssi = -999;
  String gsmOperator;
  bool gsmMqttConnected = false;
  String mqttTransport = "none";
  bool anyActiveAlarm = false;
  bool hasUnackedAlarm = false;
  bool tempHighAlarmActive = false;
  bool tempLowAlarmActive = false;
  bool contactAlarmActive = false;
  bool tempHighAlertSent = false;
  bool tempLowAlertSent = false;
  bool contactAlertSent = false;
  bool bootEventPublished = false;
  bool callInProgress = false;
  unsigned long callStartedMs = 0;
  unsigned long callBlockedUntilMs = 0;
  String currentCallPhone;
  String currentCallEvent;

  bool contactRaw = false;
  bool contactOpen = true;
  bool contactActive = false;
  bool lastContactRaw = false;
  unsigned long contactLastChangeMs = 0;

  float temperature = NAN;
  float humidity = NAN;
  float pressure_hpa = NAN;
  float batteryVoltage = NAN;

  int32_t rssi = 0;
  unsigned long bootMs = 0;
  unsigned long lastTelemetryMs = 0;
  unsigned long lastReportedMs = 0;
  unsigned long lastWebActivityMs = 0;
  unsigned long lastStaAttemptMs = 0;
  unsigned long staAttemptStartedMs = 0;
  unsigned long lastMqttAttemptMs = 0;
  unsigned long staGotIpAtMs = 0;
  unsigned long apStartedAtMs = 0;
  unsigned long rescueArmSetAtMs = 0;
};

RTC_DATA_ATTR uint32_t rtcBootCount = 0;
Preferences bootPrefs;

NetConfig netCfg;
RuntimeConfig runtimeCfg;
DeviceState state;
bool lastWifiConnectedState = false;

WebServer server(80);
WiFiClient wifiClient;
PubSubClient mqttClient(wifiClient);
HardwareSerial modemSerial(1);
Sim800Modem sim800;
Adafruit_BME280 bme;
Adafruit_NeoPixel ring(LED_RING_COUNT, LED_RING_PIN, NEO_GRB + NEO_KHZ800);

String topicBase;
String topicTelemetry;
String topicAlert;
String topicReported;
String topicDesired;
String topicCmdIn;
String topicCmdOut;
String topicLwt;


CallQueueEntry callQueue[MAX_CALL_QUEUE];
size_t callQueueHead = 0;
size_t callQueueTail = 0;
size_t callQueueCount = 0;
String recentCallKeys[MAX_RECENT_CALLS];
unsigned long recentCallTimes[MAX_RECENT_CALLS] = { 0 };

// =========================
// Helpers
// =========================
String htmlEscape(const String& in) {
  String s = in;
  s.replace("&", "&amp;");
  s.replace("<", "&lt;");
  s.replace(">", "&gt;");
  s.replace("\"", "&quot;");
  return s;
}

String isoNow() {
  time_t now = time(nullptr);
  if (now < 100000) {
    return "1970-01-01T00:00:00Z";
  }

  struct tm timeinfo;
  gmtime_r(&now, &timeinfo);
  char buf[25];
  strftime(buf, sizeof(buf), "%Y-%m-%dT%H:%M:%SZ", &timeinfo);
  return String(buf);
}

unsigned long uptimeSec() {
  return (millis() - state.bootMs) / 1000UL;
}

void logLine(const String& msg) {
  Serial.printf("[%lu ms] %s\n", millis(), msg.c_str());
}

void touchWebActivity() {
  state.lastWebActivityMs = millis();
}

String statusText() {
  if (state.mqttConnected) return "mqtt";
  if (state.wifiConnected && state.apEnabled) return "wifi+ap";
  if (state.wifiConnected) return "wifi";
  if (state.apEnabled) return "ap";
  return "offline";
}

String currentTelemetryTransport() {
  if (state.mqttTransport == "gsm" && state.gsmMqttConnected) return "gsm";
  if (state.mqttTransport == "wifi" && state.mqttConnected) return "wifi";
  if (state.wifiConnected) return "wifi";
  return "none";
}

String currentWiFiIp() {
  return state.wifiConnected ? WiFi.localIP().toString() : "";
}

bool currentGsmOk() {
  return state.gsmEnabled && state.gsmReady && state.gsmRegistered;
}

bool currentGsmRssiKnown() {
  return state.gsmRssi > -999;
}

String currentGsmOperator() {
  return state.gsmOperator;
}

String normalizeContactMode(const String& raw) {
  String v = raw;
  v.trim();
  v.toLowerCase();
  if (v == "no" || v == "nc" || v == "unused") return v;
  return "nc";
}

bool isContactMonitoringEnabled() {
  return runtimeCfg.contactAlertEnabled && normalizeContactMode(runtimeCfg.contact1Mode) != "unused";
}

bool contactAlarmForOpenState(bool isOpen) {
  String mode = normalizeContactMode(runtimeCfg.contact1Mode);
  if (mode == "unused") return false;
  if (mode == "nc") return isOpen;
  if (mode == "no") return !isOpen;
  return false;
}

String contactModeLabel() {
  String mode = normalizeContactMode(runtimeCfg.contact1Mode);
  if (mode == "no") return "Normally Open";
  if (mode == "unused") return "Nem hasznalt";
  return "Normally Closed";
}

String contactStatusLabel() {
  if (normalizeContactMode(runtimeCfg.contact1Mode) == "unused") return "nem figyelt";
  return state.contactOpen ? "open" : "closed";
}

bool findContactGroupIndex(const String& groupName, size_t& outIndex) {
  for (size_t i = 0; i < runtimeCfg.contactGroupCount; ++i) {
    if (runtimeCfg.contactGroupNames[i] == groupName) {
      outIndex = i;
      return true;
    }
  }
  return false;
}

bool findRouteIndex(const String& eventName, size_t& outIndex) {
  for (size_t i = 0; i < runtimeCfg.routeCount; ++i) {
    if (runtimeCfg.routeEventNames[i] == eventName) {
      outIndex = i;
      return true;
    }
  }
  return false;
}

bool mqttIsConnected() {
  if (state.mqttTransport == "wifi") return mqttClient.connected();
  if (state.mqttTransport == "gsm") return state.gsmMqttConnected && sim800.mqttIsConnected();
  return false;
}

bool wantWifiMqtt() {
  return state.staValidated && state.wifiConnected && !netCfg.mqttHost.isEmpty();
}

bool wantGsmMqtt() {
  return !wantWifiMqtt() && !netCfg.mqttHost.isEmpty() && state.gsmReady;
}

void disconnectMqttTransport(const String& reason = "") {
  if (state.mqttTransport == "wifi") {
    if (mqttClient.connected()) {
      if (!reason.isEmpty()) logLine(String("MQTT wifi bontas: ") + reason);
      mqttClient.disconnect();
    }
    state.mqttConnected = false;
  } else if (state.mqttTransport == "gsm") {
    if (sim800.mqttIsConnected()) {
      if (!reason.isEmpty()) logLine(String("MQTT gsm bontas: ") + reason);
      sim800.mqttDisconnect();
    }
    state.gsmMqttConnected = false;
  }
  state.mqttConnecting = false;
  state.mqttTransport = "none";
}

bool shouldThrottleCall(const String& key);
bool enqueueCallTarget(const String& phone, const char* eventType);
void processQueuedCalls();

String buildLocalNotificationText(const char* eventType, const String& message, float value, float threshold) {
  String text = "PP " + netCfg.deviceId + ": " + message;
  text += " (";
  text += String(eventType);
  text += ")";
  if (!isnan(value)) {
    text += " ertek=";
    text += String(value, 1);
  }
  if (!isnan(threshold)) {
    text += " kuszob=";
    text += String(threshold, 1);
  }
  String ip = currentWiFiIp();
  if (!ip.isEmpty()) {
    text += " IP=";
    text += ip;
  }
  return text;
}

void dispatchLocalActionsForEvent(const char* eventType, const String& message, float value = NAN, float threshold = NAN) {
  if (!state.gsmEnabled) return;

  size_t routeIndex = 0;
  if (!findRouteIndex(String(eventType), routeIndex)) {
    return;
  }

  String text = buildLocalNotificationText(eventType, message, value, threshold);

  for (size_t actionIndex = 0; actionIndex < runtimeCfg.routeActionCounts[routeIndex]; ++actionIndex) {
    String action = runtimeCfg.routeActions[routeIndex][actionIndex];
    action.trim();
    if (action.isEmpty()) continue;

    if (action == "mattermost") {
      continue;
    }

    if (action.startsWith("sms:")) {
      String target = action.substring(4);
      target.trim();
      if (target.isEmpty()) continue;

      if (target.startsWith("+")) {
        bool ok = sim800.sendSms(target, text);
        logLine(String("SMS ") + (ok ? "OK " : "HIBA ") + target + " event=" + eventType);
        continue;
      }

      size_t groupIndex = 0;
      if (!findContactGroupIndex(target, groupIndex)) {
        logLine(String("SMS csoport nem letezik: ") + target);
        continue;
      }

      for (size_t phoneIndex = 0; phoneIndex < runtimeCfg.contactGroupPhoneCounts[groupIndex]; ++phoneIndex) {
        const String& phone = runtimeCfg.contactGroupPhones[groupIndex][phoneIndex];
        if (phone.isEmpty()) continue;
        bool ok = sim800.sendSms(phone, text);
        logLine(String("SMS ") + (ok ? "OK " : "HIBA ") + phone + " event=" + eventType);
      }
      continue;
    }

    if (action.startsWith("call:")) {
      String target = action.substring(5);
      target.trim();
      if (target.isEmpty()) continue;

      if (target.startsWith("+")) {
        enqueueCallTarget(target, eventType);
        continue;
      }

      size_t groupIndex = 0;
      if (!findContactGroupIndex(target, groupIndex)) {
        logLine(String("CALL csoport nem letezik: ") + target);
        continue;
      }

      for (size_t phoneIndex = 0; phoneIndex < runtimeCfg.contactGroupPhoneCounts[groupIndex]; ++phoneIndex) {
        const String& phone = runtimeCfg.contactGroupPhones[groupIndex][phoneIndex];
        if (phone.isEmpty()) continue;
        enqueueCallTarget(phone, eventType);
      }
      continue;
    }
  }
}

bool shouldThrottleCall(const String& key) {
  unsigned long now = millis();
  for (size_t i = 0; i < MAX_RECENT_CALLS; ++i) {
    if (recentCallKeys[i] == key) {
      if (now - recentCallTimes[i] < CALL_COOLDOWN_MS) {
        return true;
      }
      recentCallTimes[i] = now;
      return false;
    }
  }

  size_t slot = 0;
  unsigned long oldest = recentCallTimes[0];
  for (size_t i = 1; i < MAX_RECENT_CALLS; ++i) {
    if (recentCallTimes[i] < oldest) {
      oldest = recentCallTimes[i];
      slot = i;
    }
  }
  recentCallKeys[slot] = key;
  recentCallTimes[slot] = now;
  return false;
}

bool enqueueCallTarget(const String& phone, const char* eventType) {
  String normalized = phone;
  normalized.trim();
  if (normalized.isEmpty()) return false;

  String key = String(eventType) + "|" + normalized;
  if (shouldThrottleCall(key)) {
    logLine(String("CALL throttle: ") + normalized + " event=" + eventType);
    return false;
  }

  if (callQueueCount >= MAX_CALL_QUEUE) {
    logLine(String("CALL queue tele: ") + normalized + " event=" + eventType);
    return false;
  }

  callQueue[callQueueTail].phone = normalized;
  callQueue[callQueueTail].eventType = String(eventType);
  callQueue[callQueueTail].queuedAtMs = millis();
  callQueueTail = (callQueueTail + 1) % MAX_CALL_QUEUE;
  callQueueCount++;
  logLine(String("CALL queue + ") + normalized + " event=" + eventType);
  return true;
}

void processQueuedCalls() {
  if (!state.gsmEnabled) return;

  unsigned long now = millis();

  if (state.callInProgress) {
    if (now - state.callStartedMs >= CALL_RING_DURATION_MS) {
      sim800.hangup();
      logLine(String("CALL vege: ") + state.currentCallPhone + " event=" + state.currentCallEvent);
      state.callInProgress = false;
      state.callBlockedUntilMs = now + CALL_GAP_MS;
      state.currentCallPhone = "";
      state.currentCallEvent = "";
    }
    return;
  }

  if (now < state.callBlockedUntilMs) return;
  if (!state.gsmReady) return;
  if (callQueueCount == 0) return;

  CallQueueEntry entry = callQueue[callQueueHead];
  callQueueHead = (callQueueHead + 1) % MAX_CALL_QUEUE;
  callQueueCount--;

  if (sim800.dial(entry.phone)) {
    state.callInProgress = true;
    state.callStartedMs = now;
    state.currentCallPhone = entry.phone;
    state.currentCallEvent = entry.eventType;
    logLine(String("CALL indul: ") + entry.phone + " event=" + entry.eventType);
  } else {
    logLine(String("CALL HIBA: ") + entry.phone + " event=" + entry.eventType);
    state.callBlockedUntilMs = now + CALL_GAP_MS;
  }
}

void buildTopics() {
  topicBase = "pp/" + netCfg.deviceId;
  topicTelemetry = topicBase + "/telemetry";
  topicAlert = topicBase + "/alert";
  topicReported = topicBase + "/state/reported";
  topicDesired = topicBase + "/state/desired";
  topicCmdIn = topicBase + "/cmd/in";
  topicCmdOut = topicBase + "/cmd/out";
  topicLwt = topicBase + "/lwt";
}

uint32_t ledColor(uint8_t r, uint8_t g, uint8_t b) {
  return ring.Color(r, g, b);
}

bool blinkState(unsigned long periodMs = 600UL) {
  return ((millis() / periodMs) % 2) == 0;
}

void setLedSafe(uint16_t index, uint32_t color) {
  if (index < LED_RING_COUNT) {
    ring.setPixelColor(index, color);
  }
}

void updateLedRing() {
  ring.setBrightness(24);
  ring.clear();

  // LED 0 – WiFi / hálózat
  if (state.apEnabled) {
    setLedSafe(0, (state.rescueApMode && blinkState(350)) ? ledColor(80, 0, 80) : ledColor(80, 0, 0));
  } else if (state.staValidated) {
    setLedSafe(0, ledColor(0, 80, 0));        // zöld: WiFi + gateway OK
  } else if (state.wifiConnected || state.staAttemptInProgress) {
    setLedSafe(0, ledColor(80, 50, 0));       // sárga: csatlakozás / gateway teszt
  } else {
    setLedSafe(0, ledColor(80, 0, 0));        // piros: nincs WiFi
  }

  // LED 1 – MQTT kapcsolat
  if (state.mqttConnected) {
    setLedSafe(1, ledColor(0, 80, 0));        // zöld: csatlakozva
  } else if (state.mqttConnecting) {
    setLedSafe(1, blinkState(500) ? ledColor(0, 0, 80) : 0);  // kék villogó: csatlakozás
  } else {
    setLedSafe(1, ledColor(80, 0, 0));        // piros: nincs MQTT
  }

  // LED 2 – GSM modem (SIM800L)
  if (state.gsmEnabled) {
    if (state.gsmInitInProgress) {
      setLedSafe(2, blinkState(500) ? ledColor(0, 0, 80) : 0);  // kék villogó: init
    } else if (state.gsmReady) {
      setLedSafe(2, ledColor(0, 80, 0));      // zöld: modem + hálózat OK
    } else if (state.gsmSimReady) {
      setLedSafe(2, ledColor(80, 50, 0));     // sárga: SIM OK, nincs hálózat
    } else {
      setLedSafe(2, ledColor(80, 0, 0));      // piros: modem nem válaszol / nincs SIM
    }
  }
  // ha GSM disabled: LED 2 kialszik

  // LED 3 – BME280 szenzor
  setLedSafe(3, state.sensorOk ? ledColor(0, 80, 0) : ledColor(80, 0, 0));

  // LED 4 – Kontakt bemenet
  if (normalizeContactMode(runtimeCfg.contact1Mode) == "unused") {
    setLedSafe(4, 0);                         // kialszik: nem figyelt
  } else if (state.contactActive) {
    setLedSafe(4, blinkState(400) ? ledColor(80, 20, 0) : ledColor(80, 0, 0));  // piros/narancs villogó: riasztás
  } else {
    setLedSafe(4, ledColor(0, 80, 0));        // zöld: normál állapot
  }

  // LED 5 – Hőmérséklet riasztás
  if (state.tempHighAlarmActive) {
    setLedSafe(5, blinkState(400) ? ledColor(80, 0, 0) : ledColor(40, 0, 0));  // piros villogó: túl meleg
  } else if (state.tempLowAlarmActive) {
    setLedSafe(5, blinkState(400) ? ledColor(0, 0, 80) : ledColor(0, 0, 40));  // kék villogó: túl hideg
  } else {
    setLedSafe(5, ledColor(0, 20, 0));        // halvány zöld: normál
  }

  // LED 6 – USB táp / töltés
  if (state.usbPower) {
    setLedSafe(6, state.batteryCharging ? ledColor(0, 60, 80) : ledColor(0, 80, 0));  // zöldeskék: tölt, zöld: teli/USB
  } else {
    setLedSafe(6, ledColor(80, 30, 0));       // narancs: akkuról megy
  }

  // LED 7 – Akkumulátor töltöttség
  uint32_t battColor = 0;
  if (state.batteryPct < 0) {
    battColor = 0;                            // ismeretlen: kialszik
  } else if (state.batteryPct <= 15) {
    battColor = blinkState(400) ? ledColor(80, 0, 0) : 0;  // piros villogó: kritikus
  } else if (state.batteryPct <= 25) {
    battColor = ledColor(80, 0, 0);           // piros: alacsony
  } else if (state.batteryPct <= 50) {
    battColor = ledColor(80, 50, 0);          // sárga: közepes
  } else if (state.batteryPct <= 75) {
    battColor = ledColor(0, 0, 80);           // kék: jó
  } else {
    battColor = ledColor(0, 80, 0);           // zöld: teli
  }
  setLedSafe(7, battColor);

  ring.show();
}


// =========================
// SPIFFS config I/O
// =========================
bool loadJsonFile(const char* path, String& content) {
  if (!SPIFFS.exists(path)) return false;
  File f = SPIFFS.open(path, FILE_READ);
  if (!f) return false;
  content = f.readString();
  f.close();
  return !content.isEmpty();
}

bool saveJsonFile(const char* path, const String& content) {
  File f = SPIFFS.open(path, FILE_WRITE);
  if (!f) return false;
  size_t written = f.print(content);
  f.close();
  return written == content.length();
}

bool loadNetConfig() {
  String content;
  if (!loadJsonFile(NETCFG_PATH, content)) {
    logLine("Nincs netcfg, gyari defaultok maradnak");
    return false;
  }

  StaticJsonDocument<768> doc;
  DeserializationError err = deserializeJson(doc, content);
  if (err) {
    logLine(String("netcfg JSON hiba: ") + err.c_str());
    return false;
  }

  netCfg.deviceId = String((const char*)(doc["device_id"] | netCfg.deviceId.c_str()));
  netCfg.wifiSsid = String((const char*)(doc["wifi_ssid"] | ""));
  netCfg.wifiPassword = String((const char*)(doc["wifi_password"] | ""));
  netCfg.mqttHost = String((const char*)(doc["mqtt_host"] | netCfg.mqttHost.c_str()));
  netCfg.mqttPort = doc["mqtt_port"] | netCfg.mqttPort;
  netCfg.mqttUser = String((const char*)(doc["mqtt_user"] | netCfg.mqttUser.c_str()));
  netCfg.mqttPassword = String((const char*)(doc["mqtt_password"] | netCfg.mqttPassword.c_str()));
  netCfg.gsmApn = String((const char*)(doc["gsm_apn"] | netCfg.gsmApn.c_str()));
  netCfg.gsmUser = String((const char*)(doc["gsm_user"] | netCfg.gsmUser.c_str()));
  netCfg.gsmPassword = String((const char*)(doc["gsm_password"] | netCfg.gsmPassword.c_str()));
  netCfg.webUser = String((const char*)(doc["web_user"] | netCfg.webUser.c_str()));
  netCfg.webPassword = String((const char*)(doc["web_password"] | netCfg.webPassword.c_str()));
  if (netCfg.webUser.isEmpty()) netCfg.webUser = "admin";
  buildTopics();
  logLine("netcfg betoltve");
  return true;
}

bool saveNetConfig() {
  StaticJsonDocument<768> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["wifi_ssid"] = netCfg.wifiSsid;
  doc["wifi_password"] = netCfg.wifiPassword;
  doc["mqtt_host"] = netCfg.mqttHost;
  doc["mqtt_port"] = netCfg.mqttPort;
  doc["mqtt_user"] = netCfg.mqttUser;
  doc["mqtt_password"] = netCfg.mqttPassword;
  doc["gsm_apn"] = netCfg.gsmApn;
  doc["gsm_user"] = netCfg.gsmUser;
  doc["gsm_password"] = netCfg.gsmPassword;
  doc["web_user"] = netCfg.webUser;
  doc["web_password"] = netCfg.webPassword;

  String content;
  serializeJsonPretty(doc, content);
  bool ok = saveJsonFile(NETCFG_PATH, content);
  if (ok) logLine("netcfg mentve");
  return ok;
}

bool validateRuntimeConfigDoc(const JsonDocument& doc) {
  if (doc["config_version"].isNull()) return false;
  if (doc["sampling_sec"].isNull()) return false;
  if (doc["heartbeat_sec"].isNull()) return false;
  return true;
}

bool applyRuntimeConfigDoc(const JsonDocument& doc) {
  RuntimeConfig next = runtimeCfg;

  next.configVersion = doc["config_version"] | next.configVersion;
  next.samplingSec = doc["sampling_sec"] | next.samplingSec;
  next.heartbeatSec = doc["heartbeat_sec"] | next.heartbeatSec;

  if (next.samplingSec < 5) next.samplingSec = 5;
  if (next.heartbeatSec < 15) next.heartbeatSec = 15;

  JsonObjectConst th = doc["thresholds"].as<JsonObjectConst>();
  if (!th.isNull()) {
    next.tempMin = th["temp_min"] | next.tempMin;
    next.tempMax = th["temp_max"] | next.tempMax;
    next.humidityMin = th["humidity_min"] | next.humidityMin;
    next.humidityMax = th["humidity_max"] | next.humidityMax;
    next.airQualityMax = th["air_quality_max"] | next.airQualityMax;
    next.batteryLowPct = th["battery_low_pct"] | next.batteryLowPct;
    next.batteryCriticalPct = th["battery_critical_pct"] | next.batteryCriticalPct;
  }

  next.tempAlertEnabled = doc["temp_alert_enabled"] | next.tempAlertEnabled;
  next.contactAlertEnabled = doc["contact_alert_enabled"] | next.contactAlertEnabled;

  JsonObjectConst contacts = doc["contacts"].as<JsonObjectConst>();
  if (!contacts.isNull()) {
    next.contact1Mode = normalizeContactMode(String((const char*)(contacts["c1_mode"] | next.contact1Mode.c_str())));
  } else {
    next.contact1Mode = normalizeContactMode(String((const char*)(doc["contact_1_mode"] | next.contact1Mode.c_str())));
  }

  next.contactGroupCount = 0;
  JsonObjectConst groups = doc["contact_groups"].as<JsonObjectConst>();
  if (!groups.isNull()) {
    for (JsonPairConst kv : groups) {
      if (next.contactGroupCount >= MAX_CONTACT_GROUPS) break;
      String groupName = String(kv.key().c_str());
      groupName.trim();
      if (groupName.isEmpty()) continue;

      size_t groupIndex = next.contactGroupCount++;
      next.contactGroupNames[groupIndex] = groupName;
      next.contactGroupPhoneCounts[groupIndex] = 0;

      JsonArrayConst phones = kv.value().as<JsonArrayConst>();
      if (!phones.isNull()) {
        for (JsonVariantConst phoneVar : phones) {
          if (next.contactGroupPhoneCounts[groupIndex] >= MAX_PHONES_PER_GROUP) break;
          String phone = String((const char*)(phoneVar | ""));
          phone.trim();
          if (phone.isEmpty()) continue;
          next.contactGroupPhones[groupIndex][next.contactGroupPhoneCounts[groupIndex]++] = phone;
        }
      }
    }
  }

  next.routeCount = 0;
  JsonObjectConst routes = doc["routes"].as<JsonObjectConst>();
  if (!routes.isNull()) {
    for (JsonPairConst kv : routes) {
      if (next.routeCount >= MAX_EVENT_ROUTES) break;
      String eventName = String(kv.key().c_str());
      eventName.trim();
      if (eventName.isEmpty()) continue;

      size_t routeIndex = next.routeCount++;
      next.routeEventNames[routeIndex] = eventName;
      next.routeActionCounts[routeIndex] = 0;

      JsonArrayConst actions = kv.value().as<JsonArrayConst>();
      if (!actions.isNull()) {
        for (JsonVariantConst actionVar : actions) {
          if (next.routeActionCounts[routeIndex] >= MAX_ROUTE_ACTIONS) break;
          String action = String((const char*)(actionVar | ""));
          action.trim();
          if (action.isEmpty()) continue;
          next.routeActions[routeIndex][next.routeActionCounts[routeIndex]++] = action;
        }
      }
    }
  }

  next.valid = true;
  runtimeCfg = next;
  state.runtimeConfigLoaded = true;
  return true;
}

bool loadRuntimeConfig() {
  String content;
  if (!loadJsonFile(RUNTIMECFG_PATH, content)) {
    logLine("Nincs runtime cfg, default runtime config marad");
    return false;
  }

  StaticJsonDocument<4096> doc;
  DeserializationError err = deserializeJson(doc, content);
  if (err) {
    logLine(String("runtime cfg JSON hiba: ") + err.c_str());
    return false;
  }

  if (!validateRuntimeConfigDoc(doc)) {
    logLine("runtime cfg ervenytelen");
    return false;
  }

  bool ok = applyRuntimeConfigDoc(doc);
  if (ok) logLine("runtime cfg betoltve helyi fajlbol");
  return ok;
}

bool saveRuntimeConfigDoc(const JsonDocument& doc) {
  String content;
  serializeJsonPretty(doc, content);
  bool ok = saveJsonFile(RUNTIMECFG_PATH, content);
  if (ok) logLine("runtime cfg mentve helyben");
  return ok;
}

// =========================
// Wi-Fi / AP
// =========================
void onWiFiEvent(WiFiEvent_t event, WiFiEventInfo_t info) {
  switch (event) {
    case ARDUINO_EVENT_WIFI_STA_START:
      logLine("[WIFI EVT] STA_START");
      break;
    case ARDUINO_EVENT_WIFI_STA_CONNECTED:
      logLine("[WIFI EVT] STA_CONNECTED");
      break;
    case ARDUINO_EVENT_WIFI_STA_GOT_IP:
      logLine(String("[WIFI EVT] GOT_IP ") + WiFi.localIP().toString());
      break;
    case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
      logLine(String("[WIFI EVT] STA_DISCONNECTED reason=") + String(info.wifi_sta_disconnected.reason));
      break;
    case ARDUINO_EVENT_WIFI_AP_START:
      logLine(String("[WIFI EVT] AP_START ip=") + WiFi.softAPIP().toString());
      break;
    case ARDUINO_EVENT_WIFI_AP_STOP:
      logLine("[WIFI EVT] AP_STOP");
      break;
    default:
      break;
  }
}

void refreshWiFiFlags() {
  wl_status_t st = WiFi.status();
  bool prev = state.wifiConnected;
  state.wifiConnected = (st == WL_CONNECTED);

  if (!state.wifiConnected) {
    state.rssi = 0;
    state.staValidated = false;
    state.staGotIpAtMs = 0;
    state.mqttConnected = false;
    state.mqttConnecting = false;
  } else {
    state.rssi = WiFi.RSSI();
    if (!prev) {
      state.staGotIpAtMs = millis();
      logLine(String("WiFi STA csatlakozva, IP: ") + WiFi.localIP().toString()
              + " gw=" + WiFi.gatewayIP().toString()
              + " dns=" + WiFi.dnsIP().toString()
              + " rssi=" + String(state.rssi));
    }
  }

  lastWifiConnectedState = state.wifiConnected;
}

bool gatewayReachable() {
  if (!state.wifiConnected) return false;

  IPAddress gw = WiFi.gatewayIP();
  if (gw == IPAddress((uint32_t)0)) {
    logLine("GW/BROKER teszt: nincs gateway IP");
    return false;
  }

  WiFiClient probe;
  logLine(String("GW teszt indul: ") + gw.toString() + ":" + String(GATEWAY_TEST_PORT));
  bool ok = probe.connect(gw, GATEWAY_TEST_PORT, GATEWAY_TEST_TIMEOUT_MS);
  if (ok) {
    probe.stop();
    logLine("GW teszt OK");
    return true;
  }
  logLine("GW teszt HIBA, broker reachability teszt kovetkezik");

  WiFiClient brokerProbe;
  logLine(String("Broker teszt indul: ") + netCfg.mqttHost + ":" + String(netCfg.mqttPort));
  bool brokerOk = brokerProbe.connect(netCfg.mqttHost.c_str(), netCfg.mqttPort, GATEWAY_TEST_TIMEOUT_MS);
  if (brokerOk) {
    brokerProbe.stop();
    logLine("Broker reachability teszt OK");
    return true;
  }

  logLine("Broker reachability teszt HIBA");
  return false;
}

bool checkAndArmRescueMode() {
  bootPrefs.begin("bootctl", false);
  bool armed = bootPrefs.getBool("rescue_arm", false);
  if (armed) {
    bootPrefs.putBool("rescue_arm", false);
    bootPrefs.end();
    logLine("Dupla gyors boot erzekelve -> RESCUE AP");
    return true;
  }
  bootPrefs.putBool("rescue_arm", true);
  bootPrefs.end();
  state.rescueArmPendingClear = true;
  state.rescueArmSetAtMs = millis();
  logLine("Rescue arm beallitva 10 masodpercre");
  return false;
}

void maybeClearRescueArm() {
  if (!state.rescueArmPendingClear) return;
  if (millis() - state.rescueArmSetAtMs < RESCUE_ARM_WINDOW_MS) return;
  bootPrefs.begin("bootctl", false);
  bootPrefs.putBool("rescue_arm", false);
  bootPrefs.end();
  state.rescueArmPendingClear = false;
  logLine("Rescue arm torolve");
}

void stopWiFiStation(const String& reason = "") {
  if (!reason.isEmpty()) logLine(String("STA leallitas: ") + reason);
  WiFi.disconnect(true, true);
  delay(150);
  state.wifiConnected = false;
  state.staAttemptInProgress = false;
  state.staValidated = false;
  state.staGotIpAtMs = 0;
  state.mqttConnected = false;
  state.mqttConnecting = false;
}

void startAccessPoint(const String& reason, bool timedMode, bool rescueMode) {
  logLine(String("AP indul. reason=") + reason + ", timed=" + (timedMode ? "yes" : "no") + ", rescue=" + (rescueMode ? "yes" : "no"));
  stopWiFiStation("AP start előtt");
  WiFi.mode(WIFI_AP);
  delay(150);
  bool ok = WiFi.softAP(AP_SSID, AP_PASS);
  if (!ok) {
    logLine("AP inditas sikertelen");
    return;
  }
  state.apEnabled = true;
  state.apTimedMode = timedMode;
  state.rescueApMode = rescueMode;
  state.fallbackApMode = !rescueMode && timedMode;
  state.apStartedAtMs = millis();
  state.lastWebActivityMs = millis();
  logLine(String("AP aktiv: ssid=") + AP_SSID + " ip=" + WiFi.softAPIP().toString());
  updateLedRing();
}

void stopAccessPoint(const String& reason = "") {
  if (!state.apEnabled) return;
  logLine(String("AP leallitas: ") + reason);
  WiFi.softAPdisconnect(true);
  delay(150);
  state.apEnabled = false;
  state.apTimedMode = false;
  state.rescueApMode = false;
  state.fallbackApMode = false;
  updateLedRing();
}

void startWiFiStationAttempt(const String& reason) {
  if (netCfg.wifiSsid.isEmpty()) {
    logLine("Nincs mentett SSID, STA nem indul");
    return;
  }
  if (state.apEnabled) {
    stopAccessPoint("STA start előtt");
  }

  logLine(String("STA indul. reason=") + reason + ", ssid=" + netCfg.wifiSsid);
  WiFi.mode(WIFI_STA);
  delay(150);
  WiFi.disconnect(true, true);
  delay(150);
  WiFi.begin(netCfg.wifiSsid.c_str(), netCfg.wifiPassword.c_str());
  state.lastStaAttemptMs = millis();
  state.staAttemptStartedMs = millis();
  state.staAttemptInProgress = true;
  state.staValidated = false;
  state.staGotIpAtMs = 0;
  updateLedRing();
}

void syncTime() {
  configTime(GMT_OFFSET_SEC, DAYLIGHT_OFFSET_SEC, "pool.ntp.org", "time.nist.gov");
  logLine("NTP sync inditva");
}

void handleStaLifecycle() {
  refreshWiFiFlags();
  maybeClearRescueArm();

  if (state.apEnabled) {
    if (state.apTimedMode && !netCfg.wifiSsid.isEmpty()) {
      unsigned long apAge = millis() - state.apStartedAtMs;
      if (apAge >= AP_MODE_DURATION_MS) {
        logLine("AP idozito lejart -> uj STA proba");
        startWiFiStationAttempt(state.rescueApMode ? "rescue_ap_timeout" : "fallback_ap_timeout");
      }
    }
    return;
  }

  if (netCfg.wifiSsid.isEmpty()) {
    startAccessPoint("no_saved_ssid", false, false);
    return;
  }

  if (state.wifiConnected) {
    if (!state.staValidated) {
      unsigned long sinceIp = millis() - state.staGotIpAtMs;
      if (sinceIp < GATEWAY_SETTLE_MS) {
        logLine(String("GW teszt varakozas: ") + String(GATEWAY_SETTLE_MS - sinceIp) + " ms");
        return;
      }

      if (gatewayReachable()) {
        state.staValidated = true;
        state.staAttemptInProgress = false;
        logLine("WiFi rendben: gateway elerheto, mehet az MQTT");
      } else {
        logLine("WiFi nem jo: gateway nem erheto el -> AP fallback");
        startAccessPoint("gateway_test_failed", true, false);
      }
    }
    return;
  }

  if (!state.staAttemptInProgress) {
    if (state.lastStaAttemptMs == 0 || millis() - state.lastStaAttemptMs >= STA_RETRY_INTERVAL_MS) {
      startWiFiStationAttempt("retry_timer");
    }
    return;
  }

  if (millis() - state.staAttemptStartedMs >= WIFI_CONNECT_TIMEOUT_MS) {
    logLine("WiFi STA timeout -> AP fallback");
    startAccessPoint("sta_timeout", true, false);
  }
}

// =========================
// MQTT
// =========================
void publishLwtOnline();
void publishReportedState(bool applied, const char* source);
void publishTelemetry();
void publishCommandReply(const String& requestId, bool ok, const String& message);
void publishAlertEvent(const char* eventType, const char* severity, const String& message, float value = NAN, float threshold = NAN);
void publishBootEvent();
void updateAlarmStateFlags();
void readContact();
void evaluateAlerts();
bool shouldThrottleCall(const String& key);
bool enqueueCallTarget(const String& phone, const char* eventType);
void processQueuedCalls();

bool mqttPublish(const String& topic, const String& payload, bool retained = false) {
  bool ok = false;
  if (state.mqttTransport == "wifi" && mqttClient.connected()) {
    ok = mqttClient.publish(topic.c_str(), payload.c_str(), retained);
    Serial.printf("[MQTT PUB WIFI] %s => %s\n", topic.c_str(), ok ? "OK" : "HIBA");
  } else if (state.mqttTransport == "gsm" && sim800.mqttIsConnected()) {
    ok = sim800.mqttPublish(topic, payload, retained);
    Serial.printf("[MQTT PUB GSM] %s => %s\n", topic.c_str(), ok ? "OK" : "HIBA");
  } else {
    Serial.printf("[MQTT PUB NONE] %s => HIBA\n", topic.c_str());
  }
  if (!ok) Serial.println(payload);
  return ok;
}

void handleDesiredConfig(JsonDocument& doc) {
  if (!validateRuntimeConfigDoc(doc)) {
    logLine("state/desired ervenytelen payload");
    return;
  }

  if (!applyRuntimeConfigDoc(doc)) {
    logLine("state/desired alkalmazas sikertelen");
    return;
  }

  saveRuntimeConfigDoc(doc);
  publishReportedState(true, "mqtt");
}

void handleCommand(JsonDocument& doc) {
  String requestId = String((const char*)(doc["request_id"] | ""));
  String cmd = String((const char*)(doc["cmd"] | ""));

  if (requestId.isEmpty()) {
    logLine("cmd/in request_id hianyzik");
    return;
  }

  bool ok = false;
  String message = "unknown_command";

  if (cmd == "ping") {
    ok = true;
    message = "pong";
  } else if (cmd == "get_status") {
    publishTelemetry();
    publishReportedState(true, "status");
    ok = true;
    message = "status_ok";
  } else if (cmd == "reload_config") {
    bool loaded = loadRuntimeConfig();
    publishReportedState(loaded, loaded ? "local" : "none");
    ok = loaded;
    message = loaded ? "reload_ok" : "reload_failed";
  } else if (cmd == "restart") {
    ok = true;
    message = "restarting";
    publishCommandReply(requestId, ok, message);
    delay(300);
    ESP.restart();
    return;
  }

  publishCommandReply(requestId, ok, message);
}

void mqttCallback(char* topic, byte* payload, unsigned int length) {
  String topicStr(topic);
  StaticJsonDocument<4096> doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    logLine(String("MQTT JSON hiba: ") + err.c_str());
    return;
  }

  if (topicStr == topicDesired) {
    handleDesiredConfig(doc);
  } else if (topicStr == topicCmdIn) {
    handleCommand(doc);
  }
}

bool connectMqtt() {
  if (mqttIsConnected()) return true;
  if (netCfg.mqttHost.isEmpty()) return false;

  state.mqttConnecting = true;
  updateLedRing();

  StaticJsonDocument<128> lwtDoc;
  lwtDoc["device_id"] = netCfg.deviceId;
  lwtDoc["status"] = "offline";
  lwtDoc["ts"] = isoNow();
  String lwtPayload;
  serializeJson(lwtDoc, lwtPayload);

  String clientId = "pp-" + netCfg.deviceId + "-" + String((uint32_t)ESP.getEfuseMac(), HEX);

  if (wantWifiMqtt()) {
    if (state.mqttTransport == "gsm") disconnectMqttTransport("wifi elerheto");
    logLine(String("MQTT wifi connect: ") + netCfg.mqttHost + ":" + netCfg.mqttPort);
    bool ok = mqttClient.connect(clientId.c_str(), netCfg.mqttUser.c_str(), netCfg.mqttPassword.c_str(), topicLwt.c_str(), 1, true, lwtPayload.c_str());
    state.mqttConnected = ok;
    state.gsmMqttConnected = false;
    state.mqttConnecting = false;
    if (!ok) {
      state.mqttTransport = "none";
      logLine(String("MQTT wifi connect hiba rc=") + mqttClient.state());
      updateLedRing();
      return false;
    }
    state.mqttTransport = "wifi";
    mqttClient.subscribe(topicDesired.c_str());
    mqttClient.subscribe(topicCmdIn.c_str());
    publishLwtOnline();
    publishReportedState(true, state.runtimeConfigLoaded ? "local" : "none");
    if (!state.bootEventPublished) publishBootEvent();
    logLine("MQTT wifi connected");
    updateLedRing();
    return true;
  }

  if (wantGsmMqtt()) {
    if (state.mqttTransport == "wifi") disconnectMqttTransport("wifi kiesett");
    logLine(String("MQTT gsm connect: ") + netCfg.mqttHost + ":" + netCfg.mqttPort);
    bool ok = sim800.mqttConnect(netCfg.mqttHost, netCfg.mqttPort, clientId, netCfg.mqttUser, netCfg.mqttPassword);
    state.gsmMqttConnected = ok;
    state.mqttConnected = false;
    state.mqttConnecting = false;
    if (!ok) {
      state.mqttTransport = "none";
      logLine("MQTT gsm connect hiba");
      updateLedRing();
      return false;
    }
    state.mqttTransport = "gsm";
    publishLwtOnline();
    publishReportedState(true, state.runtimeConfigLoaded ? "local" : "none");
    if (!state.bootEventPublished) publishBootEvent();
    logLine("MQTT gsm connected");
    updateLedRing();
    return true;
  }

  state.mqttConnecting = false;
  updateLedRing();
  return false;
}

void syncSim800State(bool logChanges = false) {
  Sim800Snapshot snap = sim800.snapshot();

  bool prevReady = state.gsmReady;
  bool prevRegistered = state.gsmRegistered;
  int prevRssi = state.gsmRssi;
  String prevOperator = state.gsmOperator;

  state.gsmEnabled = true;
  state.gsmReady = snap.ready;
  state.gsmSimReady = snap.simReady;
  state.gsmRegistered = snap.networkRegistered;
  state.gsmRssi = snap.rssiDbm;
  state.gsmOperator = snap.operatorName;
  if (!snap.ready) {
    state.gsmMqttConnected = false;
    if (state.mqttTransport == "gsm") state.mqttTransport = "none";
  }

  if (logChanges) {
    logLine(String("SIM800 ready=") + (state.gsmReady ? "igen" : "nem") + ", sim=" + (state.gsmSimReady ? "ready" : "nincs") + ", reg=" + (state.gsmRegistered ? "igen" : "nem") + ", rssi=" + String(state.gsmRssi) + ", op=" + (state.gsmOperator.isEmpty() ? String("?") : state.gsmOperator));
  } else if (prevReady != state.gsmReady || prevRegistered != state.gsmRegistered || prevRssi != state.gsmRssi || prevOperator != state.gsmOperator) {
    logLine(String("SIM800 allapot valtozott: ready=") + (state.gsmReady ? "igen" : "nem") + ", reg=" + (state.gsmRegistered ? "igen" : "nem") + ", rssi=" + String(state.gsmRssi) + ", op=" + (state.gsmOperator.isEmpty() ? String("?") : state.gsmOperator));
  }
}

void setupSim800() {
  logLine("--- SIM800L init indul ---");
  logLine(String("  Pinout: RX=") + MODEM_RX_PIN + " (ESP32 RX <- SIM800 TXD), TX=" + MODEM_TX_PIN + " (ESP32 TX -> SIM800 RXD)");
  logLine(String("  Baud: ") + MODEM_DEFAULT_BAUD + ", APN: " + (netCfg.gsmApn.isEmpty() ? "(ures)" : netCfg.gsmApn));

  state.gsmEnabled = true;
  state.gsmInitInProgress = true;
  updateLedRing();

  sim800.setDataConfig(netCfg.gsmApn, netCfg.gsmUser, netCfg.gsmPassword);
  bool ok = sim800.begin(modemSerial, MODEM_RX_PIN, MODEM_TX_PIN, MODEM_DEFAULT_BAUD, MODEM_POLL_INTERVAL_MS);
  state.gsmInitInProgress = false;
  Sim800Snapshot snap = sim800.snapshot();

  if (ok) {
    logLine("  [OK] SIM800 modem valaszol");
    logLine(String("  SIM: ") + (snap.simReady ? "READY" : "NEM KESZ (hianyzik vagy PIN vedett?)"));
    logLine(String("  Halozat: ") + (snap.networkRegistered ? "regisztralva" : "NEM regisztralva"));
    logLine(String("  RSSI: ") + String(snap.rssiDbm) + " dBm (CSQ=" + String(snap.csq) + ")");
    logLine(String("  Operator: ") + (snap.operatorName.isEmpty() ? "(ismeretlen)" : snap.operatorName));
    logLine("--- SIM800L init OK ---");
  } else {
    logLine("  [HIBA] SIM800 nem valaszol az AT parancsra");
    logLine("  Ellenorizd:");
    logLine("    1. Tapfeszultseg: 3.7-4.2V, min. 1-2A csucaram (USB port nem elég!)");
    logLine("    2. Bekotes: SIM800 TXD -> ESP GPIO" + String(MODEM_RX_PIN) + ", SIM800 RXD -> ESP GPIO" + String(MODEM_TX_PIN));
    logLine("    3. Kozos fold (GND) megvan?");
    logLine("    4. A SIM800L STATUS LED villog? (1s = halozat, 3s = no halozat, gyors = adatfolyam)");
    if (!snap.lastError.isEmpty()) {
      logLine(String("  Utolso hiba: ") + snap.lastError);
    }
    logLine("--- SIM800L init SIKERTELEN, 3 perc mulva ujra ---");
  }

  syncSim800State(false);
  updateLedRing();
}

void pollSim800() {
  if (!state.gsmEnabled) return;
  sim800.loop();
  syncSim800State(false);
}

// =========================
// Sensor
// =========================
void setupBME280() {
  Wire.begin(I2C_SDA_PIN, I2C_SCL_PIN);
  bool ok = bme.begin(0x76);
  if (!ok) ok = bme.begin(0x77);
  if (!ok) {
    state.sensorOk = false;
    logLine("BME280 init HIBA");
    updateLedRing();
    return;
  }

  bme.setSampling(Adafruit_BME280::MODE_NORMAL,
                  Adafruit_BME280::SAMPLING_X8,
                  Adafruit_BME280::SAMPLING_X4,
                  Adafruit_BME280::SAMPLING_X2,
                  Adafruit_BME280::FILTER_X4,
                  Adafruit_BME280::STANDBY_MS_500);

  state.sensorOk = true;
  logLine("BME280 init OK");
  updateLedRing();
}

void readSensors() {
  if (!state.sensorOk) return;
  state.temperature = bme.readTemperature();
  state.humidity = bme.readHumidity();
  state.pressure_hpa = bme.readPressure() / 100.0f;
  refreshWiFiFlags();
}

void readPowerState() {
  // USB tápellátás érzékelése: UPS_IN — 330k — GPIO33 — 330k — GND
  int raw = analogRead(USB_SENSE_PIN);
  state.usbPower = (raw >= USB_SENSE_THRESHOLD_RAW);

  // Töltés érzékelése: CHRG active LOW (tölt = LOW)
  state.batteryCharging = (digitalRead(UPS_CHARGE_PIN) == LOW);

  // Akkumulátor töltöttség MAX17040-ből (Waveshare Mini UPS)
  Wire.beginTransmission(UPS_I2C_ADDR);
  Wire.write(0x02);  // VCELL regiszter
  if (Wire.endTransmission(false) == 0) {
    Wire.requestFrom((uint8_t)UPS_I2C_ADDR, (uint8_t)2);
    if (Wire.available() >= 2) {
      uint16_t vcell = ((uint16_t)Wire.read() << 8) | Wire.read();
      state.batteryVoltage = (vcell >> 4) * 0.00125f;
    }
  }

  Wire.beginTransmission(UPS_I2C_ADDR);
  Wire.write(0x04);  // SOC regiszter
  if (Wire.endTransmission(false) == 0) {
    Wire.requestFrom((uint8_t)UPS_I2C_ADDR, (uint8_t)2);
    if (Wire.available() >= 2) {
      uint8_t soc_int = Wire.read();
      Wire.read();  // frakcionális rész (nem kell)
      state.batteryPct = constrain((int)soc_int, 0, 100);
    }
  }
}

// =========================
// Publish payloads
// =========================
void publishLwtOnline() {
  StaticJsonDocument<256> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["status"] = "online";
  doc["ts"] = isoNow();
  doc["telemetry_transport"] = currentTelemetryTransport();
  doc["wifi_ok"] = state.wifiConnected;
  if (state.wifiConnected) {
    doc["wifi_rssi"] = state.rssi;
  } else {
    doc["wifi_rssi"] = nullptr;
  }
  doc["wifi_ip"] = currentWiFiIp();
  doc["gsm_ok"] = currentGsmOk();
  doc["gsm_operator"] = currentGsmOperator();
  if (currentGsmRssiKnown()) {
    doc["gsm_rssi"] = state.gsmRssi;
  } else {
    doc["gsm_rssi"] = nullptr;
  }
  String payload;
  serializeJson(doc, payload);
  mqttPublish(topicLwt, payload, true);
}

void publishReportedState(bool applied, const char* source) {
  StaticJsonDocument<768> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["ts"] = isoNow();
  doc["config_version"] = runtimeCfg.configVersion;
  doc["applied"] = applied;
  doc["applied_at"] = isoNow();
  doc["config_source"] = source;
  doc["sampling_sec"] = runtimeCfg.samplingSec;
  doc["heartbeat_sec"] = runtimeCfg.heartbeatSec;
  doc["fw"] = "clean-slate-0.4.0-bme280-ups";
  doc["power_mode"] = state.usbPower ? "charging" : "battery";
  doc["telemetry_transport"] = currentTelemetryTransport();
  doc["wifi_ok"] = state.wifiConnected;
  if (state.wifiConnected) {
    doc["wifi_rssi"] = state.rssi;
  } else {
    doc["wifi_rssi"] = nullptr;
  }
  doc["wifi_ip"] = currentWiFiIp();
  doc["gsm_ok"] = currentGsmOk();
  doc["gsm_operator"] = currentGsmOperator();
  if (currentGsmRssiKnown()) {
    doc["gsm_rssi"] = state.gsmRssi;
  } else {
    doc["gsm_rssi"] = nullptr;
  }

  String payload;
  serializeJson(doc, payload);
  mqttPublish(topicReported, payload, true);
  state.lastReportedMs = millis();
}

void publishTelemetry() {
  StaticJsonDocument<1024> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["ts"] = isoNow();
  doc["telemetry_transport"] = currentTelemetryTransport();
  doc["wifi_ok"] = state.wifiConnected;
  if (state.wifiConnected) {
    doc["wifi_rssi"] = state.rssi;
  } else {
    doc["wifi_rssi"] = nullptr;
  }
  doc["wifi_ip"] = currentWiFiIp();
  doc["gsm_ok"] = currentGsmOk();
  doc["gsm_operator"] = currentGsmOperator();
  if (currentGsmRssiKnown()) {
    doc["gsm_rssi"] = state.gsmRssi;
  } else {
    doc["gsm_rssi"] = nullptr;
  }

  JsonObject env = doc["env"].to<JsonObject>();
  env["temperature"] = state.temperature;
  env["humidity"] = state.humidity;
  env["pressure_hpa"] = state.pressure_hpa;

  JsonObject power = doc["power"].to<JsonObject>();
  power["usb_present"] = state.usbPower;
  power["charging"] = state.batteryCharging;
  power["mode"] = state.usbPower ? "charging" : "battery";
  if (state.batteryPct >= 0) power["battery_pct"] = state.batteryPct;
  if (!isnan(state.batteryVoltage)) power["battery_v"] = state.batteryVoltage;

  JsonObject contacts = doc["contacts"].to<JsonObject>();
  contacts["c1"] = state.contactOpen ? "open" : "closed";
  contacts["c1_mode"] = normalizeContactMode(runtimeCfg.contact1Mode);

  JsonObject signal = doc["signal"].to<JsonObject>();
  signal["rssi"] = state.rssi;
  signal["gsm_registered"] = state.gsmRegistered;
  signal["gsm_operator"] = state.gsmOperator;
  if (currentGsmRssiKnown()) {
    signal["gsm_rssi"] = state.gsmRssi;
  } else {
    signal["gsm_rssi"] = nullptr;
  }

  JsonObject meta = doc["meta"].to<JsonObject>();
  meta["fw"] = "clean-slate-0.4.0-bme280-ups";
  meta["uptime_sec"] = uptimeSec();
  meta["config_version"] = runtimeCfg.configVersion;
  meta["runtime_cfg_loaded"] = state.runtimeConfigLoaded;
  meta["contact_1_mode"] = normalizeContactMode(runtimeCfg.contact1Mode);
  meta["gsm_registered"] = state.gsmRegistered;
  meta["gsm_operator"] = state.gsmOperator;

  String payload;
  serializeJson(doc, payload);
  mqttPublish(topicTelemetry, payload, false);
  state.lastTelemetryMs = millis();
}

void publishCommandReply(const String& requestId, bool ok, const String& message) {
  StaticJsonDocument<256> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["request_id"] = requestId;
  doc["ok"] = ok;
  doc["message"] = message;
  doc["ts"] = isoNow();
  String payload;
  serializeJson(doc, payload);
  mqttPublish(topicCmdOut, payload, false);
}

void publishAlertEvent(const char* eventType, const char* severity, const String& message, float value, float threshold) {
  dispatchLocalActionsForEvent(eventType, message, value, threshold);

  if (!mqttIsConnected()) return;
  StaticJsonDocument<768> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["ts"] = isoNow();
  doc["event_type"] = eventType;
  doc["severity"] = severity;
  doc["message"] = message;
  if (!isnan(value)) doc["value"] = value;
  if (!isnan(threshold)) doc["threshold"] = threshold;

  JsonObject details = doc["details"].to<JsonObject>();
  details["ip"] = currentWiFiIp();
  details["fw"] = "clean-slate-0.4.0-bme280-ups";
  details["config_version"] = runtimeCfg.configVersion;
  details["contact_active"] = state.contactActive;
  details["contact_open"] = state.contactOpen;
  details["contact_mode"] = normalizeContactMode(runtimeCfg.contact1Mode);
  details["telemetry_transport"] = currentTelemetryTransport();
  details["wifi_ok"] = state.wifiConnected;
  if (state.wifiConnected) {
    details["wifi_rssi"] = state.rssi;
  } else {
    details["wifi_rssi"] = nullptr;
  }
  details["gsm_ok"] = currentGsmOk();
  details["gsm_registered"] = state.gsmRegistered;
  details["gsm_operator"] = state.gsmOperator;
  if (currentGsmRssiKnown()) {
    details["gsm_rssi"] = state.gsmRssi;
  } else {
    details["gsm_rssi"] = nullptr;
  }

  String payload;
  serializeJson(doc, payload);
  mqttPublish(topicAlert, payload, false);
}

void publishBootEvent() {
  String ip = currentWiFiIp();
  if (ip.isEmpty()) ip = "n/a";
  String gsmOperator = currentGsmOperator();
  String bootMsg = "Eszkoz ujraindult es MQTT-re csatlakozott, IP: " + ip;
  if (!gsmOperator.isEmpty()) bootMsg += ", GSM: " + gsmOperator;
  publishAlertEvent("device_boot", "info", bootMsg);
  state.bootEventPublished = true;
}

void updateAlarmStateFlags() {
  state.anyActiveAlarm = state.tempHighAlarmActive || state.tempLowAlarmActive || state.contactAlarmActive;
  state.hasUnackedAlarm = state.anyActiveAlarm;
}

void syncAlertPublishes() {
  if (!mqttIsConnected()) return;

  if (state.tempHighAlarmActive && !state.tempHighAlertSent) {
    publishAlertEvent("temp_high", "warning", "Magas homerseklet riasztas", state.temperature, runtimeCfg.tempMax);
    state.tempHighAlertSent = true;
    logLine(String("TEMP_HIGH publish, temp=") + String(state.temperature, 2) + ", max=" + String(runtimeCfg.tempMax, 2));
  }

  if (state.tempLowAlarmActive && !state.tempLowAlertSent) {
    publishAlertEvent("temp_low", "warning", "Alacsony homerseklet riasztas", state.temperature, runtimeCfg.tempMin);
    state.tempLowAlertSent = true;
    logLine(String("TEMP_LOW publish, temp=") + String(state.temperature, 2) + ", min=" + String(runtimeCfg.tempMin, 2));
  }

  if (state.contactAlarmActive && !state.contactAlertSent) {
    publishAlertEvent("contact_active", "warning", "Kontakt GPIO27 riasztas aktiv", NAN, NAN);
    state.contactAlertSent = true;
    logLine("CONTACT_ACTIVE publish");
  }
}

void readContact() {
  bool rawOpen = (digitalRead(CONTACT_PIN) == HIGH);
  if (rawOpen != state.lastContactRaw) {
    state.lastContactRaw = rawOpen;
    state.contactLastChangeMs = millis();
  }
  if (millis() - state.contactLastChangeMs >= CONTACT_DEBOUNCE_MS) {
    state.contactRaw = rawOpen;
    state.contactOpen = rawOpen;
    state.contactActive = contactAlarmForOpenState(rawOpen);
  }
}

void evaluateAlerts() {
  bool tempHighJustCleared = false;
  bool tempLowJustCleared = false;
  bool contactJustCleared = false;

  if (!runtimeCfg.tempAlertEnabled) {
    state.tempHighAlarmActive = false;
    state.tempLowAlarmActive = false;
    state.tempHighAlertSent = false;
    state.tempLowAlertSent = false;
  }
  if (!isContactMonitoringEnabled()) {
    state.contactAlarmActive = false;
    state.contactAlertSent = false;
  }

  if (state.sensorOk && runtimeCfg.tempAlertEnabled && !isnan(state.temperature)) {
    if (!state.tempHighAlarmActive && state.temperature >= runtimeCfg.tempMax) {
      state.tempHighAlarmActive = true;
      logLine(String("TEMP_HIGH active, temp=") + String(state.temperature, 2) + ", max=" + String(runtimeCfg.tempMax, 2));
    } else if (state.tempHighAlarmActive && state.temperature < (runtimeCfg.tempMax - 0.5f)) {
      state.tempHighAlarmActive = false;
      tempHighJustCleared = true;
      logLine(String("TEMP_HIGH cleared, temp=") + String(state.temperature, 2) + ", max=" + String(runtimeCfg.tempMax, 2));
    }

    if (!state.tempLowAlarmActive && state.temperature <= runtimeCfg.tempMin) {
      state.tempLowAlarmActive = true;
      logLine(String("TEMP_LOW active, temp=") + String(state.temperature, 2) + ", min=" + String(runtimeCfg.tempMin, 2));
    } else if (state.tempLowAlarmActive && state.temperature > (runtimeCfg.tempMin + 0.5f)) {
      state.tempLowAlarmActive = false;
      tempLowJustCleared = true;
      logLine(String("TEMP_LOW cleared, temp=") + String(state.temperature, 2) + ", min=" + String(runtimeCfg.tempMin, 2));
    }
  }

  if (isContactMonitoringEnabled()) {
    if (!state.contactAlarmActive && state.contactActive) {
      state.contactAlarmActive = true;
      logLine(String("CONTACT active mode=") + normalizeContactMode(runtimeCfg.contact1Mode) + ", state=" + contactStatusLabel());
    } else if (state.contactAlarmActive && !state.contactActive) {
      state.contactAlarmActive = false;
      contactJustCleared = true;
      logLine("CONTACT cleared");
    }
  }

  syncAlertPublishes();

  if (tempHighJustCleared) {
    if (mqttClient.connected()) {
      publishAlertEvent("temp_high_cleared", "info", "Magas homerseklet riasztas megszunt", state.temperature, runtimeCfg.tempMax);
    }
    state.tempHighAlertSent = false;
  }

  if (tempLowJustCleared) {
    if (mqttClient.connected()) {
      publishAlertEvent("temp_low_cleared", "info", "Alacsony homerseklet riasztas megszunt", state.temperature, runtimeCfg.tempMin);
    }
    state.tempLowAlertSent = false;
  }

  if (contactJustCleared) {
    if (mqttClient.connected()) {
      publishAlertEvent("contact_cleared", "info", "Kontakt GPIO27 visszaallt", NAN, NAN);
    }
    state.contactAlertSent = false;
  }

  updateAlarmStateFlags();
}

// =========================
// HTTP
// =========================
bool ensureWebAuth() {
  if (netCfg.webPassword.isEmpty()) return true;
  String authUser = netCfg.webUser.isEmpty() ? String("admin") : netCfg.webUser;
  if (server.authenticate(authUser.c_str(), netCfg.webPassword.c_str())) {
    return true;
  }
  server.requestAuthentication(BASIC_AUTH, "PP ESP", "Add meg a felhasznalonevet es jelszot");
  return false;
}

String buildIndexHtml() {
  String html;
  html.reserve(5000);
  html += "<!doctype html><html><head><meta charset='utf-8'>";
  html += "<meta name='viewport' content='width=device-width,initial-scale=1'>";
  html += "<title>PP ESP Clean Slate</title>";
  html += "<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:20px;}";
  html += ".wrap{max-width:860px;margin:0 auto;} .card{background:#111827;border:1px solid #334155;border-radius:14px;padding:16px;margin-bottom:16px;}";
  html += "input{width:100%;padding:10px;border-radius:10px;border:1px solid #475569;background:#0b1220;color:#e2e8f0;}";
  html += "label{display:block;font-size:14px;margin:10px 0 6px;} button{padding:10px 14px;border:0;border-radius:10px;background:#2563eb;color:#fff;}";
  html += "table{width:100%;border-collapse:collapse;} td{padding:6px 0;border-bottom:1px solid #1f2937;} .mono{font-family:monospace;} .ok{color:#22c55e;} .warn{color:#f59e0b;} .bad{color:#ef4444;}";
  html += "</style></head><body><div class='wrap'>";
  html += "<div class='card'><h2>PP ESP Clean Slate</h2><p>Status: <b>" + htmlEscape(statusText()) + "</b></p></div>";

  html += "<div class='card'><h3>Halozati beallitas</h3><form method='post' action='/save'>";
  html += "<label>Device ID</label><input name='device_id' value='" + htmlEscape(netCfg.deviceId) + "'>";
  html += "<label>WiFi SSID</label><input name='wifi_ssid' value='" + htmlEscape(netCfg.wifiSsid) + "'>";
  html += "<label>WiFi jelszo</label><input type='password' name='wifi_password' value='" + htmlEscape(netCfg.wifiPassword) + "'>";
  html += "<label>MQTT host</label><input name='mqtt_host' value='" + htmlEscape(netCfg.mqttHost) + "'>";
  html += "<label>MQTT port</label><input name='mqtt_port' value='" + String(netCfg.mqttPort) + "'>";
  html += "<label>MQTT user</label><input name='mqtt_user' value='" + htmlEscape(netCfg.mqttUser) + "'>";
  html += "<label>MQTT jelszo</label><input type='password' name='mqtt_password' value='" + htmlEscape(netCfg.mqttPassword) + "'>";
  html += "<label>GSM APN</label><input name='gsm_apn' value='" + htmlEscape(netCfg.gsmApn) + "'>";
  html += "<label>GSM user</label><input name='gsm_user' value='" + htmlEscape(netCfg.gsmUser) + "'>";
  html += "<label>GSM jelszo</label><input type='password' name='gsm_password' value='" + htmlEscape(netCfg.gsmPassword) + "'>";
  html += "<label>Web user</label><input name='web_user' value='" + htmlEscape(netCfg.webUser) + "'>";
  html += "<label>Web jelszo</label><input type='password' name='web_password' value='" + htmlEscape(netCfg.webPassword) + "'>";
  html += "<p style='opacity:.8'>Ha a web jelszo ures, a helyi webfelulet szabadon elerheto.</p>";
  html += "<p><button type='submit'>Mentes</button></p></form></div>";

  html += "<div class='card'><h3>Aktualis allapot</h3><table>";
  html += "<tr><td>WiFi IP</td><td class='mono'>" + WiFi.localIP().toString() + "</td></tr>";
  html += "<tr><td>AP IP</td><td class='mono'>" + WiFi.softAPIP().toString() + "</td></tr>";
  html += "<tr><td>WiFi RSSI</td><td>" + String(state.rssi) + " dBm</td></tr>";
  html += "<tr><td>MQTT</td><td>" + String(state.mqttConnected ? "<span class='ok'>connected</span>" : "<span class='bad'>disconnected</span>") + "</td></tr>";
  html += "<tr><td>SIM800</td><td>" + String(state.gsmReady ? "<span class='ok'>ready</span>" : "<span class='bad'>not ready</span>") + "</td></tr>";
  html += "<tr><td>GSM halozat</td><td>" + String(state.gsmRegistered ? "<span class='ok'>feljelentkezve</span>" : "<span class='warn'>nincs regisztracio</span>") + "</td></tr>";
  html += "<tr><td>GSM operátor</td><td>" + htmlEscape(state.gsmOperator.isEmpty() ? String("—") : state.gsmOperator) + "</td></tr>";
  html += "<tr><td>GSM RSSI</td><td>" + String(currentGsmRssiKnown() ? String(state.gsmRssi) + " dBm" : String("—")) + "</td></tr>";
  html += "<tr><td>Runtime cfg</td><td>" + String(state.runtimeConfigLoaded ? "<span class='ok'>loaded</span>" : "<span class='warn'>default</span>") + "</td></tr>";
  html += "<tr><td>Config version</td><td>" + String(runtimeCfg.configVersion) + "</td></tr>";
  html += "<tr><td>Temperature</td><td>" + String(state.temperature, 1) + " &deg;C</td></tr>";
  html += "<tr><td>Humidity</td><td>" + String(state.humidity, 1) + " %</td></tr>";
  html += "<tr><td>Pressure</td><td>" + String(state.pressure_hpa, 1) + " hPa</td></tr>";
  html += "<tr><td>USB tap</td><td>" + String(state.usbPower ? "<span class='ok'>jelen</span>" : "<span class='warn'>nincs</span>") + "</td></tr>";
  html += "<tr><td>Toltes</td><td>" + String(state.batteryCharging ? "<span class='ok'>tolt</span>" : "<span class='warn'>nem tolt</span>") + "</td></tr>";
  html += "<tr><td>Akku %</td><td>" + String(state.batteryPct >= 0 ? String(state.batteryPct) + " %" : String("—")) + "</td></tr>";
  html += "<tr><td>Akku feszultseg</td><td>" + String(!isnan(state.batteryVoltage) ? String(state.batteryVoltage, 3) + " V" : String("—")) + "</td></tr>";
  html += "<tr><td>Kontakt GPIO27 mod</td><td>" + contactModeLabel() + "</td></tr>";
  html += "<tr><td>Kontakt GPIO27 allapot</td><td>" + String(normalizeContactMode(runtimeCfg.contact1Mode) == "unused" ? "<span class='warn'>nem hasznalt</span>" : (state.contactOpen ? "<span class='warn'>open</span>" : "<span class='ok'>closed</span>")) + "</td></tr>";
  html += "<tr><td>Kontakt GPIO27 riasztas</td><td>" + String(state.contactActive ? "<span class='bad'>aktiv</span>" : "<span class='ok'>normal</span>") + "</td></tr>";
  html += "<tr><td>Aktiv riasztas</td><td>" + String(state.anyActiveAlarm ? "<span class='bad'>igen</span>" : "<span class='ok'>nincs</span>") + "</td></tr>";
  html += "<tr><td>Uptime</td><td>" + String(uptimeSec()) + " sec</td></tr>";
  html += "</table></div>";

  html += "<div class='card'><h3>MQTT topicok</h3><table>";
  html += "<tr><td>desired</td><td class='mono'>" + htmlEscape(topicDesired) + "</td></tr>";
  html += "<tr><td>reported</td><td class='mono'>" + htmlEscape(topicReported) + "</td></tr>";
  html += "<tr><td>telemetry</td><td class='mono'>" + htmlEscape(topicTelemetry) + "</td></tr>";
  html += "<tr><td>alert</td><td class='mono'>" + htmlEscape(topicAlert) + "</td></tr>";
  html += "<tr><td>cmd/in</td><td class='mono'>" + htmlEscape(topicCmdIn) + "</td></tr>";
  html += "<tr><td>cmd/out</td><td class='mono'>" + htmlEscape(topicCmdOut) + "</td></tr>";
  html += "<tr><td>lwt</td><td class='mono'>" + htmlEscape(topicLwt) + "</td></tr>";
  html += "</table></div>";

  html += "<div class='card'><p><a href='/json' style='color:#93c5fd'>/json</a></p></div>";
  html += "</div></body></html>";
  return html;
}

void handleRoot() {
  if (!ensureWebAuth()) return;
  touchWebActivity();
  server.send(200, "text/html; charset=utf-8", buildIndexHtml());
}

void handleJson() {
  if (!ensureWebAuth()) return;
  touchWebActivity();
  StaticJsonDocument<1024> doc;
  doc["device_id"] = netCfg.deviceId;
  doc["wifi_connected"] = state.wifiConnected;
  doc["mqtt_connected"] = state.mqttConnected;
  doc["telemetry_transport"] = currentTelemetryTransport();
  doc["wifi_ok"] = state.wifiConnected;
  doc["ap_enabled"] = state.apEnabled;
  doc["wifi_ip"] = currentWiFiIp();
  doc["ap_ip"] = WiFi.softAPIP().toString();
  if (state.wifiConnected) {
    doc["wifi_rssi"] = state.rssi;
  } else {
    doc["wifi_rssi"] = nullptr;
  }
  doc["gsm_ok"] = currentGsmOk();
  if (currentGsmRssiKnown()) {
    doc["gsm_rssi"] = state.gsmRssi;
  } else {
    doc["gsm_rssi"] = nullptr;
  }
  doc["rssi"] = state.rssi;
  doc["gsm_registered"] = state.gsmRegistered;
  doc["gsm_operator"] = state.gsmOperator;
  doc["runtime_config_loaded"] = state.runtimeConfigLoaded;
  doc["config_version"] = runtimeCfg.configVersion;
  doc["temperature"] = state.temperature;
  doc["humidity"] = state.humidity;
  doc["pressure_hpa"] = state.pressure_hpa;
  doc["usb_present"] = state.usbPower;
  doc["battery_charging"] = state.batteryCharging;
  doc["battery_pct"] = state.batteryPct;
  if (!isnan(state.batteryVoltage)) doc["battery_v"] = state.batteryVoltage;
  doc["contact_mode"] = normalizeContactMode(runtimeCfg.contact1Mode);
  doc["contact_open"] = state.contactOpen;
  doc["contact_active"] = state.contactActive;
  doc["active_alarm"] = state.anyActiveAlarm;
  doc["temp_alert_enabled"] = runtimeCfg.tempAlertEnabled;
  doc["contact_alert_enabled"] = runtimeCfg.contactAlertEnabled;
  doc["temp_min"] = runtimeCfg.tempMin;
  doc["temp_max"] = runtimeCfg.tempMax;
  doc["uptime_sec"] = uptimeSec();
  String payload;
  serializeJsonPretty(doc, payload);
  server.send(200, "application/json; charset=utf-8", payload);
}

void handleSave() {
  if (!ensureWebAuth()) return;
  touchWebActivity();
  netCfg.deviceId = server.arg("device_id");
  netCfg.wifiSsid = server.arg("wifi_ssid");
  netCfg.wifiPassword = server.arg("wifi_password");
  netCfg.mqttHost = server.arg("mqtt_host");
  long mqttPortValue = server.arg("mqtt_port").toInt();
  if (mqttPortValue < 1) mqttPortValue = 1;
  netCfg.mqttPort = (uint16_t)mqttPortValue;
  netCfg.mqttUser = server.arg("mqtt_user");
  netCfg.mqttPassword = server.arg("mqtt_password");
  netCfg.gsmApn = server.arg("gsm_apn");
  netCfg.gsmUser = server.arg("gsm_user");
  netCfg.gsmPassword = server.arg("gsm_password");
  netCfg.webUser = server.arg("web_user");
  netCfg.webPassword = server.arg("web_password");
  if (netCfg.webUser.isEmpty()) netCfg.webUser = "admin";
  buildTopics();
  saveNetConfig();
  server.sendHeader("Location", "/");
  server.send(302, "text/plain", "OK");
  delay(500);
  ESP.restart();
}

void setupWebServer() {
  server.on("/", HTTP_GET, handleRoot);
  server.on("/json", HTTP_GET, handleJson);
  server.on("/save", HTTP_POST, handleSave);
  logLine("HTTP server begin...");
  server.begin();
  logLine("HTTP server elindult");
}

// =========================
// Setup / Loop
// =========================
void setup() {
  Serial.begin(115200);
  delay(500);
  state.bootMs = millis();

  ring.begin();
  analogSetPinAttenuation(USB_SENSE_PIN, ADC_11db);
  pinMode(USB_SENSE_PIN, INPUT);
  pinMode(UPS_CHARGE_PIN, INPUT_PULLUP);
  pinMode(CONTACT_PIN, INPUT_PULLUP);
  state.contactLastChangeMs = millis();
  state.lastContactRaw = (digitalRead(CONTACT_PIN) == HIGH);
  state.contactRaw = state.lastContactRaw;
  state.contactOpen = state.lastContactRaw;
  state.contactActive = contactAlarmForOpenState(state.contactOpen);
  updateLedRing();

  logLine("=== PP-ESP firmware indul (WS2812B-8 LED sor) ===");
  logLine("LED sor jelentese (bal->jobb, 0..7):");
  logLine("  [0] WiFi/halozat:");
  logLine("      Zold=OK+gateway | Sarga=csatl./teszt | Piros=nincs WiFi | Lila villog=Rescue AP");
  logLine("  [1] MQTT kapcsolat:");
  logLine("      Zold=csatlakozva | Kek villog=csatl. folyamatban | Piros=nincs kapcsolat");
  logLine("  [2] GSM modem (SIM800L):");
  logLine("      Zold=modem+halozat OK | Sarga=SIM OK,nincs halozat | Piros=modem hiba/nincs SIM");
  logLine("      Kek villog=init folyamatban | Kialszik=GSM letiltva");
  logLine("  [3] BME280 szenzor:");
  logLine("      Zold=OK | Piros=hiba (I2C nem valaszol)");
  logLine("  [4] Kontakt bemenet:");
  logLine("      Zold=normalis | Piros/narancs villog=riasztas aktiv | Kialszik=nem figyelt");
  logLine("  [5] Homerseklet riasztas:");
  logLine("      Halvany zold=normalis | Piros villog=tul meleg | Kek villog=tul hideg");
  logLine("  [6] USB tap / toltes:");
  logLine("      Zold=USB,teli | Zoldeskek=USB,tolt | Narancs=akkurol mukodik");
  logLine("  [7] Akkumulator toltotseg:");
  logLine("      Zold=76-100% | Kek=51-75% | Sarga=26-50% | Piros=16-25%");
  logLine("      Piros villog=0-15% (kritikus) | Kialszik=ismeretlen");

  if (!SPIFFS.begin(true)) {
    logLine("SPIFFS init HIBA");
  } else {
    logLine("SPIFFS init OK");
  }

  buildTopics();
  loadNetConfig();
  buildTopics();
  loadRuntimeConfig();
  state.contactActive = contactAlarmForOpenState(state.contactOpen);
  updateAlarmStateFlags();

  logLine("WiFi core init");
  WiFi.onEvent(onWiFiEvent);
  WiFi.persistent(false);
  WiFi.setSleep(false);
  WiFi.disconnect(true, true);
  delay(200);

  bool rescueMode = checkAndArmRescueMode();
  if (rescueMode) {
    startAccessPoint("double_boot_rescue", true, true);
  } else if (netCfg.wifiSsid.isEmpty()) {
    logLine("Nincs mentett SSID -> AP mod");
    startAccessPoint("no_saved_ssid", false, false);
  } else {
    logLine("Van mentett SSID -> STA mod");
    startWiFiStationAttempt("boot");
  }

  setupWebServer();
  setupBME280();
  readPowerState();
  setupSim800();
  syncTime();

  mqttClient.setServer(netCfg.mqttHost.c_str(), netCfg.mqttPort);
  mqttClient.setCallback(mqttCallback);
  mqttClient.setBufferSize(4096);
  mqttClient.setKeepAlive(60);  // 60s keepalive (SIM800 poll blokkolás ellen)

  refreshWiFiFlags();
  updateLedRing();
}

void loop() {
  server.handleClient();
  handleStaLifecycle();
  pollSim800();
  processQueuedCalls();
  readContact();

  if (state.lastTelemetryMs == 0 || millis() - state.lastTelemetryMs > runtimeCfg.samplingSec * 1000UL) {
    readSensors();
    readPowerState();
    evaluateAlerts();
    if (mqttIsConnected()) {
      publishTelemetry();
    }
  }

  if (wantWifiMqtt() && state.mqttTransport == "gsm") {
    disconnectMqttTransport("wifi helyreallt");
  }
  if (!wantWifiMqtt() && state.mqttTransport == "wifi") {
    disconnectMqttTransport("wifi kiesett vagy nem valid");
  }
  if (!wantGsmMqtt() && state.mqttTransport == "gsm") {
    disconnectMqttTransport("gsm mqtt feltetelek megszuntek");
  }

  if (!mqttIsConnected()) {
    state.mqttConnected = false;
    state.gsmMqttConnected = (state.mqttTransport == "gsm") && sim800.mqttIsConnected();
    if ((wantWifiMqtt() || wantGsmMqtt()) && millis() - state.lastMqttAttemptMs > MQTT_RETRY_INTERVAL_MS) {
      state.lastMqttAttemptMs = millis();
      connectMqtt();
    }
  } else {
    if (state.mqttTransport == "wifi") {
      mqttClient.loop();
      state.mqttConnected = mqttClient.connected();
      state.gsmMqttConnected = false;
    } else if (state.mqttTransport == "gsm") {
      state.gsmMqttConnected = sim800.mqttIsConnected();
      state.mqttConnected = false;
    }

    evaluateAlerts();

    if (state.lastReportedMs == 0 || millis() - state.lastReportedMs > runtimeCfg.heartbeatSec * 1000UL) {
      publishReportedState(true, state.runtimeConfigLoaded ? "local" : "none");
    }
  }

  updateLedRing();
  delay(10);
}
