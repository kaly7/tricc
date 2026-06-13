---
title: "BabL42 — Meghívásos belső csevegő alkalmazás"
pdf_options:
  format: A4
  margin: 28mm 22mm 28mm 22mm
  printBackground: true
stylesheet: doc_style.css
---

<div style="page-break-after: always; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 90vh; text-align: center;">
  <img src="app/assets/logo.png" style="width: 160px; height: 160px; border-radius: 32px; margin-bottom: 40px;" />
  <h1 style="font-size: 2.6em; margin: 0 0 12px 0; color: #0d1b3e;">BabL42</h1>
  <p style="font-size: 1.2em; color: #4a5568; margin: 0 0 40px 0;">Meghívásos belső csevegő alkalmazás</p>
  <hr style="width: 60px; border: 2px solid #7ec81b; margin: 0 0 40px 0;" />
  <p style="color: #718096; font-size: 0.95em; margin: 0;">iOS · Android · Flutter · PHP 8 · WebSocket · LiveKit</p>
  <p style="color: #a0aec0; font-size: 0.85em; margin: 8px 0 0 0;">v1.2.0 · 2026. június</p>
</div>

# BabL42

> **Meghívásos belső csevegő és hanghívó alkalmazás** — zárt körű, valós idejű üzenetküldő iOS és Android alkalmazás PHP/WebSocket backenddel és LiveKit SFU csoportos hanghívással.

---

## Áttekintés

A BabL42 egy privát, meghívásos alapú csevegő és hanghívó alkalmazás, amelyet zárt szervezeti vagy baráti körök belső kommunikációjára terveztek. Nem igényel külső szolgáltatókat (WhatsApp, Telegram stb.) — a teljes infrastruktúra önállóan üzemeltethető.

| Tulajdonság | Érték |
|---|---|
| Bundle ID | `com.rv42.babl42` |
| Platform | iOS · Android (Flutter) |
| Backend | PHP 8 + Ratchet WebSocket + MySQL + LiveKit SFU |
| Aktuális verzió | 1.2.0 (+90) |
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

## 1.5 Push értesítések (APNs / FCM)

- Token alapú APNs autentikáció (`.p8` kulcs, ES256/JWT) iOS-en
- Firebase Cloud Messaging (FCM) Android-on
- Ékezetes karakterek helyesen jelennek meg
- Koppintásra az értesítésen → közvetlenül az adott szobába ugrik
- Némított szobákból nem érkezik push
- App ikon badge száma = összes olvasatlan üzenet

## 1.6 Profil és beállítások

- Profilkép feltöltése (kamera vagy fotótár)
- Felhasználónév szerkesztése
- **Jelszócsere:** aktuális jelszó megadása után új jelszó beállítható
- **Betűméret beállítás:** + / − gombokkal, élő előnézettel
- **Megjelenési mód:** Rendszer / Világos / Sötét (dark mode)
- **Névjegy (About):** alkalmazásverzió és build szám, copyright
- Kijelentkezés

## 1.7 Keresés

Az AppBar-ban lévő 🔍 ikonra koppintva kereshetünk a szoba üzenetei között. A találatra koppintva az alkalmazás automatikusan az adott üzenetre ugrik, és sárga kiemeléssel jelöli meg 2 másodpercre.

## 1.8 Android támogatás

A BabL42 az iOS mellett Android rendszeren is elérhető. Az Android változat azonos funkcionalitást nyújt; push értesítéseket Firebase Cloud Messaging (FCM) segítségével kap. Minimum Android verzió: **7.0 (API 24)**.

## 1.9 Videó üzenetek

- Videó fájlok küldése a csatolás ikonon keresztül (Videó menüpont)
- A chat buborékban bélyegkép jelenik meg lejátszás gombbal
- Koppintásra teljes képernyős lejátszó nyílik (`VideoPlayerScreen`)
- Hosszú lenyomás → Lejátszás / Letöltés
- Maximális fájlméret: 100 MB
- Streamelés HTTPS-en keresztül (Let's Encrypt TLS tanúsítvány)

## 1.10 1:1 Hanghívás

- Zöld telefon ikon a direkt szoba fejlécén
- Valós idejű WebRTC alapú hang kapcsolat
- Hívás indítása, fogadása és elutasítása
- Hangszóró toggle hívás közben
- Push értesítés (APNs/FCM) beérkező hívásról

## 1.11 Csoportos hanghívás

A csoportos szobákban LiveKit SFU alapú konferenciadíjhívás érhető el, egyidejűleg kb. 10 résztvevőig.

### Hívás indítása és csatlakozás

- **Fejhallgató ikon** a csoport szoba fejlécén indít vagy csatlakoztat híváshoz
- Ha egy csoportban már folyamatban van hívás, az ikon **zöldre** vált → koppintásra csatlakozás (nem új hívás)
- Más szobában aktív hívás esetén a gomb szürke és letiltott
- Ha én vagyok a hívásban → piros leállítás ikon jelenik meg a fejlécen
- **Csatlakozási értesítés:** ha valaki hívást kezdeményez, egy SnackBar jelenik meg az app alján „Csatlakozás" gombbal

### Hívás képernyő (GroupCallScreen)

```
┌─────────────────────────────────┐
│  ← Csoport neve (← = minimalizál)│
│                                 │
│  [Avatar]  [Avatar]  [Avatar]   │
│  Kovács J. Kiss P.   Nagy A.    │
│  🎤 aktív  🔇 némítva  🎤 aktív │
│                                 │
│  [🎤 Mic] [🔈 Hangkimenet] [☎ Kilép] │
└─────────────────────────────────┘
```

- Résztvevők kör avatarral és névvel
- Mikrofon státusz jelzése (aktív / némított)
- **Mikrofon gomb:** saját mikrofon némítása/visszakapcsolása
- **Hangkimenet gomb:** bottom sheet megnyílik → hangeszköz választó
- **Kilépés gomb:** elhagyja a hívást

### Pip bar (háttérsáv)

Hívás közben a vissza gomb **nem szünteti meg** a hívást — csak minimalizálja. Az app alján egy **sáv (pip bar)** jelenik meg:

```
[🎧] Fejlesztők  ·  3 résztvevő   [🎤] [✕]
```

- Koppintásra visszaugrik a hívás képernyőre
- Mikrofon toggle a sávból is működik
- Kilépés gomb a sávból is működik
- Minden képernyőn látható, amíg hívás folyamatban van

### Hangkimenet választó

Bottom sheet a hangkimenet váltásához:

| Opció | Ikon | Leírás |
|---|---|---|
| Fülhallgató | 👂 | Belső hangszóró, telefon mellé tartva |
| Kihangosítás | 🔊 | Beépített hangszóró, asztalra téve |
| Bluetooth | 🎧 | Külső BT eszköz (headset, fülhallgató) |

Ha nincs Bluetooth eszköz párosítva, az opció szürke és letiltott.

### Közelségérzékelő

Hívás közben, ha a telefont archoz tartják:
- **iOS:** `UIDevice.isProximityMonitoringEnabled` → a kijelző automatikusan elsötétül
- **Android:** `PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK` → ugyanaz az eredmény
- Az arcot elvéve a kijelző újra bekapcsol

---

# 2. Kezelési útmutató

## 2.1 Belépés

Az alkalmazás meghívásos — fiókot csak az adminisztrátor tud létrehozni. Belépés email cím és jelszóval történik.

## 2.2 Új beszélgetés indítása

1. Jobb alsó sarokban lévő **ceruza ikon** → új szoba
2. Válaszd: **Direkt üzenet** (egy személynek) vagy **Csoport**
3. Csoportnál adj meg egy nevet, és jelöld ki a tagokat
   - A kiválasztott tagok chip-sorban jelennek meg (X-szel eltávolíthatók)
   - A tagválasztó lista görgethető, ha sokan vannak
4. **Létrehozás** gomb

## 2.3 Üzenet küldése

- Szöveg: beírás → küldés gomb
- Kép: 📎 ikon → Kamera vagy Fotótár
- Videó: 📎 ikon → Videó
- Fájl: 📎 ikon → Fájl
- Válasz: hosszú nyomás → Válasz
- Reakció / szerkesztés / törlés: hosszú nyomás az üzeneten
- Hosszú lenyomáskor az üzenetbuborék vizuálisan visszaugrik és a telefon rezeg (haptikus visszajelzés)

## 2.4 Tagok kezelése csoportban

ⓘ gomb az AppBar-ban → **Tag hozzáadása** vagy tagra koppintva: direkt üzenet indítása.

## 2.5 Online állapot megtekintése csoportban

A szoba neve melletti **👥 N** jelzőre koppintva megnyílik a tagok listája az online állapotukkal. Az online tagok felül jelennek meg.

## 2.6 Csoportos hanghívás használata

1. Nyiss meg egy **csoport szobát**
2. Koppints a **fejhallgató ikonra** (AppBar jobb oldal)
   - Ha a szobában már van hívás → csatlakozás kérdés
   - Ha nincs hívás → te indítod el
3. A **hívás képernyőn** látod a résztvevőket és a mikrofon állapotát
4. **Hangkimenet** módosítása: koppints a hangszóró ikonra → válassz az opciók közül
5. **Minimalizálás:** vissza gomb → a pip bar megjelenik az app alján
6. **Visszatérés híváshoz:** koppints a pip barra
7. **Kilépés a hívásból:** koppints a piros ☎ gombra a hívás képernyőn, vagy az ✕ gombra a pip bárban

---

# 3. Technikai megvalósítás

## 3.1 Architektúra áttekintés

```
┌─────────────────────────────────────────────────┐
│           iOS / Android kliens (Flutter)         │
│                                                  │
│  Screens → Services → Models                    │
│     │           │                                │
│  WsService  ApiService   GroupCallService        │
└──────────────────┬──────────────────────────────┘
                   │ HTTPS + WSS
                   │ (Let's Encrypt TLS — babl.rv42.hu)
┌──────────────────┴──────────────────────────────┐
│         Backend szerver (babl.rv42.hu)           │
│                                                  │
│  PHP 8 REST API  +  Ratchet WS szerver           │
│              │                                   │
│           MySQL adatbázis                        │
│                                                  │
│  LiveKit SFU  (wss://babl.rv42.hu:7880)         │
│  coturn TURN relay                               │
└──────────────────────────────────────────────────┘
```

**Szerver:** `babl.rv42.hu:9456` (HTTPS + WSS, Let's Encrypt)

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
│   ├── api_service.dart        # REST API hívások
│   ├── auth_service.dart       # Token kezelés (SharedPreferences)
│   ├── ws_service.dart         # WebSocket singleton + jelenlét
│   ├── push_service.dart       # APNs (iOS) + FCM (Android)
│   ├── settings_service.dart   # Betűméret, dark mode
│   ├── call_service.dart       # 1:1 WebRTC hívás kezelés
│   └── group_call_service.dart # Csoportos LiveKit hívás kezelés
├── screens/
│   ├── login_screen.dart
│   ├── room_list_screen.dart   # Szoba lista + tagok modal
│   ├── chat_screen.dart        # Chat + info panel + összes interakció
│   ├── profile_screen.dart     # Profil szerkesztés, betűméret
│   ├── room_search_screen.dart # Üzenetkeresés
│   ├── room_media_screen.dart  # Média galéria
│   ├── video_player_screen.dart# Teljes képernyős videólejátszó
│   └── group_call_screen.dart  # Csoportos hívás képernyő
└── widgets/
    ├── ws_status_bar.dart      # WsDot (AppBar pötty) + PresenceDot
    └── group_call_bar.dart     # Pip bar (hívás közben mindig látható)
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
| `call_started` | Valaki csoportos hívást kezdeményezett |
| `call_ended` | A csoportos hívás véget ért |

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

## 3.6 Push értesítések

**iOS (APNs):** Token alapú autentikáció (`.p8` privát kulcs, ES256 algoritmus, JWT)

**Android (FCM):** Firebase Cloud Messaging, `google-services.json` konfiguráció

## 3.7 SSL / TLS

A szerver `babl.rv42.hu` domainre Let's Encrypt tanúsítvánnyal rendelkezik. A kliens standard HTTPS/WSS kapcsolattal csatlakozik; nincs szükség tanúsítvány bypass-ra.

## 3.8 Build és kiadás folyamat

```bash
# iOS build (Ad Hoc / TestFlight)
flutter build ios --release --no-codesign
xcodebuild -workspace ios/Runner.xcworkspace \
  -scheme Runner -configuration Release \
  -archivePath ~/Library/Developer/Xcode/Archives/BabL42_vN.xcarchive \
  archive DEVELOPMENT_TEAM="K7Z734X92Z" -allowProvisioningUpdates

# Android build
flutter build apk --release
```

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
| 1.0.9 | 30 | Avatar + presence karika a kézbesítési részleteknél és tagok modalnál; fájltípus ikon üzenetbuborékban; időzóna egységesítés (szerver); szobalistán fájlnév megjelenítés |
| 1.0.10 | 31 | Chat info panel (ⓘ) taglistájánál avatar + jelenlét-karika |
| 1.0.11 | 32 | Fájlnév helyes megjelenítése küldőnél és fogadónál egyaránt |
| 1.1.0 | 33–39 | Dark mode (rendszer/világos/sötét), üzenetkeresés + ugrás találatra, jelszócsere, profilkép multi-device szinkron, Android port (FCM push), haptikus visszajelzés + scale animáció hosszú lenyomásra |
| 1.1.1 | 40–50 | Média galéria, @mention, kitűzött üzenet, szoba elrejtés, fájl letöltés hosszú nyomás menüből, About modal |
| 1.1.2 | 51–70 | 1:1 WebRTC hanghívás (CallService), VoIP push (APNs PushKit), hívás képernyő |
| 1.2.0 | 71–90 | Videó üzenetek (küldés + inline lejátszó + VideoPlayerScreen), Let's Encrypt TLS, csoportos hanghívás (LiveKit SFU), pip bar, call_started/call_ended WS események, hangkimenet választó (fülhallgató/kihangosítás/BT), közelségérzékelő (iOS + Android platform channel), admin aktív hívások panel, csoport létrehozás chip sor + görgetés |

---

*Dokumentáció generálva: 2026. június · BabL42 v1.2.0*
