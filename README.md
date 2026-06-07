<div align="center">
  <img src="app/assets/logo.png" width="120" style="border-radius: 24px;" />
  <h1>BabL42</h1>
  <p><strong>Meghívásos, zárt körű csevegő alkalmazás</strong></p>
  <p>
    <img src="https://img.shields.io/badge/Flutter-iOS%20%7C%20Android-02569B?logo=flutter" />
    <img src="https://img.shields.io/badge/Backend-PHP%208%20%2B%20WebSocket-777BB4?logo=php" />
    <img src="https://img.shields.io/badge/DB-MySQL-4479A1?logo=mysql&logoColor=white" />
    <img src="https://img.shields.io/badge/Push-APNs%20%7C%20FCM-orange" />
    <img src="https://img.shields.io/badge/verzió-1.1.0-brightgreen" />
  </p>
</div>

---

Privát, meghívásos alapú csevegő iOS és Android alkalmazás. Nincs függőség külső szolgáltatóktól (WhatsApp, Telegram) — a teljes infrastruktúra önállóan üzemeltethető.

## Főbb funkciók

| Funkció | Leírás |
|---|---|
| 🔐 Meghívásos regisztráció | Csak meghívókóddal lehet csatlakozni |
| 💬 1:1 és csoportos szobák | Direct és group típusú szobák |
| ⚡ Valós idejű üzenetküldés | WebSocket (Ratchet) alapú broadcast |
| 📎 Fájl- és képküldés | Bármilyen fájltípus, előnézettel |
| ✓✓ Kézbesítési státusz | Elküldve → Megkapta → Elolvasta |
| 😄 Emoji reakciók | 6 reakciótípus, toggle |
| 💬 Reply / Quote | Üzenetre válasz idézéssel |
| @mention | Értesítés küldése megjelölt felhasználónak |
| 🔍 Üzenetkeresés | Szobán belül, ugrással a találatra |
| 🌙 Dark mode | Rendszer / Világos / Sötét |
| 🔔 Push értesítés | APNs (iOS) + FCM (Android) |
| 📱 Multi-device | Több eszköz egyidejű használata |
| 🖼️ Média galéria | Képek és fájlok böngészése szobánként |
| 📌 Kitűzött üzenet | Fontos üzenet kiemelése szobánként |
| 🔇 Némítás | Szobánkénti értesítés-némítás |

## Architektúra

```
Flutter (iOS + Android)
        │
        ├── HTTPS ──► PHP REST API  (Apache :9456)
        │                  │
        └── WSS ───► Ratchet WebSocket (:9456/ws)
                           │
                    ───────┴───────
                    │             │
                 MySQL        APNs / FCM
                (tricc)     (push értesítés)
```

## Repo struktúra

| Mappa | Tartalom |
|---|---|
| `app/` | Flutter kliens (iOS + Android) |
| `api/` | PHP REST API (Auth, Rooms, Messages, Upload, Push) |
| `ws/` | PHP Ratchet WebSocket szerver |
| `db/` | MySQL séma (`schema.sql`) |
| `admin/` | Web alapú admin panel |
| `docs/` | Specifikációk |

## Dokumentáció

- 📄 [`TECHNIKAI.pdf`](TECHNIKAI.pdf) — Teljes technikai leírás (architektúra, API, WS protokoll, folyamatábrák)
- 📄 [`DOKUMENTACIO.pdf`](DOKUMENTACIO.pdf) — Felhasználói és funkcionális dokumentáció

## Stack

**Kliens:** Flutter 3 · Dart · Material 3 · `web_socket_channel` · `firebase_messaging`

**Szerver:** PHP 8 · Apache · Ratchet WebSocket · ReactPHP · JWT · bcrypt

**Adatbázis:** MySQL · `utf8mb4` · 8 tábla

**Push:** Apple APNs (`.p8` JWT) · Google FCM

**Hálózat:** HTTPS/WSS · önaláírt TLS · WireGuard VPN
