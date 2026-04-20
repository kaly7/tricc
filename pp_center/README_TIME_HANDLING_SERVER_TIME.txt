Időkezelés változásai
======================

Ez a változat a bejövő eszköz-üzeneteket elsődlegesen szerveridővel naplózza.

Mi változott:
- telemetry_log.ts = szerver fogadási idő
- alerts.ts = szerver fogadási idő
- device_last_state.last_seen_at = szerver fogadási idő
- device_presence_log.happened_at már eddig is szerveridő volt
- az eszköz által küldött idő nem vész el, bekerül a raw_json mezőbe:
  - _server_received_at
  - _device_ts_normalized (ha értelmezhető volt)

Megjelenítés:
- a listák és a Mattermost üzenetek elsődlegesen a szerver idejét használják
- a részleteknél látható maradhat az eszköz ideje diagnosztikai céllal
