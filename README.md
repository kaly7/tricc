# Tricc — 3C Chat

Meghívásos alapú belső csevegő alkalmazás. Flutter iOS kliens, PHP + MySQL + Ratchet WebSocket backend.

## Struktúra

| Mappa | Tartalom | Felelős |
|---|---|---|
| `app/` | Flutter iOS kliens | App Claude |
| `api/` | PHP REST API | Szerver Claude |
| `ws/` | PHP Ratchet WebSocket szerver | Szerver Claude |
| `db/` | SQL séma, migrációk | Szerver Claude |
| `docs/` | API dokumentáció | mindkettő |
| `MESSAGES.md` | Claude ↔ Claude kommunikáció | mindkettő |

## Funkciók

- Meghívókód alapú regisztráció
- 1:1 és csoportos beszélgetések
- Szöveg, kép, fájl (PDF/XLS/DOC), link küldés
- Valós idejű üzenetküldés WebSocket-en
- APNs push értesítés (iOS)
