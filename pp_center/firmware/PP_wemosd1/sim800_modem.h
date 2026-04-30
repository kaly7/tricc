#ifndef SIM800_MODEM_H
#define SIM800_MODEM_H

#include <Arduino.h>

struct Sim800Snapshot {
  bool serialReady = false;
  bool modemDetected = false;
  bool simReady = false;
  bool networkRegistered = false;
  bool ready = false;
  int csq = 99;
  int rssiDbm = -999;
  String operatorName;
  String lastError;
  bool mqttSupported = false;
  bool mqttConnected = false;
};

class Sim800Modem {
public:
  bool begin(HardwareSerial& serial, int rxPin, int txPin, uint32_t defaultBaud = 9600, unsigned long pollIntervalMs = 15000UL);
  void loop();

  Sim800Snapshot snapshot() const;

  bool sendSms(const String& phoneNumber, const String& text);
  bool dial(const String& phoneNumber);
  void hangup();

  void setDataConfig(const String& apn, const String& user, const String& password);

  bool mqttConnect(const String& host, uint16_t port, const String& clientId, const String& user, const String& password);
  bool mqttPublish(const String& topic, const String& payload, bool retained = false);
  void mqttDisconnect();
  bool mqttIsConnected() const;

private:
  HardwareSerial* serial_ = nullptr;
  int rxPin_ = -1;
  int txPin_ = -1;
  uint32_t activeBaud_ = 9600;
  unsigned long pollIntervalMs_ = 15000UL;
  unsigned long lastPollMs_ = 0;
  unsigned long lastInitAttemptMs_ = 0;
  Sim800Snapshot status_;

  String gprsApn_;
  String gprsUser_;
  String gprsPassword_;
  bool bearerReady_ = false;
  bool mqttSessionAllocated_ = false;
  String mqttClientId_;

  bool ensureBearer();
  bool ensureMqttService();
  bool sendCommandAndWaitResult(const String& cmd, const String& successToken, String& response, uint32_t timeoutMs);

  bool initModem();
  bool tryAutobaud();
  bool basicInit();
  void refreshStatus();

  bool sendCommand(const String& cmd, String& response, uint32_t timeoutMs = 1500, bool appendCR = true);
  bool waitForToken(const String& token, String& response, uint32_t timeoutMs = 3000);
  void flushInput();

  void updateRegistrationFromResponse(const String& response);
  void updateSimStateFromResponse(const String& response);
  void updateSignalFromResponse(const String& response);
  void updateOperatorFromResponse(const String& response);

  static int csqToDbm(int csq);
  static int parseIntAfterColon(const String& response);
};

#endif
