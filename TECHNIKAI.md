---
title: "BabL42 — Technikai dokumentáció"
pdf_options:
  format: A4
  margin: 28mm 22mm 28mm 22mm
  printBackground: true
stylesheet: doc_style.css
---

<div style="page-break-after: always; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 90vh; text-align: center;">
  <img src="app/assets/logo.png" style="width: 160px; height: 160px; border-radius: 32px; margin-bottom: 40px;" />
  <h1 style="font-size: 2.6em; margin: 0 0 12px 0; color: #0d1b3e;">BabL42</h1>
  <p style="font-size: 1.2em; color: #4a5568; margin: 0 0 16px 0;">Technikai dokumentáció</p>
  <hr style="width: 60px; border: 2px solid #7ec81b; margin: 0 0 32px 0;" />
  <p style="color: #718096; font-size: 0.95em; margin: 0;">iOS · Android · Flutter · PHP 8 · MySQL · WebSocket · APNs · FCM</p>
  <p style="color: #a0aec0; font-size: 0.85em; margin: 8px 0 0 0;">v1.1.0 · 2026. június</p>
</div>

# BabL42 — Technikai dokumentáció

> Meghívásos, zárt körű csevegő alkalmazás teljes technikai leírása: kliens (iOS + Android), szerver (REST API + WebSocket), adatbázis, push értesítés.

---

# 1. Rendszerarchitektúra

## 1.1 Áttekintés

A BabL42 egy kétszintű (client–server) rendszer. A kliens Flutter alkalmazás iOS-en és Androidon fut; a szerver egy önálló Linux gépen üzemel Apache webszerverrel.

```
┌─────────────────────────────────────────────────────────────┐
│                        KLIENS                               │
│                                                             │
│   ┌──────────────────┐       ┌──────────────────┐          │
│   │   Flutter iOS    │       │  Flutter Android  │          │
│   │  (APNs push)     │       │   (FCM push)      │          │
│   └────────┬─────────┘       └────────┬──────────┘          │
└────────────┼────────────────────────── ┼────────────────────┘
             │  HTTPS / WSS              │
             ▼                           ▼
┌─────────────────────────────────────────────────────────────┐
│                     SZERVER (192.168.16.22)                  │
│                                                             │
│   ┌──────────────────────────────────────────────────────┐  │
│   │              Apache (port 9456, HTTPS)               │  │
│   │   /tricc/api/*  → PHP REST API                       │  │
│   │   /ws           → Ratchet WebSocket (reverse proxy)  │  │
│   └────────────────┬──────────────────┬──────────────────┘  │
│                    │                  │                      │
│   ┌────────────────▼────┐    ┌────────▼───────────────────┐ │
│   │   PHP REST API      │    │  PHP Ratchet WebSocket      │ │
│   │   (api/)            │◄──►│  (ws/, port 9454)           │ │
│   │                     │    │  IPC TCP: 127.0.0.1:9455    │ │
│   └────────────────┬────┘    └────────────────────────────┘ │
│                    │                                         │
│   ┌────────────────▼────────────────────────────────────┐   │
│   │              MySQL — tricc adatbázis                 │   │
│   └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
             │ APNs (iOS)        │ FCM (Android)
             ▼                   ▼
    Apple Push Network    Google Firebase
```

## 1.2 Portok és URL-ek

| Szolgáltatás | Port | Protokoll | URL |
|---|---|---|---|
| REST API | 9456 | HTTPS | `https://192.168.16.22:9456/tricc/api` |
| WebSocket | 9456/ws | WSS | `wss://192.168.16.22:9456/ws` |
| Ratchet (belső) | 9454 | WS | `ws://127.0.0.1:9454` |
| REST→WS IPC | 9455 | TCP | `127.0.0.1:9455` |

> A szerver önaláírt TLS tanúsítványt használ. A kliens `badCertificateCallback = true`-val fogadja el (belső hálózat).

## 1.3 Mappa-struktúra

```
05_tricc/
├── app/              ← Flutter kliens (iOS + Android)
│   ├── lib/
│   │   ├── main.dart
│   │   ├── app_theme.dart
│   │   ├── models/
│   │   ├── screens/
│   │   ├── services/
│   │   └── widgets/
│   ├── ios/          ← iOS natív rész, APNs konfig
│   └── android/      ← Android natív rész, FCM konfig
├── api/              ← PHP REST API
│   └── src/
│       ├── Controllers/
│       ├── APNs.php
│       ├── Auth.php
│       └── DB.php
├── ws/               ← PHP Ratchet WebSocket szerver
│   └── src/ChatServer.php
├── db/               ← Adatbázis séma és migrációk
│   └── schema.sql
├── admin/            ← Web alapú admin felület
└── docs/             ← Dokumentáció
```

---

# 2. Adatbázis

## 2.1 Séma áttekintés

Az adatbázis neve `tricc`, karakterkészlete `utf8mb4`. Összesen 8 tábla.

```
users ──────────────────── invite_codes
  │                              │
  │◄─── room_members ────► rooms
  │          │                   │
  │          │              messages ──── message_reactions
  │          │                   │
  └──────────┴─────────── message_deliveries
  │
  └──── push_tokens
```

## 2.2 Táblák részletesen

### `users`
| Mező | Típus | Leírás |
|---|---|---|
| `id` | INT PK AUTO | Felhasználó azonosítója |
| `name` | VARCHAR(100) | Megjelenített név |
| `email` | VARCHAR(150) UNIQUE | Bejelentkezési email |
| `password` | VARCHAR(255) | bcrypt hash |
| `avatar_url` | VARCHAR(500) | Profilkép URL |
| `invite_code` | VARCHAR(32) | Felhasznált meghívókód |
| `is_admin` | TINYINT(1) | Admin jogosultság |
| `is_active` | TINYINT(1) | Aktív-e a fiók |
| `created_at` | DATETIME | Regisztráció dátuma |

### `invite_codes`
| Mező | Típus | Leírás |
|---|---|---|
| `id` | INT PK AUTO | Azonosító |
| `code` | VARCHAR(32) UNIQUE | Meghívókód (pl. `TRICC-ADMIN-0001`) |
| `created_by` | INT FK→users | Ki generálta |
| `used_by` | INT FK→users | Ki használta fel (NULL = szabad) |
| `used_at` | DATETIME | Felhasználás ideje |
| `expires_at` | DATETIME | Lejárat |

### `push_tokens`
| Mező | Típus | Leírás |
|---|---|---|
| `user_id` | INT PK FK→users | Felhasználó (1 sor/user) |
| `token` | VARCHAR(200) | APNs vagy FCM token |
| `platform` | VARCHAR(10) | `ios` vagy `android` |
| `updated_at` | DATETIME | Utolsó frissítés |

> **Megjegyzés:** Egy felhasználónak több eszköze is lehet; ezért a platform + több token tárolása is megvalósítható (a szerver a saját push táblájában kezeli).

### `rooms`
| Mező | Típus | Leírás |
|---|---|---|
| `id` | INT PK AUTO | Szoba azonosítója |
| `name` | VARCHAR(150) | Szoba neve (group) |
| `type` | ENUM('direct','group') | Szobatípus |
| `created_by` | INT FK→users | Létrehozó |
| `pinned_message_id` | INT | Kitűzött üzenet (NULL = nincs) |
| `delete_requested_by` | INT FK→users | Ki kért törlést (NULL = nincs) |

### `room_members`
| Mező | Típus | Leírás |
|---|---|---|
| `room_id` | INT PK FK→rooms | Szoba |
| `user_id` | INT PK FK→users | Tag |
| `role` | ENUM('admin','member') | Szerepkör |
| `joined_at` | DATETIME | Csatlakozás ideje |
| `last_read_at` | DATETIME | Utolsó olvasás (unread count alapja) |
| `hidden_at` | DATETIME | Elrejtés ideje (NULL = látható) |
| `is_muted` | TINYINT(1) | Némított-e a szoba |

### `messages`
| Mező | Típus | Leírás |
|---|---|---|
| `id` | BIGINT PK AUTO | Üzenet azonosítója |
| `room_id` | INT FK→rooms | Szoba |
| `sender_id` | INT FK→users | Küldő |
| `type` | ENUM('text','image','file','link','system') | Típus |
| `content` | TEXT | Szöveg tartalom |
| `is_edited` | TINYINT(1) | Szerkesztett-e |
| `file_url` | VARCHAR(500) | Fájl/kép URL |
| `file_name` | VARCHAR(255) | Eredeti fájlnév |
| `file_size` | BIGINT | Fájlméret bájtban |
| `reply_to_id` | BIGINT | Idézett üzenet ID |
| `reply_to_content` | VARCHAR(200) | Idézett tartalom (cache) |
| `reply_to_user_name` | VARCHAR(100) | Idézett küldő neve (cache) |
| `created_at` | DATETIME(3) | Időbélyeg milliszekundum pontossággal |

> Az `INDEX idx_room_time (room_id, created_at)` index biztosítja az üzenetek hatékony lapozását.

### `message_reactions`
| Mező | Típus | Leírás |
|---|---|---|
| `id` | BIGINT PK AUTO | Azonosító |
| `message_id` | BIGINT FK→messages | Üzenet |
| `user_id` | INT FK→users | Felhasználó |
| `emoji` | VARCHAR(10) | Emoji (pl. `👍`) |
| — | UNIQUE(message_id, user_id, emoji) | Egy user egyszer reagálhat adott emojival |

### `message_deliveries`
| Mező | Típus | Leírás |
|---|---|---|
| `message_id` | BIGINT PK FK→messages | Üzenet |
| `user_id` | INT PK FK→users | Fogadó |
| `delivered_at` | DATETIME | Kézbesítés ideje (NULL = nem kézbesítve) |
| `read_at` | DATETIME | Olvasás ideje (NULL = nem olvasott) |

---

# 3. REST API

## 3.1 Alap URL és hitelesítés

```
Base URL: https://192.168.16.22:9456/tricc/api
Auth:     Authorization: Bearer <jwt_token>
```

A JWT token bejelentkezéskor kapott, `HS256` algoritmussal aláírt. A payload tartalmaz `user_id`-t és `exp` lejáratot.

Minden válasz formátuma:
```json
{ "ok": true, "data": { ... } }
// vagy hiba esetén:
{ "ok": false, "error": "Hibaüzenet" }
```

## 3.2 Auth végpontok

| Metódus | Végpont | Leírás |
|---|---|---|
| `POST` | `/auth/register` | Regisztráció meghívókóddal |
| `POST` | `/auth/login` | Bejelentkezés, JWT visszaad |
| `GET` | `/auth/me` | Saját profil lekérése |
| `PUT` | `/auth/profile` | Névmódosítás |
| `PUT` | `/auth/password` | Jelszócsere |
| `POST` | `/upload/avatar` | Profilkép feltöltés |

**Regisztráció (`POST /auth/register`):**
```json
// Kérés:
{ "name": "Kovács János", "email": "janos@example.com",
  "password": "titkosJelszo", "invite_code": "TRICC-ADMIN-0001" }

// Válasz:
{ "token": "eyJ...", "user_id": 1, "name": "Kovács János" }
```

**Bejelentkezés (`POST /auth/login`):**
```json
// Kérés:
{ "email": "janos@example.com", "password": "titkosJelszo" }

// Válasz:
{ "token": "eyJ...", "user_id": 1, "name": "Kovács János",
  "avatar_url": "https://.../avatars/1.jpg" }
```

**Jelszócsere (`PUT /auth/password`):**
```json
{ "current_password": "regiJelszo", "new_password": "ujJelszo123" }
```

## 3.3 Szobavégpontok

| Metódus | Végpont | Leírás |
|---|---|---|
| `GET` | `/rooms` | Saját szobák listája |
| `POST` | `/rooms` | Szoba létrehozása |
| `GET` | `/rooms/{id}` | Szoba részletei + tagok |
| `POST` | `/rooms/{id}/members` | Tag hozzáadása |
| `DELETE` | `/rooms/{id}/members/{uid}` | Tag eltávolítása |
| `POST` | `/rooms/{id}/read` | Olvasottnak jelölés |
| `POST` | `/rooms/{id}/hide` | Szoba elrejtése |
| `POST` | `/rooms/{id}/mute` | Némítás |
| `DELETE` | `/rooms/{id}/mute` | Némítás feloldása |
| `POST` | `/rooms/{id}/pin` | Üzenet kitűzése |
| `DELETE` | `/rooms/{id}/pin` | Kitűzés törlése |
| `POST` | `/rooms/{id}/delete-request` | Törlési kérés |
| `POST` | `/rooms/{id}/keep` | Törlési kérés visszavonása |

**Szoba létrehozása:**
```json
// Csoport:
{ "type": "group", "name": "Fejlesztők", "members": [2, 3, 4] }

// Direkt (1:1):
{ "type": "direct", "user_id": 2 }
// Válasz: { "room_id": 5 }
```

**Szoba lista válasz elemei:**
```json
{
  "id": 5, "name": "Fejlesztők", "type": "group",
  "unread_count": 3,
  "last_message": "Mikor lesz a deploy?",
  "last_message_at": "2026-06-07T18:22:10.123",
  "other_user": null,        // csak direct szobáknál
  "is_muted": false,
  "pinned_message": null
}
```

## 3.4 Üzenetvégpontok

| Metódus | Végpont | Leírás |
|---|---|---|
| `GET` | `/rooms/{id}/messages` | Üzenetek lapozva |
| `POST` | `/rooms/{id}/messages` | Üzenet küldése |
| `PUT` | `/rooms/{id}/messages/{mid}` | Üzenet szerkesztése |
| `DELETE` | `/rooms/{id}/messages/{mid}` | Üzenet törlése |
| `POST` | `/rooms/{id}/messages/{mid}/reactions` | Reakció toggle |
| `GET` | `/rooms/{id}/messages/search` | Keresés |
| `GET` | `/rooms/{id}/media` | Média galéria |

**Lapozás paraméterei:**
```
GET /rooms/5/messages?limit=50&before=12345
```
Az `before` paraméter az utolsó betöltött üzenet `id`-ja — az annál régebbieket adja vissza.

**Üzenet küldése:**
```json
// Szöveges:
{ "type": "text", "content": "Hello!" }

// Fájl:
{ "type": "file", "file_url": "https://.../files/doc.pdf",
  "file_name": "doc.pdf", "file_size": 102400 }

// Kép:
{ "type": "image", "file_url": "https://.../uploads/kep.jpg",
  "file_name": "kep.jpg", "file_size": 204800 }

// Idézett válasz:
{ "type": "text", "content": "Igen!", "reply_to_id": 100 }

// @mention:
{ "type": "text", "content": "Szia @Kiss Péter!",
  "mention_all": false, "mention_user_ids": [3] }
```

**Keresés:**
```
GET /rooms/5/messages/search?q=deploy
// Visszaad: üzenetek listája ahol a tartalom tartalmazza a keresőszót
```

## 3.5 Fájlfeltöltés

| Metódus | Végpont | Leírás |
|---|---|---|
| `POST` | `/upload` | Általános fájl feltöltése |
| `POST` | `/upload/avatar` | Profilkép feltöltése |

Mindkét végpont `multipart/form-data` formátumot vár, `file` mezőnévvel.

```
POST /upload
Authorization: Bearer <token>
Content-Type: multipart/form-data

[fájl adatok]

→ { "url": "https://.../files/abc123.pdf",
    "file_name": "dokument.pdf", "mime": "application/pdf",
    "size": 102400, "type": "file" }
```

A feltöltött fájlok szerveren maradnak; az URL az üzenetben tárolódik.

## 3.6 Push token végpontok

| Metódus | Végpont | Leírás |
|---|---|---|
| `POST` | `/push/register` | Token regisztrálása |
| `DELETE` | `/push/register` | Token törlése (kijelentkezéskor) |

```json
// Regisztráció:
{ "device_token": "abc123...", "platform": "ios" }
// vagy:
{ "device_token": "fcm_token...", "platform": "android" }
```

## 3.7 Admin végpontok

| Metódus | Végpont | Leírás |
|---|---|---|
| `GET` | `/users` | Összes felhasználó listája |
| `GET` | `/admin/invite-codes` | Meghívókódok |
| `POST` | `/admin/invite-codes` | Új meghívókód generálás |
| `PUT` | `/admin/users/{id}` | Felhasználó módosítása (tiltás, admin) |

---

# 4. WebSocket protokoll

## 4.1 Kapcsolat felépítése

```
Kliens                              Szerver
  │                                    │
  │──── WSS connect ──────────────────►│
  │                                    │
  │──── { type: "auth",               │
  │       token: "eyJ..." } ──────────►│
  │                                    │◄─ JWT ellenőrzés
  │◄─── { type: "auth_ok",            │
  │       user_id: 1 } ───────────────│
  │                                    │
  │──── { type: "join",               │
  │       room_id: 5 } ───────────────►│
  │                                    │◄─ tagság ellenőrzés
  │◄─── { type: "joined",             │
  │       room_id: 5,                  │
  │       online_user_ids: [1,3] } ───│
  │                                    │
  │◄─── { type: "presence",           │
  │       user_id: 1, online: true } ─│  ◄─ broadcast a szoba többi tagjának
```

## 4.2 Kliens → Szerver üzenetek

| Típus | Leírás | Mezők |
|---|---|---|
| `auth` | JWT hitelesítés | `token` |
| `join` | Szobához csatlakozás | `room_id` |
| `leave` | Szoba elhagyása | `room_id` |
| `typing` | Gépelés jelzés | `room_id`, `typing: bool` |
| `delivered` | Kézbesítési ACK | `message_id`, `room_id` |
| `ping` | Heartbeat | — |

## 4.3 Szerver → Kliens üzenetek

| Típus | Leírás | Főbb mezők |
|---|---|---|
| `auth_ok` | Sikeres hitelesítés | `user_id` |
| `joined` | Szobába léptünk | `room_id`, `online_user_ids` |
| `message` | Új üzenet érkezett | `room_id`, `message: {...}` |
| `typing` | Valaki gépel | `room_id`, `user_id`, `typing` |
| `presence` | Online állapotváltozás | `user_id`, `online: bool` |
| `presence_list` | Aktuális online lista | `online_user_ids: [...]` |
| `status_update` | Kézbesítési státusz frissítés | `message_id`, `user_id`, `delivered_at`, `read_at` |
| `reaction` | Reakció hozzáadva/elvéve | `message_id`, `emoji`, `user_id`, `action: add/remove` |
| `message_edited` | Üzenet szerkesztve | `room_id`, `message_id`, `content` |
| `message_deleted` | Üzenet törölve | `room_id`, `message_id` |
| `member_left` | Tag elhagyta a szobát | `room_id`, `user_id` |
| `delete_request` | Törlési kérés érkezett | `room_id`, `requested_by` |
| `user_updated` | Profilkép/név változott | `user_id`, `name`, `avatar_url` |
| `pong` | Heartbeat válasz | — |
| `error` | Hiba | `message` |

## 4.4 Üzenetküldés folyamat

```
Kliens                  REST API              WebSocket (IPC)          Többi kliens
  │                        │                        │                        │
  │─ POST /messages ───────►│                        │                        │
  │                         │─ INSERT messages ─────►DB                       │
  │                         │◄─ message_id ──────────│                        │
  │                         │─ TCP IPC üzenet ───────►│                        │
  │◄─ 200 message JSON ─────│                        │─ WS broadcast ─────────►│
  │                                                  │  {type:"message",...}   │
  │                                                  │─ push küldés ──────────►│ (ha offline)
```

**IPC (belső TCP):** A REST API és a WebSocket szerver között egy belső TCP kapcsolat fut (`127.0.0.1:9455`). Az API üzenetküldés után JSON-t küld az IPC csatornán, a WS szerver ezt továbbítja az összes csatlakozott kliensnek.

## 4.5 Heartbeat mechanizmus

```
Kliens                              Szerver
  │──── ping (30 másodpercenként) ──►│
  │◄─── pong ─────────────────────── │
  │                                   │
  │  [ha 10 mp-en belül nincs pong]   │
  │──── kapcsolat megszakítás ────────│
  │──── 5 mp után újracsatlakozás ───►│
  │                                   │
  [szerver 60 mp tétlenség után bontja]
```

---

# 5. Push értesítések

## 5.1 Mikor küld a szerver push-t?

Push értesítést kap egy felhasználó, ha:
- Nincs aktív WebSocket kapcsolata (offline)
- A szobát **nem némította** el (`is_muted = 0`)
- Kivétel: `@mention` esetén a némítás felülírható (a szerver mindig küld)

## 5.2 iOS — APNs

**Technikai részletek:**
- Protokoll: HTTP/2 + JWT token (`.p8` kulcsfájl)
- Key ID: `94HGSV4WAL`, Team ID: `K7Z734X92Z`
- Bundle ID: `com.rv42.babl42`
- Értesítés típus: `alert` (nem VoIP/PushKit)

**Push payload:**
```json
{
  "aps": {
    "alert": {
      "title": "Kovács János",
      "subtitle": "Fejlesztők",
      "body": "Mikor lesz a deploy?"
    },
    "badge": 3,
    "sound": "default"
  },
  "room_id": 5,
  "message_id": 42
}
```

**iOS push regisztráció folyamat:**
```
AppDelegate (Swift)                 PushService (Dart)          API szerver
      │                                    │                         │
      │─ APNs token kérés ──────────────►APNs                        │
      │◄─ device token ──────────────────│                           │
      │─ MethodChannel 'onToken' ─────────►│                          │
      │                                    │─ POST /push/register ───►│
      │                                    │  { token, platform:"ios"}│
      │                                    │◄─ 200 OK ───────────────│
```

## 5.3 Android — FCM

**Technikai részletek:**
- Firebase Cloud Messaging (FCM)
- `google-services.json` konfiguráció
- Package: `com.rv42.babl42`
- Library: `firebase_messaging: ^15.x`

**Android push regisztráció folyamat:**
```
FirebaseMessaging (Flutter)         PushService (Dart)          API szerver
      │                                    │                         │
      │─ requestPermission ───────────────►│                          │
      │─ getToken() ──────────────────────►│                          │
      │◄─ FCM token ──────────────────────│                           │
      │                                    │─ POST /push/register ───►│
      │                                    │  { token, platform:"android"}
      │                                    │◄─ 200 OK ───────────────│
```

## 5.4 Szerver oldali push küldés

```
API kap üzenetet
      │
      ├─ WebSocket tagok lekérése (ki online?)
      │
      └─ Offline tagok → push_tokens lekérése
            │
            ├─ platform = 'ios'  → APNs HTTP/2 küldés
            │                      (badge = olvasatlan üzenetek)
            │
            └─ platform = 'android' → FCM küldés
                                       (Firebase Server Key)
```

---

# 6. Flutter kliens architektúra

## 6.1 Könyvtárstruktúra

```
lib/
├── main.dart              ← App belépési pont, lifecycle kezelés
├── app_theme.dart         ← Light + dark téma (kBlue/kLime)
├── models/
│   ├── message.dart       ← Message, MessageDelivery, ReplyTo, MessageReaction
│   ├── room.dart          ← Room modell
│   └── user.dart          ← User modell
├── services/
│   ├── api_service.dart   ← Összes REST hívás
│   ├── auth_service.dart  ← Token, userId, profil tárolás
│   ├── ws_service.dart    ← WebSocket kapcsolat kezelés
│   ├── push_service.dart  ← APNs (iOS) + FCM (Android)
│   └── settings_service.dart ← Betűméret, dark mode, SharedPreferences
├── screens/
│   ├── login_screen.dart
│   ├── register_screen.dart
│   ├── room_list_screen.dart
│   ├── chat_screen.dart
│   ├── profile_screen.dart
│   ├── room_search_screen.dart
│   └── room_media_screen.dart
└── widgets/
    └── ws_status_bar.dart  ← WsDot, PresenceDot, avatar dialog
```

## 6.2 Service réteg

### `AuthService` (singleton)
- Tárolja: `token`, `userId`, `userName`, `avatarUrl`
- `SharedPreferences` perzisztencia
- `init()`: betöltés induláskor
- `updateProfile(name, avatarUrl)`: profil frissítése memóriában

### `ApiService` (singleton)
- Összes HTTP kérés kezelése
- `IOClient` + `badCertificateCallback` (önaláírt tanúsítvány)
- `Authorization: Bearer <token>` header automatikusan
- Hibakezelés: `ApiException(message, statusCode)`

### `WsService` (singleton)
- WebSocket kapcsolat: `wss://192.168.16.22:9456/ws`
- Állapotok: `WsState.connected / connecting / disconnected`
- Automatikus újracsatlakozás 5 másodperc után
- Ping/pong heartbeat: 30s ping, 10s timeout, 60s szerver timeout
- `events` broadcast stream: minden beérkező WS esemény
- `onlineUsers`: jelenlegi online felhasználó ID-k halmaza
- `join(roomId)` / `leave(roomId)`: szoba feliratkozás

### `PushService` (singleton)
- iOS: `MethodChannel('push_channel')` → natív AppDelegate
- Android: `FirebaseMessaging` → FCM token
- `_saveAndRegister(token, platform)`: token regisztrálása az API-n
- `setBadge(count)`: iOS badge frissítése (Android: FCM kezeli)
- `reregisterIfNeeded()`: újrabejelentkezéskor token újraküldés

### `SettingsService` (singleton, ChangeNotifier)
- `fontScale`: 0.8–1.5x szövegméret, SharedPreferences
- `themeMode`: `ThemeMode.system / light / dark`, SharedPreferences
- `setFontScale()` / `setThemeMode()`: async mentés + `notifyListeners()`

## 6.3 App belépési pont (`main.dart`)

```dart
void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  HttpOverrides.global = _DevHttpOverrides(); // önaláírt cert elfogadás
  if (Platform.isAndroid) await Firebase.initializeApp();
  await AuthService().init();
  await SettingsService().init();
  runApp(const TriccApp());
}
```

`TriccApp` (`StatefulWidget + WidgetsBindingObserver`):
- **App resume**: `_refreshProfile()` + `WsService().connect()` + badge nullázás
- **WS `user_updated`**: avatár/név frissítés valós időben
- **SettingsService listener**: téma/betűméret változáskor `setState()`

---

# 7. Képernyők

## 7.1 Bejelentkezési képernyő (`login_screen.dart`)

Email + jelszó mezők, `POST /auth/login`, token mentés, átirányítás RoomListScreen-re.

## 7.2 Regisztrációs képernyő (`register_screen.dart`)

Név, email, jelszó, meghívókód mezők, `POST /auth/register`.

## 7.3 Szoba lista képernyő (`room_list_screen.dart`)

```
Betöltés:
  GET /rooms → Room lista

Frissítés:
  WS 'message' esemény → unread count növelés + szoba lista frissítés
  WS 'presence' esemény → online dot frissítés

UI elemek:
  - Avatar (online dot: zöld karika ha online)
  - Szoba neve / utolsó üzenet előnézete
  - Olvasatlan darabszám (kék buborék)
  - Némított ikon
  - Kitűzött ikon

Műveletek (hosszú nyomás → BottomSheet):
  - Elrejtés
  - Némítás/feloldás
  - Törlési kérés
```

## 7.4 Chat képernyő (`chat_screen.dart`)

Ez a legösszetettebb képernyő. Fő komponensei:

**Üzenetlista:**
- `ListView.builder(reverse: true)` — az index 0 = legújabb üzenet = alul
- Lapozás: görgetéskor felfelé `GET /messages?before=<id>`
- `_highlightMessageId`: keresési találat kiemelése (amber, 2 másodperc)

**Üzenetbuborék (`_MessageBubble`):**
```
Saját üzenet (jobbra):      Fogadott üzenet (balra):
┌──────────────────┐         ┌──────────────────┐
│ [idézet ha van]  │         │Kovács J.          │
│ Üzenet szövege   │         │ [idézet ha van]  │
│ 18:22  ✓✓        │         │ Üzenet szövege   │
│ 👍 2  ❤️ 1       │         │ 18:22            │
└──────────────────┘         │ 👍 2             │
                             └──────────────────┘
```

**Kézbesítési ikonok:**
| Ikon | Szín | Jelentés |
|---|---|---|
| ✓ | Piros | Elküldve (szerveren) |
| ✓✓ | Sárga | Megkapta (delivered_at) |
| ✓✓ | Zöld | Elolvasta (read_at) |

**Beviteli sor:**
- Szövegmező + gomb sor
- Fájl csatolás (fájlválasztó) / kép küldés (kamera/fotótár)
- Gépelés jelzés: 1 másodperces debounce, WS `typing` üzenet
- Válasz/szerkesztés bar: törölhető `_ReplyBar` / `_EditBar`
- @mention: `@` után felugró felhasználó lista (`_MentionSuggestionBar`)
- Markdown előnézet gomb

**Keresés → ugrás üzenetre:**
```
Kereső ikonra nyomás
      │
      ▼
RoomSearchScreen megnyílik
      │
      ├─ GET /messages/search?q=...
      │
      └─ Találatra nyomás → Navigator.pop(message)
              │
              ▼
       ChatScreen fogadja a kiválasztott Message-t
              │
              ├─ GET /messages?before=msg.id+1  (batch betöltés a célüzenetig)
              │
              ├─ ListView.jumpTo(0) → alulra görget
              │
              └─ _highlightMessageId = msg.id
                  AnimatedContainer: amber kiemelés 2 másodpercig
```

## 7.5 Profil képernyő (`profile_screen.dart`)

- Profilkép (avatar): megjelenítés, feltöltés (`image_picker` → `POST /upload/avatar`)
- Névmódosítás: szövegmező + mentés (`PUT /auth/profile`)
- Betűméret beállítás: csúszka (0.8–1.5x) — `_FontSizeSection`
- Megjelenési mód: `SegmentedButton` (Rendszer / Világos / Sötét) — `_ThemeModeSection`
- Jelszócsere: dialog (`_ChangePasswordDialog`) — aktuális + új jelszó + megerősítés
- Kijelentkezés: token törlése + navigálás Login-ra

## 7.6 Keresési képernyő (`room_search_screen.dart`)

- Valós idejű keresés (0.5s debounce)
- `GET /rooms/{id}/messages/search?q=...`
- Kiemelés: egyezés sárga háttérrel (`_buildHighlighted`)
- Találatra nyomás: `Navigator.pop(context, message)` → ChatScreen megkapja

## 7.7 Média galéria (`room_media_screen.dart`)

- `GET /rooms/{id}/media` → képek + fájlok listája
- Képek rácsos elrendezésben, fájlok lista nézetben
- Kép megnyitás: `open_filex` / URL megnyitás

---

# 8. Modellek

## 8.1 `Message` modell

```dart
class Message {
  final int id;
  final int roomId;
  final int userId;
  final String userName;
  final String? avatarUrl;
  final String type;           // text, image, file, link, system
  final String content;
  final bool isEdited;
  final String? fileUrl;
  final String? fileName;
  final int? fileSize;
  final ReplyTo? replyTo;
  final DateTime createdAt;
  final MessageDelivery? delivery;
  final List<MessageReaction> reactions;
}
```

## 8.2 `Room` modell

```dart
class Room {
  final int id;
  final String name;
  final String type;            // direct / group
  final int unreadCount;
  final String? lastMessage;
  final DateTime? lastMessageAt;
  final User? otherUser;        // csak direct szobánál
  final bool isMuted;
  final Message? pinnedMessage;
  final List<User> members;
}
```

---

# 9. Témakezelés (Dark mode)

## 9.1 Megközelítés

Material 3 `ThemeData` alapú, két téma: `buildAppTheme()` (világos) és `buildDarkTheme()` (sötét). A `MaterialApp` `themeMode` paramétere `SettingsService().themeMode`-ra mutat.

## 9.2 Brandszínek

| Konstans | Érték | Használat |
|---|---|---|
| `kBlue` | `#1E5BB5` | Saját üzenet buborék, AppBar |
| `kLime` | `#7CC042` | Kiemelések, online jelzők |
| `kBlueDark` | `#1A3A6E` | Dark mód AppBar |

## 9.3 Theme-aware színhasználat

A chat képernyőn a hard-coded szürke értékeket `colorScheme` alapú hívásokra cseréltük:

| Korábbi | Új |
|---|---|
| `Color(0xFFEEEEEE)` | `colorScheme.surfaceContainerHighest` |
| `Colors.grey.shade300` | `colorScheme.outlineVariant` |
| `Colors.black54` | `colorScheme.onSurfaceVariant` |
| `Colors.black87` | `colorScheme.onSurface` |
| `Colors.grey.shade100` | `colorScheme.surfaceContainerHighest` |

## 9.4 3-fokozatú kapcsoló

```
Profil képernyő → _ThemeModeSection
      │
      └─ SegmentedButton<ThemeMode>:
             [🌙 Rendszer] [☀️ Világos] [🌑 Sötét]
                  │
                  └─ SettingsService().setThemeMode(mode)
                           │
                           └─ SharedPreferences mentés + notifyListeners()
                                    │
                                    └─ TriccApp rebuild → új themeMode
```

---

# 10. Online jelenlét (Presence)

## 10.1 Működési elv

```
Kliens A csatlakozik
      │
      ├─ WS auth → WS szerver
      │       │
      │       └─ Közös szobák tagjai kapnak:
      │           { type: "presence", user_id: A, online: true }
      │
      └─ Kliens A join(szoba) →
              { type: "presence_list", online_user_ids: [B, C] }

Kliens A lecsatlakozik
      │
      └─ WS szerver → közös szobák tagjai kapnak:
          { type: "presence", user_id: A, online: false }
```

## 10.2 UI megjelenítés

- `WsDot`: zöld/sárga/piros pötty a WS kapcsolat állapotáról (AppBar)
- `PresenceDot`: zöld karika az avatarokon ha a felhasználó online
- `WsService.onlineUsers`: Set<int> — a jelenleg online user ID-k

---

# 11. Kézbesítési státusz

## 11.1 Státuszok és triggereik

```
Üzenet elküldve (REST POST)
      │
      └─ message_deliveries sor létrehozva (delivered_at=NULL, read_at=NULL)
              │
              ├─ Fogadó ONLINE (WS):
              │       WS kliens fogadja a 'message' eseményt
              │       → Küld: { type: "delivered", message_id: X, room_id: Y }
              │       → Szerver: UPDATE delivered_at = NOW()
              │       → Szerver: broadcast { type: "status_update" } küldőnek
              │
              └─ Fogadó OFFLINE (push):
                      APNs/FCM push küldése
                      → Szerver az APNs HTTP válasz alapján: delivered_at = NOW()
                      → Broadcast: { type: "status_update" } küldőnek

Fogadó megnyitja a szobát (GET /messages)
      │
      └─ POST /rooms/{id}/read
              │
              └─ UPDATE read_at = NOW() WHERE user_id = fogadó AND message_id IN (...)
                      │
                      └─ broadcast { type: "status_update" } küldőnek
```

## 11.2 UI

- **Long press** saját üzenetre: kézbesítési részletei modal
- Lista: ki kapta, ki olvasta, mikor

---

# 12. Fájlfeltöltés folyamat

```
Felhasználó kiválaszt egy fájlt (FilePicker / ImagePicker)
      │
      ├─ Megerősítési dialog (fájlnév, méret)
      │
      ▼
POST /upload   (multipart/form-data)
      │
      ├─ Szerver menti a fájlt
      │
      └─ Visszakap: { url, file_name, mime, size, type }
              │
              ▼
      POST /rooms/{id}/messages
      { type: "file", file_url: url, file_name: ..., file_size: ... }
              │
              └─ WS broadcast → többi kliens megkapja az üzenetet
```

**MIME típus kezelés (kliens oldal):**
```dart
final mime = lookupMimeType(file.path) ?? 'application/octet-stream';
final parts = mime.split('/');
req.files.add(await http.MultipartFile.fromPath(
  'file', file.path,
  contentType: MediaType(parts[0], parts[1]),
));
```

---

# 13. iOS specifikus részletek

## 13.1 APNs integráció (natív Swift)

Az AppDelegate.swift kezeli a token regisztrációt és az értesítés fogadást:

```
iOS rendszer                AppDelegate (Swift)         Flutter (Dart)
      │                            │                         │
      │─ didRegisterForRemote ────►│                          │
      │   PushNotificationsWithDevice                         │
      │   DeviceToken              │─ MethodChannel ─────────►│
      │                            │  'push_channel'          │
      │                            │  onToken(tokenString)    │
      │                            │                         PushService._initIos()
      │                            │                              │
      │                            │◄─ invokeMethod ─────────────│
      │                            │   'refreshToken'            │
```

## 13.2 Alkalmazás ikonok

- `flutter_launcher_icons` package
- Forrás: `assets/icon.png`
- iOS: `remove_alpha_channel_ios: true` (App Store követelmény)
- Megjelenített név: `BabL42`

## 13.3 Hálózati biztonság

- Info.plist: `NSAppTransportSecurity` exception a `192.168.16.22` szerverre
- `badCertificateCallback = true` a Flutter HTTP kliensben

## 13.4 Build és kiadás

- Bundle ID: `com.rv42.babl42`
- Team: K7Z734X92Z
- Kiadás: Xcode archive → `.ipa` → Ad Hoc / App Store Connect

---

# 14. Android specifikus részletek

## 14.1 FCM integráció

- Package: `firebase_messaging: ^15.x`
- `google-services.json` az `android/app/` mappában
- `com.google.gms:google-services` Gradle plugin

**Gradle konfiguráció (`android/build.gradle.kts`):**
```kotlin
buildscript {
    repositories { google(); mavenCentral() }
    dependencies {
        classpath("com.google.gms:google-services:4.4.2")
    }
}

// Minden Android library plugin compileSdk override-ja 36-ra:
gradle.afterProject {
    val android = extensions.findByType(com.android.build.api.dsl.LibraryExtension::class)
    if (android != null && (android.compileSdk ?: 0) < 36) {
        android.compileSdk = 36
    }
}
```

> **Megjegyzés:** A `gradle.afterProject {}` hook azért szükséges, mert egyes plugin-könyvtárak (pl. `file_picker`) saját `android {}` blokkjaikban alacsonyabb `compileSdk` értéket állítanak be. A hook az összes subproject kiértékelése UTÁN fut le, ezért felül tudja írni ezeket.

## 14.2 AndroidManifest.xml főbb elemek

```xml
<uses-permission android:name="android.permission.INTERNET"/>
<uses-permission android:name="android.permission.POST_NOTIFICATIONS"/>
<uses-permission android:name="android.permission.READ_MEDIA_IMAGES"/>

<application
    android:label="BabL42"
    android:networkSecurityConfig="@xml/network_security_config">

  <!-- FCM Service -->
  <service android:name=".FlutterFirebaseMessagingService"
           android:exported="false">
    <intent-filter>
      <action android:name="com.google.firebase.MESSAGING_EVENT"/>
    </intent-filter>
  </service>
</application>
```

## 14.3 Hálózati biztonság (önaláírt tanúsítvány)

`android/app/src/main/res/xml/network_security_config.xml`:
```xml
<network-security-config>
  <domain-config cleartextTrafficPermitted="false">
    <domain includeSubdomains="true">192.168.16.22</domain>
    <trust-anchors>
      <certificates src="system"/>
      <certificates src="user"/>
    </trust-anchors>
  </domain-config>
</network-security-config>
```

## 14.4 Build

- Application ID: `com.rv42.babl42`
- compileSdk: 36, minSdk: Flutter alapértelmezett (21)
- Kiadás: `flutter build apk --release` → `.apk` fájl
- Jövőbeli: App Bundle (`flutter build appbundle`) Play Store-hoz

---

# 15. Biztonsági megfontolások

| Terület | Megvalósítás |
|---|---|
| Hitelesítés | JWT token (HS256), lejárattal |
| Jelszó tárolás | bcrypt hash |
| Regisztráció | Meghívókód szükséges |
| HTTPS | TLS mindenhol (önaláírt belső CA) |
| WebSocket | WSS (TLS) |
| Fájlfeltöltés | Szerver oldali típusellenőrzés |
| Admin jogok | Szerver oldali `is_admin` ellenőrzés minden admin végponton |
| Push tokenek | Felhasználóhoz kötve, kijelentkezéskor törlés |
| Belső hálózat | WireGuard VPN — a szerver csak VPN-en keresztül érhető el |

---

# 16. Admin panel

Webes felület (`admin/`) az alábbi funkciókkal:

- **Meghívókód kezelés:** kód generálás, listázás, lejárat beállítása
- **Felhasználó kezelés:** tiltás (`is_active = 0`), admin jog adása/visszavonása
- **Hozzáférés:** admin jogú fiókkal, `Authorization: Bearer` header

---

# 17. Dependency-k (Flutter)

| Package | Verzió | Szerepe |
|---|---|---|
| `http` | ^1.2 | REST API kérések |
| `web_socket_channel` | ^3.0 | WebSocket kapcsolat |
| `shared_preferences` | ^2.3 | Beállítások perzisztencia |
| `image_picker` | ^1.1 | Kamera / fotótár |
| `file_picker` | ^8.1 | Általános fájlválasztó |
| `cached_network_image` | ^3.4 | Avatar / kép cache |
| `path_provider` | ^2.1 | Fájlrendszer útvonalak |
| `open_filex` | ^4.5 | Letöltött fájl megnyitása |
| `url_launcher` | ^6.3 | URL megnyitása böngészőben |
| `mime` | ^2.0 | MIME típus detektálás |
| `flutter_markdown` | ^0.7 | Markdown renderelés |
| `http_parser` | ^4.0 | MIME típus HTTP fejléc |
| `firebase_core` | ^3.0 | Firebase inicializáció |
| `firebase_messaging` | ^15.0 | Android FCM push |
| `flutter_launcher_icons` | ^0.14 | App ikon generálás |

---

# 18. Verzióhistória

| Verzió | Jellemzők |
|---|---|
| 1.0.0 | Alapfunkciók: auth, szobák, üzenetek, WebSocket |
| 1.0.5 | Fájlküldés, delivery státusz |
| 1.0.8 | Emoji reakciók, üzenet szerkesztés, törlés |
| 1.0.9 | Média galéria, üzenet keresés |
| 1.0.10 | @mention, pin üzenet, szoba elrejtés |
| 1.0.11 | Fájlnév fix, push badge, admin fejlesztések |
| 1.1.0 | Dark mode, keresés→ugrás, jelszócsere, Android port, FCM push |
