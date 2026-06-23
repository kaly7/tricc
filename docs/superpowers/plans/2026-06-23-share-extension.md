# Share Extension Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** BabL42 megjelenjen az iOS share sheet-en és Android megosztás listán, hogy képeket, videókat és fájlokat lehessen beküldeni szobákba más appokból.

**Architecture:** `receive_sharing_intent` package kezeli mindkét platformon a beérkező megosztást. iOS-en egy natív Share Extension gyűjti a fájlokat az App Group shared container-be, majd megnyitja a főappot. A room picker + feltöltés + üzenetküldés teljes egészében Flutter Dart kódban zajlik egy `ShareModal` bottom sheet-en keresztül.

**Tech Stack:** Flutter, `receive_sharing_intent ^1.8.0`, Swift (iOS extension), Kotlin/AndroidManifest (Android)

## Global Constraints

- Bundle ID fő app: `com.rv42.babl42`
- App Group ID: `group.com.rv42.babl42`
- URL scheme (extension → főapp): `ShareMedia-com.rv42.babl42`
- iOS deployment target: 14.0
- Android minSdk: 23 (már teljesül)
- Max fájl egyszerre: 10
- A `receive_sharing_intent` package `SharedMediaType` értékei: `image`, `video`, `file`
- `ApiService.uploadFile(File, {String? fileName})` → `Map` mezői: `file_url`, `file_name`, `file_size`, `type`
- `ApiService.sendMessage(roomId, type:, fileUrl:, fileName:, fileSize:)` — meglévő metódus
- App theme: `kBlue` (`Color(0xFF1A73E8)`), `kLime` (`Color(0xFFC6FF00)`) — `app_theme.dart`-ból

---

## Fájlstruktúra

| Fájl | Változás |
|---|---|
| `app/pubspec.yaml` | `receive_sharing_intent: ^1.8.0` hozzáadása |
| `app/ios/Runner/Info.plist` | URL scheme (`ShareMedia-com.rv42.babl42`) bejegyzés |
| `app/ios/ShareExtension/ShareViewController.swift` | **ÚJ** — Swift extension kód |
| `app/ios/ShareExtension/Info.plist` | **ÚJ** — extension konfigurációja |
| `app/android/app/src/main/AndroidManifest.xml` | Intent-filter bejegyzések |
| `app/lib/services/share_service.dart` | **ÚJ** — Dart wrapper singleton |
| `app/lib/screens/share_modal.dart` | **ÚJ** — megosztás UI |
| `app/lib/main.dart` | ShareService init + modal trigger |

---

## Task 1: Package telepítés + Android intent-filter

**Files:**
- Modify: `app/pubspec.yaml`
- Modify: `app/android/app/src/main/AndroidManifest.xml`

**Interfaces:**
- Produces: `receive_sharing_intent` package elérhető, Android-on az app megjelenik a megosztás listában

- [ ] **Step 1: Package hozzáadása**

`app/pubspec.yaml` `dependencies:` blokkba:
```yaml
  receive_sharing_intent: ^1.8.0
```

- [ ] **Step 2: Android intent-filterek hozzáadása**

`app/android/app/src/main/AndroidManifest.xml`-ben a `<activity android:name=".MainActivity"` tagen belül, a meglévő intent-filter UTÁN add hozzá:

```xml
        <!-- Share Extension: egy fájl -->
        <intent-filter>
            <action android:name="android.intent.action.SEND" />
            <category android:name="android.intent.category.DEFAULT" />
            <data android:mimeType="image/*" />
        </intent-filter>
        <intent-filter>
            <action android:name="android.intent.action.SEND" />
            <category android:name="android.intent.category.DEFAULT" />
            <data android:mimeType="video/*" />
        </intent-filter>
        <intent-filter>
            <action android:name="android.intent.action.SEND" />
            <category android:name="android.intent.category.DEFAULT" />
            <data android:mimeType="application/*" />
        </intent-filter>
        <!-- Share Extension: több fájl -->
        <intent-filter>
            <action android:name="android.intent.action.SEND_MULTIPLE" />
            <category android:name="android.intent.category.DEFAULT" />
            <data android:mimeType="image/*" />
        </intent-filter>
        <intent-filter>
            <action android:name="android.intent.action.SEND_MULTIPLE" />
            <category android:name="android.intent.category.DEFAULT" />
            <data android:mimeType="video/*" />
        </intent-filter>
```

- [ ] **Step 3: flutter pub get**

```bash
cd app && flutter pub get
```

Elvárt output: `Got dependencies!` (hibamentesen)

- [ ] **Step 4: Commit**

```bash
git add app/pubspec.yaml app/pubspec.lock app/android/app/src/main/AndroidManifest.xml
git commit -m "feat: receive_sharing_intent package + Android intent-filter"
```

---

## Task 2: iOS URL scheme regisztrálás

**Files:**
- Modify: `app/ios/Runner/Info.plist`

**Interfaces:**
- Produces: iOS tudja megnyitni az appot a `ShareMedia-com.rv42.babl42://share` URL-lel

- [ ] **Step 1: URL scheme hozzáadása az Info.plist-hez**

`app/ios/Runner/Info.plist`-ben a gyökér `<dict>` blokkba (bármelyik meglévő kulcs elé/mögé):

```xml
	<key>CFBundleURLTypes</key>
	<array>
		<dict>
			<key>CFBundleTypeRole</key>
			<string>Editor</string>
			<key>CFBundleURLSchemes</key>
			<array>
				<string>ShareMedia-com.rv42.babl42</string>
			</array>
		</dict>
	</array>
```

- [ ] **Step 2: Ellenőrzés**

```bash
grep -A6 "CFBundleURLTypes" app/ios/Runner/Info.plist
```

Elvárt output: a fenti XML blokk látszik.

- [ ] **Step 3: Commit**

```bash
git add app/ios/Runner/Info.plist
git commit -m "feat: iOS URL scheme regisztrálás share extension-höz"
```

---

## Task 3: iOS Share Extension Xcode target + Swift kód

**Files:**
- Create: `app/ios/ShareExtension/ShareViewController.swift`
- Create: `app/ios/ShareExtension/Info.plist`

**Interfaces:**
- Produces: iOS share sheet-en megjelenik a BabL42, kattintásra fájlokat átmásolja az App Group containerbe, majd megnyitja a főappot

### 3A — Xcode target létrehozása (manuális lépések)

- [ ] **Step 1: Nyisd meg az Xcode projektet**

```bash
open app/ios/Runner.xcworkspace
```

- [ ] **Step 2: Új target hozzáadása**

1. Xcode menü: **File → New → Target...**
2. Válaszd: **Share Extension**
3. Product Name: `ShareExtension`
4. Bundle Identifier: `com.rv42.babl42.ShareExtension`
5. Language: **Swift**
6. Kattints: **Finish**
7. Ha rákérdez "Activate scheme?": **Cancel** (marad a Runner scheme)

- [ ] **Step 3: App Group beállítása a fő targeten**

1. Xcode bal oldal: kattints a **Runner** targetra (nem a project, a target!)
2. **Signing & Capabilities** tab
3. **+ Capability** gomb → keresés: **App Groups**
4. **+** gomb → `group.com.rv42.babl42`
5. Pipa legyen bekapcsolva mellette

- [ ] **Step 4: App Group beállítása a ShareExtension targeten**

1. Xcode bal oldal: kattints a **ShareExtension** targetra
2. **Signing & Capabilities** tab
3. **+ Capability** → **App Groups**
4. **+** gomb → `group.com.rv42.babl42` (ugyanaz mint fent)
5. Deployment Target: **14.0**

- [ ] **Step 5: Xcode által létrehozott fájlok törlése**

Az Xcode létrehoz egy `MainInterface.storyboard`-ot és egy default `ShareViewController.swift`-et. Ezeket felülírjuk — töröld Xcode-ból a `ShareExtension` mappában lévő `MainInterface.storyboard`-ot (Move to Trash).

### 3B — Swift kód megírása

- [ ] **Step 6: ShareViewController.swift felülírása**

`app/ios/ShareExtension/ShareViewController.swift` tartalma (teljesen cseréld):

```swift
import UIKit
import UniformTypeIdentifiers

class ShareViewController: UIViewController {

    private let hostAppBundleIdentifier = "com.rv42.babl42"
    private let appGroupId = "group.com.rv42.babl42"
    private let urlScheme = "ShareMedia-com.rv42.babl42"

    private var sharedMedia: [SharedMediaFile] = []
    private var totalItems = 0
    private var processedItems = 0

    override func viewDidLoad() {
        super.viewDidLoad()
        view.isHidden = true
        handleSharedFiles()
    }

    private func handleSharedFiles() {
        guard let extensionItem = extensionContext?.inputItems.first as? NSExtensionItem,
              let attachments = extensionItem.attachments,
              !attachments.isEmpty else {
            completeWithRedirect()
            return
        }

        totalItems = attachments.count

        for provider in attachments {
            if provider.hasItemConformingToTypeIdentifier(UTType.image.identifier) {
                handleTypedItem(provider: provider, typeId: UTType.image.identifier, mediaType: .image)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.movie.identifier) {
                handleTypedItem(provider: provider, typeId: UTType.movie.identifier, mediaType: .video)
            } else if provider.hasItemConformingToTypeIdentifier(UTType.data.identifier) {
                handleTypedItem(provider: provider, typeId: UTType.data.identifier, mediaType: .file)
            } else {
                itemDone()
            }
        }
    }

    private func handleTypedItem(provider: NSItemProvider, typeId: String, mediaType: SharedMediaType) {
        provider.loadItem(forTypeIdentifier: typeId, options: nil) { [weak self] item, _ in
            guard let self = self else { return }
            if let url = item as? URL {
                let destPath = self.copyToSharedContainer(url: url)
                let media = SharedMediaFile(path: destPath, thumbnail: nil, duration: nil, type: mediaType)
                DispatchQueue.main.async { self.sharedMedia.append(media) }
            }
            self.itemDone()
        }
    }

    private func copyToSharedContainer(url: URL) -> String {
        let fm = FileManager.default
        guard let container = fm.containerURL(forSecurityApplicationGroupIdentifier: appGroupId) else {
            return url.path
        }
        // Egyedi fájlnév ütközés elkerülésére
        let dest = container.appendingPathComponent("\(UUID().uuidString)-\(url.lastPathComponent)")
        try? fm.copyItem(at: url, to: dest)
        return dest.path
    }

    private func itemDone() {
        processedItems += 1
        if processedItems == totalItems {
            DispatchQueue.main.async { self.saveAndRedirect() }
        }
    }

    private func saveAndRedirect() {
        let encoder = JSONEncoder()
        if let data = try? encoder.encode(sharedMedia),
           let json = String(data: data, encoding: .utf8) {
            UserDefaults(suiteName: appGroupId)?.set(json, forKey: hostAppBundleIdentifier)
        }
        completeWithRedirect()
    }

    private func completeWithRedirect() {
        guard let url = URL(string: "\(urlScheme)://") else {
            extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
            return
        }
        // Responder chain-en keresztül nyitjuk a főappot (extension limitáció)
        var responder: UIResponder? = self
        while responder != nil {
            if let app = responder as? UIApplication {
                app.open(url, options: [:])
                break
            }
            responder = responder?.next
        }
        extensionContext?.completeRequest(returningItems: [], completionHandler: nil)
    }
}

// MARK: - Data structs (egyeznek a receive_sharing_intent Dart oldali elvárásával)

struct SharedMediaFile: Codable {
    let path: String
    let thumbnail: String?
    let duration: Double?
    let type: SharedMediaType
}

enum SharedMediaType: String, Codable {
    case image
    case video
    case file
    case url
    case text
}
```

- [ ] **Step 7: ShareExtension Info.plist felülírása**

`app/ios/ShareExtension/Info.plist` tartalma (teljesen cseréld):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleDisplayName</key>
	<string>BabL42</string>
	<key>CFBundleExecutable</key>
	<string>$(EXECUTABLE_NAME)</string>
	<key>CFBundleIdentifier</key>
	<string>$(PRODUCT_BUNDLE_IDENTIFIER)</string>
	<key>CFBundleInfoDictionaryVersion</key>
	<string>6.0</string>
	<key>CFBundleName</key>
	<string>$(PRODUCT_NAME)</string>
	<key>CFBundlePackageType</key>
	<string>XPC!</string>
	<key>CFBundleShortVersionString</key>
	<string>1.3.0</string>
	<key>CFBundleVersion</key>
	<string>91</string>
	<key>NSExtension</key>
	<dict>
		<key>NSExtensionAttributes</key>
		<dict>
			<key>NSExtensionActivationRule</key>
			<dict>
				<key>NSExtensionActivationSupportsImageWithMaxCount</key>
				<integer>10</integer>
				<key>NSExtensionActivationSupportsMovieWithMaxCount</key>
				<integer>10</integer>
				<key>NSExtensionActivationSupportsFileWithMaxCount</key>
				<integer>10</integer>
			</dict>
		</dict>
		<key>NSExtensionPrincipalClass</key>
		<string>$(PRODUCT_MODULE_NAME).ShareViewController</string>
		<key>NSExtensionPointIdentifier</key>
		<string>com.apple.share-services</string>
	</dict>
</dict>
</plist>
```

- [ ] **Step 8: Commit**

```bash
git add app/ios/ShareExtension/
git commit -m "feat: iOS ShareExtension Swift target + konfigurálás"
```

---

## Task 4: ShareService (Dart)

**Files:**
- Create: `app/lib/services/share_service.dart`

**Interfaces:**
- Consumes: `receive_sharing_intent` package (`ReceiveSharingIntent`, `SharedMediaFile`, `SharedMediaType`)
- Produces:
  - `ShareService()` — singleton
  - `ShareService().initialize()` → `void`
  - `ShareService().initialMedia` → `Future<List<SharedMediaFile>>`
  - `ShareService().mediaStream` → `Stream<List<SharedMediaFile>>`
  - `ShareService().reset()` → `void`

- [ ] **Step 1: share_service.dart létrehozása**

`app/lib/services/share_service.dart`:

```dart
import 'package:receive_sharing_intent/receive_sharing_intent.dart';

export 'package:receive_sharing_intent/receive_sharing_intent.dart'
    show SharedMediaFile, SharedMediaType;

class ShareService {
  static final ShareService _i = ShareService._();
  factory ShareService() => _i;
  ShareService._();

  Future<List<SharedMediaFile>> getInitialMedia() =>
      ReceiveSharingIntent.instance.getInitialMedia();

  Stream<List<SharedMediaFile>> get mediaStream =>
      ReceiveSharingIntent.instance.getMediaStream();

  void reset() => ReceiveSharingIntent.instance.reset();
}
```

- [ ] **Step 2: Ellenőrzés — analyze**

```bash
cd app && flutter analyze lib/services/share_service.dart
```

Elvárt: `No issues found!`

- [ ] **Step 3: Commit**

```bash
git add app/lib/services/share_service.dart
git commit -m "feat: ShareService wrapper (receive_sharing_intent)"
```

---

## Task 5: ShareModal (Dart)

**Files:**
- Create: `app/lib/screens/share_modal.dart`

**Interfaces:**
- Consumes:
  - `ShareService` (SharedMediaFile, SharedMediaType) — Task 4-ből
  - `ApiService().getRooms()` → `Future<List<Room>>`
  - `ApiService().uploadFile(File file, {String? fileName})` → `Future<Map<String, dynamic>>` (mezők: `file_url`, `file_name`, `file_size`, `type`)
  - `ApiService().sendMessage(int roomId, {required String type, String? content, String? fileUrl, String? fileName, int? fileSize})` → `Future<Message>`
  - `AuthService().isLoggedIn` → `bool`
  - `Room.displayName(int myUserId)` → `String`
  - `Room.isDirect` → `bool`
  - `kBlue`, `kLime` — `app_theme.dart`-ból
- Produces:
  - `ShareModal({required List<SharedMediaFile> files})` — Widget
  - `ShareModal.show(BuildContext, List<SharedMediaFile>)` — static helper

- [ ] **Step 1: share_modal.dart létrehozása**

`app/lib/screens/share_modal.dart`:

```dart
import 'dart:io';
import 'package:flutter/material.dart';
import '../models/room.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/share_service.dart';
import '../app_theme.dart';

class ShareModal extends StatefulWidget {
  final List<SharedMediaFile> files;
  const ShareModal({super.key, required this.files});

  static Future<void> show(BuildContext context, List<SharedMediaFile> files) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => ShareModal(files: files),
    );
  }

  @override
  State<ShareModal> createState() => _ShareModalState();
}

class _ShareModalState extends State<ShareModal> {
  final _textCtrl = TextEditingController();
  final _searchCtrl = TextEditingController();
  List<Room> _rooms = [];
  List<Room> _filtered = [];
  Room? _selected;
  bool _loading = true;
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (!AuthService().isLoggedIn) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) Navigator.pop(context);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Kérlek jelentkezz be a BabL42 appban')),
        );
      });
      return;
    }
    _loadRooms();
    _searchCtrl.addListener(_onSearch);
  }

  @override
  void dispose() {
    _textCtrl.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadRooms() async {
    try {
      final rooms = await ApiService().getRooms();
      if (mounted) setState(() { _rooms = rooms; _filtered = rooms; _loading = false; });
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _loading = false; });
    }
  }

  void _onSearch() {
    final q = _searchCtrl.text.toLowerCase();
    setState(() {
      _filtered = q.isEmpty
          ? _rooms
          : _rooms.where((r) =>
              r.displayName(AuthService().userId ?? 0).toLowerCase().contains(q)).toList();
    });
  }

  Future<void> _send() async {
    if (_selected == null || _sending) return;
    setState(() { _sending = true; _error = null; });
    try {
      for (final f in widget.files) {
        final file = File(f.path);
        final uploadResult = await ApiService().uploadFile(file);
        final fileUrl  = uploadResult['file_url'] as String;
        final fileName = uploadResult['file_name'] as String?;
        final fileSize = uploadResult['file_size'] as int?;
        final msgType  = _resolveType(f.type);
        await ApiService().sendMessage(
          _selected!.id,
          type: msgType,
          fileUrl: fileUrl,
          fileName: fileName,
          fileSize: fileSize,
        );
      }
      final text = _textCtrl.text.trim();
      if (text.isNotEmpty) {
        await ApiService().sendMessage(_selected!.id, type: 'text', content: text);
      }
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) setState(() { _error = e.toString(); _sending = false; });
    }
  }

  String _resolveType(SharedMediaType t) {
    switch (t) {
      case SharedMediaType.image: return 'image';
      case SharedMediaType.video: return 'video';
      default: return 'file';
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    return ConstrainedBox(
      constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
      child: Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, bottomInset + 16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Fejléc
            Row(children: [
              const Text('Megosztás', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              const Spacer(),
              IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(context)),
            ]),
            const SizedBox(height: 8),

            // Fájl előnézet sor
            SizedBox(
              height: 72,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: widget.files.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (_, i) => _FileTile(file: widget.files[i]),
              ),
            ),
            const SizedBox(height: 12),

            // Szoba kereső
            TextField(
              controller: _searchCtrl,
              decoration: const InputDecoration(
                hintText: 'Szoba keresése...',
                prefixIcon: Icon(Icons.search),
                isDense: true,
              ),
            ),
            const SizedBox(height: 8),

            // Szoba lista
            if (_loading)
              const Center(child: CircularProgressIndicator())
            else if (_error != null && _rooms.isEmpty)
              Text(_error!, style: const TextStyle(color: Colors.red))
            else
              Flexible(
                child: ListView.builder(
                  shrinkWrap: true,
                  itemCount: _filtered.length,
                  itemBuilder: (_, i) {
                    final room = _filtered[i];
                    final name = room.displayName(AuthService().userId ?? 0);
                    final selected = _selected?.id == room.id;
                    return ListTile(
                      dense: true,
                      leading: CircleAvatar(
                        backgroundColor: room.isDirect ? kBlue : kLime,
                        radius: 16,
                        child: Icon(
                          room.isDirect ? Icons.person : Icons.group,
                          size: 14,
                          color: Colors.white,
                        ),
                      ),
                      title: Text(name, style: const TextStyle(fontSize: 14)),
                      selected: selected,
                      selectedTileColor: kBlue.withOpacity(0.08),
                      onTap: () => setState(() => _selected = room),
                      trailing: selected ? const Icon(Icons.check_circle, color: kBlue, size: 18) : null,
                    );
                  },
                ),
              ),
            const SizedBox(height: 8),

            // Opcionális szöveg
            TextField(
              controller: _textCtrl,
              decoration: const InputDecoration(
                hintText: 'Üzenet (opcionális)',
                isDense: true,
              ),
              maxLines: 2,
            ),
            const SizedBox(height: 12),

            // Hibaüzenet
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(_error!, style: const TextStyle(color: Colors.red, fontSize: 13)),
              ),

            // Küldés gomb
            ElevatedButton(
              onPressed: (_selected == null || _sending) ? null : _send,
              child: _sending
                  ? const SizedBox(
                      height: 20, width: 20,
                      child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                  : Text('Küldés${widget.files.length > 1 ? ' (${widget.files.length} fájl)' : ''}'),
            ),
          ],
        ),
      ),
    );
  }
}

class _FileTile extends StatelessWidget {
  final SharedMediaFile file;
  const _FileTile({required this.file});

  @override
  Widget build(BuildContext context) {
    final isImage = file.type == SharedMediaType.image;
    final isVideo = file.type == SharedMediaType.video;
    final name = file.path.split('/').last;

    return Container(
      width: 64,
      decoration: BoxDecoration(
        color: Colors.grey.shade100,
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (isImage)
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: Image.file(File(file.path),
                  width: 48, height: 48, fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => const Icon(Icons.image, size: 32)),
            )
          else
            Icon(
              isVideo ? Icons.videocam : Icons.insert_drive_file,
              size: 32,
              color: isVideo ? Colors.purple : Colors.grey,
            ),
          const SizedBox(height: 2),
          Text(
            name.length > 8 ? '${name.substring(0, 6)}..' : name,
            style: const TextStyle(fontSize: 9),
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
  }
}
```

- [ ] **Step 2: Analyze**

```bash
cd app && flutter analyze lib/screens/share_modal.dart
```

Elvárt: `No issues found!` (esetleges deprecated `withOpacity` warning elfogadható)

- [ ] **Step 3: Commit**

```bash
git add app/lib/screens/share_modal.dart
git commit -m "feat: ShareModal UI — fájl preview, szoba választó, küldés"
```

---

## Task 6: main.dart integráció

**Files:**
- Modify: `app/lib/main.dart`

**Interfaces:**
- Consumes:
  - `ShareService().getInitialMedia()` — Task 4-ből
  - `ShareService().mediaStream` — Task 4-ből
  - `ShareService().reset()` — Task 4-ből
  - `ShareModal.show(context, files)` — Task 5-ből
  - `navigatorKey` — már létező globális kulcs
- Produces: App induláskor és háttérből előtérbe jövetelekor megjelenik a ShareModal ha van beérkező share

- [ ] **Step 1: Import hozzáadása a main.dart-ba**

`app/lib/main.dart` importok közé:

```dart
import 'services/share_service.dart';
import 'screens/share_modal.dart';
```

- [ ] **Step 2: `_TriccAppState`-ben `_shareSub` StreamSubscription hozzáadása**

Az osztályváltozók közé (a `_wsSub` mellé):

```dart
  StreamSubscription? _wsSub;
  StreamSubscription? _shareSub;  // ← ADD
```

- [ ] **Step 3: initState-ben share inicializálás**

A meglévő `initState()` végéhez, közvetlenül a `_wsSub = WsService().events.listen(...)` sor UTÁN add hozzá:

```dart
    _initShareService();
```

- [ ] **Step 4: `_initShareService` metódus hozzáadása**

Az `_onSettingsChanged()` metódus ELÉ add be:

```dart
  void _initShareService() {
    // App zárt volt, share nyitotta meg
    ShareService().getInitialMedia().then((files) {
      if (files.isNotEmpty) _showShareModal(files);
    });
    // App háttérben volt
    _shareSub = ShareService().mediaStream.listen((files) {
      if (files.isNotEmpty) _showShareModal(files);
    });
  }

  void _showShareModal(List<SharedMediaFile> files) {
    ShareService().reset();
    final ctx = navigatorKey.currentContext;
    if (ctx == null) return;
    ShareModal.show(ctx, files);
  }
```

- [ ] **Step 5: dispose-ban _shareSub cleanup**

A meglévő `dispose()` metódusban a `_wsSub?.cancel();` sor mellé:

```dart
    _wsSub?.cancel();
    _shareSub?.cancel();  // ← ADD
```

- [ ] **Step 6: Analyze**

```bash
cd app && flutter analyze lib/main.dart
```

Elvárt: `No issues found!`

- [ ] **Step 7: Commit**

```bash
git add app/lib/main.dart
git commit -m "feat: ShareService + ShareModal integráció main.dart-ba"
```

---

## Task 7: iOS build + tesztelés

**Files:** Nem módosít fájlokat — build és manuális teszt

- [ ] **Step 1: iOS release build**

```bash
cd app && flutter build ios --release --no-codesign
```

Elvárt: `✓ Built build/ios/iphoneos/Runner.app` hibamentesen.

Ha `ShareExtension` build hibát jelez (`'UniformTypeIdentifiers' not found`): ellenőrizd, hogy a ShareExtension target iOS Deployment Target **14.0** (Xcode → ShareExtension target → Build Settings → iOS Deployment Target).

- [ ] **Step 2: Szimulátoros build (teszteléshez)**

```bash
flutter build ios --simulator --no-codesign
xcrun simctl install 5D44FE24-3EA4-4594-9529-09D274BD91E0 build/ios/iphonesimulator/Runner.app
xcrun simctl launch 5D44FE24-3EA4-4594-9529-09D274BD91E0 com.rv42.babl42
```

**Megjegyzés:** A Share Extension szimulátoron részlegesen tesztelhető — a Photos appból megosztás működik, de egyes fájltípusok korlátozottak. Teljes teszt fizikai eszközön ajánlott.

- [ ] **Step 3: Manuális teszt — szimulátoron**

1. Nyisd meg a **Fotók** appot a szimulátorban
2. Válassz ki egy képet
3. Nyomd meg a megosztás ikont (☐↑)
4. Elvárt: a listában megjelenik **BabL42**
5. Kattints rá → a BabL42 app megnyílik, ShareModal felugrik
6. Válassz szobát, írj üzenetet (opcionális), nyomd: **Küldés**
7. Elvárt: a fájl megjelenik a kiválasztott szobában

- [ ] **Step 4: Több fájl teszt**

1. Fotók app → válassz ki 3 képet (hosszú nyomás + kijelölés)
2. Megosztás → BabL42
3. Elvárt: ShareModal preview sorban 3 thumbnail látszik, Küldés gombon `(3 fájl)` felirat

- [ ] **Step 5: Bejelentkezés nélküli teszt**

1. Jelentkezz ki a BabL42-ből
2. Próbálj meg megosztani a Photos-ból
3. Elvárt: ShareModal felugrik, azonnal bezárul, snackbar: "Kérlek jelentkezz be a BabL42 appban"

- [ ] **Step 6: Android teszt (ha Android build be van állítva)**

```bash
flutter build apk --release
```

Eszközre telepítés után: Galéria app → kép → megosztás → BabL42 megjelenik a listában.

- [ ] **Step 7: IPA export TestFlight-ra**

```bash
xcodebuild -workspace app/ios/Runner.xcworkspace \
  -scheme Runner \
  -configuration Release \
  -archivePath ~/Desktop/IPA_export/BabL42.xcarchive \
  archive CODE_SIGN_STYLE=Automatic DEVELOPMENT_TEAM=K7Z734X92Z

xcodebuild -exportArchive \
  -archivePath ~/Desktop/IPA_export/BabL42.xcarchive \
  -exportOptionsPlist ~/Desktop/IPA_export/ExportOptions.plist \
  -exportPath ~/Desktop/IPA_export/
```

**Fontos:** Az archive előtt a pubspec.yaml verzióját növeld: `1.3.0+92`

- [ ] **Step 8: Végső commit**

```bash
git add app/pubspec.yaml  # ha verzió lett növelve
git commit -m "feat: Share Extension v1.3.0+92 — iOS + Android megosztás támogatás"
git push origin main
```
