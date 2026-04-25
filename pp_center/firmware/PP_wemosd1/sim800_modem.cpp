#include "sim800_modem.h"

namespace {
// Ha a modem nem válaszol, 3 percenként próbálkozzunk újra (nem 15mp-nként),
// hogy ne blokkoljuk a főciklust túl sűrűn (~50mp-es autobaud scan).
constexpr unsigned long MODEM_RETRY_MS = 180000UL;
constexpr uint32_t BAUD_CANDIDATES[] = {9600, 19200, 38400, 57600, 115200};
}

bool Sim800Modem::begin(HardwareSerial& serial, int rxPin, int txPin, uint32_t defaultBaud, unsigned long pollIntervalMs) {
  serial_ = &serial;
  rxPin_ = rxPin;
  txPin_ = txPin;
  activeBaud_ = defaultBaud;
  pollIntervalMs_ = pollIntervalMs;
  lastInitAttemptMs_ = millis();
  return initModem();
}

void Sim800Modem::loop() {
  if (serial_ == nullptr) return;

  unsigned long now = millis();
  if (!status_.modemDetected && now - lastInitAttemptMs_ >= MODEM_RETRY_MS) {
    lastInitAttemptMs_ = now;
    initModem();
    return;
  }

  if (now - lastPollMs_ >= pollIntervalMs_) {
    lastPollMs_ = now;
    refreshStatus();
  }
}

Sim800Snapshot Sim800Modem::snapshot() const {
  return status_;
}

void Sim800Modem::setDataConfig(const String& apn, const String& user, const String& password) {
  gprsApn_ = apn;
  gprsUser_ = user;
  gprsPassword_ = password;
  bearerReady_ = false;
}

bool Sim800Modem::sendSms(const String& phoneNumber, const String& text) {
  if (serial_ == nullptr || !status_.ready) return false;

  String response;
  if (!sendCommand("AT+CMGF=1", response, 1500)) return false;

  flushInput();
  serial_->print("AT+CMGS=\"");
  serial_->print(phoneNumber);
  serial_->print("\"\r");

  if (!waitForToken(">", response, 4000)) {
    status_.lastError = "CMGS prompt timeout";
    return false;
  }

  serial_->print(text);
  serial_->write(26);

  if (!waitForToken("OK", response, 15000)) {
    status_.lastError = response;
    return false;
  }

  return true;
}

bool Sim800Modem::dial(const String& phoneNumber) {
  if (serial_ == nullptr || !status_.ready) return false;
  String response;
  return sendCommand("ATD" + phoneNumber + ";", response, 3000);
}

void Sim800Modem::hangup() {
  if (serial_ == nullptr) return;
  String response;
  sendCommand("ATH", response, 1500);
}

bool Sim800Modem::mqttIsConnected() const {
  return status_.mqttConnected;
}

bool Sim800Modem::ensureBearer() {
  if (serial_ == nullptr || !status_.ready) return false;
  if (bearerReady_) return true;

  String response;
  sendCommand("AT+SAPBR=3,1,\"CONTYPE\",\"GPRS\"", response, 3000);

  if (!gprsApn_.isEmpty()) {
    sendCommand("AT+SAPBR=3,1,\"APN\",\"" + gprsApn_ + "\"", response, 3000);
  }
  if (!gprsUser_.isEmpty()) {
    sendCommand("AT+SAPBR=3,1,\"USER\",\"" + gprsUser_ + "\"", response, 3000);
  }
  if (!gprsPassword_.isEmpty()) {
    sendCommand("AT+SAPBR=3,1,\"PWD\",\"" + gprsPassword_ + "\"", response, 3000);
  }

  sendCommand("AT+SAPBR=1,1", response, 30000);
  if (!sendCommand("AT+SAPBR=2,1", response, 5000)) {
    status_.lastError = response;
    return false;
  }

  if (response.indexOf("+SAPBR: 1,1") < 0 && response.indexOf("+SAPBR:1,1") < 0) {
    status_.lastError = response;
    return false;
  }

  bearerReady_ = true;
  return true;
}

bool Sim800Modem::ensureMqttService() {
  if (status_.mqttSupported && mqttSessionAllocated_) return true;
  if (!ensureBearer()) return false;

  String response;
  if (!status_.mqttSupported) {
    if (!sendCommandAndWaitResult("AT+CMQTTSTART", "+CMQTTSTART: 0", response, 15000)) {
      status_.mqttSupported = false;
      status_.lastError = response;
      return false;
    }
    status_.mqttSupported = true;
  }

  if (!mqttSessionAllocated_) {
    if (!mqttClientId_.isEmpty()) {
      sendCommand("AT+CMQTTREL=0", response, 3000);
    }
    String cmd = "AT+CMQTTACCQ=0,\"" + mqttClientId_ + "\"";
    if (!sendCommand(cmd, response, 5000)) {
      status_.lastError = response;
      return false;
    }
    mqttSessionAllocated_ = true;
  }
  return true;
}

bool Sim800Modem::mqttConnect(const String& host, uint16_t port, const String& clientId, const String& user, const String& password) {
  if (serial_ == nullptr || !status_.ready) return false;
  if (status_.mqttConnected) return true;

  mqttClientId_ = clientId;
  if (!ensureMqttService()) return false;

  String url = "tcp://" + host + ":" + String(port);
  String response;
  String cmd = "AT+CMQTTCONNECT=0,\"" + url + "\",60,1,\"" + user + "\",\"" + password + "\"";
  if (!sendCommandAndWaitResult(cmd, "+CMQTTCONNECT: 0,0", response, 30000)) {
    status_.mqttConnected = false;
    status_.lastError = response;
    return false;
  }

  status_.mqttConnected = true;
  return true;
}

bool Sim800Modem::mqttPublish(const String& topic, const String& payload, bool retained) {
  if (serial_ == nullptr || !status_.mqttConnected) return false;
  String response;

  flushInput();
  serial_->print("AT+CMQTTTOPIC=0,");
  serial_->print(topic.length());
  serial_->print("\r");
  if (!waitForToken(">", response, 5000)) {
    status_.lastError = response;
    return false;
  }
  serial_->print(topic);
  if (!waitForToken("OK", response, 5000)) {
    status_.lastError = response;
    return false;
  }

  flushInput();
  serial_->print("AT+CMQTTPAYLOAD=0,");
  serial_->print(payload.length());
  serial_->print("\r");
  if (!waitForToken(">", response, 5000)) {
    status_.lastError = response;
    return false;
  }
  serial_->print(payload);
  if (!waitForToken("OK", response, 10000)) {
    status_.lastError = response;
    return false;
  }

  String cmd = String("AT+CMQTTPUB=0,1,60") + (retained ? ",1" : ",0");
  if (!sendCommandAndWaitResult(cmd, "+CMQTTPUB: 0,0", response, 20000)) {
    status_.lastError = response;
    return false;
  }
  return true;
}

void Sim800Modem::mqttDisconnect() {
  if (serial_ == nullptr) return;
  String response;
  if (status_.mqttConnected) {
    sendCommandAndWaitResult("AT+CMQTTDISC=0,60", "+CMQTTDISC: 0,0", response, 10000);
  }
  status_.mqttConnected = false;
}

bool Sim800Modem::initModem() {
  status_ = Sim800Snapshot{};
  status_.lastError = "initializing";
  bearerReady_ = false;
  mqttSessionAllocated_ = false;

  if (!tryAutobaud()) {
    status_.lastError = "autobaud failed";
    return false;
  }

  status_.serialReady = true;
  status_.modemDetected = true;

  if (!basicInit()) {
    if (status_.lastError.isEmpty()) status_.lastError = "basic init failed";
    return false;
  }

  refreshStatus();
  return status_.modemDetected;
}

bool Sim800Modem::tryAutobaud() {
  if (serial_ == nullptr) return false;

  // Baudrátánként csak 1 kísérlet, rövidebb timeout:
  // 5 baud × (350ms settle + 1 × 400ms timeout) = ~3.75s összesen (régen ~50s volt).
  for (uint32_t baud : BAUD_CANDIDATES) {
    serial_->end();
    delay(50);
    serial_->begin(baud, SERIAL_8N1, rxPin_, txPin_);
    delay(350);
    flushInput();

    String response;
    if (sendCommand("AT", response, 400)) {
      activeBaud_ = baud;
      return true;
    }
  }

  return false;
}

bool Sim800Modem::basicInit() {
  String response;
  if (!sendCommand("ATE0", response, 1200)) return false;
  sendCommand("AT+CMEE=2", response, 1200);
  sendCommand("AT+CLIP=1", response, 1200);
  sendCommand("AT+CMGF=1", response, 1200);
  sendCommand("AT+COPS=3,0", response, 1500);
  return true;
}

void Sim800Modem::refreshStatus() {
  if (serial_ == nullptr) return;

  String response;
  if (!sendCommand("AT", response, 900)) {
    status_.modemDetected = false;
    status_.ready = false;
    status_.networkRegistered = false;
    status_.mqttConnected = false;
    bearerReady_ = false;
    status_.lastError = response.isEmpty() ? String("AT timeout") : response;
    return;
  }

  status_.modemDetected = true;
  status_.lastError = "";

  if (sendCommand("AT+CPIN?", response, 1500)) {
    updateSimStateFromResponse(response);
  }
  if (sendCommand("AT+CREG?", response, 1500)) {
    updateRegistrationFromResponse(response);
  }
  if (sendCommand("AT+CSQ", response, 1500)) {
    updateSignalFromResponse(response);
  }
  if (sendCommand("AT+COPS?", response, 2500)) {
    updateOperatorFromResponse(response);
  }

  status_.ready = status_.modemDetected && status_.simReady && status_.networkRegistered;
  if (!status_.ready) {
    status_.mqttConnected = false;
    bearerReady_ = false;
  }
}

bool Sim800Modem::sendCommand(const String& cmd, String& response, uint32_t timeoutMs, bool appendCR) {
  if (serial_ == nullptr) return false;

  flushInput();
  serial_->print(cmd);
  if (appendCR) serial_->print("\r");

  unsigned long start = millis();
  response = "";
  while (millis() - start < timeoutMs) {
    while (serial_->available()) {
      char c = static_cast<char>(serial_->read());
      response += c;
    }

    if (response.indexOf("\r\nOK\r\n") >= 0 || response.endsWith("OK\r\n") || response.endsWith("OK\n")) {
      return true;
    }
    if (response.indexOf("ERROR") >= 0 || response.indexOf("+CME ERROR") >= 0) {
      status_.lastError = response;
      return false;
    }
    delay(5);
  }

  status_.lastError = response;
  return false;
}

bool Sim800Modem::sendCommandAndWaitResult(const String& cmd, const String& successToken, String& response, uint32_t timeoutMs) {
  String first;
  if (!sendCommand(cmd, first, (timeoutMs < 8000 ? timeoutMs : 8000))) {
    response = first;
    return false;
  }
  if (successToken.isEmpty()) {
    response = first;
    return true;
  }
  String second;
  if (!waitForToken(successToken, second, timeoutMs)) {
    response = first + second;
    return false;
  }
  response = first + second;
  return true;
}

bool Sim800Modem::waitForToken(const String& token, String& response, uint32_t timeoutMs) {
  if (serial_ == nullptr) return false;

  unsigned long start = millis();
  response = "";
  while (millis() - start < timeoutMs) {
    while (serial_->available()) {
      char c = static_cast<char>(serial_->read());
      response += c;
    }
    if (response.indexOf(token) >= 0) {
      return true;
    }
    if (response.indexOf("ERROR") >= 0 || response.indexOf("+CME ERROR") >= 0) {
      status_.lastError = response;
      return false;
    }
    delay(5);
  }

  status_.lastError = response;
  return false;
}

void Sim800Modem::flushInput() {
  if (serial_ == nullptr) return;
  while (serial_->available()) {
    serial_->read();
  }
}

void Sim800Modem::updateRegistrationFromResponse(const String& response) {
  int comma = response.lastIndexOf(',');
  if (comma < 0) return;
  int end = response.indexOf('\r', comma);
  if (end < 0) end = response.indexOf('\n', comma);
  if (end < 0) end = response.length();
  int stat = response.substring(comma + 1, end).toInt();
  status_.networkRegistered = (stat == 1 || stat == 5);
}

void Sim800Modem::updateSimStateFromResponse(const String& response) {
  status_.simReady = response.indexOf("READY") >= 0;
}

void Sim800Modem::updateSignalFromResponse(const String& response) {
  int csq = parseIntAfterColon(response);
  if (csq < 0) return;
  status_.csq = csq;
  status_.rssiDbm = csqToDbm(csq);
}

void Sim800Modem::updateOperatorFromResponse(const String& response) {
  int firstQuote = response.indexOf('"');
  if (firstQuote >= 0) {
    int secondQuote = response.indexOf('"', firstQuote + 1);
    if (secondQuote > firstQuote) {
      status_.operatorName = response.substring(firstQuote + 1, secondQuote);
      status_.operatorName.trim();
      return;
    }
  }

  int colon = response.indexOf(':');
  if (colon < 0) return;
  int firstComma = response.indexOf(',', colon + 1);
  if (firstComma < 0) return;
  int secondComma = response.indexOf(',', firstComma + 1);
  if (secondComma < 0) return;
  int thirdComma = response.indexOf(',', secondComma + 1);
  int end = thirdComma >= 0 ? thirdComma : response.indexOf('\r', secondComma + 1);
  if (end < 0) end = response.indexOf('\n', secondComma + 1);
  if (end < 0) end = response.length();

  String candidate = response.substring(secondComma + 1, end);
  candidate.trim();
  if (candidate.length() >= 2 && candidate.startsWith("\"") && candidate.endsWith("\"")) {
    candidate = candidate.substring(1, candidate.length() - 1);
    candidate.trim();
  }
  if (!candidate.isEmpty()) {
    status_.operatorName = candidate;
  }
}

int Sim800Modem::csqToDbm(int csq) {
  if (csq == 99) return -999;
  if (csq < 0) return -999;
  if (csq > 31) csq = 31;
  return -113 + (2 * csq);
}

int Sim800Modem::parseIntAfterColon(const String& response) {
  int colon = response.indexOf(':');
  if (colon < 0) return -1;
  int comma = response.indexOf(',', colon + 1);
  String value = comma >= 0 ? response.substring(colon + 1, comma) : response.substring(colon + 1);
  value.trim();
  return value.toInt();
}
