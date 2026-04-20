<?php
require __DIR__ . '/../app/web_bootstrap.php';

$pageTitle = 'Súgó';
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Gyors referencia</div>
        <h1>ESP32 saját üzenetküldő – súgó</h1>
        <div class="muted">LED jelentések, Mattermost parancsok és gyors példák egy oldalon.</div>
    </div>
</div>

<div class="content-grid two-col">
    <section class="panel">
        <div class="section-head">
            <h2>LED jelentések</h2>
        </div>
        <div class="help-stack">
            <article class="help-card">
                <h3>LED 0 – Wi‑Fi / hálózat / AP</h3>
                <ul class="help-list">
                    <li><strong>Piros</strong>: nincs kapcsolat</li>
                    <li><strong>Sárga</strong>: Wi‑Fi csatlakozás vagy hálózatellenőrzés folyamatban</li>
                    <li><strong>Zöld</strong>: Wi‑Fi rendben, gateway elérhető</li>
                    <li><strong>Piros (AP mód)</strong>: az eszköz saját AP módban fut</li>
                    <li><strong>Lilás villogás</strong>: rescue AP mód</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 1 – tápellátás</h3>
                <ul class="help-list">
                    <li><strong>Zöld</strong>: USB táp rendben</li>
                    <li><strong>Piros</strong>: nincs USB táp</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 2 – akkumulátor</h3>
                <ul class="help-list">
                    <li><strong>Kikapcsolva</strong>: nincs akkuadat / nincs használva</li>
                    <li><strong>Villogó piros</strong>: nagyon alacsony töltöttség</li>
                    <li><strong>Piros</strong>: alacsony töltöttség</li>
                    <li><strong>Sárga</strong>: közepes töltöttség</li>
                    <li><strong>Kék</strong>: jó töltöttség</li>
                    <li><strong>Zöld</strong>: magas töltöttség</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 3 – BME680 szenzor</h3>
                <ul class="help-list">
                    <li><strong>Zöld</strong>: szenzor rendben</li>
                    <li><strong>Piros</strong>: szenzorhiba / nincs érzékelő</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 4 – GSM modem</h3>
                <ul class="help-list">
                    <li><strong>Kikapcsolva</strong>: GSM nincs használatban</li>
                    <li><strong>Kék villogás</strong>: GSM inicializálás folyamatban</li>
                    <li><strong>Zöld</strong>: GSM kész</li>
                    <li><strong>Piros</strong>: GSM hiba / nem kész</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 5 – riasztás</h3>
                <ul class="help-list">
                    <li><strong>Halvány zöld</strong>: nincs aktív riasztás</li>
                    <li><strong>Villogó piros</strong>: aktív riasztás van</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>LED 6 – MQTT kapcsolat</h3>
                <ul class="help-list">
                    <li><strong>Zöld</strong>: MQTT kapcsolódva</li>
                    <li><strong>Kék villogás</strong>: MQTT kapcsolódás folyamatban</li>
                    <li><strong>Piros</strong>: nincs MQTT kapcsolat</li>
                </ul>
            </article>
        </div>
        <div class="help-note">A leírás a jelenlegi firmware logikáját követi. Ha a LED-kezelés később változik, ezt az oldalt is érdemes frissíteni.</div>
    </section>

    <section class="panel">
        <div class="section-head">
            <h2>Mattermost parancsok</h2>
        </div>

        <div class="help-stack">
            <article class="help-card">
                <h3>Általános parancsok</h3>
                <div class="help-code-list">
                    <code>/pp help</code>
                    <code>/pp bridge status</code>
                    <code>/pp status &lt;device_id&gt;</code>
                    <code>/pp queue &lt;device_id&gt;</code>
                    <code>/pp cfg show &lt;device_id&gt;</code>
                    <code>/pp cfg push &lt;device_id&gt;</code>
                    <code>/pp cfg validate &lt;device_id&gt;</code>
                </div>
            </article>

            <article class="help-card">
                <h3>Eszközparancsok</h3>
                <div class="help-code-list">
                    <code>/pp cmd &lt;device_id&gt; ping</code>
                    <code>/pp cmd &lt;device_id&gt; get_status</code>
                    <code>/pp cmd &lt;device_id&gt; reload_config</code>
                    <code>/pp cmd &lt;device_id&gt; restart</code>
                </div>
                <ul class="help-list mt-2">
                    <li><strong>ping</strong>: válasz <code>pong</code></li>
                    <li><strong>get_status</strong>: azonnali státusz/telemetria küldés</li>
                    <li><strong>reload_config</strong>: helyi konfiguráció újratöltése</li>
                    <li><strong>restart</strong>: eszköz újraindítása</li>
                </ul>
            </article>

            <article class="help-card">
                <h3>Gyors példa</h3>
                <div class="help-example">
                    <code>/pp cmd esp32-01 get_status</code>
                    <p>Az <strong>esp32-01</strong> eszköztől azonnali státusz- és telemetriaküldést kér.</p>
                </div>
            </article>

            <article class="help-card">
                <h3>Fontos</h3>
                <p class="mb-2">Az ESP jelenleg ezeket a konkrét parancsokat ismeri:</p>
                <div class="help-code-list inline">
                    <code>ping</code>
                    <code>get_status</code>
                    <code>reload_config</code>
                    <code>restart</code>
                </div>
                <p class="mt-3 mb-0">Más parancs esetén az eszköz általában <code>unknown_command</code> választ adhat.</p>
            </article>
        </div>
    </section>
</div>

<section class="panel mt-4">
    <div class="section-head">
        <h2>Hasznos megjegyzések</h2>
    </div>
    <div class="content-grid two-col">
        <article class="help-card">
            <h3>Hibakeresés gyorsan</h3>
            <ul class="help-list">
                <li>Ha az MQTT LED piros, először a broker elérhetőségét és a Wi‑Fi állapotot ellenőrizd.</li>
                <li>Ha a szenzor LED piros, a BME680 inicializálást vagy a kábelezést kell megnézni.</li>
                <li>Ha van aktív riasztás, a grafikonos nézet riasztási idővonala segít az időpont visszakeresésében.</li>
            </ul>
        </article>
        <article class="help-card">
            <h3>Javasolt napi parancsok</h3>
            <div class="help-code-list">
                <code>/pp status &lt;device_id&gt;</code>
                <code>/pp cmd &lt;device_id&gt; get_status</code>
                <code>/pp queue &lt;device_id&gt;</code>
            </div>
        </article>
    </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
