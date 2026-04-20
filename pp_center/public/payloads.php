<?php
require __DIR__ . '/../app/web_bootstrap.php';

$pageTitle = 'ESP payloadok';
include __DIR__ . '/../templates/header.php';
?>
<section class="panel">
    <div class="section-head">
        <h1>ESP → MQTT payload specifikáció</h1>
        <div class="muted">Ajánlott mezők a pp_center feldolgozásához. A feldolgozó kompatibilis a lapos és a strukturált JSON-nal is.</div>
    </div>

    <div class="content-grid two-col mt-4">
        <article class="panel panel-soft">
            <h2>Topicok</h2>
            <ul class="mb-0">
                <li><code>pp/&lt;device_id&gt;/telemetry</code></li>
                <li><code>pp/&lt;device_id&gt;/alert</code></li>
                <li><code>pp/&lt;device_id&gt;/state/reported</code></li>
                <li><code>pp/&lt;device_id&gt;/cmd/out</code></li>
                <li><code>pp/&lt;device_id&gt;/lwt</code></li>
            </ul>
        </article>
        <article class="panel panel-soft">
            <h2>Általános szabályok</h2>
            <ul class="mb-0">
                <li><code>device_id</code> maradhat a topicból is, de küldhető a JSON-ben is.</li>
                <li><code>ts</code> lehet ISO dátum, Unix másodperc vagy milliszekundum, de a rendszer elsődleges naplóideje a szerver fogadási ideje.</li>
                <li>A feldolgozó elfogad lapos és strukturált payloadot is.</li>
                <li>A nem külön tárolt mezők a <code>raw_json</code> mezőben megmaradnak.</li>
            </ul>
        </article>
    </div>

    <h2 class="mt-4">telemetry</h2>
<pre class="code-block">{
  "device_id": "esp001",
  "ts": "2026-04-04T20:01:00Z",
  "env": {
    "temperature": 24.8,
    "humidity": 52.1,
    "air_quality": 410
  },
  "battery": {
    "pct": 88,
    "voltage": 4.08
  },
  "power": {
    "mode": "usb",
    "usb_present": true
  },
  "contacts": {
    "c1": "closed",
    "c2": "open",
    "c3": "closed",
    "c4": "closed"
  },
  "signal": {
    "rssi": -70,
    "transport": "wifi"
  },
  "meta": {
    "fw": "1.0.7",
    "uptime_sec": 81234,
    "config_version": 12
  }
}</pre>

    <h2 class="mt-4">alert</h2>
<pre class="code-block">{
  "device_id": "esp001",
  "ts": "2026-04-04T20:02:00Z",
  "event_type": "temp_trend_warn",
  "severity": "warning",
  "message": "Gyors hőmérséklet-emelkedés",
  "rule_id": "temp_trend",
  "value": 27.4,
  "threshold": 28.5,
  "actions_taken": ["mattermost"]
}</pre>

    <h2 class="mt-4">state/reported</h2>
<pre class="code-block">{
  "device_id": "esp001",
  "ts": "2026-04-04T20:03:00Z",
  "config_version": 12,
  "applied": true,
  "fw": "1.0.7",
  "power_mode": "battery",
  "battery_pct": 87,
  "contacts": {
    "c1": "closed",
    "c2": "closed"
  }
}</pre>

    <h2 class="mt-4">cmd/out</h2>
<pre class="code-block">{
  "device_id": "esp001",
  "request_id": "abc12345",
  "ok": true,
  "message": "status_ok",
  "ts": "2026-04-04T20:04:00Z"
}</pre>

    <h2 class="mt-4">lwt</h2>
<pre class="code-block">{"status":"online"}
{"status":"offline"}</pre>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
