# Message Delivery Status — Design Spec
**Dátum:** 2026-06-05  
**Projekt:** BabL42 (05_tricc)

---

## Összefoglalás

Saját üzeneteinknél kis színes pöttyök jelzik a kézbesítési státuszt:

| Szín | Jelentés | Feltétel |
|------|----------|----------|
| 🔴 Piros | Elküldve | Üzenet DB-be mentve (REST OK) |
| 🟡 Sárga | Odaért | APNs HTTP 200 visszaigazolás VAGY WS delivery |
| 🟢 Zöld | Elolvasva | Fogadó megnyitotta a szobát |

- **Direct szoba:** 1 pötty (1 fogadó)
- **Csoport szoba:** N-1 pötty (minden tag külön)

---

## Adatbázis

Új tábla a szerveren:

```sql
CREATE TABLE message_deliveries (
  message_id   INT      NOT NULL,
  user_id      INT      NOT NULL,          -- a fogadó
  delivered_at DATETIME NULL,
  read_at      DATETIME NULL,
  PRIMARY KEY (message_id, user_id),
  FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
);
```

---

## Szerver oldali logika

### Üzenet küldésekor (`POST /rooms/{id}/messages`)
1. Üzenet mentése DB-be (meglévő)
2. INSERT INTO message_deliveries minden tagnak (sender kivételével), `delivered_at=NULL, read_at=NULL`
3. APNs küldés (meglévő) — ha HTTP 200 → UPDATE delivered_at=NOW()
4. WS broadcast (meglévő)

### WS: `delivered` ACK fogadása
Az app WS-en küldi ha megkapta az üzenetet (online volt):
```json
{ "type": "delivered", "message_id": 42, "room_id": 5 }
```
Szerver: UPDATE message_deliveries SET delivered_at=NOW() WHERE message_id=? AND user_id=?  
Majd broadcast a küldőnek:
```json
{ "type": "status_update", "message_id": 42, "user_id": 3, "delivered_at": "2026-06-05 01:00:00", "read_at": null }
```

### Szoba olvasásakor (`POST /rooms/{id}/read`)
UPDATE message_deliveries SET read_at=NOW()  
WHERE user_id=current_user AND message_id IN (SELECT id FROM messages WHERE room_id=? AND read_at IS NULL)  
Majd batch broadcast minden érintett üzenet küldőjének:
```json
{ "type": "status_update", "message_id": 42, "user_id": 3, "delivered_at": "...", "read_at": "2026-06-05 01:05:00" }
```

### `GET /rooms/{id}/messages` válasz módosítás
Saját üzeneteknél (sender_id == current_user) adjuk vissza a deliveries tömböt:
```json
{
  "id": 42,
  "deliveries": [
    { "user_id": 3, "delivered_at": "2026-06-05 01:00:00", "read_at": null },
    { "user_id": 7, "delivered_at": null, "read_at": null }
  ]
}
```
Más üzeneteinél `"deliveries": []`.

---

## App oldali logika

### MessageDelivery modell
```dart
class MessageDelivery {
  final int userId;
  final DateTime? deliveredAt;
  final DateTime? readAt;
}
```

### Message modell bővítés
- `final List<MessageDelivery> deliveries` mező hozzáadva
- `copyWith({List<MessageDelivery>? deliveries})` metódus

### WsService
- Bejövő `message` event + nem saját üzenet → küld `{"type":"delivered","message_id":N,"room_id":R}`
- `status_update` event továbbítja a `events` streamen

### ChatScreen
- `_onWsEvent` kiegészítve: `status_update` → megkeresi az üzenetet a listában, frissíti a deliveries-t `copyWith`-tel

### _MessageBubble UI
- Saját üzeneteknél (`isMine == true`) a timestamp mellé kerülnek a pöttyök
- Direct: max 1 pötty; Group: annyi pötty ahány delivery bejegyzés van
- Pötty mérete: 7×7px, köztük 2px gap

---

## Adatfolyam összefoglalva

```
A küld → REST POST /messages
  → DB mentés
  → message_deliveries INSERT (delivered_at=NULL)
  → APNs küldés → ha 200: delivered_at=NOW()
  → WS broadcast → B megkapja (online) → B küld delivered ACK
                                        → szerver: delivered_at=NOW()
                                        → szerver broadcast A-nak: status_update
B megnyitja szobát → POST /rooms/read
  → message_deliveries.read_at=NOW()
  → szerver broadcast A-nak: status_update (read_at feltöltve)
```

---

## Határok, korlátok

- Ha B telefon ki van kapcsolva: APNs `delivered_at` sem kerül be (Apple nem ad visszajelzést a tényleges kézbesítésről, csak az átvételről). A `delivered_at` az "Apple átvette" pillanatát jelzi.
- A pöttyök csak saját küldött üzeneteknél látszanak.
- Régi üzeneteknél (a funkció bevezetése előtt küldöttek) nincs delivery adat → pötty sem jelenik meg.
