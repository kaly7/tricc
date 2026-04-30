<?php
require __DIR__ . '/../app/web_bootstrap.php';

use App\Services\AlertService;
use App\Services\DeviceService;
use App\Services\TelemetryService;

function h($value): string
{
    return e((string) $value);
}

function resolve_chart_range(string $range, ?string $fromInput, ?string $toInput): array
{
    $tz = new DateTimeZone((string) cfg('app.timezone', 'Europe/Budapest'));
    $now = new DateTimeImmutable('now', $tz);
    $allowed = ['1h', '6h', '12h', '24h', '2d', '7d', 'custom'];
    if (!in_array($range, $allowed, true)) {
        $range = '24h';
    }

    if ($range === 'custom') {
        try {
            $from = new DateTimeImmutable((string) $fromInput, $tz);
            $to = new DateTimeImmutable((string) $toInput, $tz);
            if ($to <= $from) {
                $to = $from->modify('+1 hour');
            }
            return [$range, $from, $to];
        } catch (Throwable) {
            $range = '24h';
        }
    }

    $from = match ($range) {
        '1h' => $now->modify('-1 hour'),
        '6h' => $now->modify('-6 hours'),
        '12h' => $now->modify('-12 hours'),
        '2d' => $now->modify('-2 days'),
        '7d' => $now->modify('-7 days'),
        default => $now->modify('-24 hours'),
    };

    return [$range, $from, $now];
}

function format_duration_hu(?int $seconds): string
{
    if ($seconds === null) {
        return '—';
    }
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' nap';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' ó';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . ' p';
    }
    if (!$parts || $seconds > 0) {
        $parts[] = $seconds . ' mp';
    }

    return implode(' ', array_slice($parts, 0, 3));
}

$deviceId = trim((string) ($_GET['device_id'] ?? ''));
$range = trim((string) ($_GET['range'] ?? '24h'));
$fromInput = trim((string) ($_GET['from'] ?? '')) ?: null;
$toInput = trim((string) ($_GET['to'] ?? '')) ?: null;
[$range, $from, $to] = resolve_chart_range($range, $fromInput, $toInput);

$deviceService = new DeviceService();
$telemetryService = new TelemetryService();
$alertService = new AlertService();
$device = $deviceId !== '' ? $deviceService->find($deviceId) : null;

if (!$device) {
    flash_set('error', 'Az eszköz nem található.');
    redirect_to(app_url('devices.php'));
}

$history = $telemetryService->historySeries($deviceId, $from, $to, 720);
$timeline = $alertService->timelineData($deviceId, $from, $to);
$intervalsForTable = $timeline['intervals'] ?? [];
usort($intervalsForTable, static function (array $a, array $b): int {
    if (($a['start_epoch'] ?? 0) === ($b['start_epoch'] ?? 0)) {
        return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
    }
    return ($b['start_epoch'] ?? 0) <=> ($a['start_epoch'] ?? 0);
});

$rangeLabels = [
    '1h' => '1 órás',
    '6h' => '6 órás',
    '12h' => '12 órás',
    '24h' => '24 órás',
    '2d' => '2 napos',
    '7d' => '7 napos',
    'custom' => 'Egyedi',
];

$pageTitle = 'Grafikonok – ' . ($device['name'] ?? $deviceId);
include __DIR__ . '/../templates/header.php';
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Eszköz grafikonok</div>
        <h1><?= h($device['name'] ?? $deviceId) ?></h1>
        <div class="muted small"><code><?= h($deviceId) ?></code><?php if (!empty($device['location'])): ?> · <?= h((string) $device['location']) ?><?php endif; ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-secondary" href="<?= e(app_url('device.php?device_id=' . urlencode($deviceId))) ?>">Vissza az eszközhöz</a>
    </div>
</div>

<section class="panel mb-3">
    <div class="section-head">
        <div>
            <h2>Időablak</h2>
            <div class="muted small">A hőmérséklet és páratartalom közös grafikonon látszik, a jelerősségek külön, a kontaktok pedig riasztási idővonalon jelennek meg.</div>
        </div>
    </div>
    <div class="chart-toolbar mb-3">
        <?php foreach ($rangeLabels as $rangeKey => $label): if ($rangeKey === 'custom') { continue; } ?>
            <a class="chart-chip <?= $range === $rangeKey ? 'active' : '' ?>" href="<?= e(app_url('device_charts.php?device_id=' . urlencode($deviceId) . '&range=' . urlencode($rangeKey))) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="chart-range-form">
        <input type="hidden" name="device_id" value="<?= h($deviceId) ?>">
        <input type="hidden" name="range" value="custom">
        <label>
            <span>Kezdő idő</span>
            <input type="datetime-local" name="from" value="<?= h($from->format('Y-m-d\TH:i')) ?>">
        </label>
        <label>
            <span>Záró idő</span>
            <input type="datetime-local" name="to" value="<?= h($to->format('Y-m-d\TH:i')) ?>">
        </label>
        <div class="chart-range-actions">
            <button type="submit" class="btn btn-primary">Egyedi megjelenítés</button>
        </div>
    </form>
    <div class="mini-stats mt-3">
        <span class="badge-status status-info">Időablak: <?= h($rangeLabels[$range] ?? $range) ?></span>
        <span class="badge-status status-warn">Telemetria sorok: <?= h((string) ($history['row_count'] ?? 0)) ?></span>
        <span class="badge-status status-online">Telemetria pontok: <?= h((string) ($history['point_count'] ?? 0)) ?></span>
        <span class="badge-status status-info">Riasztási szakaszok: <?= h((string) ($timeline['interval_count'] ?? 0)) ?></span>
        <span class="badge-status status-offline">Riasztási események: <?= h((string) ($timeline['event_count'] ?? 0)) ?></span>
    </div>
</section>

<div class="chart-grid">
    <section class="panel chart-panel">
        <div class="section-head mb-2">
            <div>
                <h2>Hőmérséklet és páratartalom</h2>
                <div class="muted small">Közös idősor, külön bal/jobb tengellyel.</div>
            </div>
            <div class="chart-legend">
                <span><i class="legend-swatch swatch-temp"></i>Hőmérséklet</span>
                <span><i class="legend-swatch swatch-humidity"></i>Páratartalom</span>
            </div>
        </div>
        <div id="chart-temp-humidity" class="svg-chart" data-empty="Nincs hőmérséklet vagy páratartalom adat a kiválasztott időszakban."></div>
    </section>

    <section class="panel chart-panel">
        <div class="section-head mb-2">
            <div>
                <h2>Légnyomás</h2>
                <div class="muted small">BME280 barometrikus nyomás hPa-ban.</div>
            </div>
        </div>
        <div id="chart-pressure" class="svg-chart" data-empty="Nincs légnyomás adat a kiválasztott időszakban."></div>
    </section>

    <section class="panel chart-panel">
        <div class="section-head mb-2">
            <div>
                <h2>Wi‑Fi jelszint</h2>
                <div class="muted small">dBm értékek időben.</div>
            </div>
        </div>
        <div id="chart-wifi" class="svg-chart" data-empty="Nincs Wi‑Fi jelszint adat a kiválasztott időszakban."></div>
    </section>

    <section class="panel chart-panel">
        <div class="section-head mb-2">
            <div>
                <h2>GSM jelszint</h2>
                <div class="muted small">SIM800L után fog igazán megtelni, de a felület már kész.</div>
            </div>
        </div>
        <div id="chart-gsm" class="svg-chart" data-empty="Nincs GSM jelszint adat a kiválasztott időszakban."></div>
    </section>

    <section class="panel chart-panel chart-panel-full">
        <div class="section-head mb-2">
            <div>
                <h2>Riasztási idővonal</h2>
                <div class="muted small">A kontaktok és a többi időtartam alapú riasztás a naplóból rajzolva. Pontosabb, mint a telemetriából visszakövetkeztetni.</div>
            </div>
            <div class="chart-legend">
                <span><i class="legend-swatch swatch-alert-high"></i>Magas hőmérséklet</span>
                <span><i class="legend-swatch swatch-alert-low"></i>Alacsony hőmérséklet</span>
                <span><i class="legend-swatch swatch-alert-contact"></i>Kontakt</span>
                <span><i class="legend-swatch swatch-alert-offline"></i>Offline</span>
            </div>
        </div>
        <div id="chart-alert-timeline" class="svg-chart svg-chart-timeline" data-empty="Nincs riasztási időszak a kiválasztott időablakban."></div>
    </section>
</div>

<section class="panel mt-3">
    <div class="section-head mb-2">
        <div>
            <h2>Riasztási szakaszok</h2>
            <div class="muted small">Mikor indult, mikor ért véget, és mennyi ideig tartott az adott riasztás.</div>
        </div>
    </div>

    <?php if (!$intervalsForTable): ?>
        <div class="chart-empty">Nincs riasztási szakasz a kiválasztott időablakban.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Típus</th>
                        <th>Kezdete</th>
                        <th>Vége</th>
                        <th>Időtartam</th>
                        <th>Állapot</th>
                        <th>Üzenet</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($intervalsForTable as $interval): ?>
                        <tr>
                            <td>
                                <div class="timeline-type">
                                    <span class="timeline-badge timeline-badge-<?= h($interval['style'] ?? 'generic') ?>"></span>
                                    <strong><?= h($interval['lane_label'] ?? '—') ?></strong>
                                </div>
                            </td>
                            <td>
                                <?= h($interval['start_ts'] ?? '—') ?>
                                <?php if (!empty($interval['started_before_range'])): ?>
                                    <div class="muted small">korábban indult</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($interval['end_ts'] ?? '—') ?>
                                <?php if (!empty($interval['is_ongoing'])): ?>
                                    <div class="muted small">még aktív</div>
                                <?php elseif (!empty($interval['ended_after_range'])): ?>
                                    <div class="muted small">később ért véget</div>
                                <?php endif; ?>
                            </td>
                            <td><?= h(format_duration_hu(isset($interval['duration_sec']) ? (int) $interval['duration_sec'] : null)) ?></td>
                            <td>
                                <?php if (!empty($interval['is_ongoing'])): ?>
                                    <span class="badge-status status-offline">Aktív</span>
                                <?php else: ?>
                                    <span class="badge-status status-online">Lezárt</span>
                                <?php endif; ?>
                            </td>
                            <td><?= h($interval['message'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
const historySeries = <?= json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const alertTimeline = <?= json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

(function () {
    const points = Array.isArray(historySeries.points) ? historySeries.points : [];
    const fmtDate = new Intl.DateTimeFormat('hu-HU', {
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit'
    });
    const fmtTimeOnly = new Intl.DateTimeFormat('hu-HU', {
        hour: '2-digit', minute: '2-digit'
    });
    const fmtDayHour = new Intl.DateTimeFormat('hu-HU', {
        month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit'
    });

    function setEmpty(el) {
        el.innerHTML = '<div class="chart-empty">' + (el.dataset.empty || 'Nincs adat') + '</div>';
    }

    function toXYPath(points, valueKey, xFn, yFn) {
        let d = '';
        let started = false;
        for (const point of points) {
            const value = point[valueKey];
            if (value === null || value === undefined || Number.isNaN(Number(value))) {
                started = false;
                continue;
            }
            const x = xFn(point.epoch);
            const y = yFn(Number(value));
            d += (started ? ' L ' : ' M ') + x.toFixed(2) + ' ' + y.toFixed(2);
            started = true;
        }
        return d.trim();
    }

    function extent(values, fallbackMin, fallbackMax) {
        const filtered = values.filter(v => v !== null && v !== undefined && !Number.isNaN(Number(v))).map(Number);
        if (!filtered.length) {
            return null;
        }
        let min = Math.min(...filtered);
        let max = Math.max(...filtered);
        if (min === max) {
            min -= 1;
            max += 1;
        }
        if (fallbackMin !== undefined) {
            min = Math.min(min, fallbackMin);
        }
        if (fallbackMax !== undefined) {
            max = Math.max(max, fallbackMax);
        }
        const pad = Math.max((max - min) * 0.08, 1);
        return { min: min - pad, max: max + pad };
    }

    function axisLabels(ext) {
        if (!ext) return [];
        const labels = [];
        const steps = 4;
        for (let i = 0; i <= steps; i++) {
            const ratio = i / steps;
            labels.push(ext.max - ((ext.max - ext.min) * ratio));
        }
        return labels;
    }

    function createSvg(width, height) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('class', 'svg-chart-canvas');
        return svg;
    }

    function addSvgEl(parent, tag, attrs = {}, text = null) {
        const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, String(value)));
        if (text !== null) {
            el.textContent = text;
        }
        parent.appendChild(el);
        return el;
    }

    function formatAxisTime(epoch, totalSeconds) {
        const date = new Date(epoch * 1000);
        if (totalSeconds <= 43200) {
            return fmtTimeOnly.format(date);
        }
        if (totalSeconds <= 172800) {
            return fmtDayHour.format(date).replace(',', '');
        }
        return new Intl.DateTimeFormat('hu-HU', { month: '2-digit', day: '2-digit', hour: '2-digit' }).format(date).replace(',', '');
    }

    function gridProfile(totalSeconds) {
        if (totalSeconds <= 21600) {
            return { minor: 900, medium: 1800, major: 3600 };
        }
        if (totalSeconds <= 43200) {
            return { minor: 1800, medium: 3600, major: 7200 };
        }
        if (totalSeconds <= 172800) {
            return { minor: 3600, medium: 21600, major: 43200 };
        }
        return { minor: 21600, medium: 43200, major: 86400 };
    }

    function drawTimeGrid(g, opts) {
        const { minEpoch, maxEpoch, margin, innerW, innerH, height } = opts;
        const totalSeconds = Math.max(1, maxEpoch - minEpoch);
        const profile = gridProfile(totalSeconds);
        const xFn = epoch => margin.left + ((epoch - minEpoch) / totalSeconds) * innerW;
        const startEpoch = Math.floor(minEpoch / profile.minor) * profile.minor;

        for (let epoch = startEpoch; epoch <= maxEpoch + profile.minor; epoch += profile.minor) {
            if (epoch < minEpoch || epoch > maxEpoch) {
                continue;
            }
            let level = 'minor';
            if (epoch % profile.major === 0) {
                level = 'major';
            } else if (epoch % profile.medium === 0) {
                level = 'medium';
            }
            const x = xFn(epoch);
            addSvgEl(g, 'line', {
                x1: x,
                y1: margin.top,
                x2: x,
                y2: margin.top + innerH,
                class: `chart-time-grid-line chart-time-grid-line-${level}`,
            });
            if (level === 'major') {
                addSvgEl(g, 'line', { x1: x, y1: margin.top + innerH, x2: x, y2: margin.top + innerH + 6, class: 'chart-axis-tick' });
                addSvgEl(g, 'text', { x, y: height - 12, 'text-anchor': 'middle', class: 'chart-axis-label' }, formatAxisTime(epoch, totalSeconds));
            }
        }
    }

    function ensureTooltip(el) {
        let tip = el.querySelector('.chart-hover-tooltip');
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-hover-tooltip';
            tip.hidden = true;
            el.appendChild(tip);
        }
        return tip;
    }

    function showTooltip(el, html, x, y) {
        const tip = ensureTooltip(el);
        tip.innerHTML = html;
        tip.hidden = false;
        const rect = el.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        let left = x + 14;
        let top = y + 12;
        if (left + tipRect.width > rect.width - 8) {
            left = Math.max(8, x - tipRect.width - 14);
        }
        if (top + tipRect.height > rect.height - 8) {
            top = Math.max(8, y - tipRect.height - 14);
        }
        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;
    }

    function hideTooltip(el) {
        const tip = el.querySelector('.chart-hover-tooltip');
        if (tip) {
            tip.hidden = true;
        }
    }

    function nearestPoint(sourcePoints, epoch, keys) {
        let best = null;
        let bestDelta = Infinity;
        for (const point of sourcePoints) {
            if (keys && !keys.some(key => point[key] !== null && point[key] !== undefined && !Number.isNaN(Number(point[key])))) {
                continue;
            }
            const delta = Math.abs(Number(point.epoch) - epoch);
            if (delta < bestDelta) {
                bestDelta = delta;
                best = point;
            }
        }
        return best;
    }

    function attachLineHover(el, svg, cfg) {
        const { width, height, margin, innerW, innerH, minEpoch, maxEpoch, buildTooltip, markers = [] } = cfg;
        const hoverLayer = addSvgEl(svg, 'g', { class: 'chart-hover-layer' });
        const hoverLine = addSvgEl(hoverLayer, 'line', {
            x1: margin.left,
            y1: margin.top,
            x2: margin.left,
            y2: margin.top + innerH,
            class: 'chart-hover-line',
            visibility: 'hidden'
        });
        const hoverDots = markers.map(marker => addSvgEl(hoverLayer, 'circle', {
            cx: margin.left,
            cy: margin.top,
            r: marker.r || 4,
            class: `chart-hover-dot ${marker.className || ''}`.trim(),
            visibility: 'hidden'
        }));

        function hide() {
            hoverLine.setAttribute('visibility', 'hidden');
            hoverDots.forEach(dot => dot.setAttribute('visibility', 'hidden'));
            hideTooltip(el);
        }

        svg.addEventListener('mouseleave', hide);
        svg.addEventListener('mousemove', event => {
            const rect = svg.getBoundingClientRect();
            const px = event.clientX - rect.left;
            const py = event.clientY - rect.top;
            const viewX = (px / rect.width) * width;
            if (viewX < margin.left || viewX > margin.left + innerW) {
                hide();
                return;
            }
            const ratio = (viewX - margin.left) / innerW;
            const epoch = minEpoch + ratio * Math.max(1, maxEpoch - minEpoch);
            const state = buildTooltip(epoch);
            if (!state || !state.point) {
                hide();
                return;
            }

            const markerX = state.x;
            hoverLine.setAttribute('x1', markerX);
            hoverLine.setAttribute('x2', markerX);
            hoverLine.setAttribute('visibility', 'visible');

            hoverDots.forEach((dot, idx) => {
                const marker = state.markers && state.markers[idx];
                if (!marker || marker.y === null || marker.y === undefined) {
                    dot.setAttribute('visibility', 'hidden');
                    return;
                }
                dot.setAttribute('cx', markerX);
                dot.setAttribute('cy', marker.y);
                dot.setAttribute('visibility', 'visible');
            });

            showTooltip(el, state.html, px, py);
        });
    }

    function renderDualAxisChart(el, allPoints) {
        const tempExt = extent(allPoints.map(p => p.temperature));
        const humExt = extent(allPoints.map(p => p.humidity), 0, 100);
        if (!tempExt && !humExt) {
            setEmpty(el);
            return;
        }

        const width = 980, height = 300;
        const margin = { top: 18, right: 58, bottom: 38, left: 58 };
        const innerW = width - margin.left - margin.right;
        const innerH = height - margin.top - margin.bottom;
        const minEpoch = points.length ? points[0].epoch : Date.now() / 1000;
        const maxEpoch = points.length ? points[points.length - 1].epoch : minEpoch + 3600;
        const xFn = epoch => margin.left + ((epoch - minEpoch) / Math.max(1, maxEpoch - minEpoch)) * innerW;
        const yTemp = value => margin.top + ((tempExt.max - value) / Math.max(0.0001, tempExt.max - tempExt.min)) * innerH;
        const yHum = value => margin.top + ((humExt.max - value) / Math.max(0.0001, humExt.max - humExt.min)) * innerH;

        const svg = createSvg(width, height);
        const g = addSvgEl(svg, 'g');
        addSvgEl(g, 'rect', { x: margin.left, y: margin.top, width: innerW, height: innerH, rx: 12, class: 'chart-plot-bg' });
        drawTimeGrid(g, { minEpoch, maxEpoch, margin, innerW, innerH, height });

        const leftLabels = axisLabels(tempExt || humExt);
        leftLabels.forEach((value, idx) => {
            const y = margin.top + (innerH / Math.max(1, leftLabels.length - 1)) * idx;
            addSvgEl(g, 'line', { x1: margin.left, y1: y, x2: margin.left + innerW, y2: y, class: 'chart-grid-line' });
            if (tempExt) {
                addSvgEl(g, 'text', { x: margin.left - 10, y: y + 4, 'text-anchor': 'end', class: 'chart-axis-label' }, value.toFixed(1) + '°C');
            }
            if (humExt) {
                const rightValue = humExt.max - ((humExt.max - humExt.min) * (idx / Math.max(1, leftLabels.length - 1)));
                addSvgEl(g, 'text', { x: width - margin.right + 10, y: y + 4, 'text-anchor': 'start', class: 'chart-axis-label' }, rightValue.toFixed(0) + '%');
            }
        });

        if (tempExt) {
            const tempPath = toXYPath(allPoints, 'temperature', xFn, yTemp);
            if (tempPath) {
                addSvgEl(g, 'path', { d: tempPath, class: 'chart-line chart-line-temp' });
            }
        }
        if (humExt) {
            const humPath = toXYPath(allPoints, 'humidity', xFn, yHum);
            if (humPath) {
                addSvgEl(g, 'path', { d: humPath, class: 'chart-line chart-line-humidity' });
            }
        }

        el.innerHTML = '';
        el.appendChild(svg);

        attachLineHover(el, svg, {
            width, height, margin, innerW, innerH, minEpoch, maxEpoch,
            markers: [
                { className: 'chart-hover-dot-temp' },
                { className: 'chart-hover-dot-humidity' }
            ],
            buildTooltip(epoch) {
                const point = nearestPoint(allPoints, epoch, ['temperature', 'humidity']);
                if (!point) return null;
                const parts = [`<div class="chart-tooltip-title">${fmtDate.format(new Date(point.epoch * 1000)).replace(',', '')}</div>`];
                if (point.temperature !== null && point.temperature !== undefined) {
                    parts.push(`<div><span class="chart-tip-swatch chart-tip-temp"></span>Hőmérséklet: <strong>${Number(point.temperature).toFixed(1)} °C</strong></div>`);
                }
                if (point.humidity !== null && point.humidity !== undefined) {
                    parts.push(`<div><span class="chart-tip-swatch chart-tip-humidity"></span>Páratartalom: <strong>${Number(point.humidity).toFixed(0)} %</strong></div>`);
                }
                return {
                    point,
                    x: xFn(point.epoch),
                    markers: [
                        point.temperature !== null && point.temperature !== undefined ? { y: yTemp(Number(point.temperature)) } : null,
                        point.humidity !== null && point.humidity !== undefined ? { y: yHum(Number(point.humidity)) } : null,
                    ],
                    html: parts.join(''),
                };
            }
        });
    }

    function renderSignalChart(el, valueKey, label) {
        const ext = extent(points.map(p => p[valueKey]));
        if (!ext) {
            setEmpty(el);
            return;
        }
        const width = 980, height = 260;
        const margin = { top: 18, right: 18, bottom: 38, left: 58 };
        const innerW = width - margin.left - margin.right;
        const innerH = height - margin.top - margin.bottom;
        const minEpoch = points[0].epoch;
        const maxEpoch = points[points.length - 1].epoch;
        const xFn = epoch => margin.left + ((epoch - minEpoch) / Math.max(1, maxEpoch - minEpoch)) * innerW;
        const yFn = value => margin.top + ((ext.max - value) / Math.max(0.0001, ext.max - ext.min)) * innerH;

        const svg = createSvg(width, height);
        const g = addSvgEl(svg, 'g');
        addSvgEl(g, 'rect', { x: margin.left, y: margin.top, width: innerW, height: innerH, rx: 12, class: 'chart-plot-bg' });
        drawTimeGrid(g, { minEpoch, maxEpoch, margin, innerW, innerH, height });

        axisLabels(ext).forEach((value, idx, arr) => {
            const y = margin.top + (innerH / Math.max(1, arr.length - 1)) * idx;
            addSvgEl(g, 'line', { x1: margin.left, y1: y, x2: margin.left + innerW, y2: y, class: 'chart-grid-line' });
            addSvgEl(g, 'text', { x: margin.left - 10, y: y + 4, 'text-anchor': 'end', class: 'chart-axis-label' }, value.toFixed(0) + ' ' + label);
        });

        const lineClass = valueKey === 'gsm_rssi' ? 'chart-line-gsm' : (valueKey === 'pressure_hpa' ? 'chart-line-pressure' : 'chart-line-wifi');
        const path = toXYPath(points, valueKey, xFn, yFn);
        if (path) {
            addSvgEl(g, 'path', { d: path, class: 'chart-line ' + lineClass });
        }

        el.innerHTML = '';
        el.appendChild(svg);

        attachLineHover(el, svg, {
            width, height, margin, innerW, innerH, minEpoch, maxEpoch,
            markers: [
                { className: valueKey === 'gsm_rssi' ? 'chart-hover-dot-gsm' : 'chart-hover-dot-wifi' }
            ],
            buildTooltip(epoch) {
                const point = nearestPoint(points, epoch, [valueKey]);
                if (!point || point[valueKey] === null || point[valueKey] === undefined) {
                    return null;
                }
                const seriesName = valueKey === 'gsm_rssi' ? 'GSM jelszint' : (valueKey === 'pressure_hpa' ? 'Légnyomás' : 'Wi‑Fi jelszint');
                const tipClass = valueKey === 'gsm_rssi' ? 'chart-tip-gsm' : (valueKey === 'pressure_hpa' ? 'chart-tip-pressure' : 'chart-tip-wifi');
                const decimals = valueKey === 'pressure_hpa' ? 1 : 0;
                return {
                    point,
                    x: xFn(point.epoch),
                    markers: [{ y: yFn(Number(point[valueKey])) }],
                    html: `
                        <div class="chart-tooltip-title">${fmtDate.format(new Date(point.epoch * 1000)).replace(',', '')}</div>
                        <div><span class="chart-tip-swatch ${tipClass}"></span>${seriesName}: <strong>${Number(point[valueKey]).toFixed(decimals)} ${label}</strong></div>
                    `,
                };
            }
        });
    }

    function renderAlertTimeline(el, timeline) {
        const lanes = Array.isArray(timeline.lanes) ? timeline.lanes : [];
        const intervals = Array.isArray(timeline.intervals) ? timeline.intervals : [];
        if (!lanes.length || !intervals.length) {
            setEmpty(el);
            return;
        }

        const width = 980;
        const laneHeight = 48;
        const height = Math.max(200, 28 + (lanes.length * laneHeight) + 54);
        const margin = { top: 18, right: 18, bottom: 38, left: 170 };
        const innerW = width - margin.left - margin.right;
        const innerH = height - margin.top - margin.bottom;
        const minEpoch = Number(timeline.from_epoch || 0);
        const maxEpoch = Number(timeline.to_epoch || 0);
        const xFn = epoch => margin.left + ((epoch - minEpoch) / Math.max(1, maxEpoch - minEpoch)) * innerW;

        const svg = createSvg(width, height);
        const g = addSvgEl(svg, 'g');
        addSvgEl(g, 'rect', { x: margin.left, y: margin.top, width: innerW, height: innerH, rx: 12, class: 'chart-plot-bg' });
        drawTimeGrid(g, { minEpoch, maxEpoch, margin, innerW, innerH, height });

        const laneIndex = new Map();
        lanes.forEach((lane, idx) => {
            laneIndex.set(lane.key, idx);
            const top = margin.top + idx * laneHeight;
            if (idx > 0) {
                addSvgEl(g, 'line', { x1: margin.left, y1: top, x2: margin.left + innerW, y2: top, class: 'chart-grid-line' });
            }
            addSvgEl(g, 'text', { x: margin.left - 12, y: top + (laneHeight / 2) + 4, 'text-anchor': 'end', class: 'chart-axis-label chart-lane-label' }, lane.label || lane.key);
        });

        intervals.forEach(interval => {
            const idx = laneIndex.get(interval.lane_key);
            if (idx === undefined) {
                return;
            }
            const top = margin.top + idx * laneHeight + 9;
            const barHeight = laneHeight - 18;
            const x1 = xFn(Number(interval.display_start_epoch || minEpoch));
            const x2 = xFn(Number(interval.display_end_epoch || maxEpoch));
            const barWidth = Math.max(4, x2 - x1);
            const rect = addSvgEl(g, 'rect', {
                x: x1,
                y: top,
                width: barWidth,
                height: barHeight,
                rx: 8,
                class: `chart-interval chart-interval-${interval.style || 'generic'}`,
            });
            const title = document.createElementNS('http://www.w3.org/2000/svg', 'title');
            title.textContent = `${interval.lane_label}: ${interval.message} | ${interval.start_ts}${interval.end_ts ? ' → ' + interval.end_ts : ' → még aktív'}`;
            rect.appendChild(title);

            if (barWidth > 90) {
                const label = interval.is_ongoing ? 'Aktív' : 'Lezárt';
                addSvgEl(g, 'text', {
                    x: x1 + 10,
                    y: top + (barHeight / 2) + 4,
                    class: 'chart-interval-label',
                }, label);
            }

            if (interval.is_ongoing) {
                addSvgEl(g, 'circle', {
                    cx: x1 + barWidth - 10,
                    cy: top + (barHeight / 2),
                    r: 4,
                    class: 'chart-interval-live-dot',
                });
            }
        });

        el.innerHTML = '';
        el.appendChild(svg);

        const hoverLayer = addSvgEl(svg, 'g', { class: 'chart-hover-layer' });
        const hoverLine = addSvgEl(hoverLayer, 'line', {
            x1: margin.left,
            y1: margin.top,
            x2: margin.left,
            y2: margin.top + innerH,
            class: 'chart-hover-line',
            visibility: 'hidden'
        });

        function hide() {
            hoverLine.setAttribute('visibility', 'hidden');
            hideTooltip(el);
        }

        svg.addEventListener('mouseleave', hide);
        svg.addEventListener('mousemove', event => {
            const rect = svg.getBoundingClientRect();
            const px = event.clientX - rect.left;
            const py = event.clientY - rect.top;
            const viewX = (px / rect.width) * width;
            if (viewX < margin.left || viewX > margin.left + innerW) {
                hide();
                return;
            }
            const ratio = (viewX - margin.left) / innerW;
            const epoch = minEpoch + ratio * Math.max(1, maxEpoch - minEpoch);
            const active = intervals.filter(interval => {
                const start = Number(interval.display_start_epoch || minEpoch);
                const end = Number(interval.display_end_epoch || maxEpoch);
                return epoch >= start && epoch <= end;
            });

            hoverLine.setAttribute('x1', viewX);
            hoverLine.setAttribute('x2', viewX);
            hoverLine.setAttribute('visibility', 'visible');

            const parts = [`<div class="chart-tooltip-title">${fmtDate.format(new Date(epoch * 1000)).replace(',', '')}</div>`];
            if (!active.length) {
                parts.push('<div>Nincs aktív riasztás ennél az időpontnál.</div>');
            } else {
                active.slice(0, 5).forEach(interval => {
                    parts.push(`
                        <div>
                            <span class="chart-tip-swatch chart-tip-${interval.style || 'generic'}"></span>
                            <strong>${interval.lane_label || 'Riasztás'}</strong><br>
                            <span class="chart-tip-sub">${interval.start_ts || '—'}${interval.end_ts ? ' → ' + interval.end_ts : ' → még aktív'}</span>
                        </div>
                    `);
                });
                if (active.length > 5) {
                    parts.push(`<div class="chart-tip-sub">+${active.length - 5} további aktív riasztás</div>`);
                }
            }
            showTooltip(el, parts.join(''), px, py);
        });
    }

    renderDualAxisChart(document.getElementById('chart-temp-humidity'), points);
    renderSignalChart(document.getElementById('chart-pressure'), 'pressure_hpa', 'hPa');
    renderSignalChart(document.getElementById('chart-wifi'), 'wifi_rssi', 'dBm');
    renderSignalChart(document.getElementById('chart-gsm'), 'gsm_rssi', 'dBm');
    renderAlertTimeline(document.getElementById('chart-alert-timeline'), alertTimeline);
})();
</script>
<?php include __DIR__ . '/../templates/footer.php'; ?>
