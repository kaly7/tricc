# Share Extension — Design Spec
**Dátum:** 2026-06-23  
**Projekt:** BabL42 (05_tricc)  
**Verzió:** v1.3.0+91 alapján

---

## Összefoglalás

iOS Share Extension és Android Intent Filter megvalósítása, amellyel a BabL42 megjelenik a rendszer megosztás listájában. A user bármely appból (Fotók, Fájlok, böngésző stb.) tud képet, videót vagy fájlt megosztani egy BabL42 szobába, opcionális szöveges üzenettel.

---

## Architektúra

### Megközelítés: `receive_sharing_intent` package + Flutter-első

A megosztás logikája teljes egészében Flutter-ben fut. Az iOS extension csak összegyűjti a fájlokat és megnyitja a főappot — a room picker, feltöltés és üzenetküldés mind Dart kódban történik.

```
┌─────────────────────────────────────────────────────┐
│ iOS Share Extension (Swift, ~50 sor)                │
│  - NSItemProvider → fájlok → App Group container   │
│  - openURL: babl42://share → főapp megnyílik        │
└────────────────────┬────────────────────────────────┘
                     │ App Group: group.com.rv42.babl42
┌────────────────────▼────────────────────────────────┐
│ Flutter főapp                                       │
│  ShareService (receive_sharing_intent wrapper)      │
│    ↓ SharedMediaFile lista (path, type)             │
│  ShareModal (bottom sheet)                          │
│    ├─ fájl preview sor (thumbnail / ikon)           │
│    ├─ szobakereső + lista                           │
│    ├─ opcionális szöveg mező                        │
│    └─ Küldés gomb                                   │
│          ↓                                          │
│       ApiService.uploadFile() × N fájl             │
│       ApiService.sendMessage() × N                  │
│       ApiService.sendMessage() — szöveg (ha van)   │
└─────────────────────────────────────────────────────┘

Android: ACTION_SEND / ACTION_SEND_MULTIPLE intent-filter
→ receive_sharing_intent közvetlenül adja a fájl path-okat
→ ugyanaz a ShareModal jelenik meg
```

---

## Komponensek

### Új komponensek

| Komponens | Leírás |
|---|---|
| `ios/ShareExtension/` | iOS Xcode target — Swift, fájlgyűjtés + app megnyitás |
| `lib/services/share_service.dart` | receive_sharing_intent inicializálás, lifecycle kezelés |
| `lib/screens/share_modal.dart` | Megosztás UI: preview, szoba választó, szöveg, küldés |

### Meglévő kód amit újrahasznál

| Meglévő | Felhasználás |
|---|---|
| `ApiService.uploadFile()` | Fájl feltöltés az API-ra |
| `ApiService.getRooms()` | Szoba lista lekérése |
| `ApiService.sendMessage()` | Üzenet küldése szobába |
| `AuthService().isLoggedIn` | Bejelentkezés ellenőrzés |
| `navigatorKey` | Modal megjelenítés főappon belül |

---

## ShareModal UI

```
┌─────────────────────────────────┐
│ Megosztás                    ✕ │
├─────────────────────────────────┤
│ [🖼][🖼][📄]  ← preview sor   │
│  kép1.jpg  kép2.jpg  doc.pdf   │
├─────────────────────────────────┤
│ 🔍 Szoba keresése...           │
│ ────────────────────────────── │
│ 👤 Kovács Péter          online│
│ 👥 Projekt csapat              │
│ 👤 Nagy Anna                   │
├─────────────────────────────────┤
│ Üzenet (opcionális)            │
│ ┌─────────────────────────────┐ │
│ │                             │ │
│ └─────────────────────────────┘ │
├─────────────────────────────────┤
│        [ Küldés ]              │
└─────────────────────────────────┘
```

**Preview sor:**
- Képeknél: thumbnail (CachedNetworkImage-hez hasonló, de lokális fájlból)
- Videóknál: play ikon + fájlnév
- Fájloknál: típus ikon + fájlnév
- Max 10 fájl egyszerre

**Szoba lista:**
- `ApiService().getRooms()` az aktuális token-nel
- Szűrhető keresőmezővel
- Ugyanolyan vizuális stílus mint a RoomListScreen (direct/group ikon, jelenlét jelző)

**Küldési flow:**
1. Szoba kiválasztva → Küldés gomb aktív
2. Küldés gombra: spinner, gombok letiltva
3. `uploadFile()` hívások sorban (nem párhuzamosan) — minden fájlhoz
4. Minden sikeres feltöltés után `sendMessage()` a file_url-lel
5. Ha van szöveg: utolsóként egy `sendMessage()` szöveggel
6. Siker → modal bezárul, app a szoba listán marad
7. Hiba → snackbar, modal nyitva marad, újrapróbálható

---

## Lifecycle kezelés

A `ShareService` a `main.dart`-ban inicializálódik, két esetet kezel:

```dart
// App zárt volt, share nyitotta meg
ShareService().getInitialMedia() → modal felugrik

// App háttérben volt
ShareService().mediaStream → modal felugrik
```

Mindkét esetben a `navigatorKey`-en keresztül jelenik meg a modal (mint a hívás értesítő).

---

## iOS Setup

### Xcode target: `ShareExtension`
- Bundle ID: `com.rv42.babl42.ShareExtension`
- App Group: `group.com.rv42.babl42` (fő target + extension is)
- Info.plist aktivációs szabályok:
  - `public.image` (kép)
  - `public.movie` (videó)
  - `public.data` (fájl)
  - `public.file-url` (fájl URL)
- Minimum iOS: 14.0

### Swift extension kód (~50 sor)
- `SLComposeServiceViewController` helyett `NSExtensionPrincipalClass` → egyedi ViewController
- Fájlokat App Group shared container-be másolja
- `openURL` via `UIApplication` (vagy `NSUserActivity`) → `babl42://share`

---

## Android Setup

### AndroidManifest.xml intent-filterek (MainActivity-be):

```xml
<!-- Egy fájl -->
<intent-filter>
    <action android:name="android.intent.action.SEND" />
    <category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="image/*" />
    <data android:mimeType="video/*" />
    <data android:mimeType="application/*" />
</intent-filter>

<!-- Több fájl -->
<intent-filter>
    <action android:name="android.intent.action.SEND_MULTIPLE" />
    <category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="image/*" />
    <data android:mimeType="video/*" />
</intent-filter>
```

---

## Package

```yaml
receive_sharing_intent: ^1.8.0
```

iOS 13+, Android 6+ (minSdk 23 — már teljesül)

---

## Edge Case-ek

| Eset | Kezelés |
|---|---|
| Nincs bejelentkezve | "Kérlek jelentkezz be a BabL42 appban" üzenet, modal bezárul |
| Nincs hálózat feltöltéskor | Snackbar hiba, modal nyitva, újrapróbálható |
| Nagy fájl (API visszaad hibát) | Snackbar mutatja melyik fájlnál történt hiba |
| Több fájl, egyik sikertelen | Leállás az adott fájlnál, hibaüzenet |
| Share közben érkezik hívás | Hívás értesítő modal felett jelenik meg (normál prioritás) |
| Max 10 fájl felett | Első 10 kerül feldolgozásra, figyelmeztetés |

---

## Nem része a scope-nak

- Szöveg megosztása (URL, szöveg más appból) — csak fájl és kép/videó
- Az extension saját bejelentkezési flow-ja
- Push értesítés a megosztás sikeréről
- Offline queue (sikertelen feltöltések automatikus újrapróbálása)
