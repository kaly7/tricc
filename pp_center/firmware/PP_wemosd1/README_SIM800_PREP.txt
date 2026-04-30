Ez a csomag a 2026-04-10-i mukodo PP_wemosd1_0410.ino valtozatbol keszult.

Mi van benne:
- SIM800L alap integracio kulon fajlokban (sim800_modem.h / sim800_modem.cpp)
- hasznalt UART pinek:
  RX = GPIO19  (ESP32 RX <- SIM800L TXD)
  TX = GPIO18  (ESP32 TX -> SIM800L RXD)
- SIM800 allapot, regisztracio es jelszint periodikus lekerdezese
- a mar meglevo gsm_ok / gsm_rssi telemetria mezo tenyleges kitoltese
- a helyi webes statuszban GSM allapot, operator, RSSI megjelenitese

Mi NINCS meg ebben a korben:
- SMS kuldes automatikus riasztasra
- hivasinditas automatikusan
- MQTT fallback GSM adatuton

Megjegyzes:
- a pp_centerhez ehhez a korhoz nem kellett modositas, a jelenlegi backend mar fogadja a gsm_ok / gsm_rssi mezeket.
- Arduino IDE-ben a teljes mappat kell megnyitni, nem csak az .ino fajlt kulon.
