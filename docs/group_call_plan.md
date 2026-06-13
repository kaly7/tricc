# Csoportos hanghívás — architektúra terv

## Cél

Csoportos (konferencia) hanghívás megvalósítása mobil klienssel. Több, egymástól független szoba, szobánként **max. 8-10 fő**. Egyelőre **csak hang**, de a megoldás legyen jövőbiztos: később videó és nagyobb létszám is jöhet anélkül, hogy architektúrát kellene váltani.

Egy 1-1 hanghívás már **működik** WebRTC alapon, coturn TURN szerverrel, megoldott tunnellel. A cél ennek a kibővítése csoportos hívásra.

## Döntés: SFU (LiveKit), nem mesh

Három topológia jött szóba:

- **Mesh (full mesh P2P):** mindenki mindenkivel külön peer connection. Nulla új infra, de a kliens terhelése négyzetesen nő (N fő → N-1 stream fel és le fejenként, N-1 encode/decode pipeline). Hangra ~8 főig elviselhető, 10-nél már a felső plafon. Videóval 10 fő menthetetlen.
- **SFU (Selective Forwarding Unit):** mindenki **egy** kapcsolatot tart a szerverrel, felküldi a streamjét egyszer, a szerver szétosztja. A kliens terhelése konstans. Új szerverkomponens kell, de cserébe tiszta skálázás és belépés/kilépés-kezelés.
- **MCU:** szerveroldali keverés egy streammé. CPU-zabáló, elvetve.

**Választott megoldás: SFU, konkrétan LiveKit.** Indok: a 8-10 fős limit a mesh határán van, és mivel később videó is cél, a mesh most befektetett, később kidobott munka lenne. A LiveKit self-hostolható, újrahasznosítja a meglévő coturnt, van Flutter SDK-ja, és a hangról videóra váltás nála nem architektúraváltás, csak egy track hozzáadása.

## Komponensek és hozzárendelés a meglévő infrához

| Komponens | Szerep | Hol fut |
|---|---|---|
| **LiveKit szerver** | SFU — streamek szétosztása, room-kezelés, signaling | Új Proxmox VM (Debian + Docker). Kezdésre 2 vCPU / 4 GB elég egy 10 fős szobához. |
| **coturn** | TURN relay (NAT traversal), marad változatlanul | Meglévő szerver. A LiveKit configba bekötve, credentialekkel. |
| **PHP / MySQL backend** | Access token (JWT) generálás + szoba-nyilvántartás + jogosultság + létszámlimit | Meglévő backend. |
| **Flutter kliens** | `livekit_client` csomag (`Room.connect`, mute, résztvevő-lista room-eseményként) | iOS + Android egy kódbázisból. |
| **Signaling** | A LiveKit saját protokollja váltja ki a korábbi egyedi signalinget | LiveKit szerveren belül — már nem a mi gondunk. |

## Hogyan illeszkedik a meglévő stackhez

- **A coturn marad.** A LiveKit configban megadjuk a meglévő TURN szerver címét és credentialjeit. Nem kell külön TURN-t állítani.
- **A signaling eltűnik mint saját feladat.** Az eddig magunknak épített signaling (kapcsolatállapot, újracsatlakozás, ICE-csere) helyét a LiveKit veszi át. Egyszeri veszteség (a régi signaling kód nyugdíjazása), cserébe nem mi tartjuk karban.
- **A PHP kapja a token-generálást.** A LiveKit JWT-alapú access tokeneket használ. Minden résztvevő egy aláírt tokennel lép be a szobába, ami megmondja, melyik room-hoz fér hozzá és milyen jogokkal (publish/subscribe). Ezt a PHP generálja a saját user/auth logikájából — hivatalos PHP SDK-val, vagy minimalista módon `firebase/php-jwt`-vel (a token formátuma dokumentált).

## Több szoba kezelése

A "room" a LiveKit alapvető absztrakciója — **tetszőleges sok párhuzamos, egymástól elszigetelt szoba**, ugyanazon az egy szerveren. Egy szoba résztvevői csak egymást látják/hallják.

- A szoba egy **név / ID**. A PHP tokenbe beleírja, melyik room-hoz ad hozzáférést (pl. `room: "user_42_room"`).
- A szoba **automatikusan létrejön az első belépőnél, és megszűnik az utolsó kilépésekor** — nincs előzetes létrehozás vagy takarítás.
- A **szoba-nyilvántartás a PHP/MySQL oldalon marad** (pl. `rooms` tábla: név, létrehozó, létszámlimit, jogosultságok). A LiveKit ebből semmit nem tud és nem is kell neki — ő csak a kapott token alapján osztja a médiát.

## Létszámlimit (8-10 fő) — termékdöntésként, nem technikai kényszerből

Két helyen érdemes érvényesíteni:

1. **PHP token-generálásnál (szép UX):** token kiadása előtt a PHP lekérdezi a room aktuális létszámát a LiveKit server API-n keresztül. Ha tele van, nem ad tokent → "a hívás megtelt" üzenet. Ez a megkerülhetetlen kontroll, mert szerveroldalon dől el (a kliens nem megbízható).
2. **LiveKit room config `max_participants` (backstop):** room-létrehozáskor a server API-n állítható, akár **szobánként eltérő** érték. Ez a garancia.

## Skálázás (jövő, nem most)

- A méretezés a **csúcsidei összes egyidejű résztvevő/stream** számától függ, nem a szobák számától önmagában. Pl. 5 szoba × 8 fő = 40 egyidejű hangos résztvevő — egy 4 vCPU / 8 GB VM ezt kényelmesen viszi (a hang olcsó).
- **Lehetséges szűk keresztmetszet a coturn:** ha sok résztvevő TURN-relay-en megy (mobilhálón gyakori), a teljes audio-forgalom a coturn-ön folyik át. Hangnál sávszélességben még nem vészes, de figyelni kell, ha a felhasználószám nő.
- Egy szerver kinövésekor a LiveKit **több node-ra skálázható** (distributed mode, Redis-szel a node-ok között). 8-10 fős szobákkal egy node sokáig elég — ez csak biztosíték, hogy nincs falba futás.

## Javasolt felépítési sorrend

1. **LiveKit szerver felhúzása** Dockerrel a Proxmox VM-en + **coturn bekötése** a configba. Tesztelés a LiveKit **böngészős demó kliensével** — előbb derüljön ki, hogy a hálózat, a TURN és a tunnel átmegy, mielőtt egy sor Flutter/PHP kód készülne. Ez kiszűri a hálózati buktatókat tiszta terepen.
2. **PHP token-generáló endpoint** — a meglévő auth logikára építve.
3. **Flutter kliens** — `livekit_client`, `Room.connect(url, token)`, room-események kezelése.
4. **Létszámlimit + finomítás** a végén.

## Ismert buktató, amire előre számítani kell

A self-hosted LiveKit telepítésnél a **TLS és a domain** beállítása viszi a setup idejét, nem a media-réteg. A böngészős és iOS kliensek HTTPS/WSS-t várnak, tehát kell rendes tanúsítvány a LiveKit elé. **Caddy**-vel a LiveKit ezt szinte magától intézi (ezt ajánlják is). A meglévő tunnel illeszkedhet ebbe.

## Nyitott kérdések (még nem eldöntött)

- Konkrét csúcsidei számok (összes szoba, egyidejű felhasználók) — ezekből jön a pontos VM-méretezés és a több-node-os irány időzítése.
- Menet közbeni videó-bővítés időzítése (most csak hang).
