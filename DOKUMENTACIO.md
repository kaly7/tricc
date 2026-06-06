---
title: "BabL42 — Meghívásos belső csevegő alkalmazás"
pdf_options:
  format: A4
  margin: 28mm 22mm 28mm 22mm
  printBackground: true
stylesheet: doc_style.css
---

# BabL42

> **Meghívásos belső csevegő alkalmazás** — zárt körű, valós idejű üzenetküldő iOS alkalmazás PHP/WebSocket backenddel.

---

## Áttekintés

A BabL42 egy privát, meghívásos alapú csevegő alkalmazás, amelyet zárt szervezeti vagy baráti körök belső kommunikációjára terveztek. Nem igényel külső szolgáltatókat (WhatsApp, Telegram stb.) — a teljes infrastruktúra önállóan üzemeltethető.

| Tulajdonság | Érték |
|---|---|
| Bundle ID | `com.rv42.babl42` |
| Platform | iOS (Flutter) |
| Backend | PHP 8 + Ratchet WebSocket + MySQL |
| Aktuális verzió | 1.0.11 (32) |
| Fejlesztői csapat | K7Z734X92Z |

---

# 1. Funkciók

## 1.1 Üzenetküldés

### Szöveges üzenetek
Egyszerű szöveg írható és küldhető. Az üzenetek valós időben jelennek meg minden csatlakozott kliensnél WebSocket kapcsolaton keresztül.

### Képek és fájlok küldése
- **Képek:** kamera vagy fotótár — előnézet, letöltés és megosztás lehetséges; a szoba lista előnézetben a fájlnév jelenik meg
- **Fájlok:** tetszőleges fájltípus — a buborékban megjelenik az eredeti fájlnév, a méret (B / KB / MB) és a kiterjesztéstől függő típusikon (PDF, Word, Excel, PowerPoint, archív, hang, videó, szöveg); a szoba lista előnézetben is az eredeti fájlnév látható
- Küldés előtt megerősítő dialog jelenik meg a fájlnévvel és mérettel

### Linkek
Az alkalmazás automatikusan felismeri az URL-eket. Linkre koppintás előtt megerősítő dialog kérdez rá a megnyitásra, hogy véletlenszerű megnyitást elkerüljük.

### Markdown támogatás
A szöveges üzenetek alapvető Markdown formázást támogatnak (`**félkövér**`, `_dőlt_`, `` `kód` ``, listák).

## 1.2 Üzenet interakciók

### Emoji reakciók
Hat emoji érhető el reakcióként: 👍 ❤️ 😂 😮 😢 🔥  
Hosszú nyomásra megnyíló kontextusmenüből választható. A reakció száma megjelenik a buborékon, saját reakció kiemelve látszik.

### Válasz / idézet
Hosszú nyomás az üzeneten → **Válasz** menüpont. Az idézett üzenet buborékon belül jelenik meg, halvány háttérrel.

### Üzenet szerkesztése
Saját szöveges vagy link üzeneten hosszú nyomás → **Szerkesztés**. Szerkesztés közben narancssárga sáv jelzi az aktív módot. A szerkesztés valós időben frissül minden kliensnél, és `· szerk.` jelzés kerül az időbélyeg mellé.

### Üzenet törlése
Saját üzeneten hosszú nyomás → **Törlés** → megerősítés után az üzenet eltűnik mindenkinél.

### Kézbesítési állapot
Saját üzenetek mellett kézbesítési ikon jelenik meg:
- `✓` — elküldve
- `✓✓` — kézbesítve (legalább egy résztvevőhöz megérkezett)
- `✓✓` kék — olvasva

Hosszú nyomás → **Kézbesítés részletei** — ki és mikor kapta meg / olvasta el. A listában minden taghoz avatar jelenik meg a jelenlét-karikával (zöld = online, szürke = offline). Az időpontok egységesen Budapest-időzónában (UTC+2) jelennek meg.

### Kiemelés (pin)
Adminisztrátorok kiemelhetnek egy üzenetet. A kiemelt üzenet a chat tetején megjelenik egy sárga sávban.

## 1.3 Szobák

### Direkt üzenetek
Két felhasználó közötti privát csevegő szoba. Automatikusan jön létre, ha már létezik direkt szoba ugyanazzal a személlyel.

### Csoportos szobák
Több tagból álló szoba névvel. Az admin tagokat adhat hozzá és távolíthat el, üzeneteket emelhet ki.

### Szoba lista
- Utolsó üzenet és időbélyeg (fájlküldésnél a fájl neve jelenik meg)
- Olvasatlan üzenetek piros badge-dzsel
- Hosszú nyomásra: értesítések némítása / visszakapcsolása

### Szoba törlése
- **Csak nálam:** eltűnik a listából, a másik félnél megmarad
- **Mindenkinél:** értesítés megy a másiknak, aki dönthet megtartásról vagy törlésről

## 1.4 Online állapot jelzése

| Hol látható | Mit mutat |
|---|---|
| AppBar (minden képernyőn) | Pulzáló pötty: 🟢 csatlakozva, 🟡 csatlakozás alatt, 🔴 nincs kapcsolat |
| Direkt szoba neve mellé | 🟢 / ⚫ pötty — a partner online állapota |
| Direkt szoba avatarjának karikája | Zöld vagy szürke szegély + árnyék |
| Csoport szoba neve mellé | 👥 tagszám — koppintásra tagok listája online állapottal |
| Chat info panel (ⓘ gomb) | Minden tag mellett avatar (jelenlét-karikával), Online / Offline felirat és pötty |
| Üzenet avatarjának karikája | Zöld (online) vagy szürke (offline) keret |

Az online állapot valós időben frissül. A pontosság érdekében a kliens 30 másodpercenként ping üzenetet küld a szervernek — ha a kapcsolat "csendben" megszakad (pl. iOS háttérben levágja), a szerver ezt 60 másodpercen belül észleli és offline-nak jelöli a felhasználót.

## 1.5 Push értesítések (APNs)

- Token alapú APNs autentikáció (`.p8` kulcs, ES256/JWT)
- Ékezetes karakterek helyesen jelennek meg (Unicode escape kódolással)
- Koppintásra az értesítésen → közvetlenül az adott szobába ugrik
- Némított szobákból nem érkezik push
- App ikon badge száma = összes olvasatlan üzenet

## 1.6 Profil és beállítások

- Profilkép feltöltése (kamera vagy fotótár)
- Felhasználónév és email szerkesztése
- **Betűméret beállítás:** + / − gombokkal, élő előnézettel
- Kijelentkezés

---

# 2. Kezelési útmutató

## 2.1 Belépés

Az alkalmazás meghívásos — fiókot csak az adminisztrátor tud létrehozni. Belépés email cím és jelszóval történik.

## 2.2 Új beszélgetés indítása

1. Jobb alsó sarokban lévő **ceruza ikon** → új szoba
2. Válaszd: **Direkt üzenet** (egy személynek) vagy **Csoport**
3. Csoportnál adj meg egy nevet, és jelöld ki a tagokat
4. **Létrehozás** gomb

## 2.3 Üzenet küldése

- Szöveg: beírás → küldés gomb
- Kép: 📎 ikon → Kamera vagy Fotótár
- Fájl: 📎 ikon → Fájl
- Válasz: hosszú nyomás → Válasz
- Reakció / szerkesztés / törlés: hosszú nyomás az üzeneten

## 2.4 Tagok kezelése csoportban

ⓘ gomb az AppBar-ban → **Tag hozzáadása** vagy tagra koppintva: direkt üzenet indítása.

## 2.5 Online állapot megtekintése csoportban

A szoba neve melletti **👥 N** jelzőre koppintva megnyílik a tagok listája az online állapotukkal. Az online tagok felül jelennek meg.

---

# 3. Technikai megvalósítás

## 3.1 Architektúra áttekintés

```
┌─────────────────────────────────────────┐
│           iOS kliens (Flutter)          │
│                                         │
│  Screens → Services → Models           │
│     │          │                        │
│  WsService  ApiService                  │
└──────────────────┬──────────────────────┘
                   │ HTTPS + WSS
                   │ (önaláírt tanúsítvány,
                   │  Dart SSL bypass)
┌──────────────────┴──────────────────────┐
│         Backend szerver                 │
│                                         │
│  PHP 8 REST API  +  Ratchet WS szerver  │
│              │                          │
│           MySQL adatbázis               │
└─────────────────────────────────────────┘
```

**Szerver:** `192.168.16.22:9456` (LAN, HTTPS + WSS)

## 3.2 Flutter alkalmazás struktúra

```
app/lib/
├── main.dart
├── app_theme.dart              # Színek, téma
├── models/
│   ├── message.dart            # Üzenet, kézbesítés, reakció modellek
│   ├── room.dart               # Szoba modell, helper getterek
│   └── user.dart               # Felhasználó modell
├── services/
│   ├── api_service.dart        # REST API hívások (IOClient, SSL bypass)
│   ├── auth_service.dart       # Token kezelés (SharedPreferences)
│   ├── ws_service.dart         # WebSocket singleton + jelenlét
│   └── push_service.dart       # APNs regisztráció és kezelés
├── screens/
│   ├── login_screen.dart
│   ├── room_list_screen.dart   # Szoba lista + tagok modal
│   ├── chat_screen.dart        # Chat + info panel + összes interakció
│   └── profile_screen.dart     # Profil szerkesztés, betűméret
└── widgets/
    └── ws_status_bar.dart      # WsDot (AppBar pötty) + PresenceDot
```

## 3.3 WebSocket szolgáltatás (`WsService`)

Singleton osztály, amely az alkalmazás teljes életciklusa alatt kezeli a kapcsolatot.

```dart
enum WsState { connected, connecting, disconnected }

class WsService {
  final Set<int> _joinedRooms = {};   // reconnect után újracsatlakozás
  final Set<int> _onlineUsers = {};   // jelenlét nyilvántartás
  final _stateController = StreamController<WsState>.broadcast();
}
```

**Kapcsolat életciklus:**
1. `connect()` → `auth` üzenet küldése tokennel
2. `auth_ok` esemény → állapot: `connected`, összes szoba újra-`join`
3. Kapcsolat szakadás → 5 mp után automatikus újracsatlakozás
4. Újracsatlakozáskor minden `_joinedRooms` szobába újra belép

**Kezelt WS eseménytípusok:**

| Esemény | Leírás |
|---|---|
| `auth_ok` | Sikeres autentikáció |
| `message` | Új üzenet érkezett |
| `message_edited` | Üzenet szerkesztve (valós idejű frissítés) |
| `message_deleted` | Üzenet törölve |
| `status_update` | Kézbesítési állapot változás |
| `reaction` | Emoji reakció frissítés |
| `presence` | Egy felhasználó be/kilépett |
| `presence_list` | Szobában lévő online felhasználók listája |
| `member_left` | Tag elhagyta a szobát |
| `delete_request` | Szoba törlési kérelem |

## 3.4 Online jelenlét pipeline

```
Kliens csatlakozik
      │
      ▼
Szerver → presence { user_id, online: true } → minden szoba
      │
      ▼
WsService._onlineUsers.add(user_id)
      │
      ▼
_stateController (WsDot frissül)
WS events stream (RoomList + ChatScreen setState)
      │
      ▼
PresenceDot / avatar keret szín frissül
```

Szobába lépéskor a szerver `presence_list` eseményt küld az éppen online tagok ID listájával.

## 3.5 Ping/pong heartbeat

Az online jelenlét pontosítása érdekében a kliens és szerver rendszeres "életjel" cserét folytat:

```
Kliens                          Szerver
  │                               │
  │── ping (30 mp-enként) ───────▶│
  │◀─ pong ────────────────────── │
  │                               │
  │  [ha 10 mp-en belül nem jön pong]
  │── kapcsolat bontva ───────────▶ onClose() → offline
  │── 5 mp múlva reconnect ───────▶ onOpen() → auth → online
```

**Miért szükséges:** iOS agresszívan bezárja a háttér WebSocket kapcsolatokat, de nem mindig küld TCP FIN csomagot. Enélkül a szerver nem értesülne a kilépésről — a felhasználó "szellemként" online maradna órákon át.

**Időzítések:**

| Esemény | Időzítés |
|---|---|
| Kliens ping küldése | 30 másodpercenként |
| Pong várakozási idő | 10 másodperc |
| Szerver idle timeout | 60 másodperc ping nélkül |
| Automatikus reconnect | 5 másodperccel a bontás után |

## 3.6 Push értesítések (APNs)

**Autentikáció:** Token alapú (`.p8` privát kulcs, ES256 algoritmus, JWT)

**Fontos tanulság — ékezetek:** Az iOS APNs rendszer a `JSON_UNESCAPED_UNICODE` PHP flag esetén helytelen byte szekvenciát kaphat. A megoldás: standard JSON kódolás (`\uXXXX` escape), amelyet az iOS JSON parser helyesen dekódol.

```php
// HELYTELEN:
json_encode($payload, JSON_UNESCAPED_UNICODE);

// HELYES:
json_encode($payload);  // \uXXXX escape → iOS helyesen jeleníti meg
```

**Token regisztráció Flow (iOS):**
```
AppDelegate.didRegisterForRemoteNotifications
      │
      ▼ (SceneDelegate alapú app: FlutterImplicitEngineDelegate kell)
pendingToken buffer → Flutter engine kész
      │
      ▼
MethodChannel → PushService.dart → API /push-token
```

**Entitlement:** `aps-environment = production` szükséges a `Runner.entitlements`-ben.

## 3.7 SSL kezelés (önaláírt tanúsítvány)

```dart
final client = IOClient(
  HttpClient()..badCertificateCallback = (cert, host, port) => true,
);
```

Ez kizárólag fejlesztési/belső használatra alkalmas megoldás. Éles, nyilvános alkalmazásban érvényes tanúsítvány szükséges.

## 3.8 Üzenet buborék

A buborék maximális szélessége a képernyőhöz igazodik:

```dart
// Szöveg/link:
BoxConstraints(maxWidth: MediaQuery.of(context).size.width * 0.75)

// Kép:
width: MediaQuery.of(context).size.width * 0.65
```

## 3.9 Build és kiadás folyamat

```bash
# 1. Flutter fordítás (aláírás nélkül)
flutter build ios --release --no-codesign

# 2. Xcode archív létrehozása
xcodebuild \
  -workspace ios/Runner.xcworkspace \
  -scheme Runner \
  -configuration Release \
  -archivePath ~/Library/Developer/Xcode/Archives/BabL42_vN.xcarchive \
  archive \
  DEVELOPMENT_TEAM="K7Z734X92Z" \
  -allowProvisioningUpdates

# 3. Feltöltés TestFlight-ra
# Xcode → Window → Organizer → Distribute App → App Store Connect → Upload
```

> **Fontos:** a `flutter build` **megelőzi** az `xcodebuild archive` lépést. Ha fordítva végzik, az archív az előző build kódját tartalmazza.

---

# 4. Verziónapló

| Verzió | Build | Változások |
|---|---|---|
| 1.0.0 | 1–10 | Alapfunkciók: auth, szoba lista, chat, WS |
| 1.0.1 | 11–14 | Képküldés, fájlküldés, markdown |
| 1.0.2 | 15–17 | APNs push értesítések, badge, mélylinkek |
| 1.0.3 | 18–20 | Emoji reakciók, válasz/idézet, kézbesítési állapot, részletek modal |
| 1.0.4 | 21–22 | Üzenet szerkesztés és törlés, WS reconnect javítás, online jelenlét (pötty + avatar keret) |
| 1.0.5 | 23–25 | Presence pötty a nevek mellé, csoportos jelenlét modal, chat info panel online állapottal |
| 1.0.6 | 26 | Üzenet buborék szélesség képernyőarányos |
| 1.0.7 | 27 | Avatar karika hangsúlyosabb, statikus AppBar pötty, avatar dialog bárhol, saját üzenet olvasatlan bug fix |
| 1.0.8 | 29 | Ping/pong heartbeat — pontos online/offline érzékelés |
| 1.0.9 | 30 | Avatar + presence karika a kézbesítési részleteknél és tagok modalnál; fájltípus ikon üzenetbuborékban; időzóna egységesítés (szerver); szobalistán fájlnév megjelenítés (szerver) |
| 1.0.10 | 31 | Chat info panel (ⓘ) taglistájánál avatar + jelenlét-karika |
| 1.0.11 | 32 | Fájlnév helyes megjelenítése küldőnél és fogadónál egyaránt (buborék + szoba lista előnézet) |

---

*Dokumentáció generálva: 2026. június · BabL42 v1.0.11*
